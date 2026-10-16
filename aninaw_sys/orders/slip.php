<?php
/**
 * Printable order slip: what was ordered, prices, payments and balance. Not a receipt;
 * official receipts are issued per payment.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_institution_context();
require_permission(['orders.view', 'orders.manage', 'collections.record']);

$order = require_found(find_order(require_get_id()));
$currency = current_currency();

$pageTitle = 'Order Slip ' . $order['order_number'];
$activeNav = 'orders';
$breadcrumb = [auth_context()['label'], 'Orders'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Print this slip for the customer. It is not an official receipt; receipts are issued for each payment.',
    button_link('orders/view.php?id=' . $order['id'], 'Back to Order', 'bi-arrow-left', 'btn-outline-secondary')
    . '<button class="btn btn-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>
<div class="card panel order-slip" style="max-width: 760px">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div><div class="fw-bold fs-5"><?= e(auth_context()['label']) ?></div><div class="text-muted-sm">Order slip · not an official receipt</div></div>
            <div class="text-end"><div class="fw-bold"><?= e($order['order_number']) ?></div><div class="text-muted-sm"><?= e(format_date($order['order_date'])) ?></div></div>
        </div>
        <dl class="detail-grid mb-3">
            <div><dt>Customer</dt><dd><?= e($order['customer_name']) ?><div class="text-muted-sm"><?= e($order['participant_number']) ?></div></dd></div>
            <div><dt>Order status</dt><dd><?= e(ORDER_STATUSES[$order['order_status']]) ?></dd></div>
            <div><dt>Payment status</dt><dd><?= e(ORDER_PAYMENT_STATUSES[$order['payment_status']]) ?></dd></div>
        </dl>
        <table class="table table-clean align-middle mb-3">
            <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Amount</th></tr></thead>
            <tbody><?php foreach ($order['items'] as $item): ?>
                <tr><td><?= e($item['description']) ?><?= $item['job_pages'] ? '<div class="text-muted-sm">' . e($item['job_pages'] . ' pages × ' . ($item['job_copies'] ?: 1) . ' copies') . '</div>' : '' ?><?= to_cents($item['additional_charge']) ? '<div class="text-muted-sm">+ ' . e(m($item['additional_charge']) . ' ' . $item['additional_charge_note']) . '</div>' : '' ?></td>
                    <td class="text-end"><?= e((string) $item['quantity']) ?></td><td class="text-end"><?= e(m($item['unit_price'])) ?></td><td class="text-end"><?= e(m($item['line_total'])) ?></td></tr>
            <?php endforeach; ?></tbody>
            <tfoot>
                <tr><td colspan="3" class="text-end">Subtotal</td><td class="text-end"><?= e(m($order['subtotal'])) ?></td></tr>
                <?php if (to_cents($order['discount_amount'])): ?><tr><td colspan="3" class="text-end">Discount</td><td class="text-end">−<?= e(m($order['discount_amount'])) ?></td></tr><?php endif; ?>
                <tr class="fw-semibold"><td colspan="3" class="text-end">Total</td><td class="text-end"><?= e(m($order['total_amount'])) ?></td></tr>
                <tr><td colspan="3" class="text-end">Paid (posted)</td><td class="text-end"><?= e(money($order['paid_cents'], $currency)) ?></td></tr>
                <tr class="fw-bold"><td colspan="3" class="text-end">Balance</td><td class="text-end"><?= e(money(max(0, $order['balance_cents']), $currency)) ?></td></tr>
            </tfoot>
        </table>
        <?php $posted = array_filter($order['payments'], static fn($p) => $p['status'] === 'posted'); if ($posted): ?>
            <p class="text-muted-sm mb-2">Receipts: <?= e(implode(', ', array_map(static fn($p) => $p['receipt_number'] . ' (' . m($p['amount']) . ')', $posted))) ?></p>
        <?php endif; ?>
        <p class="text-muted-sm mb-0">Printed <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(auth_user()['username']) ?>. Present this slip when claiming your order.</p>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
