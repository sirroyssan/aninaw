<?php
/**
 * Store dashboard: catalogue and stock health, order pipeline, printing queue, and the
 * money side of the store (order value, posted collections, balances, revenue and
 * gross profit). Every figure is computed from recorded data for this institution.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/store_reports.php';
require_institution_context();
require_permission(['products.view', 'inventory.view', 'orders.view', 'orders.manage']);

$institutionId = (int) current_institution_id();
$currency = current_currency();
$canOrders = can_any(['orders.view', 'orders.manage']);
$canStock = can_any(['inventory.view', 'inventory.manage']);
$canProfit = can_any(['products.pricing', 'reports.view']);

$catalogue = db_one(
    "SELECT COUNT(*) AS total, SUM(fulfillment = 'service') AS services, SUM(fulfillment = 'made_to_order') AS made_to_order, SUM(fulfillment = 'stock') AS stock
     FROM products WHERE institution_id = ? AND status = 'active'",
    [$institutionId]
);
$lowStock = $canStock ? stock_levels(['low' => true]) : [];
$lowStock = array_values(array_filter($lowStock, static fn($r) => $r['fulfillment'] === 'stock'));

if ($canOrders) {
    $pipeline = db_query(
        "SELECT order_status, COUNT(*) FROM sales_orders WHERE institution_id = ? GROUP BY order_status",
        [$institutionId]
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $unclaimed = (int) db_value("SELECT COUNT(*) FROM sales_orders WHERE institution_id = ? AND order_status = 'ready_for_release' AND ready_at < (NOW() - INTERVAL 7 DAY)", [$institutionId]);
    $jobs = db_query(
        "SELECT i.service_status, COUNT(*) FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
         WHERE i.institution_id = ? AND i.fulfillment = 'service' AND o.order_status <> 'cancelled' GROUP BY i.service_status",
        [$institutionId]
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $money = db_one(
        'SELECT COALESCE(SUM(o.total_amount), 0) AS value, COALESCE(SUM(' . store_order_paid_sql() . "), 0) AS paid
         FROM sales_orders o WHERE o.institution_id = ? AND o.order_status <> 'cancelled'",
        [$institutionId]
    );
    $valueCents = to_cents($money['value']);
    $paidCents = to_cents($money['paid']);
    $salesRevenue = -ledger_key_balance($institutionId, 'sales');
    $serviceRevenue = -ledger_key_balance($institutionId, 'service_revenue');
    $recent = db_all(
        'SELECT o.id, o.order_number, o.order_date, o.total_amount, o.order_status, o.payment_status, ' . store_customer_sql() . ' AS customer
         FROM sales_orders o JOIN participants pt ON pt.id = o.customer_participant_id AND pt.institution_id = o.institution_id
         WHERE o.institution_id = ? ORDER BY o.id DESC LIMIT 8',
        [$institutionId]
    );
}
if ($canProfit) {
    $net = STORE_NET_LINE;
    $profit = db_one(
        "SELECT COALESCE(SUM({$net}), 0) AS sales, COALESCE(SUM(ROUND(i.unit_cost * i.quantity, 2)), 0) AS cost,
                COALESCE(SUM(ROUND({$net} * i.quantity_released / i.quantity, 2)), 0) AS released_sales, COALESCE(SUM(ROUND(i.unit_cost * i.quantity_released, 2)), 0) AS released_cost
         FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
         WHERE i.institution_id = ? AND o.order_status <> 'cancelled'",
        [$institutionId]
    );
}
$count = static fn(array $map, string ...$keys) => array_sum(array_map(static fn($k) => (int) ($map[$k] ?? 0), $keys));

$pageTitle = 'Store Dashboard';
$activeNav = 'store-dashboard';
$breadcrumb = [auth_context()['label'], 'Store'];
$pageScripts = array_merge($pageScripts ?? [], ['js/page_modal.js']);
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Products, stock, college shirt orders and printing jobs at a glance. Collections count posted payments only.',
    button_link('products/reports.php', 'Store Reports', 'bi-bar-chart', 'btn-outline-primary')
    . (can('orders.manage') ? button_link('orders/create.php', 'New Order', 'bi-cart-plus', 'btn-primary', 'xl') : '')) ?>

<div class="stat-grid">
    <?= stat_card('bi-box-seam', 'Products & services', number_format((int) $catalogue['total']), (int) $catalogue['stock'] . ' stock · ' . (int) $catalogue['made_to_order'] . ' made to order · ' . (int) $catalogue['services'] . ' services') ?>
    <?php if ($canStock): ?><?= stat_card('bi-exclamation-triangle', 'Low stock', number_format(count($lowStock)), 'At or below reorder level', count($lowStock) ? 'warning' : 'success') ?><?php endif; ?>
    <?php if ($canOrders): ?>
        <?= stat_card('bi-cart', 'Open orders', number_format($count($pipeline, ...ORDER_OPEN_STATUSES)), $count($pipeline, 'pending') . ' pending confirmation', 'info') ?>
        <?= stat_card('bi-printer', 'Printing jobs waiting', number_format($count($jobs, 'pending', 'in_progress')), $count($jobs, 'completed') . ' done, not yet claimed', 'info') ?>
    <?php endif; ?>
</div>

<?php if ($canOrders): ?>
    <div class="stat-grid">
        <?= stat_card('bi-receipt', 'Order value', money($valueCents, $currency), 'All orders not cancelled', 'info') ?>
        <?= stat_card('bi-cash-coin', 'Collected', money($paidCents, $currency), 'Posted payments on orders') ?>
        <?= stat_card('bi-hourglass-split', 'Outstanding', money(max(0, $valueCents - $paidCents), $currency), 'Balances still to collect', 'warning') ?>
        <?= stat_card('bi-graph-up', 'Store revenue (ledger)', money($salesRevenue + $serviceRevenue, $currency), 'Sales ' . money($salesRevenue, $currency) . ' · services ' . money($serviceRevenue, $currency)) ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <?php if ($canOrders): ?>
        <div class="col-xl-6">
            <div class="card panel h-100">
                <div class="card-header"><h3>Order pipeline</h3><a class="text-muted-sm" href="<?= e(url('orders/index.php?status=open')) ?>">Open orders</a></div>
                <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                    <tbody>
                    <?php foreach (['pending' => 'Awaiting confirmation', 'confirmed' => 'Confirmed', 'in_production' => 'In production (shirts ordered from printer)', 'ready_for_release' => 'Ready for release', 'partially_released' => 'Partially released', 'completed' => 'Completed'] as $status => $label): ?>
                        <tr><td><a href="<?= e(url('orders/index.php?status=' . $status)) ?>"><?= e($label) ?></a></td><td class="text-end fw-semibold"><?= number_format((int) ($pipeline[$status] ?? 0)) ?></td></tr>
                    <?php endforeach; ?>
                        <tr><td><a href="<?= e(url('orders/index.php?status=unclaimed')) ?>">Unclaimed (ready more than 7 days)</a></td><td class="text-end fw-semibold<?= $unclaimed ? ' text-danger' : '' ?>"><?= number_format($unclaimed) ?></td></tr>
                    </tbody>
                </table></div>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($canProfit): $expected = to_cents($profit['sales']) - to_cents($profit['cost']); $actual = to_cents($profit['released_sales']) - to_cents($profit['released_cost']); ?>
        <div class="col-xl-6">
            <div class="card panel h-100">
                <div class="card-header"><h3>Estimated gross profit</h3><a class="text-muted-sm" href="<?= e(url('reports/view.php?r=store_profit')) ?>">By product</a></div>
                <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                    <thead><tr><th></th><th class="text-end">Sales</th><th class="text-end">Cost</th><th class="text-end">Gross profit</th></tr></thead>
                    <tbody>
                        <tr><td>Expected (all active orders)</td><td class="text-end"><?= e(m($profit['sales'])) ?></td><td class="text-end"><?= e(m($profit['cost'])) ?></td><td class="text-end fw-semibold"><?= e(money($expected, $currency)) ?></td></tr>
                        <tr><td>Actual (released to customers)</td><td class="text-end"><?= e(m($profit['released_sales'])) ?></td><td class="text-end"><?= e(m($profit['released_cost'])) ?></td><td class="text-end fw-semibold"><?= e(money($actual, $currency)) ?></td></tr>
                    </tbody>
                </table></div>
                <div class="card-footer-note">Uses the prices and costs saved on each order line, net of discounts. Damaged or lost stock is not deducted.</div>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($canStock): ?>
        <div class="col-xl-6">
            <div class="card panel h-100">
                <div class="card-header"><h3>Low stock</h3><a class="text-muted-sm" href="<?= e(url('inventory/index.php?low=1')) ?>">Inventory</a></div>
                <?php if (!$lowStock): ?>
                    <div class="card-body"><?= empty_state('bi-check2-circle', 'No items below their reorder level') ?></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Item</th><th class="text-end">Available</th><th class="text-end">Reorder at</th><th class="text-end">On order</th></tr></thead>
                        <tbody><?php foreach (array_slice($lowStock, 0, 8) as $row): ?>
                            <tr><td><a href="<?= e(url('inventory/adjust.php?variant=' . $row['id'])) ?>"><?= e($row['product_name'] . ($row['variant_name'] !== 'Standard' ? ' · ' . $row['variant_name'] : '')) ?></a></td>
                                <td class="text-end fw-semibold<?= $row['available'] <= 0 ? ' text-danger' : '' ?>"><?= e((string) $row['available']) ?></td><td class="text-end"><?= e((string) $row['reorder_level']) ?></td><td class="text-end"><?= e((string) $row['on_order']) ?></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($canOrders): ?>
        <div class="col-xl-6">
            <div class="card panel h-100">
                <div class="card-header"><h3>Recent orders</h3><a class="text-muted-sm" href="<?= e(url('orders/index.php')) ?>">All orders</a></div>
                <?php if (!$recent): ?>
                    <div class="card-body"><?= empty_state('bi-cart', 'No orders yet') ?></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                        <tbody><?php foreach ($recent as $row): ?>
                            <tr><td><a class="fw-semibold" href="<?= e(url('orders/view.php?id=' . $row['id'])) ?>"><?= e($row['order_number']) ?></a><div class="text-muted-sm"><?= e($row['customer'] . ' · ' . format_date($row['order_date'])) ?></div></td>
                                <td class="text-end"><?= e(m($row['total_amount'])) ?></td><td><?= status_badge($row['order_status']) ?><div><?= status_badge($row['payment_status']) ?></div></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
