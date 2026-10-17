<?php
/**
 * Orders: search by order number, customer, student ID or product; filter by status,
 * payment status and date. Totals separate amounts collected from balances.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_institution_context();
require_permission(['orders.view', 'orders.manage', 'collections.record']);

$institutionId = (int) current_institution_id();
$filters = [
    'q'       => get_str('q', 100),
    'status'  => isset(ORDER_STATUSES[get_str('status', 30)]) || in_array(get_str('status', 30), ['open', 'unclaimed'], true) ? get_str('status', 30) : '',
    'payment' => isset(ORDER_PAYMENT_STATUSES[get_str('payment', 20)]) || get_str('payment', 20) === 'balance' ? get_str('payment', 20) : '',
    'product' => get_int('product'),
    'from'    => input_date($_GET, 'from'),
    'to'      => input_date($_GET, 'to'),
];
$where = ['o.institution_id = ?'];
$params = [$institutionId];
if ($filters['status'] === 'open') {
    $where[] = "o.order_status IN ('" . implode("','", ORDER_OPEN_STATUSES) . "')";
} elseif ($filters['status'] === 'unclaimed') {
    $where[] = "o.order_status = 'ready_for_release' AND o.ready_at < (NOW() - INTERVAL 7 DAY)";
} elseif ($filters['status']) {
    $where[] = 'o.order_status = ?';
    $params[] = $filters['status'];
}
if ($filters['payment'] === 'balance') {
    $where[] = "o.payment_status <> 'fully_paid' AND o.order_status <> 'cancelled'";
} elseif ($filters['payment']) {
    $where[] = 'o.payment_status = ?';
    $params[] = $filters['payment'];
}
if ($filters['product']) {
    $where[] = 'EXISTS (SELECT 1 FROM sales_order_items i WHERE i.institution_id = o.institution_id AND i.order_id = o.id AND i.product_id = ?)';
    $params[] = $filters['product'];
}
if ($filters['from']) {
    $where[] = 'o.order_date >= ?';
    $params[] = $filters['from'];
}
if ($filters['to']) {
    $where[] = 'o.order_date <= ?';
    $params[] = $filters['to'];
}
if ($filters['q'] !== '') {
    $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
    $where[] = '(o.order_number LIKE ? OR pt.participant_number LIKE ? OR pt.first_name LIKE ? OR pt.last_name LIKE ? OR CONCAT(pt.first_name, \' \', pt.last_name) LIKE ?
                 OR EXISTS (SELECT 1 FROM sales_order_items i WHERE i.institution_id = o.institution_id AND i.order_id = o.id AND i.description LIKE ?))';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);
$from = 'FROM sales_orders o JOIN participants pt ON pt.id = o.customer_participant_id AND pt.institution_id = o.institution_id';
$paidSql = "(SELECT COALESCE(SUM(sop.amount), 0) FROM sales_order_payments sop JOIN payments p ON p.id = sop.payment_id AND p.institution_id = sop.institution_id AND p.status = 'posted' WHERE sop.institution_id = o.institution_id AND sop.order_id = o.id)";
$totals = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN o.order_status <> 'cancelled' THEN o.total_amount END), 0) AS value, COALESCE(SUM({$paidSql}), 0) AS paid {$from} WHERE {$whereSql}", $params);
$pagination = paginate((int) $totals['n']);
$rows = db_all(
    "SELECT o.*, pt.participant_number, pt.first_name, pt.last_name, {$paidSql} AS paid,
            (SELECT GROUP_CONCAT(CONCAT(i.description, ' ×', i.quantity) ORDER BY i.id SEPARATOR ', ') FROM sales_order_items i WHERE i.institution_id = o.institution_id AND i.order_id = o.id) AS items
     {$from} WHERE {$whereSql} ORDER BY o.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$currency = current_currency();
$products = options_from_rows(db_all('SELECT id, product_name FROM products WHERE institution_id = ? ORDER BY product_name', [$institutionId]), 'id', 'product_name');

$pageTitle = 'Orders';
$activeNav = 'orders';
$breadcrumb = [auth_context()['label'], 'Store'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Product orders, college shirt advance orders and printing jobs. An order is paid only when a payment is recorded against it.',
    button_link('orders/production.php', 'Shirt Production', 'bi-truck', 'btn-outline-primary')
    . button_link('orders/printing.php', 'Printing Jobs', 'bi-printer', 'btn-outline-primary')
    . (can('orders.manage') ? button_link('orders/create.php', 'New Order', 'bi-cart-plus') : '')) ?>
<div class="stat-grid">
    <?= stat_card('bi-cart', 'Orders', number_format((int) $totals['n']), 'Matching the filters') ?>
    <?= stat_card('bi-receipt', 'Order value', m($totals['value']), 'Excluding cancelled', 'info') ?>
    <?= stat_card('bi-cash-coin', 'Collected', m($totals['paid']), 'Posted payments') ?>
    <?= stat_card('bi-hourglass', 'Balance', money(max(0, to_cents($totals['value']) - to_cents($totals['paid'])), $currency), 'Still to collect', 'warning') ?>
</div>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Order no., name, student ID, item"></div>
        <div class="filter-field"><label for="status">Order status</label><select class="form-select" id="status" name="status"><?= options_html(['open' => 'Open orders', 'unclaimed' => 'Unclaimed (ready > 7 days)'] + ORDER_STATUSES, $filters['status'], 'All') ?></select></div>
        <div class="filter-field"><label for="payment">Payment</label><select class="form-select" id="payment" name="payment"><?= options_html(['balance' => 'With balance'] + ORDER_PAYMENT_STATUSES, $filters['payment'], 'All') ?></select></div>
        <div class="filter-field"><label for="product">Product</label><select class="form-select" id="product" name="product" data-searchable><?= options_html($products, $filters['product'], 'All') ?></select></div>
        <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
        <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-cart', 'No orders match', can('orders.manage') ? 'Create an order for a shirt, merchandise or a printing job.' : '') ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-clean align-middle mb-0">
            <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th class="text-end">Total</th><th class="text-end">Balance</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach ($rows as $row): $balance = to_cents($row['total_amount']) - to_cents($row['paid']); ?>
                <tr>
                    <td><div class="fw-semibold"><?= e($row['order_number']) ?></div><div class="text-muted-sm"><?= e(format_date($row['order_date'])) ?></div></td>
                    <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?><div class="text-muted-sm"><?= e($row['participant_number']) ?></div></td>
                    <td class="text-muted-sm"><div class="text-truncate" style="max-width:260px" title="<?= e((string) $row['items']) ?>"><?= e((string) $row['items']) ?></div></td>
                    <td class="text-end"><?= e(m($row['total_amount'])) ?></td>
                    <td class="text-end fw-semibold<?= $balance > 0 && $row['order_status'] !== 'cancelled' ? ' text-danger' : '' ?>"><?= $row['order_status'] === 'cancelled' ? '—' : e(money(max(0, $balance), $currency)) ?></td>
                    <td><?= status_badge($row['order_status']) ?><div><?= status_badge($row['payment_status']) ?></div></td>
                    <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('orders/view.php?id=' . $row['id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
