<?php
/**
 * Consolidated production list for made-to-order items (college shirts): demand per
 * size by stage, quantities on order and received, and what still has to be ordered.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_institution_context();
require_permission(['orders.view', 'orders.manage', 'inventory.view']);

if (is_post()) {
    require_permission('orders.manage');
    handle_post(function () {
        $productId = post_int('product_id') ?? 0;
        $count = allocate_ready_orders($productId);
        flash($count ? 'success' : 'warning', $count ? $count . ' order(s) ready for release' : 'No orders could be completed', $count ? 'Received sizes were set aside, oldest orders first.' : 'Not enough received stock for the waiting orders yet.');
        return 'orders/production.php' . ($productId ? '?product=' . $productId : '');
    }, false);
}

$productId = get_int('product');
$rows = production_summary($productId);
$byProduct = [];
foreach ($rows as $row) {
    $byProduct[$row['product_id']]['name'] = $row['product_name'];
    $byProduct[$row['product_id']]['rows'][] = $row;
}
$products = options_from_rows(db_all("SELECT id, product_name FROM products WHERE institution_id = ? AND fulfillment = 'made_to_order' ORDER BY product_name", [current_institution_id()]), 'id', 'product_name');

if (get_str('export', 10) === 'csv') {
    audit_log('report.exported', 'report', null, current_institution_id(), (int) auth_user()['id'], ['report' => 'production_list', 'rows' => count($rows)]);
    send_csv('production_list_' . date('Ymd') . '.csv', ['Product', 'Size', 'Pending orders', 'Confirmed to fill', 'Set aside (ready)', 'Released', 'On hand', 'On order', 'Still to order'],
        array_map(static fn($r) => [$r['product_name'], $r['variant_name'], $r['pending_qty'], $r['to_fill'], $r['ready_qty'], $r['released_qty'], $r['on_hand'], $r['on_order'], $r['to_order']], $rows),
        [['Production list', auth_context()['label']], ['Generated', date('Y-m-d H:i') . ' by ' . auth_user()['username']]]);
}

$pageTitle = 'Shirt Production';
$activeNav = 'production';
$breadcrumb = [auth_context()['label'], 'Orders'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Production List</h2><p><?= e(auth_context()['label']) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions('Made-to-order items by size. Confirmed orders are what to produce; on-order quantities are not stock until received.',
    '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv'])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>
<div class="card panel mb-3">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="product">Product</label><select class="form-select" id="product" name="product" data-autosubmit><?= options_html($products, $productId, 'All made-to-order products') ?></select></div>
    </form>
</div>
<?php if (!$byProduct): ?>
    <div class="card panel"><div class="card-body"><?= empty_state('bi-truck', 'No made-to-order products', 'Create a product sold "made to order" (e.g. a college shirt) with its sizes.') ?></div></div>
<?php endif; ?>
<?php foreach ($byProduct as $id => $group): $sum = static fn(string $key) => array_sum(array_column($group['rows'], $key)); ?>
    <div class="card panel mb-3">
        <div class="card-header"><h3><?= e($group['name']) ?></h3>
            <div class="d-flex gap-2 d-print-none">
                <?php if (can('inventory.manage')): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('inventory/batch.php?product=' . $id)) ?>">Order from printer</a><?php endif; ?>
                <?php if (can('orders.manage')): ?><form method="post" data-confirm="Set aside received sizes for waiting orders?" data-confirm-text="Oldest confirmed orders are marked Ready for Release while stock lasts."><?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int) $id ?>"><button class="btn btn-primary btn-xs" type="submit">Mark orders ready</button></form><?php endif; ?>
            </div>
        </div>
        <div class="table-responsive"><table class="table table-clean align-middle mb-0">
            <thead><tr><th>Size</th><th class="text-end">Pending</th><th class="text-end">To produce</th><th class="text-end">On order</th><th class="text-end">On hand</th><th class="text-end">Set aside</th><th class="text-end">Released</th><th class="text-end">Still to order</th></tr></thead>
            <tbody><?php foreach ($group['rows'] as $row): ?>
                <tr><td class="fw-semibold"><?= e($row['variant_name']) ?></td>
                    <td class="text-end text-muted-sm"><?= e((string) $row['pending_qty']) ?></td>
                    <td class="text-end fw-semibold"><?= e((string) $row['to_fill']) ?></td>
                    <td class="text-end"><?= e((string) $row['on_order']) ?></td>
                    <td class="text-end"><?= e((string) $row['on_hand']) ?></td>
                    <td class="text-end"><?= e((string) $row['ready_qty']) ?></td>
                    <td class="text-end text-muted-sm"><?= e((string) $row['released_qty']) ?></td>
                    <td class="text-end fw-semibold<?= $row['to_order'] ? ' text-danger' : '' ?>"><?= e((string) $row['to_order']) ?></td></tr>
            <?php endforeach; ?></tbody>
            <tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end"><?= $sum('pending_qty') ?></td><td class="text-end"><?= $sum('to_fill') ?></td><td class="text-end"><?= $sum('on_order') ?></td><td class="text-end"><?= $sum('on_hand') ?></td><td class="text-end"><?= $sum('ready_qty') ?></td><td class="text-end"><?= $sum('released_qty') ?></td><td class="text-end"><?= $sum('to_order') ?></td></tr></tfoot>
        </table></div>
        <div class="card-footer-note">Pending = not yet confirmed (not counted). To produce = confirmed orders not yet set aside. Set aside = received and assigned to orders ready for release. Still to order = to produce − free stock − on order.</div>
    </div>
<?php endforeach; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
