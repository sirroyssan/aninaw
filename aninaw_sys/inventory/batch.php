<?php
/**
 * Supplier / production order: create (quantities per size), place, receive deliveries,
 * link the paying expense, cancel. Received quantities become stock.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/products.php';
require_institution_context();
require_permission(['inventory.view', 'inventory.manage']);

$batchId = get_int('id');
$batch = $batchId ? require_found(find_batch($batchId)) : null;
$product = require_found(find_product($batch ? (int) $batch['product_id'] : (get_int('product') ?? 0)));
if ($product['fulfillment'] === 'service') {
    render_error_page(404, 'Not Stocked', 'Services are not ordered from suppliers.');
}

if (is_post()) {
    require_permission('inventory.manage');
    $action = post_str('action', 20);
    handle_post(function () use ($action, $batchId, $product) {
        switch ($action) {
            case 'create':
                $id = create_batch((int) $product['id'], $_POST);
                flash('success', 'Order drafted', 'Place it when it is sent to the supplier or printer.');
                return 'inventory/batch.php?id=' . $id;
            case 'order':
                mark_batch_ordered($batchId, $_POST);
                flash('success', 'Order placed', 'Open made-to-order customer orders for this product are now In Production.');
                break;
            case 'receive':
                receive_batch($batchId, $_POST);
                flash('success', 'Delivery received', 'The quantities were added to stock.');
                break;
            case 'expense':
                link_batch_expense($batchId, post_int('expense_id'));
                flash('success', 'Expense linked');
                break;
            case 'cancel':
                cancel_batch($batchId, post_str('reason', 500));
                flash('success', 'Order cancelled');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'inventory/batch.php?id=' . $batchId;
    }, in_array($action, ['create', 'receive'], true));
}

$currency = current_currency();
$demand = [];
if (!$batch && $product['fulfillment'] === 'made_to_order') {
    // Suggest quantities: confirmed customer demand not yet covered by stock or open orders.
    foreach (db_all(
        "SELECT i.variant_id, SUM(i.quantity - i.quantity_released) AS qty FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
         WHERE i.institution_id = ? AND i.product_id = ? AND i.fulfillment = 'made_to_order' AND o.order_status IN ('confirmed', 'in_production') GROUP BY i.variant_id",
        [current_institution_id(), $product['id']]
    ) as $row) {
        $demand[(int) $row['variant_id']] = (int) $row['qty'];
    }
}
$expenses = $batch ? options_from_rows(db_all(
    "SELECT id, expense_number, payee, amount FROM expenses WHERE institution_id = ? AND fund_id = ? AND status IN ('submitted', 'approved', 'paid') ORDER BY expense_date DESC LIMIT 100",
    [current_institution_id(), $product['fund_id']]
), 'id', static fn($r) => $r['expense_number'] . ' · ' . $r['payee'] . ' · ' . m($r['amount'])) : [];

$pageTitle = $batch ? 'Order ' . $batch['batch_number'] : ($product['fulfillment'] === 'made_to_order' ? 'New Production Order' : 'New Supplier Order');
$activeNav = 'inventory';
$breadcrumb = [auth_context()['label'], 'Supplier & Production Orders'];
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$batch): ?>
    <?= page_actions($product['product_name'] . ' · ' . PRODUCT_FULFILLMENT[$product['fulfillment']], button_link('products/view.php?id=' . $product['id'], 'Product', 'bi-arrow-left', 'btn-outline-secondary')) ?>
    <form method="post" class="card panel form-card" style="max-width: 860px" data-modal-body>
        <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="create">
        <div class="card-body">
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label" for="supplier_name">Supplier / printer</label><input class="form-control" id="supplier_name" name="supplier_name" maxlength="180"></div>
                <div class="col-md-6"><label class="form-label" for="expected_date">Expected delivery</label><input class="form-control" type="date" id="expected_date" name="expected_date"></div>
            </div>
            <div class="table-responsive">
                <table class="table table-clean align-middle mb-2">
                    <thead><tr><th>Size / variant</th><th class="text-end">On hand</th><?php if ($demand !== [] || $product['fulfillment'] === 'made_to_order'): ?><th class="text-end">Orders to fill</th><?php endif; ?><th style="width:140px">Quantity to order</th><th style="width:150px">Unit cost</th></tr></thead>
                    <tbody>
                    <?php foreach ($product['variants'] as $variant): if ($variant['status'] !== 'active') { continue; } $need = max(0, ($demand[(int) $variant['id']] ?? 0) - max(0, $variant['available']) - $variant['on_order']); ?>
                        <tr>
                            <td class="fw-semibold"><?= e($variant['variant_name']) ?></td>
                            <td class="text-end"><?= e(number_format($variant['on_hand'])) ?></td>
                            <?php if ($product['fulfillment'] === 'made_to_order'): ?><td class="text-end"><?= e(number_format($demand[(int) $variant['id']] ?? 0)) ?></td><?php endif; ?>
                            <td><input class="form-control form-control-sm" type="number" min="0" name="quantity[<?= (int) $variant['id'] ?>]" value="<?= $need ?: '' ?>" aria-label="Quantity for <?= e($variant['variant_name']) ?>"></td>
                            <td><input class="form-control form-control-sm text-amount" name="unit_cost[<?= (int) $variant['id'] ?>]" inputmode="decimal" placeholder="<?= e(cents_to_decimal(to_cents($variant['effective_cost']))) ?>" aria-label="Unit cost for <?= e($variant['variant_name']) ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($product['fulfillment'] === 'made_to_order'): ?><p class="text-muted-sm">Suggested quantities cover confirmed customer orders not yet met by available stock or open orders.</p><?php endif; ?>
            <div class="mb-2"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"></textarea></div>
            <div class="form-actions"><a class="btn btn-outline-secondary" href="<?= e(url('products/view.php?id=' . $product['id'])) ?>">Cancel</a><button class="btn btn-primary" type="submit">Save Draft Order</button></div>
        </div>
    </form>
<?php else: $ordered = array_sum(array_column($batch['items'], 'quantity_ordered')); $received = array_sum(array_column($batch['items'], 'quantity_received')); $cost = array_sum(array_map(static fn($i) => $i['quantity_ordered'] * to_cents($i['unit_cost']), $batch['items'])); ?>
    <div class="report-header"><h2><?= e(($product['fulfillment'] === 'made_to_order' ? 'Production order ' : 'Supplier order ') . $batch['batch_number']) ?></h2><p><?= e(auth_context()['label']) ?> · <?= e($product['product_name']) ?> · <?= e($batch['supplier_name'] ?: 'Supplier not named') ?> · Printed <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
    <?= page_actions($product['product_name'] . ' · ' . ($batch['supplier_name'] ?: 'Supplier not named'),
        button_link('inventory/batches.php', 'All Orders', 'bi-arrow-left', 'btn-outline-secondary')
        . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>
    <div class="stat-grid">
        <?= stat_card('bi-truck', 'Ordered', number_format($ordered), BATCH_STATUSES[$batch['status']]) ?>
        <?= stat_card('bi-box-arrow-in-down', 'Received', number_format($received), number_format($ordered - $received) . ' still expected', $ordered > $received ? 'warning' : 'success') ?>
        <?= stat_card('bi-cash', 'Order cost', money($cost, $currency), 'Quantity × unit cost', 'info') ?>
    </div>
    <div class="row g-3">
        <div class="col-xl-8">
            <form method="post" class="card panel" data-confirm="Record this delivery?" data-confirm-text="Received quantities are added to stock.">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="receive">
                <div class="card-header"><h3>Quantities</h3><?= status_badge($batch['status']) ?></div>
                <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Size / variant</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Remaining</th><th class="text-end">Unit cost</th><?php $canReceive = can('inventory.manage') && in_array($batch['status'], ['ordered', 'partially_received'], true); if ($canReceive): ?><th class="d-print-none" style="width:130px">Receive now</th><?php endif; ?></tr></thead>
                    <tbody><?php foreach ($batch['items'] as $item): $left = $item['quantity_ordered'] - $item['quantity_received']; ?>
                        <tr><td class="fw-semibold"><?= e($item['variant_name']) ?></td><td class="text-end"><?= e((string) $item['quantity_ordered']) ?></td><td class="text-end"><?= e((string) $item['quantity_received']) ?></td><td class="text-end"><?= e((string) $left) ?></td><td class="text-end"><?= e(m($item['unit_cost'])) ?></td>
                            <?php if ($canReceive): ?><td class="d-print-none"><input class="form-control form-control-sm" type="number" min="0" max="<?= $left ?>" name="received[<?= (int) $item['id'] ?>]" value="" <?= $left ? '' : 'disabled' ?> aria-label="Receive <?= e($item['variant_name']) ?>"></td><?php endif; ?></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php if ($canReceive): ?>
                    <div class="card-body d-print-none row g-2 align-items-end">
                        <div class="col-md-4"><label class="form-label required" for="received_date">Delivery date</label><input class="form-control" type="date" id="received_date" name="received_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
                        <div class="col-md-5"><label class="form-label" for="reason">Delivery note / DR no.</label><input class="form-control" id="reason" name="reason" maxlength="500"></div>
                        <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Receive</button></div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <div class="col-xl-4 d-print-none">
            <div class="card panel mb-3"><div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Ordered on</dt><dd><?= e(format_date($batch['ordered_at'])) ?></dd></div>
                    <div><dt>Expected</dt><dd><?= e(format_date($batch['expected_date'])) ?></dd></div>
                    <div><dt>Created by</dt><dd><?= e($batch['created_by_name']) ?></dd></div>
                    <div><dt>Paid by expense</dt><dd><?= $batch['expense_number'] ? '<a href="' . e(url('expenses/view.php?id=' . $batch['expense_id'])) . '">' . e($batch['expense_number']) . '</a>' : '—' ?></dd></div>
                </dl>
                <?php if ($batch['notes']): ?><p class="mt-3 mb-0 text-muted-sm" style="white-space: pre-line"><?= e($batch['notes']) ?></p><?php endif; ?>
            </div></div>
            <?php if (can('inventory.manage')): ?>
                <?php if ($batch['status'] === 'draft'): ?>
                    <form method="post" class="card panel mb-3" data-confirm="Place this order?" data-confirm-text="Customer orders waiting for this product move to In Production.">
                        <?= csrf_field() ?><input type="hidden" name="action" value="order">
                        <div class="card-body"><label class="form-label" for="ordered_at">Ordered on</label><input class="form-control mb-2" type="date" id="ordered_at" name="ordered_at" value="<?= e(date('Y-m-d')) ?>"><button class="btn btn-primary w-100" type="submit">Place Order</button></div>
                    </form>
                <?php endif; ?>
                <?php if ($batch['status'] !== 'cancelled'): ?>
                    <form method="post" class="card panel mb-3">
                        <?= csrf_field() ?><input type="hidden" name="action" value="expense">
                        <div class="card-body"><label class="form-label" for="expense_id">Expense that pays the supplier</label><select class="form-select mb-2" id="expense_id" name="expense_id"><?= options_html($expenses, $batch['expense_id'], 'None') ?></select><button class="btn btn-outline-primary w-100" type="submit">Link Expense</button>
                            <div class="form-text">Record the payment in Expenses (with its receipt), then link it here.</div></div>
                    </form>
                <?php endif; ?>
                <?php if (in_array($batch['status'], ['draft', 'ordered'], true)): ?>
                    <form method="post" class="card panel" data-confirm="Cancel this order?" data-reason="Reason" data-danger>
                        <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><div class="card-body"><button class="btn btn-outline-danger w-100" type="submit">Cancel Order</button></div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
