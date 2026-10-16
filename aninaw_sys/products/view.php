<?php
/**
 * Product or service: variants with prices and stock, supplier/production orders,
 * consumption rules (services), recent movements and change history.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/products.php';
require_institution_context();
require_permission(['products.view', 'products.manage']);

$productId = require_get_id();
$product = require_found(find_product($productId));

if (is_post()) {
    require_permission('products.manage');
    $action = post_str('action', 20);
    handle_post(function () use ($action, $productId) {
        switch ($action) {
            case 'variant':
                save_variant($productId, post_int('variant_id'), $_POST);
                flash('success', 'Variant saved');
                break;
            case 'activate':
            case 'deactivate':
                set_product_status($productId, $action === 'activate' ? 'active' : 'inactive');
                flash('success', $action === 'activate' ? 'Product activated' : 'Product deactivated', $action === 'deactivate' ? 'It can no longer be added to new orders; history is kept.' : '');
                break;
            case 'rule':
                save_consumption_rule(post_int('service_variant_id') ?? 0, $_POST);
                flash('success', 'Consumption rule saved');
                break;
            case 'remove_rule':
                remove_consumption_rule(post_int('rule_id') ?? 0);
                flash('success', 'Rule removed');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'products/view.php?id=' . $productId;
    }, false);
}

$institutionId = (int) current_institution_id();
$currency = current_currency();
$isService = $product['fulfillment'] === 'service';
$editVariant = get_int('variant') ? array_values(array_filter($product['variants'], static fn($v) => (int) $v['id'] === get_int('variant')))[0] ?? null : null;
$margin = to_cents($product['retail_price']) - to_cents($product['unit_cost']);
$movements = $isService ? [] : db_all(
    "SELECT m.*, v.variant_name, CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name FROM inventory_movements m
     JOIN product_variants v ON v.id = m.variant_id AND v.institution_id = m.institution_id JOIN users u ON u.id = m.recorded_by
     WHERE m.institution_id = ? AND v.product_id = ? ORDER BY m.movement_date DESC, m.id DESC LIMIT 10",
    [$institutionId, $productId]
);
$batches = $isService ? [] : db_all('SELECT * FROM stock_batches WHERE institution_id = ? AND product_id = ? ORDER BY id DESC LIMIT 5', [$institutionId, $productId]);
$rules = $isService ? consumption_rules($productId) : [];
$history = db_all(
    "SELECT a.action, a.reason, a.created_at, a.before_data, a.after_data, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE a.institution_id = ? AND a.entity_type = 'product' AND a.entity_id = ? AND a.action LIKE 'product.%' ORDER BY a.id DESC LIMIT 12",
    [$institutionId, $productId]
);
$totals = ['on_hand' => array_sum(array_column($product['variants'], 'on_hand')), 'reserved' => array_sum(array_column($product['variants'], 'reserved')), 'on_order' => array_sum(array_column($product['variants'], 'on_order'))];

$pageTitle = $product['product_name'];
$activeNav = 'products';
$breadcrumb = [auth_context()['label'], 'Products & Services'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($product['sku'] . ' · ' . ($product['category_name'] ?: 'No category') . ' · ' . PRODUCT_FULFILLMENT[$product['fulfillment']] . ' · sales to ' . $product['fund_name'],
    button_link('products/index.php', 'All Products', 'bi-arrow-left', 'btn-outline-secondary')
    . (can('products.manage') ? button_link('products/form.php?id=' . $productId, 'Edit', 'bi-pencil', 'btn-outline-primary') : '')
    . (!$isService && can('inventory.manage') ? button_link('inventory/batch.php?product=' . $productId, $product['fulfillment'] === 'made_to_order' ? 'New Production Order' : 'New Supplier Order', 'bi-truck', 'btn-outline-primary') : '')
    . ($product['status'] === 'active' && can('orders.manage') ? button_link('orders/create.php?product=' . $productId, 'New Order', 'bi-cart-plus') : '')) ?>

<?php if ($product['status'] !== 'active'): ?><div class="notice notice-warning"><i class="bi bi-pause-circle" aria-hidden="true"></i><div>This item is inactive and cannot be added to new orders.</div></div><?php endif; ?>
<div class="stat-grid">
    <?= stat_card('bi-tag', 'Retail price', m($product['retail_price']), 'per ' . $product['unit_of_measure']) ?>
    <?= stat_card('bi-cash', 'Unit cost', m($product['unit_cost']), 'Margin ' . money($margin, $currency) . (to_cents($product['retail_price']) ? ' (' . round($margin * 100 / to_cents($product['retail_price']), 1) . '%)' : ''), 'info') ?>
    <?php if (!$isService): ?>
        <?= stat_card('bi-box-seam', 'On hand', number_format($totals['on_hand']), number_format($totals['on_hand'] - $totals['reserved']) . ' available · ' . number_format($totals['reserved']) . ' reserved') ?>
        <?= stat_card('bi-truck', 'On order', number_format($totals['on_order']), 'Ordered from supplier/production, not yet stock', $totals['on_order'] ? 'warning' : 'success') ?>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3><?= $isService ? 'Service options and rates' : 'Variants and stock' ?></h3><?= status_badge($product['status']) ?></div>
            <div class="table-responsive">
                <table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Variant</th><th class="text-end">Price</th><th class="text-end">Cost</th><?php if (!$isService): ?><th class="text-end">On hand</th><th class="text-end">Reserved</th><th class="text-end">Available</th><th class="text-end">On order</th><?php endif; ?><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($product['variants'] as $variant): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($variant['variant_name']) ?><?= !$isService && $variant['low_stock'] && $variant['status'] === 'active' ? ' <span class="badge badge-soft-warning">Low</span>' : '' ?></td>
                            <td class="text-end"><?= e(m($variant['effective_price'])) ?><?= $variant['retail_price'] !== null ? '<div class="text-muted-sm">own price</div>' : '' ?></td>
                            <td class="text-end text-muted-sm"><?= e(m($variant['effective_cost'])) ?></td>
                            <?php if (!$isService): ?>
                                <td class="text-end"><?= e(number_format($variant['on_hand'])) ?></td>
                                <td class="text-end"><?= e(number_format($variant['reserved'])) ?></td>
                                <td class="text-end fw-semibold<?= $variant['available'] < 0 ? ' text-danger' : '' ?>"><?= e(number_format($variant['available'])) ?></td>
                                <td class="text-end text-muted-sm"><?= e(number_format($variant['on_order'])) ?></td>
                            <?php endif; ?>
                            <td><?= status_badge($variant['status']) ?></td>
                            <td class="actions">
                                <?php if (can('products.manage')): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('products/view.php?id=' . $productId . '&variant=' . $variant['id'] . '#variant-form')) ?>">Edit</a><?php endif; ?>
                                <?php if (!$isService && can_any(['inventory.manage', 'inventory.adjust'])): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('inventory/adjust.php?variant=' . $variant['id'])) ?>">Stock</a><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$isService): ?><div class="card-footer-note">Reserved = promised to confirmed orders, not yet released. Available = on hand − reserved. On order is not stock until received.</div><?php endif; ?>
        </div>

        <?php if ($isService): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Supplies used</h3></div>
                <div class="card-body">
                    <p class="text-muted-sm">When a job is completed, the supplies below are deducted from stock. Without a rule, the service never touches inventory.</p>
                    <?php if (!$rules): ?>
                        <p class="mb-0 text-muted-sm">No consumption rules.</p>
                    <?php else: ?>
                        <ul class="metric-list">
                            <?php foreach ($rules as $rule): ?>
                                <li><span><?= e($rule['service_variant']) ?> → <?= e(rtrim(rtrim($rule['quantity_per_unit'], '0'), '.') . ' ' . $rule['supply_unit'] . ' of ' . $rule['supply_product'] . ($rule['supply_variant'] !== 'Standard' ? ' · ' . $rule['supply_variant'] : '')) ?> per <?= e($product['unit_of_measure']) ?></span>
                                    <?php if (can('products.manage')): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove_rule"><input type="hidden" name="rule_id" value="<?= (int) $rule['id'] ?>"><button class="btn btn-outline-danger btn-xs" type="submit">Remove</button></form><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php $supplies = variant_options(['stock']); if (can('products.manage') && $supplies): ?>
                        <form method="post" class="row g-2 mt-2 align-items-end">
                            <?= csrf_field() ?><input type="hidden" name="action" value="rule">
                            <div class="col-md-4"><label class="form-label" for="service_variant_id">Option</label><select class="form-select" id="service_variant_id" name="service_variant_id"><?= options_html(options_from_rows($product['variants'], 'id', 'variant_name')) ?></select></div>
                            <div class="col-md-4"><label class="form-label" for="supply_variant_id">Uses supply</label><select class="form-select" id="supply_variant_id" name="supply_variant_id"><?= options_html($supplies, null, 'Select…') ?></select></div>
                            <div class="col-md-2"><label class="form-label" for="quantity_per_unit">Per unit</label><input class="form-control" id="quantity_per_unit" name="quantity_per_unit" value="1" inputmode="decimal"></div>
                            <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Add</button></div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Recent stock movements</h3><a class="text-muted-sm" href="<?= e(url('inventory/movements.php?product=' . $productId)) ?>">All movements</a></div>
                <?php if (!$movements): ?>
                    <div class="card-body"><?= empty_state('bi-arrow-down-up', 'No stock recorded yet', 'Record beginning inventory or receive a delivery.') ?></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Date</th><th>Variant</th><th>Movement</th><th class="text-end">Qty</th><th>By · Reason</th></tr></thead>
                        <tbody><?php foreach ($movements as $row): ?>
                            <tr><td><?= e(format_date($row['movement_date'])) ?></td><td><?= e($row['variant_name']) ?></td><td><?= e(MOVEMENT_TYPES[$row['movement_type']]) ?><?= $row['negative_override'] ? ' <span class="badge badge-soft-danger">Override</span>' : '' ?></td>
                                <td class="text-end fw-semibold <?= $row['quantity'] > 0 ? 'text-success' : 'text-danger' ?>"><?= e(($row['quantity'] > 0 ? '+' : '') . $row['quantity']) ?></td>
                                <td class="text-muted-sm"><?= e($row['recorded_by_name'] . ($row['reason'] ? ' · ' . $row['reason'] : '')) ?></td></tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </div>
            <?php if ($batches): ?>
                <div class="card panel mb-3">
                    <div class="card-header"><h3>Supplier and production orders</h3><a class="text-muted-sm" href="<?= e(url('inventory/batches.php?product=' . $productId)) ?>">All</a></div>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($batches as $batch): ?><li class="list-group-item d-flex justify-content-between"><a href="<?= e(url('inventory/batch.php?id=' . $batch['id'])) ?>"><?= e($batch['batch_number']) ?></a><span class="text-muted-sm"><?= e($batch['supplier_name'] ?: '—') ?></span><?= status_badge($batch['status']) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <?php if ($product['image_attachment_id']): ?>
            <div class="card panel mb-3"><div class="card-body text-center"><img src="<?= e(url('attachments/download.php?id=' . $product['image_attachment_id'])) ?>" alt="<?= e($product['product_name']) ?>" class="img-fluid rounded" style="max-height:240px"></div></div>
        <?php endif; ?>
        <div class="card panel mb-3">
            <div class="card-header"><h3>Details</h3></div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Unit</dt><dd><?= e($product['unit_of_measure']) ?></dd></div>
                    <div><dt>Reorder level</dt><dd><?= e($isService ? '—' : (string) $product['reorder_level']) ?></dd></div>
                    <div><dt>Partial payments</dt><dd><?= $product['allow_partial_payment'] ? 'Allowed' : 'Full payment only' ?></dd></div>
                    <div><dt>Created</dt><dd><?= e(format_datetime($product['created_at']) . ' · ' . $product['created_by_name']) ?></dd></div>
                    <div><dt>Last updated</dt><dd><?= e(format_datetime($product['updated_at'])) ?></dd></div>
                </dl>
                <?php if ($product['description']): ?><p class="mt-3 mb-0" style="white-space: pre-line"><?= e($product['description']) ?></p><?php endif; ?>
            </div>
        </div>
        <?php if (can('products.manage')): ?>
            <form method="post" class="card panel form-card mb-3" id="variant-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="variant"><?php if ($editVariant): ?><input type="hidden" name="variant_id" value="<?= (int) $editVariant['id'] ?>"><?php endif; ?>
                <div class="card-header"><h3><?= $editVariant ? 'Edit ' . e($editVariant['variant_name']) : ($isService ? 'Add an option' : 'Add a size or variant') ?></h3></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label required" for="variant_name">Name</label><input class="form-control" id="variant_name" name="variant_name" required maxlength="80" value="<?= e($editVariant['variant_name'] ?? '') ?>" placeholder="<?= $isService ? 'e.g. A4 colour' : 'e.g. XXXL' ?>"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label" for="v-price">Own price</label><input class="form-control text-amount" id="v-price" name="retail_price" inputmode="decimal" value="<?= e(isset($editVariant['retail_price']) ? cents_to_decimal(to_cents($editVariant['retail_price'])) : '') ?>" placeholder="<?= e(cents_to_decimal(to_cents($product['retail_price']))) ?>" <?= can('products.pricing') ? '' : 'readonly' ?>></div>
                        <div class="col-6"><label class="form-label" for="v-cost">Own cost</label><input class="form-control text-amount" id="v-cost" name="unit_cost" inputmode="decimal" value="<?= e(isset($editVariant['unit_cost']) ? cents_to_decimal(to_cents($editVariant['unit_cost'])) : '') ?>" placeholder="<?= e(cents_to_decimal(to_cents($product['unit_cost']))) ?>" <?= can('products.pricing') ? '' : 'readonly' ?>></div>
                    </div>
                    <div class="form-text mb-2">Leave empty to use the product's price and cost.</div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="form-label" for="sort_order">Order</label><input class="form-control" type="number" id="sort_order" name="sort_order" value="<?= e((string) ($editVariant['sort_order'] ?? (count($product['variants']) * 10))) ?>"></div>
                        <div class="col-6"><label class="form-label" for="v-status">Status</label><select class="form-select" id="v-status" name="status"><?= options_html(['active' => 'Active', 'inactive' => 'Inactive'], $editVariant['status'] ?? 'active') ?></select></div>
                    </div>
                    <button class="btn btn-primary w-100" type="submit">Save variant</button>
                </div>
            </form>
            <form method="post" class="card panel mb-3" data-confirm="<?= $product['status'] === 'active' ? 'Deactivate this item?' : 'Activate this item?' ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="<?= $product['status'] === 'active' ? 'deactivate' : 'activate' ?>">
                <div class="card-body"><button class="btn btn-outline-secondary w-100" type="submit"><?= $product['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></div>
            </form>
        <?php endif; ?>
        <div class="card panel">
            <div class="card-header"><h3>Change history</h3></div>
            <div class="card-body"><ul class="timeline">
                <?php foreach ($history as $row): $before = json_decode((string) $row['before_data'], true) ?: []; $after = json_decode((string) $row['after_data'], true) ?: []; ?>
                    <li><div class="fw-semibold"><?= e(humanize(str_replace('product.', '', $row['action']))) ?></div><div class="text-muted-sm"><?= e(($row['actor'] ?: 'System') . ' · ' . format_datetime($row['created_at'])) ?></div>
                        <?php if (isset($before['retail_price'], $after['retail_price']) && $before['retail_price'] !== $after['retail_price']): ?><div class="text-muted-sm">Price <?= e(m($before['retail_price'] ?? 0)) ?> → <?= e(m($after['retail_price'] ?? 0)) ?></div><?php endif; ?>
                        <?php if (isset($before['unit_cost'], $after['unit_cost']) && $before['unit_cost'] !== $after['unit_cost']): ?><div class="text-muted-sm">Cost <?= e(m($before['unit_cost'] ?? 0)) ?> → <?= e(m($after['unit_cost'] ?? 0)) ?></div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
