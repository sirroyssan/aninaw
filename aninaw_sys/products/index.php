<?php
/**
 * Products and services catalogue: search, filter, stock at a glance.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/products.php';
require_institution_context();
require_permission(['products.view', 'products.manage']);

$institutionId = (int) current_institution_id();
$filters = [
    'q'        => get_str('q', 100),
    'category' => get_int('category'),
    'type'     => isset(PRODUCT_FULFILLMENT[get_str('type', 20)]) ? get_str('type', 20) : '',
    'status'   => in_array(get_str('status', 10), ['active', 'inactive', 'all'], true) ? get_str('status', 10) : 'active',
];
$where = ['p.institution_id = ?'];
$params = [$institutionId];
if ($filters['status'] !== 'all') {
    $where[] = 'p.status = ?';
    $params[] = $filters['status'];
}
if ($filters['category']) {
    $where[] = 'p.category_id = ?';
    $params[] = $filters['category'];
}
if ($filters['type']) {
    $where[] = 'p.fulfillment = ?';
    $params[] = $filters['type'];
}
if ($filters['q'] !== '') {
    $where[] = '(p.product_name LIKE ? OR p.sku LIKE ? OR p.description LIKE ?)';
    $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);
[$sortSql, $sortKey, $sortDir] = sort_clause(['name' => 'p.product_name', 'sku' => 'p.sku', 'price' => 'p.retail_price', 'updated' => 'p.updated_at'], 'name');
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM products p WHERE {$whereSql}", $params));
$rows = db_all(
    "SELECT p.*, c.category_name,
            (SELECT COUNT(*) FROM product_variants v WHERE v.institution_id = p.institution_id AND v.product_id = p.id AND v.status = 'active') AS variant_count,
            (SELECT COALESCE(SUM(m.quantity), 0) FROM inventory_movements m JOIN product_variants v ON v.id = m.variant_id AND v.institution_id = m.institution_id
              WHERE m.institution_id = p.institution_id AND v.product_id = p.id) AS on_hand
     FROM products p LEFT JOIN product_categories c ON c.id = p.category_id AND c.institution_id = p.institution_id
     WHERE {$whereSql} ORDER BY {$sortSql} LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$counts = db_one("SELECT SUM(status = 'active' AND product_type = 'physical') AS physical, SUM(status = 'active' AND product_type = 'service') AS services FROM products WHERE institution_id = ?", [$institutionId]);
$low = count(stock_levels(['low' => true]));

$pageTitle = 'Products & Services';
$activeNav = 'products';
$breadcrumb = [auth_context()['label'], 'Store'];
$pageScripts = array_merge($pageScripts ?? [], ['js/page_modal.js']);
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Everything the institution sells: merchandise from stock, made-to-order shirts and on-demand services such as printing.',
    (can('products.manage') ? button_link('products/categories.php', 'Categories', 'bi-tags', 'btn-outline-primary') : '')
    . (can('products.manage') ? button_link('products/form.php', 'New Product or Service', 'bi-plus-lg', 'btn-primary', 'xl') : '')) ?>
<div class="stat-grid">
    <?= stat_card('bi-box-seam', 'Physical products', number_format((int) $counts['physical']), 'Active') ?>
    <?= stat_card('bi-printer', 'Services', number_format((int) $counts['services']), 'Active', 'info') ?>
    <?= stat_card('bi-exclamation-triangle', 'Low stock items', number_format($low), 'At or below reorder level', $low ? 'warning' : 'success') ?>
</div>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name or SKU"></div>
        <div class="filter-field"><label for="category">Category</label><select class="form-select" id="category" name="category"><?= options_html(category_options(false), $filters['category'], 'All') ?></select></div>
        <div class="filter-field"><label for="type">Type</label><select class="form-select" id="type" name="type"><?= options_html(PRODUCT_FULFILLMENT, $filters['type'], 'All') ?></select></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'], $filters['status']) ?></select></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-box-seam', 'No products yet', can('products.manage') ? 'Add college shirts, merchandise or printing services.' : '') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th><?= sort_link('Product / service', 'name', $sortKey, $sortDir) ?></th><th><?= sort_link('SKU', 'sku', $sortKey, $sortDir) ?></th><th>Category · Type</th><th class="text-end">Unit cost</th><th class="text-end"><?= sort_link('Retail price', 'price', $sortKey, $sortDir) ?></th><th class="text-end">On hand</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($row['product_name']) ?></div><div class="text-muted-sm"><?= e((int) $row['variant_count'] > 1 ? $row['variant_count'] . ' variants' : 'per ' . $row['unit_of_measure']) ?></div></td>
                        <td class="text-muted-sm"><?= e($row['sku']) ?></td>
                        <td><?= e($row['category_name'] ?: '—') ?><div class="text-muted-sm"><?= e(PRODUCT_FULFILLMENT[$row['fulfillment']]) ?></div></td>
                        <td class="text-end"><?= e(m($row['unit_cost'])) ?></td>
                        <td class="text-end fw-semibold"><?= e(m($row['retail_price'])) ?></td>
                        <td class="text-end"><?= $row['fulfillment'] === 'service' ? '<span class="text-muted-sm">Service</span>' : e(number_format((int) $row['on_hand'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('products/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
