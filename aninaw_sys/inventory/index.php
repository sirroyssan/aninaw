<?php
/**
 * Stock levels per item and variant: on hand, reserved, available, on order, low-stock flags.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/products.php';
require_institution_context();
require_permission(['inventory.view', 'inventory.manage']);

$filters = ['q' => get_str('q', 100), 'category' => get_int('category'), 'low' => get_str('low', 1) === '1', 'inactive' => get_str('inactive', 1) === '1'];
$rows = stock_levels($filters);
$currency = current_currency();
$value = array_sum(array_map(static fn($r) => max(0, (int) $r['on_hand']) * to_cents($r['effective_cost']), $rows));

if (get_str('export', 10) === 'csv') {
    audit_log('report.exported', 'report', null, current_institution_id(), (int) auth_user()['id'], ['report' => 'inventory', 'rows' => count($rows)]);
    send_csv('inventory_' . date('Ymd') . '.csv', ['SKU', 'Product', 'Variant', 'Category', 'Unit', 'On hand', 'Reserved', 'Available', 'On order', 'Reorder level', 'Low stock', 'Unit cost', 'Stock value at cost'],
        array_map(static fn($r) => [$r['sku'], $r['product_name'], $r['variant_name'], $r['category_name'], $r['unit_of_measure'], $r['on_hand'], $r['reserved'], $r['available'], $r['on_order'], $r['reorder_level'], $r['low_stock'] ? 'yes' : 'no',
            cents_to_decimal(to_cents($r['effective_cost'])), cents_to_decimal(max(0, (int) $r['on_hand']) * to_cents($r['effective_cost']))], $rows),
        [['Inventory', auth_context()['label']], ['Filter', $filters['low'] ? 'Low stock only' : 'All items'], ['Generated', date('Y-m-d H:i') . ' by ' . auth_user()['username']]]);
}
$pagination = paginate(count($rows), 40);
$pageRows = array_slice($rows, $pagination['offset'], $pagination['per_page']);

$pageTitle = 'Inventory';
$activeNav = 'inventory';
$breadcrumb = [auth_context()['label'], 'Store'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Inventory</h2><p><?= e(auth_context()['label']) ?> · <?= $filters['low'] ? 'Low stock only' : 'All items' ?> · As of <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions('Physical stock per item and size. Items on order from a supplier or in production are shown separately and are not counted as stock until received.',
    button_link('inventory/movements.php', 'Movement History', 'bi-arrow-down-up', 'btn-outline-primary')
    . button_link('inventory/batches.php', 'Supplier & Production Orders', 'bi-truck', 'btn-outline-primary')
    . '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv', 'page' => null])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>
<div class="stat-grid">
    <?= stat_card('bi-box-seam', 'Units on hand', number_format(array_sum(array_column($rows, 'on_hand'))), count($rows) . ' items listed') ?>
    <?= stat_card('bi-bookmark-check', 'Reserved', number_format(array_sum(array_column($rows, 'reserved'))), 'Promised to orders', 'info') ?>
    <?= stat_card('bi-truck', 'On order', number_format(array_sum(array_column($rows, 'on_order'))), 'Not yet received', 'warning') ?>
    <?= stat_card('bi-cash-stack', 'Stock value at cost', money($value, $currency), 'On hand × unit cost') ?>
</div>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, SKU or size"></div>
        <div class="filter-field"><label for="category">Category</label><select class="form-select" id="category" name="category"><?= options_html(category_options(false), $filters['category'], 'All') ?></select></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="low" name="low" value="1" <?= $filters['low'] ? 'checked' : '' ?>><label class="form-check-label" for="low">Low stock only</label></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="inactive" name="inactive" value="1" <?= $filters['inactive'] ? 'checked' : '' ?>><label class="form-check-label" for="inactive">Include inactive</label></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$pageRows): ?>
        <div class="card-body"><?= empty_state('bi-box-seam', 'No stocked items match') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Item</th><th>Category</th><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Available</th><th class="text-end">On order</th><th class="text-end">Reorder at</th><th class="d-print-none"></th></tr></thead>
                <tbody>
                <?php foreach ($pageRows as $row): ?>
                    <tr<?= $row['low_stock'] ? ' class="table-warning"' : '' ?>>
                        <td><a class="fw-semibold" href="<?= e(url('products/view.php?id=' . $row['product_id'])) ?>"><?= e($row['product_name']) ?></a><?= $row['variant_name'] !== 'Standard' ? ' · ' . e($row['variant_name']) : '' ?><div class="text-muted-sm"><?= e($row['sku'] . ' · ' . PRODUCT_FULFILLMENT[$row['fulfillment']]) ?></div></td>
                        <td class="text-muted-sm"><?= e($row['category_name'] ?: '—') ?></td>
                        <td class="text-end"><?= e(number_format((int) $row['on_hand'])) ?></td>
                        <td class="text-end"><?= e(number_format((int) $row['reserved'])) ?></td>
                        <td class="text-end fw-semibold<?= $row['available'] < 0 ? ' text-danger' : '' ?>"><?= e(number_format($row['available'])) ?><?= $row['low_stock'] ? '<div class="text-muted-sm">low</div>' : '' ?></td>
                        <td class="text-end text-muted-sm"><?= e(number_format((int) $row['on_order'])) ?></td>
                        <td class="text-end text-muted-sm"><?= e((string) $row['reorder_level']) ?></td>
                        <td class="actions d-print-none"><?php if (can_any(['inventory.manage', 'inventory.adjust'])): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('inventory/adjust.php?variant=' . $row['id'])) ?>">Record</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
