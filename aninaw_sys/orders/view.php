<?php
/**
 * Order: lines with fulfilment and job status, payments and balance, release to the
 * customer, discount, cancellation and history. Order and payment status are separate.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_institution_context();
require_permission(['orders.view', 'orders.manage', 'collections.record']);

$orderId = require_get_id();
$order = require_found(find_order($orderId));

if (is_post()) {
    $action = post_str('action', 20);
    handle_post(function () use ($action, $orderId) {
        switch ($action) {
            case 'confirm':
                confirm_order($orderId);
                flash('success', 'Order confirmed', 'Stock items are now reserved for this customer.');
                break;
            case 'ready':
                mark_order_ready($orderId);
                flash('success', 'Ready for release', 'The items are set aside for this customer.');
                break;
            case 'pay':
                // A pending order can be confirmed and paid in one step (both or neither are saved).
                $confirmed = false;
                $paymentId = db_transaction(function () use ($orderId, &$confirmed) {
                    if (post_str('confirm_now', 1) === '1' && db_value('SELECT order_status FROM sales_orders WHERE institution_id = ? AND id = ?', [current_institution_id(), $orderId]) === 'pending') {
                        confirm_order($orderId);
                        $confirmed = true;
                    }
                    return record_order_payment($orderId, $_POST);
                });
                flash('success', $confirmed ? 'Order confirmed and payment recorded' : 'Payment recorded', 'An official receipt was issued.');
                return 'collections/payment.php?id=' . $paymentId;
            case 'release':
                $count = release_order_items($orderId, $_POST);
                flash('success', 'Released', $count . ' unit(s) handed over to the customer.');
                break;
            case 'job':
                update_service_job(post_int('item_id') ?? 0, post_str('status', 20), post_int('assigned_membership_id'));
                flash('success', 'Job updated');
                break;
            case 'discount':
                apply_order_discount($orderId, $_POST);
                flash('success', 'Discount updated');
                break;
            case 'cancel':
                cancel_order($orderId, post_str('reason', 1000));
                flash('success', 'Order cancelled');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'orders/view.php?id=' . $orderId;
    }, in_array($action, ['pay', 'release'], true));
}

$institutionId = (int) current_institution_id();
$currency = current_currency();
$open = in_array($order['order_status'], ORDER_OPEN_STATUSES, true);
$canManage = can('orders.manage');
$hasMade = (bool) array_filter($order['items'], static fn($i) => $i['fulfillment'] === 'made_to_order');
$releasable = array_filter($order['items'], static fn($i) => $i['quantity_released'] < $i['quantity']);
$staff = member_options('orders.manage');
$accounts = can('collections.record') ? usable_financial_accounts((int) $order['fund_id']) : [];
$accountOptions = options_from_rows($accounts, 'id', static fn($r) => financial_account_label($r));
$attachments = [];
foreach ($order['items'] as $item) {
    if ($item['fulfillment'] === 'service') {
        $attachments[(int) $item['id']] = attachments_for('order_item', (int) $item['id']);
    }
}
$history = db_all(
    "SELECT a.action, a.reason, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE a.institution_id = ? AND a.entity_type = 'sales_order' AND a.entity_id = ? ORDER BY a.id",
    [$institutionId, $orderId]
);
$fulfillmentLabels = ['stock' => 'From stock', 'made_to_order' => 'Made to order', 'service' => 'Service'];

$pageTitle = 'Order ' . $order['order_number'];
$activeNav = 'orders';
$breadcrumb = [auth_context()['label'], 'Orders'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($order['customer_name'] . ' · ' . $order['participant_number'] . ' · ' . format_date($order['order_date']) . ' · ' . $order['fund_name'],
    button_link('orders/index.php', 'All Orders', 'bi-arrow-left', 'btn-outline-secondary')
    . button_link('orders/slip.php?id=' . $orderId, 'Order Slip', 'bi-printer', 'btn-outline-primary')) ?>

<?php if ($order['order_status'] === 'cancelled'): ?>
    <div class="notice notice-danger"><i class="bi bi-x-circle" aria-hidden="true"></i><div>Cancelled: <?= e((string) $order['cancelled_reason']) ?></div></div>
<?php elseif ($order['order_status'] === 'ready_for_release' && $order['ready_at'] && strtotime($order['ready_at']) < strtotime('-7 days')): ?>
    <div class="notice notice-warning"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div>Unclaimed: ready since <?= e(format_date($order['ready_at'])) ?>.</div></div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('bi-flag', 'Order status', ORDER_STATUSES[$order['order_status']], $order['ready_at'] ? 'Ready since ' . format_date($order['ready_at']) : 'Created by ' . $order['created_by_name']) ?>
    <?= stat_card('bi-receipt', 'Total due', m($order['total_amount']), to_cents($order['discount_amount']) ? 'After ' . m($order['discount_amount']) . ' discount' : 'No discount', 'info') ?>
    <?= stat_card('bi-cash-coin', 'Paid', money($order['paid_cents'], $currency), ORDER_PAYMENT_STATUSES[$order['payment_status']], $order['payment_status'] === 'fully_paid' ? 'success' : 'warning') ?>
    <?= stat_card('bi-hourglass', 'Balance', money(max(0, $order['balance_cents']), $currency), $order['partial_allowed'] ? 'Partial payments allowed' : 'Full payment required', $order['balance_cents'] > 0 ? 'danger' : 'success') ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Items</h3><span><?= status_badge($order['order_status']) ?> <?= status_badge($order['payment_status']) ?></span></div>
            <div class="table-responsive">
                <table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Line total</th><th class="text-end">Released</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($order['items'] as $item): ?>
                        <tr>
                            <td><div class="fw-semibold"><?= e($item['description']) ?></div>
                                <div class="text-muted-sm"><?= e($fulfillmentLabels[$item['fulfillment']] . ' · per ' . $item['pricing_unit']) ?><?= $item['job_pages'] ? ' · ' . e($item['job_pages'] . ' pages × ' . ($item['job_copies'] ?: 1) . ' copies') : '' ?><?= to_cents($item['additional_charge']) ? ' · + ' . e(m($item['additional_charge']) . ' ' . $item['additional_charge_note']) : '' ?></div>
                                <?php if ($item['fulfillment'] === 'service'): ?>
                                    <div class="text-muted-sm"><?= e(($item['job_instructions'] ?: 'No instructions') . ' · ' . format_date($item['service_date']) . ' · ' . ($item['assigned_name'] ?: 'unassigned')) ?></div>
                                    <?= $attachments[(int) $item['id']] ? attachment_list_html($attachments[(int) $item['id']]) : '' ?>
                                <?php endif; ?></td>
                            <td class="text-end"><?= e((string) $item['quantity']) ?></td>
                            <td class="text-end"><?= e(m($item['unit_price'])) ?></td>
                            <td class="text-end fw-semibold"><?= e(m($item['line_total'])) ?></td>
                            <td class="text-end"><?= e($item['quantity_released'] . ' / ' . $item['quantity']) ?></td>
                            <td><?php if ($item['fulfillment'] === 'service'): ?><?= status_badge($item['service_status']) ?>
                                    <?php if ($canManage && in_array($item['service_status'], ['pending', 'in_progress'], true) && in_array($order['order_status'], ['confirmed', 'in_production', 'ready_for_release', 'partially_released'], true)): ?>
                                        <form method="post" class="mt-1" data-confirm="<?= $item['service_status'] === 'pending' ? 'Start this job?' : 'Mark this job completed?' ?>"<?= $item['service_status'] === 'in_progress' ? ' data-confirm-text="Supplies set up for this service are deducted from stock."' : '' ?>>
                                            <?= csrf_field() ?><input type="hidden" name="action" value="job"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="status" value="<?= $item['service_status'] === 'pending' ? 'in_progress' : 'completed' ?>">
                                            <button class="btn btn-outline-primary btn-xs" type="submit"><?= $item['service_status'] === 'pending' ? 'Start' : 'Complete' ?></button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?><span class="text-muted-sm"><?= $item['quantity_released'] >= $item['quantity'] ? 'Released' : ($item['quantity_released'] ? 'Partly released' : 'Not released') ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="3" class="text-end">Subtotal</td><td class="text-end"><?= e(m($order['subtotal'])) ?></td><td colspan="2"></td></tr>
                        <?php if (to_cents($order['discount_amount'])): ?><tr><td colspan="3" class="text-end">Discount <span class="text-muted-sm">(<?= e((string) $order['discount_reason']) ?>, by <?= e((string) $order['discount_approved_by_name']) ?>)</span></td><td class="text-end">−<?= e(m($order['discount_amount'])) ?></td><td colspan="2"></td></tr><?php endif; ?>
                        <tr class="fw-semibold"><td colspan="3" class="text-end">Total due</td><td class="text-end"><?= e(m($order['total_amount'])) ?></td><td colspan="2"></td></tr>
                    </tfoot>
                </table>
            </div>
            <?php if (can_any(['orders.view']) && can('products.pricing')): ?><div class="card-footer-note">Cost of goods (snapshot) <?= e(money($order['cost_cents'], $currency)) ?> · expected gross profit <?= e(money($order['expected_profit_cents'], $currency)) ?></div><?php endif; ?>
        </div>

        <div class="card panel mb-3">
            <div class="card-header"><h3>Payments</h3></div>
            <?php if (!$order['payments']): ?>
                <div class="card-body"><?= empty_state('bi-cash-coin', 'No payments yet', 'An order is not paid until a payment is recorded.') ?></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Date</th><th>Payment · Receipt</th><th>Received by</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                    <tbody><?php foreach ($order['payments'] as $payment): ?>
                        <tr><td><?= e(format_datetime($payment['payment_date'])) ?></td><td><?= e($payment['payment_number']) ?><div class="text-muted-sm"><?= e(($payment['receipt_number'] ?: '—') . ' · ' . (PAYMENT_METHODS[$payment['payment_method']] ?? $payment['payment_method'])) ?></div></td>
                            <td><?= e($payment['received_by_name']) ?></td><td class="text-end fw-semibold<?= $payment['status'] !== 'posted' ? ' text-decoration-line-through text-muted' : '' ?>"><?= e(m($payment['amount'])) ?></td><td><?= status_badge($payment['status']) ?></td>
                            <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('collections/payment.php?id=' . $payment['id'])) ?>">Open</a></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
            <div class="card-footer-note">Refunds: open the payment and reverse it with a reason; the receipt is voided and the balance reopens.</div>
        </div>

        <?php if ($order['releases']): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Released to customer</h3></div>
                <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                    <thead><tr><th>When</th><th>Item</th><th class="text-end">Qty</th><th>Claimed by</th><th>Released by</th></tr></thead>
                    <tbody><?php foreach ($order['releases'] as $release): ?>
                        <tr><td><?= e(format_datetime($release['released_at'])) ?></td><td><?= e($release['description']) ?></td><td class="text-end"><?= e((string) $release['quantity']) ?></td><td><?= e($release['received_by_name']) ?></td><td class="text-muted-sm"><?= e($release['released_by_name']) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <?php if ($order['order_status'] === 'pending' && $canManage): ?>
            <form method="post" class="card panel mb-3" data-confirm="Confirm this order?" data-confirm-text="Items from stock are reserved for this customer.">
                <?= csrf_field() ?><input type="hidden" name="action" value="confirm">
                <div class="card-body"><p class="text-muted-sm">Confirm once the customer commits to the order. Stock items are reserved; made-to-order items join the production list.</p><button class="btn btn-primary w-100" type="submit">Confirm Order</button></div>
            </form>
        <?php endif; ?>
        <?php if (in_array($order['order_status'], ['confirmed', 'in_production'], true) && $canManage): ?>
            <form method="post" class="card panel mb-3" data-confirm="Mark ready for release?" data-confirm-text="<?= $hasMade ? 'Received items are set aside for this customer.' : 'The customer can now claim the order.' ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="ready">
                <div class="card-body"><p class="text-muted-sm"><?= $hasMade ? 'When the shirts have been received, set this customer\'s sizes aside.' : 'Use when everything is prepared for the customer.' ?></p><button class="btn btn-outline-primary w-100" type="submit">Mark Ready for Release</button></div>
            </form>
        <?php endif; ?>

        <?php if ($open && $order['balance_cents'] > 0 && can('collections.record')): ?>
            <form method="post" class="card panel form-card mb-3" data-confirm="Record this payment?" data-confirm-text="An official receipt is issued and the amount is posted to <?= e($order['fund_name']) ?>.">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="pay">
                <div class="card-header"><h3>Receive payment</h3></div>
                <div class="card-body">
                    <?php if (!$accountOptions): ?>
                        <p class="text-muted-sm mb-0">You have no cash box or account mapped to <?= e($order['fund_name']) ?>. Ask the treasurer to map one before receiving payments.</p>
                    <?php else: ?>
                        <div class="mb-2"><label class="form-label required" for="amount">Amount</label><input class="form-control text-amount" id="amount" name="amount" required inputmode="decimal" value="<?= e(cents_to_decimal($order['balance_cents'])) ?>" <?= $order['partial_allowed'] ? '' : 'readonly' ?>><div class="form-text"><?= $order['partial_allowed'] ? 'Full amount, or enter a smaller down payment. Balance ' . e(money($order['balance_cents'], $currency)) . '.' : 'Full payment required (down payment not allowed for these items).' ?></div></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label" for="payment_method">Method</label><select class="form-select" id="payment_method" name="payment_method"><?= options_html(PAYMENT_METHODS, 'cash') ?></select></div>
                            <div class="col-6"><label class="form-label" for="payment_date">Date</label><input class="form-control" type="date" id="payment_date" name="payment_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
                        </div>
                        <div class="mb-2"><label class="form-label required" for="financial_account_id">Received into</label><select class="form-select" id="financial_account_id" name="financial_account_id" required><?= options_html($accountOptions, count($accountOptions) === 1 ? array_key_first($accountOptions) : null, 'Select…') ?></select></div>
                        <div class="mb-3"><label class="form-label" for="external_reference">Reference no.</label><input class="form-control" id="external_reference" name="external_reference" maxlength="150" placeholder="Required for GCash / bank"></div>
                        <?php if ($order['order_status'] === 'pending' && $canManage): ?>
                            <div class="form-check mb-3"><input type="hidden" name="confirm_now" value="0"><input class="form-check-input" type="checkbox" id="pay_confirm_now" name="confirm_now" value="1" checked><label class="form-check-label" for="pay_confirm_now">Also confirm the order (the customer has committed)</label></div>
                        <?php endif; ?>
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-cash me-1" aria-hidden="true"></i>Record Payment</button>
                    <?php endif; ?>
                </div>
            </form>
        <?php elseif ($open && $order['balance_cents'] > 0): ?>
            <div class="card panel mb-3"><div class="card-body">
                <h3 class="h6">Receiving payment</h3>
                <p class="text-muted-sm mb-0">Balance <?= e(money($order['balance_cents'], $currency)) ?><?= $order['partial_allowed'] ? ' (a down payment is allowed)' : ' (full payment required)' ?>. Payments are recorded by a cashier, collector or treasurer, who issues the official receipt.
                    To receive payments yourself, another administrator can add the Cashier role to your account (Users → Roles), with a cash box in your custody linked to <?= e($order['fund_name']) ?>.</p>
            </div></div>
        <?php endif; ?>

        <?php if ($releasable && $canManage && in_array($order['order_status'], ['confirmed', 'in_production', 'ready_for_release', 'partially_released'], true)): ?>
            <form method="post" class="card panel form-card mb-3" data-confirm="Release these items?" data-confirm-text="Released items leave stock and cannot be undone except by a return.">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="release">
                <div class="card-header"><h3>Release to customer</h3></div>
                <div class="card-body">
                    <?php if ($order['balance_cents'] > 0): ?><div class="notice notice-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>Balance of <?= e(money($order['balance_cents'], $currency)) ?> must be paid before release.</div></div><?php endif; ?>
                    <?php foreach ($releasable as $item): $left = $item['quantity'] - $item['quantity_released']; ?>
                        <div class="mb-2"><label class="form-label" for="rel-<?= (int) $item['id'] ?>"><?= e($item['description']) ?> <span class="text-muted-sm">(<?= $left ?> left)</span></label>
                            <input class="form-control" type="number" min="0" max="<?= $left ?>" id="rel-<?= (int) $item['id'] ?>" name="release[<?= (int) $item['id'] ?>]" value="<?= $left ?>" <?= $item['fulfillment'] === 'service' && $item['service_status'] !== 'completed' ? 'disabled' : '' ?>></div>
                    <?php endforeach; ?>
                    <div class="mb-3"><label class="form-label required" for="received_by_name">Claimed by</label><input class="form-control" id="received_by_name" name="received_by_name" required maxlength="180" value="<?= e($order['customer_name']) ?>"></div>
                    <button class="btn btn-primary w-100" type="submit"<?= $order['balance_cents'] > 0 ? ' disabled' : '' ?>>Release</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($open && can('orders.discount')): ?>
            <form method="post" class="card panel form-card mb-3" data-confirm="Update the discount?">
                <?= csrf_field() ?><input type="hidden" name="action" value="discount">
                <div class="card-header"><h3>Discount</h3></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label" for="discount_amount">Amount</label><input class="form-control text-amount" id="discount_amount" name="discount_amount" inputmode="decimal" value="<?= e(cents_to_decimal(to_cents($order['discount_amount']))) ?>"></div>
                    <div class="mb-3"><label class="form-label" for="discount_reason">Reason</label><input class="form-control" id="discount_reason" name="discount_reason" maxlength="255" value="<?= e((string) $order['discount_reason']) ?>"></div>
                    <button class="btn btn-outline-primary w-100" type="submit">Save Discount</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if (in_array($order['order_status'], ['pending', 'confirmed', 'in_production', 'ready_for_release'], true) && can('orders.cancel')): ?>
            <form method="post" class="card panel mb-3" data-confirm="Cancel this order?" data-reason="Reason for cancelling" data-danger>
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <div class="card-body"><p class="text-muted-sm"><?= $order['paid_cents'] > 0 ? 'Reverse the payments first; a paid order cannot be cancelled.' : 'Reserved stock is freed.' ?></p><button class="btn btn-outline-danger w-100" type="submit">Cancel Order</button></div>
            </form>
        <?php endif; ?>

        <div class="card panel mb-3"><div class="card-body">
            <dl class="detail-grid">
                <div><dt>Customer</dt><dd><?= e($order['customer_name']) ?><div class="text-muted-sm"><?= e($order['participant_number'] . ' · ' . humanize($order['participant_type']) . ($order['phone'] ? ' · ' . $order['phone'] : '')) ?></div></dd></div>
                <div><dt>Fund</dt><dd><?= e($order['fund_name']) ?></dd></div>
            </dl>
            <?php if ($order['notes']): ?><p class="mt-3 mb-0 text-muted-sm" style="white-space: pre-line"><?= e($order['notes']) ?></p><?php endif; ?>
        </div></div>
        <div class="card panel">
            <div class="card-header"><h3>History</h3></div>
            <div class="card-body"><ul class="timeline">
                <?php foreach ($history as $row): ?>
                    <li><div class="fw-semibold"><?= e(humanize(str_replace('order.', '', $row['action']))) ?></div><div class="text-muted-sm"><?= e(($row['actor'] ?: 'System') . ' · ' . format_datetime($row['created_at'])) ?></div><?php if ($row['reason']): ?><div class="text-muted-sm">“<?= e($row['reason']) ?>”</div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
