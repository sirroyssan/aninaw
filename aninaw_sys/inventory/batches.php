<?php
/**
 * Supplier purchases and production runs: ordered vs received quantities.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/products.php';
require_institution_context();
require_permission(['inventory.view', 'inventory.manage']);

$institutionId = (int) current_institution_id();
$filters = ['product' => get_int('product'), 'status' => isset(BATCH_STATUSES[get_str('status', 20)]) ? get_str('status', 20) : (isset($_GET['status']) ? '' : 'open')];
$where = ['b.institution_id = ?'];
$params = [$institutionId];
if ($filters['product']) {
    $where[] = 'b.product_id = ?';
    $params[] = $filters['product'];
}
if ($filters['status'] === 'open') {
    $where[] = "b.status IN ('draft', 'ordered', 'partially_received')";
} elseif ($filters['status']) {
    $where[] = 'b.status = ?';
    $params[] = $filters['status'];
}
$whereSql = implode(' AND ', $where);
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM stock_batches b WHERE {$whereSql}", $params), 25);
$rows = db_all(
    "SELECT b.*, p.product_name, p.fulfillment,
            (SELECT COALESCE(SUM(quantity_ordered), 0) FROM stock_batch_items i WHERE i.institution_id = b.institution_id AND i.batch_id = b.id) AS ordered,
            (SELECT COALESCE(SUM(quantity_received), 0) FROM stock_batch_items i WHERE i.institution_id = b.institution_id AND i.batch_id = b.id) AS received,
            (SELECT COALESCE(SUM(quantity_ordered * unit_cost), 0) FROM stock_batch_items i WHERE i.institution_id = b.institution_id AND i.batch_id = b.id) AS cost
     FROM stock_batches b JOIN products p ON p.id = b.product_id AND p.institution_id = b.institution_id
     WHERE {$whereSql} ORDER BY b.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$products = options_from_rows(db_all("SELECT id, product_name FROM products WHERE institution_id = ? AND fulfillment <> 'service' ORDER BY product_name", [$institutionId]), 'id', 'product_name');

$pageTitle = 'Supplier & Production Orders';
$activeNav = 'inventory';
$breadcrumb = [auth_context()['label'], 'Inventory'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Stock ordered from suppliers or sent to production. Only quantities received become stock.', button_link('inventory/index.php', 'Inventory', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="product">Product</label><select class="form-select" id="product" name="product"><?= options_html($products, $filters['product'], 'All') ?></select></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['open' => 'Open (not fully received)'] + BATCH_STATUSES, $filters['status'], 'All') ?></select></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (can('inventory.manage') && $products): ?>
        <form class="filter-bar border-top" method="get" action="<?= e(url('inventory/batch.php')) ?>">
            <div class="filter-field"><label for="new-product">New order for</label><select class="form-select" id="new-product" name="product" required><?= options_html($products, null, 'Select a product…') ?></select></div>
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Order</button>
        </form>
    <?php endif; ?>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-truck', 'No orders match', 'Create one from a product page or the selector above.') ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-clean align-middle mb-0">
            <thead><tr><th>Order</th><th>Product</th><th>Supplier</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Cost</th><th>Expected</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach ($rows as $row): ?>
                <tr>
                    <td class="fw-semibold"><?= e($row['batch_number']) ?></td>
                    <td><?= e($row['product_name']) ?><div class="text-muted-sm"><?= e(PRODUCT_FULFILLMENT[$row['fulfillment']]) ?></div></td>
                    <td><?= e($row['supplier_name'] ?: '—') ?></td>
                    <td class="text-end"><?= e(number_format((int) $row['ordered'])) ?></td>
                    <td class="text-end"><?= e(number_format((int) $row['received'])) ?></td>
                    <td class="text-end"><?= e(m($row['cost'])) ?></td>
                    <td class="text-muted-sm"><?= e(format_date($row['expected_date'])) ?></td>
                    <td><?= status_badge($row['status']) ?></td>
                    <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('inventory/batch.php?id=' . $row['id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
