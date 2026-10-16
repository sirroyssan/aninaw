<?php
/**
 * New order: one or more products and services for one customer. Creating an order
 * records no payment; payments are received on the order page.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission('orders.manage');

if (is_post()) {
    handle_post(function () {
        $result = create_order_with_payment($_POST);
        if ($result['payment_id']) {
            $payment = db_one('SELECT p.amount, r.receipt_number FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id WHERE p.id = ?', [$result['payment_id']]);
            $order = find_order($result['order_id']);
            flash('success', 'Order created and payment received',
                $order['order_number'] . ($result['confirmed'] ? ' is confirmed. ' : ' is saved. ') . 'Receipt ' . $payment['receipt_number'] . ' for ' . m($payment['amount'])
                . ($order['balance_cents'] > 0 ? '; balance ' . money($order['balance_cents'], current_currency()) . '.' : '; fully paid.'));
            return 'collections/payment.php?id=' . $result['payment_id'];
        }
        flash('success', 'Order created', $result['confirmed'] ? 'It is confirmed and unpaid. Record the payment on this page when the customer pays.' : 'It is Pending and unpaid. Confirm it and record the payment on this page.');
        return 'orders/view.php?id=' . $result['order_id'];
    });
}

$institutionId = (int) current_institution_id();
$items = db_all(
    "SELECT v.id, v.variant_name, p.id AS product_id, p.product_name, p.sku, c.category_name, p.allow_partial_payment, p.fulfillment, p.unit_of_measure, p.fund_id, f.fund_name,
            COALESCE(v.retail_price, p.retail_price) AS price, " . SQL_ON_HAND . ' AS on_hand, ' . SQL_RESERVED . " AS reserved
     FROM product_variants v JOIN products p ON p.id = v.product_id AND p.institution_id = v.institution_id
     JOIN fund_accounts f ON f.id = p.fund_id AND f.institution_id = p.institution_id
     LEFT JOIN product_categories c ON c.id = p.category_id AND c.institution_id = p.institution_id
     WHERE v.institution_id = ? AND v.status = 'active' AND p.status = 'active' ORDER BY p.fulfillment = 'service', p.product_name, v.sort_order, v.id",
    [$institutionId]
);
$canReceive = can('collections.record');
$payAccounts = [];
if ($canReceive) {
    foreach (usable_financial_accounts() as $account) {
        $funds = db_query('SELECT fund_id FROM fund_account_mappings WHERE institution_id = ? AND financial_account_id = ? AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE())',
            [$institutionId, $account['id']])->fetchAll(PDO::FETCH_COLUMN);
        if ($funds) {
            $payAccounts[] = $account + ['fund_ids' => implode(',', $funds)];
        }
    }
}
$preselect = get_int('product');
$staff = member_options('orders.manage');
$lines = old('lines');
if (!is_array($lines) || !$lines) {
    $first = $preselect ? array_values(array_filter($items, static fn($i) => (int) $i['product_id'] === $preselect))[0]['id'] ?? null : null;
    $lines = [['variant_id' => $first]];
}

$renderLine = static function (int|string $index, array $line) use ($items, $staff): string {
    $name = static fn(string $field) => 'lines[' . $index . '][' . $field . ']';
    $options = '<option value="">Choose an item…</option>';
    $group = null;
    foreach ($items as $item) {
        $label = $item['fulfillment'] === 'service' ? 'Services' : 'Products';
        if ($label !== $group) {
            $options .= ($group ? '</optgroup>' : '') . '<optgroup label="' . e($label) . '">';
            $group = $label;
        }
        $stock = $item['fulfillment'] === 'stock' ? ' — ' . max(0, (int) $item['on_hand'] - (int) $item['reserved']) . ' available' : ($item['fulfillment'] === 'made_to_order' ? ' — made to order' : '');
        $options .= '<option value="' . (int) $item['id'] . '" data-price="' . e(cents_to_decimal(to_cents($item['price']))) . '" data-unit="' . e($item['unit_of_measure']) . '" data-service="' . ($item['fulfillment'] === 'service' ? '1' : '0') . '" data-fund="' . e($item['fund_name']) . '" data-fund-id="' . (int) $item['fund_id'] . '" data-partial="' . ((int) $item['allow_partial_payment'] ? '1' : '0') . '" data-search="' . e($item['sku'] . ' ' . ($item['category_name'] ?? '')) . '"'
            . ((string) ($line['variant_id'] ?? '') === (string) $item['id'] ? ' selected' : '') . '>'
            . e($item['product_name'] . ($item['variant_name'] !== 'Standard' ? ' · ' . $item['variant_name'] : '') . ' — ' . m($item['price']) . '/' . $item['unit_of_measure'] . $stock) . '</option>';
    }
    $options .= $group ? '</optgroup>' : '';
    $field = static fn(string $key) => e((string) ($line[$key] ?? ''));
    return '<div class="order-line card panel mb-2" data-order-line><div class="card-body">'
        . '<div class="row g-2 align-items-end">'
        . '<div class="col-lg-5"><label class="form-label">Item</label><select class="form-select" name="' . $name('variant_id') . '" data-order-variant data-searchable>' . $options . '</select></div>'
        . '<div class="col-4 col-lg-2" data-goods-only><label class="form-label">Quantity</label><input class="form-control" type="number" min="1" name="' . $name('quantity') . '" value="' . $field('quantity') . '" data-order-qty></div>'
        . '<div class="col-4 col-lg-1 d-none" data-service-only><label class="form-label">Pages</label><input class="form-control" type="number" min="1" name="' . $name('job_pages') . '" value="' . $field('job_pages') . '" data-order-pages></div>'
        . '<div class="col-4 col-lg-1 d-none" data-service-only><label class="form-label">Copies</label><input class="form-control" type="number" min="1" name="' . $name('job_copies') . '" value="' . $field('job_copies') . '" data-order-copies></div>'
        . '<div class="col-4 col-lg-2"><label class="form-label">Extra charge</label><input class="form-control text-amount" name="' . $name('additional_charge') . '" inputmode="decimal" value="' . $field('additional_charge') . '" data-order-charge></div>'
        . '<div class="col-8 col-lg-2 text-end"><div class="text-muted-sm">Line total</div><div class="fw-semibold" data-order-line-total>0.00</div></div>'
        . '<div class="col-4 col-lg-1 text-end"><button class="btn btn-outline-danger btn-xs" type="button" data-row-remove aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>'
        . '<div class="col-lg-4"><input class="form-control form-control-sm" name="' . $name('additional_charge_note') . '" maxlength="180" placeholder="Extra charge for… (binding, rush)" value="' . $field('additional_charge_note') . '" aria-label="Extra charge note"></div>'
        . '<div class="col-lg-8 d-none" data-service-only><div class="row g-2">'
        . '<div class="col-md-5"><input class="form-control form-control-sm" name="' . $name('job_instructions') . '" maxlength="2000" placeholder="Job instructions (paper, colour, binding…)" value="' . $field('job_instructions') . '" aria-label="Job instructions"></div>'
        . '<div class="col-md-3"><input class="form-control form-control-sm" type="date" name="' . $name('service_date') . '" value="' . e((string) ($line['service_date'] ?? date('Y-m-d'))) . '" aria-label="Service date"></div>'
        . '<div class="col-md-4"><select class="form-select form-select-sm" name="' . $name('assigned_membership_id') . '" aria-label="Assigned staff" data-searchable>' . options_html($staff, $line['assigned_membership_id'] ?? null, 'Assign staff…') . '</select></div>'
        . '<div class="col-12"><input class="form-control form-control-sm" type="file" name="job_file_' . $index . '" accept="application/pdf,image/jpeg,image/png,image/webp" aria-label="Job file"><div class="form-text">Optional file to print (PDF or image, 5 MB).</div></div>'
        . '</div></div>'
        . '</div></div></div>';
};

$pageTitle = 'New Order';
$activeNav = 'orders';
$breadcrumb = [auth_context()['label'], 'Orders'];
$pageScripts = ['js/finance.js'];
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$items): ?>
    <div class="card panel"><div class="card-body"><?= empty_state('bi-box-seam', 'No products or services yet', 'Add them under Products & Services first.') ?></div></div>
<?php else: ?>
<form method="post" enctype="multipart/form-data" class="form-card" data-row-editor data-order-form data-currency="<?= e(current_currency() === 'PHP' ? '₱' : '') ?>" style="max-width: 1100px">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card panel mb-3"><div class="card-body">
        <h3 class="form-section-title">Customer</h3>
        <div class="row g-3">
            <div class="col-12">
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="customer_mode" id="mode-existing" value="existing" <?= old('customer_mode', 'existing') === 'existing' ? 'checked' : '' ?> data-customer-mode><label class="form-check-label" for="mode-existing">Student / employee</label></div>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="customer_mode" id="mode-walkin" value="walkin" <?= old('customer_mode') === 'walkin' ? 'checked' : '' ?> data-customer-mode><label class="form-check-label" for="mode-walkin">Walk-in customer</label></div>
            </div>
            <div class="col-md-8" data-customer-existing><label class="form-label" for="customer_participant_id">Customer</label><select class="form-select" id="customer_participant_id" name="customer_participant_id" data-searchable><?= options_html(customer_options(), old('customer_participant_id'), 'Search or choose…') ?></select></div>
            <div class="col-md-4 d-none" data-customer-walkin><label class="form-label" for="walkin_first_name">First name</label><input class="form-control" id="walkin_first_name" name="walkin_first_name" maxlength="100" value="<?= e(old('walkin_first_name')) ?>"></div>
            <div class="col-md-4 d-none" data-customer-walkin><label class="form-label" for="walkin_last_name">Last name</label><input class="form-control" id="walkin_last_name" name="walkin_last_name" maxlength="100" value="<?= e(old('walkin_last_name')) ?>"></div>
            <div class="col-md-4 d-none" data-customer-walkin><label class="form-label" for="walkin_phone">Phone</label><input class="form-control" id="walkin_phone" name="walkin_phone" maxlength="50" value="<?= e(old('walkin_phone')) ?>"></div>
            <div class="col-md-4"><label class="form-label" for="order_date">Order date</label><input class="form-control" type="date" id="order_date" name="order_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('order_date', date('Y-m-d'))) ?>"></div>
        </div>
    </div></div>

    <h3 class="form-section-title">Items</h3>
    <div data-row-body>
        <?php foreach (array_values($lines) as $index => $line): ?><?= $renderLine($index, is_array($line) ? $line : []) ?><?php endforeach; ?>
    </div>
    <template data-row-template><?= $renderLine('__INDEX__', []) ?></template>
    <button class="btn btn-outline-primary mb-3" type="button" data-row-add><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add item</button>
    <p class="text-muted-sm">Prices are fixed when the order is saved; later price changes do not affect it. Printing per page is billed as pages × copies. All items in one order must belong to the same fund.</p>

    <div class="card panel"><div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-6"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"><?= e(old('notes')) ?></textarea></div>
            <?php if (can('orders.discount')): ?>
                <div class="col-md-2"><label class="form-label" for="discount_amount">Discount</label><input class="form-control text-amount" id="discount_amount" name="discount_amount" inputmode="decimal" value="<?= e(old('discount_amount')) ?>" data-order-discount></div>
                <div class="col-md-4"><label class="form-label" for="discount_reason">Discount reason</label><input class="form-control" id="discount_reason" name="discount_reason" maxlength="255" value="<?= e(old('discount_reason')) ?>"></div>
            <?php endif; ?>
        </div>
        <dl class="order-totals mt-3">
            <div><dt>Subtotal</dt><dd data-order-subtotal>0.00</dd></div>
            <div><dt>Discount</dt><dd data-order-discount-shown>0.00</dd></div>
            <div class="order-total"><dt>Total due</dt><dd data-order-total>0.00</dd></div>
        </dl>
    </div></div>

    <div class="card panel mt-3" data-order-pay><div class="card-body">
        <h3 class="form-section-title">Payment</h3>
        <?php $payMode = old('pay_mode', 'later'); ?>
        <?php if (!$canReceive): ?>
            <div class="notice notice-info mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><div>The order is saved unpaid, and a cashier records the payment on the order page. Your role cannot receive money.
                To take payments while creating orders, another administrator can add the <strong>Cashier</strong> role to your account (Users → Roles; roles can be combined), and a cash box in your custody must be linked to the product's fund (Funds → Financial Accounts).</div></div>
            <input type="hidden" name="pay_mode" value="later">
        <?php else: ?>
            <div class="mb-2">
                <?php foreach (['later' => 'Pay later', 'full' => 'Paid in full now', 'down' => 'Down payment now'] as $mode => $label): ?>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="pay_mode" id="pay-<?= $mode ?>" value="<?= $mode ?>" <?= $payMode === $mode ? 'checked' : '' ?> data-pay-mode><label class="form-check-label" for="pay-<?= $mode ?>"><?= e($label) ?></label></div>
                <?php endforeach; ?>
                <div class="form-text d-none" data-pay-partial-note>A down payment is not available: one or more items must be paid in full. To allow it, turn on "Allow partial payment" for the product.</div>
            </div>
            <div class="row g-3 d-none" data-pay-fields>
                <div class="col-md-3" data-pay-down-only><label class="form-label required" for="pay_amount">Down payment</label><input class="form-control text-amount" id="pay_amount" name="pay_amount" inputmode="decimal" value="<?= e(old('pay_amount')) ?>" data-pay-amount><div class="form-text">Balance after: <span data-pay-balance>0.00</span></div></div>
                <div class="col-md-3" data-pay-full-only><label class="form-label">Amount received</label><div class="form-control-plaintext fw-semibold" data-pay-full>0.00</div></div>
                <div class="col-md-3"><label class="form-label" for="payment_method">Method</label><select class="form-select" id="payment_method" name="payment_method"><?= options_html(PAYMENT_METHODS, old('payment_method', 'cash')) ?></select></div>
                <div class="col-md-3"><label class="form-label required" for="financial_account_id">Received into</label>
                    <select class="form-select" id="financial_account_id" name="financial_account_id" data-pay-account>
                        <option value="">Select…</option>
                        <?php foreach ($payAccounts as $account): ?>
                            <option value="<?= (int) $account['id'] ?>" data-funds="<?= e($account['fund_ids']) ?>"<?= (string) old('financial_account_id') === (string) $account['id'] ? ' selected' : '' ?>><?= e(financial_account_label($account)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text text-danger d-none" data-pay-no-account>You have no cash box or account linked to this item's fund. Ask the treasurer to link one (Funds → Financial Accounts).</div></div>
                <div class="col-md-3"><label class="form-label" for="external_reference">Reference no.</label><input class="form-control" id="external_reference" name="external_reference" maxlength="150" value="<?= e(old('external_reference')) ?>" placeholder="Required for GCash / bank"></div>
            </div>
            <p class="text-muted-sm mt-2 mb-0">An official receipt is issued for the amount received. The order, its confirmation and the payment are saved together: if any part fails, nothing is saved.</p>
        <?php endif; ?>
        <div class="form-check mt-3"><input type="hidden" name="confirm_now" value="0"><input class="form-check-input" type="checkbox" id="confirm_now" name="confirm_now" value="1" <?= old('confirm_now', '0') === '1' ? 'checked' : '' ?> data-confirm-now><label class="form-check-label" for="confirm_now">Confirm the order now: the customer has committed (stock items are reserved; shirts join the production list)</label></div>
        <div class="form-actions">

            <a class="btn btn-outline-secondary" href="<?= e(url('orders/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-cart-check me-1" aria-hidden="true"></i>Create Order</button>
        </div>
    </div></div>
</form>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
