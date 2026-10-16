<?php
/**
 * Create or edit a product or service. Prices and costs need price authority.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/products.php';
require_institution_context();
require_permission('products.manage');

$productId = get_int('id');
$product = $productId ? require_found(find_product($productId)) : null;

if (is_post()) {
    handle_post(function () use ($productId) {
        $id = save_product($productId, $_POST);
        flash('success', $productId ? 'Product saved' : 'Product created', $productId ? '' : 'Add sizes or other variants and stock from its page.');
        return 'products/view.php?id=' . $id;
    });
}

$funds = options_from_rows(db_all("SELECT id, fund_code, fund_name FROM fund_accounts WHERE institution_id = ? AND status IN ('draft', 'pending', 'active') ORDER BY fund_name", [current_institution_id()]), 'id', static fn($r) => $r['fund_name'] . ' (' . $r['fund_code'] . ')');
$canPrice = can('products.pricing');
$value = static fn(string $key, mixed $default = '') => old($key, $product[$key] ?? $default);

$pageTitle = $product ? 'Edit ' . $product['product_name'] : 'New Product or Service';
$activeNav = 'products';
$breadcrumb = [auth_context()['label'], 'Products & Services'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" enctype="multipart/form-data" class="card panel form-card" style="max-width: 920px">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="form-section">
            <h3 class="form-section-title">Product or service</h3>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label required" for="sku">Product ID / SKU</label><input class="form-control" id="sku" name="sku" required maxlength="60" value="<?= e($value('sku')) ?>" placeholder="e.g. SHIRT-2026"></div>
                <div class="col-md-8"><label class="form-label required" for="product_name">Name</label><input class="form-control" id="product_name" name="product_name" required maxlength="180" value="<?= e($value('product_name')) ?>" placeholder="e.g. College Shirt 2026"></div>
                <div class="col-md-4"><label class="form-label" for="category_id">Category</label><select class="form-select" id="category_id" name="category_id"><?= options_html(category_options(), $value('category_id'), 'None') ?></select></div>
                <div class="col-md-4"><label class="form-label required" for="product_type">Type</label><select class="form-select" id="product_type" name="product_type"><?= options_html(PRODUCT_TYPES, $value('product_type', 'physical')) ?></select></div>
                <div class="col-md-4"><label class="form-label required" for="fulfillment">How it is sold</label><select class="form-select" id="fulfillment" name="fulfillment"><?= options_html(PRODUCT_FULFILLMENT, $value('fulfillment', 'stock')) ?></select><div class="form-text">Services are always "on demand".</div></div>
                <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control" id="description" name="description" rows="2" maxlength="3000"><?= e($value('description')) ?></textarea></div>
            </div>
        </div>
        <div class="form-section">
            <h3 class="form-section-title">Pricing and stock</h3>
            <?php if (!$canPrice): ?><div class="notice notice-info"><i class="bi bi-lock" aria-hidden="true"></i><div>Prices and costs can only be set by someone with price authority. Leave them unchanged.</div></div><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-3"><label class="form-label" for="unit_cost">Unit cost</label><input class="form-control text-amount" id="unit_cost" name="unit_cost" inputmode="decimal" value="<?= e($product ? cents_to_decimal(to_cents($product['unit_cost'])) : old('unit_cost')) ?>" <?= $canPrice ? '' : 'readonly' ?>><div class="form-text">What one unit costs the institution.</div></div>
                <div class="col-md-3"><label class="form-label" for="retail_price">Retail price</label><input class="form-control text-amount" id="retail_price" name="retail_price" inputmode="decimal" value="<?= e($product ? cents_to_decimal(to_cents($product['retail_price'])) : old('retail_price')) ?>" <?= $canPrice ? '' : 'readonly' ?>><div class="form-text">Selling price per unit.</div></div>
                <div class="col-md-3"><label class="form-label required" for="unit_of_measure">Unit</label><input class="form-control" id="unit_of_measure" name="unit_of_measure" list="units" required maxlength="30" value="<?= e($value('unit_of_measure', 'piece')) ?>"><datalist id="units"><?php foreach (PRODUCT_UNITS as $unit): ?><option value="<?= e($unit) ?>"><?php endforeach; ?></datalist></div>
                <div class="col-md-3"><label class="form-label" for="reorder_level">Reorder level</label><input class="form-control" type="number" min="0" id="reorder_level" name="reorder_level" value="<?= e((string) $value('reorder_level', 0)) ?>"><div class="form-text">Low-stock alert at or below.</div></div>
                <div class="col-md-6"><label class="form-label required" for="fund_id">Sales go to fund</label><select class="form-select" id="fund_id" name="fund_id" required><?= options_html($funds, $value('fund_id'), 'Select…') ?></select></div>
                <div class="col-md-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="allow_partial_payment" name="allow_partial_payment" value="1" <?= $value('allow_partial_payment') ? 'checked' : '' ?>><label class="form-check-label" for="allow_partial_payment">Allow partial (down) payments for this item</label></div></div>
                <?php if (!$product): ?>
                    <div class="col-12"><label class="form-label" for="variants">Sizes or variants</label><input class="form-control" id="variants" name="variants" maxlength="1000" value="<?= e(old('variants')) ?>" placeholder="e.g. XS, S, M, L, XL, XXL — or A4 B&W, A4 colour, Long B&W"><div class="form-text">Separate with commas. Leave empty for a single standard item. Stock is tracked per variant; variants can have their own price.</div></div>
                <?php endif; ?>
                <div class="col-md-6"><?= attachment_input('image', 'Product image (JPG, PNG or WEBP)') ?></div>
            </div>
        </div>
        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url($product ? 'products/view.php?id=' . $productId : 'products/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>Save</button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
