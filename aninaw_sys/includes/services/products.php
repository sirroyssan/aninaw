<?php
/**
 * Products, services, variants and inventory.
 *
 * Catalogue: products (physical or service) with variants (sizes, paper sizes…).
 * Every product has at least one variant; stock, prices and order lines work per variant.
 *
 * Stock per variant:
 *   on hand   = SUM(inventory_movements.quantity)               (physical stock only)
 *   reserved  = unreleased quantities promised to orders        (see SQL_RESERVED)
 *   available = on hand − reserved                              (what can be sold now)
 *   on order  = ordered − received on open supplier/production batches (not stock)
 *
 * Stock never goes below zero unless a user holding inventory.override explicitly
 * allows it for that movement; the movement is flagged.
 */
declare(strict_types=1);

require_once __DIR__ . '/funds.php';

const PRODUCT_TYPES = ['physical' => 'Physical product', 'service' => 'Service'];
const PRODUCT_FULFILLMENT = [
    'stock'         => 'Sold from stock',
    'made_to_order' => 'Made to order (advance orders)',
    'service'       => 'Service (on demand)',
];
const PRODUCT_UNITS = ['piece', 'set', 'pair', 'pack', 'box', 'ream', 'roll', 'page', 'copy', 'sheet', 'job', 'sqm', 'hour'];
const MOVEMENT_TYPES = [
    'beginning'  => 'Beginning inventory',
    'received'   => 'Received (supplier / production)',
    'released'   => 'Released to customer',
    'consumed'   => 'Used by a service',
    'damaged'    => 'Damaged',
    'lost'       => 'Lost',
    'returned'   => 'Returned to stock',
    'adjustment' => 'Count adjustment',
];
/** Adjustments a custodian may record directly; the sign is fixed except for count adjustments. */
const ADJUSTMENT_TYPES = ['damaged' => -1, 'lost' => -1, 'returned' => 1, 'adjustment' => 0];
const BATCH_STATUSES = ['draft' => 'Draft', 'ordered' => 'Ordered', 'partially_received' => 'Partially received', 'received' => 'Received', 'cancelled' => 'Cancelled'];

/**
 * Quantity reserved per variant: stock lines of confirmed (not yet released) orders,
 * and made-to-order lines once their order is ready for release.
 */
const SQL_RESERVED = "(SELECT COALESCE(SUM(i.quantity - i.quantity_released), 0) FROM sales_order_items i
    JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
    WHERE i.institution_id = v.institution_id AND i.variant_id = v.id
      AND ((i.fulfillment = 'stock' AND o.order_status IN ('confirmed', 'in_production', 'ready_for_release', 'partially_released'))
        OR (i.fulfillment = 'made_to_order' AND o.order_status IN ('ready_for_release', 'partially_released'))))";
const SQL_ON_HAND = '(SELECT COALESCE(SUM(m.quantity), 0) FROM inventory_movements m WHERE m.institution_id = v.institution_id AND m.variant_id = v.id)';
const SQL_ON_ORDER = "(SELECT COALESCE(SUM(bi.quantity_ordered - bi.quantity_received), 0) FROM stock_batch_items bi
    JOIN stock_batches b ON b.id = bi.batch_id AND b.institution_id = bi.institution_id
    WHERE bi.institution_id = v.institution_id AND bi.variant_id = v.id AND b.status IN ('ordered', 'partially_received'))";

// ---------------------------------------------------------------------------
// Categories
// ---------------------------------------------------------------------------

function category_options(bool $activeOnly = true): array
{
    return options_from_rows(db_all(
        'SELECT id, category_name FROM product_categories WHERE institution_id = ?' . ($activeOnly ? " AND status = 'active'" : '') . ' ORDER BY category_name',
        [current_institution_id()]
    ), 'id', 'category_name');
}

function save_category(?int $categoryId, array $input): int
{
    if (!can('products.manage')) {
        fail_user('You do not have permission to manage product categories.');
    }
    $institutionId = (int) current_institution_id();
    $name = input_str($input, 'category_name', 120);
    if (mb_strlen($name) < 2) {
        fail_user('Enter a category name.');
    }
    $duplicate = db_value('SELECT id FROM product_categories WHERE institution_id = ? AND category_name = ? AND id <> ?', [$institutionId, $name, $categoryId ?? 0]);
    if ($duplicate) {
        fail_user('A category with that name already exists.');
    }
    if ($categoryId) {
        $before = db_one('SELECT * FROM product_categories WHERE institution_id = ? AND id = ?', [$institutionId, $categoryId]) ?? fail_user('Category not found.');
        db_query('UPDATE product_categories SET category_name = ? WHERE id = ?', [$name, $categoryId]);
        audit_log('product_category.updated', 'product_category', $categoryId, $institutionId, (int) auth_user()['id'], ['category_name' => $name], ['category_name' => $before['category_name']]);
        return $categoryId;
    }
    db_query('INSERT INTO product_categories (institution_id, category_name) VALUES (?, ?)', [$institutionId, $name]);
    $categoryId = (int) db()->lastInsertId();
    audit_log('product_category.created', 'product_category', $categoryId, $institutionId, (int) auth_user()['id'], ['category_name' => $name]);
    return $categoryId;
}

function set_category_status(int $categoryId, string $status): void
{
    if (!can('products.manage')) {
        fail_user('You do not have permission to manage product categories.');
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        fail_user('Unknown status.');
    }
    $institutionId = (int) current_institution_id();
    $before = db_one('SELECT * FROM product_categories WHERE institution_id = ? AND id = ?', [$institutionId, $categoryId]) ?? fail_user('Category not found.');
    db_query('UPDATE product_categories SET status = ? WHERE id = ?', [$status, $categoryId]);
    audit_log('product_category.' . ($status === 'active' ? 'activated' : 'deactivated'), 'product_category', $categoryId, $institutionId, (int) auth_user()['id'], ['status' => $status], ['status' => $before['status']]);
}

// ---------------------------------------------------------------------------
// Products and variants
// ---------------------------------------------------------------------------

function find_product(int $productId): ?array
{
    if (!can_any(['products.view', 'products.manage', 'orders.manage', 'inventory.view'])) {
        return null;
    }
    $product = db_one(
        "SELECT p.*, c.category_name, f.fund_name, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
         FROM products p
         LEFT JOIN product_categories c ON c.id = p.category_id AND c.institution_id = p.institution_id
         JOIN fund_accounts f ON f.id = p.fund_id AND f.institution_id = p.institution_id
         JOIN users u ON u.id = p.created_by
         WHERE p.institution_id = ? AND p.id = ?",
        [current_institution_id(), $productId]
    );
    if ($product) {
        $product['variants'] = product_variants($productId);
    }
    return $product;
}

/** Variants with effective price/cost and stock figures. */
function product_variants(int $productId, bool $activeOnly = false): array
{
    $rows = db_all(
        'SELECT v.*, COALESCE(v.retail_price, p.retail_price) AS effective_price, COALESCE(v.unit_cost, p.unit_cost) AS effective_cost,
                p.fulfillment, p.reorder_level, ' . SQL_ON_HAND . ' AS on_hand, ' . SQL_RESERVED . ' AS reserved, ' . SQL_ON_ORDER . " AS on_order
         FROM product_variants v JOIN products p ON p.id = v.product_id AND p.institution_id = v.institution_id
         WHERE v.institution_id = ? AND v.product_id = ?" . ($activeOnly ? " AND v.status = 'active'" : '') . '
         ORDER BY v.sort_order, v.id',
        [current_institution_id(), $productId]
    );
    foreach ($rows as &$row) {
        $row['on_hand'] = (int) $row['on_hand'];
        $row['reserved'] = (int) $row['reserved'];
        $row['on_order'] = (int) $row['on_order'];
        $row['available'] = $row['on_hand'] - $row['reserved'];
        $row['low_stock'] = $row['fulfillment'] !== 'service' && $row['available'] <= (int) $row['reorder_level'];
    }
    unset($row);
    return $rows;
}

function find_variant(int $variantId): ?array
{
    $row = db_one(
        'SELECT v.*, p.product_name, p.product_type, p.fulfillment, p.unit_of_measure, p.fund_id, p.status AS product_status, p.reorder_level, p.allow_partial_payment,
                COALESCE(v.retail_price, p.retail_price) AS effective_price, COALESCE(v.unit_cost, p.unit_cost) AS effective_cost,
                ' . SQL_ON_HAND . ' AS on_hand, ' . SQL_RESERVED . ' AS reserved
         FROM product_variants v JOIN products p ON p.id = v.product_id AND p.institution_id = v.institution_id
         WHERE v.institution_id = ? AND v.id = ?',
        [current_institution_id(), $variantId]
    );
    if ($row) {
        $row['on_hand'] = (int) $row['on_hand'];
        $row['reserved'] = (int) $row['reserved'];
        $row['available'] = $row['on_hand'] - $row['reserved'];
        $row['label'] = $row['product_name'] . ($row['variant_name'] !== 'Standard' ? ' · ' . $row['variant_name'] : '');
    }
    return $row;
}

/** Validated product fields. Prices are cents or null when absent. */
function product_input(array $input, ?array $existing): array
{
    $institutionId = (int) current_institution_id();
    $data = [
        'sku'                   => strtoupper(input_str($input, 'sku', 60)),
        'product_name'          => input_str($input, 'product_name', 180),
        'category_id'           => input_int($input, 'category_id'),
        'description'           => input_str($input, 'description', 3000) ?: null,
        'product_type'          => input_enum($input, 'product_type', array_keys(PRODUCT_TYPES), 'physical'),
        'fulfillment'           => input_enum($input, 'fulfillment', array_keys(PRODUCT_FULFILLMENT), 'stock'),
        'fund_id'               => input_int($input, 'fund_id'),
        'unit_of_measure'       => strtolower(input_str($input, 'unit_of_measure', 30)) ?: 'piece',
        'reorder_level'         => max(0, (int) (input_int($input, 'reorder_level') ?? 0)),
        'allow_partial_payment' => input_str($input, 'allow_partial_payment', 1) === '1' ? 1 : 0,
    ];
    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,59}$/', $data['sku'])) {
        fail_user('Enter a product ID / SKU of 2–60 letters, digits, dots, dashes or underscores (e.g. SHIRT-2026).');
    }
    if (mb_strlen($data['product_name']) < 2) {
        fail_user('Enter the product or service name.');
    }
    if (!preg_match('/^[a-z][a-z .]{0,29}$/', $data['unit_of_measure'])) {
        fail_user('Enter the unit of measurement in words (e.g. piece, page, ream).');
    }
    if ($data['product_type'] === 'service') {
        $data['fulfillment'] = 'service';
    } elseif ($data['fulfillment'] === 'service') {
        fail_user('A physical product is sold from stock or made to order.');
    }
    if ($data['category_id'] && !db_value("SELECT 1 FROM product_categories WHERE institution_id = ? AND id = ?", [$institutionId, $data['category_id']])) {
        fail_user('Choose a valid category.');
    }
    find_fund($data['fund_id'] ?? 0, ['draft', 'pending', 'active']);
    if (db_value('SELECT id FROM products WHERE institution_id = ? AND sku = ? AND id <> ?', [$institutionId, $data['sku'], $existing['id'] ?? 0])) {
        fail_user('Another product already uses the ID / SKU ' . $data['sku'] . '.');
    }
    $cost = input_str($input, 'unit_cost', 20);
    $price = input_str($input, 'retail_price', 20);
    $data['unit_cost'] = $cost === '' ? 0 : (parse_money($cost, true) ?? fail_user('Enter a valid unit cost.'));
    $data['retail_price'] = $price === '' ? 0 : (parse_money($price, true) ?? fail_user('Enter a valid retail price.'));
    return $data;
}

/** Creates or edits a product. New products get the variants listed (or one "Standard"). */
function save_product(?int $productId, array $input): int
{
    if (!can('products.manage')) {
        fail_user('You do not have permission to manage products.');
    }
    $institutionId = (int) current_institution_id();
    $userId = (int) auth_user()['id'];
    $existing = $productId ? (db_one('SELECT * FROM products WHERE institution_id = ? AND id = ?', [$institutionId, $productId]) ?? fail_user('Product not found.')) : null;
    $data = product_input($input, $existing);

    $priceChanged = $existing
        ? ($data['unit_cost'] !== to_cents($existing['unit_cost']) || $data['retail_price'] !== to_cents($existing['retail_price']))
        : ($data['unit_cost'] > 0 || $data['retail_price'] > 0);
    if ($priceChanged && !can('products.pricing')) {
        fail_user('Setting or changing unit costs and retail prices needs price authority (products.pricing).');
    }
    if ($existing && $existing['fulfillment'] !== $data['fulfillment']
        && db_value("SELECT 1 FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id WHERE i.institution_id = ? AND i.product_id = ? AND o.order_status NOT IN ('completed', 'cancelled') LIMIT 1", [$institutionId, $productId])) {
        fail_user('The product type or fulfilment cannot change while it has open orders.');
    }
    if ($existing && $existing['fulfillment'] !== 'service' && $data['fulfillment'] === 'service'
        && db_value('SELECT 1 FROM inventory_movements m JOIN product_variants v ON v.id = m.variant_id WHERE m.institution_id = ? AND v.product_id = ? LIMIT 1', [$institutionId, $productId])) {
        fail_user('A product with stock history cannot become a service.');
    }
    $variantNames = [];
    if (!$existing) {
        $variantNames = array_values(array_unique(array_filter(array_map(static fn($v) => mb_substr(trim($v), 0, 80), preg_split('/[,\n]/', input_str($input, 'variants', 1000)) ?: []))));
        $variantNames = $variantNames ?: ['Standard'];
    }

    return db_transaction(function () use ($institutionId, $userId, $productId, $existing, $data, $variantNames, $priceChanged) {
        $columns = ['sku', 'product_name', 'category_id', 'description', 'product_type', 'fulfillment', 'fund_id', 'unit_of_measure', 'reorder_level', 'allow_partial_payment'];
        $values = array_map(static fn($c) => $data[$c], $columns);
        $values[] = cents_to_decimal($data['unit_cost']);
        $values[] = cents_to_decimal($data['retail_price']);
        if ($existing) {
            db_query('UPDATE products SET ' . implode(' = ?, ', $columns) . ' = ?, unit_cost = ?, retail_price = ? WHERE id = ?', array_merge($values, [$productId]));
            $before = array_intersect_key($existing, array_flip(array_merge($columns, ['unit_cost', 'retail_price'])));
            $after = array_combine(array_merge($columns, ['unit_cost', 'retail_price']), $values);
            audit_log($priceChanged ? 'product.price_changed' : 'product.updated', 'product', $productId, $institutionId, $userId, $after, $before);
        } else {
            db_query('INSERT INTO products (institution_id, ' . implode(', ', $columns) . ', unit_cost, retail_price, created_by) VALUES (?, ' . implode(', ', array_fill(0, count($columns) + 2, '?')) . ', ?)',
                array_merge([$institutionId], $values, [$userId]));
            $productId = (int) db()->lastInsertId();
            foreach ($variantNames as $order => $name) {
                db_query('INSERT INTO product_variants (institution_id, product_id, variant_name, sort_order) VALUES (?, ?, ?, ?)', [$institutionId, $productId, $name, $order * 10]);
            }
            audit_log('product.created', 'product', $productId, $institutionId, $userId, array_combine(array_merge($columns, ['unit_cost', 'retail_price']), $values) + ['variants' => $variantNames]);
        }
        if (has_upload('image')) {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $_FILES['image']['tmp_name']);
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                fail_user('The product image must be a JPG, PNG or WEBP picture.');
            }
            db_query('UPDATE products SET image_attachment_id = ? WHERE id = ?', [store_attachment('image', 'product', $productId), $productId]);
        }
        return $productId;
    });
}

function set_product_status(int $productId, string $status): void
{
    if (!can('products.manage')) {
        fail_user('You do not have permission to manage products.');
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        fail_user('Unknown status.');
    }
    $institutionId = (int) current_institution_id();
    $product = db_one('SELECT * FROM products WHERE institution_id = ? AND id = ?', [$institutionId, $productId]) ?? fail_user('Product not found.');
    db_query('UPDATE products SET status = ? WHERE id = ?', [$status, $productId]);
    audit_log('product.' . ($status === 'active' ? 'activated' : 'deactivated'), 'product', $productId, $institutionId, (int) auth_user()['id'], ['status' => $status], ['status' => $product['status']]);
}

/** Adds or edits a variant (size, paper size…). Price/cost overrides need price authority. */
function save_variant(int $productId, ?int $variantId, array $input): int
{
    if (!can('products.manage')) {
        fail_user('You do not have permission to manage products.');
    }
    $institutionId = (int) current_institution_id();
    db_one('SELECT id FROM products WHERE institution_id = ? AND id = ?', [$institutionId, $productId]) ?? fail_user('Product not found.');
    $name = input_str($input, 'variant_name', 80);
    if ($name === '') {
        fail_user('Enter the variant name (e.g. M, XL, A4 colour).');
    }
    $cost = input_str($input, 'unit_cost', 20);
    $price = input_str($input, 'retail_price', 20);
    $costCents = $cost === '' ? null : (parse_money($cost, true) ?? fail_user('Enter a valid unit cost.'));
    $priceCents = $price === '' ? null : (parse_money($price, true) ?? fail_user('Enter a valid price.'));
    $sort = (int) (input_int($input, 'sort_order') ?? 0);
    $status = input_enum($input, 'status', ['active', 'inactive'], 'active');
    if (db_value('SELECT id FROM product_variants WHERE product_id = ? AND variant_name = ? AND id <> ?', [$productId, $name, $variantId ?? 0])) {
        fail_user('This product already has a variant named ' . $name . '.');
    }
    $before = $variantId ? (db_one('SELECT * FROM product_variants WHERE institution_id = ? AND id = ? AND product_id = ?', [$institutionId, $variantId, $productId]) ?? fail_user('Variant not found.')) : null;
    $oldCost = $before && $before['unit_cost'] !== null ? to_cents($before['unit_cost']) : null;
    $oldPrice = $before && $before['retail_price'] !== null ? to_cents($before['retail_price']) : null;
    if (($costCents !== $oldCost || $priceCents !== $oldPrice) && !can('products.pricing')) {
        fail_user('Setting or changing variant prices needs price authority (products.pricing).');
    }
    $values = [$name, $costCents === null ? null : cents_to_decimal($costCents), $priceCents === null ? null : cents_to_decimal($priceCents), $sort, $status];
    if ($before) {
        db_query('UPDATE product_variants SET variant_name = ?, unit_cost = ?, retail_price = ?, sort_order = ?, status = ? WHERE id = ?', array_merge($values, [$variantId]));
        audit_log('product.variant_updated', 'product', $productId, $institutionId, (int) auth_user()['id'],
            ['variant_id' => $variantId, 'variant_name' => $name, 'unit_cost' => $values[1], 'retail_price' => $values[2], 'status' => $status],
            ['variant_name' => $before['variant_name'], 'unit_cost' => $before['unit_cost'], 'retail_price' => $before['retail_price'], 'status' => $before['status']]);
        return $variantId;
    }
    db_query('INSERT INTO product_variants (institution_id, product_id, variant_name, unit_cost, retail_price, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, ?)', array_merge([$institutionId, $productId], $values));
    $variantId = (int) db()->lastInsertId();
    audit_log('product.variant_added', 'product', $productId, $institutionId, (int) auth_user()['id'], ['variant_id' => $variantId, 'variant_name' => $name, 'unit_cost' => $values[1], 'retail_price' => $values[2]]);
    return $variantId;
}

/** Variant options for selects: [variant id => "Product · Variant"]. */
function variant_options(array $fulfillments = ['stock', 'made_to_order'], bool $activeOnly = true): array
{
    $placeholders = implode(',', array_fill(0, count($fulfillments), '?'));
    return options_from_rows(db_all(
        "SELECT v.id, p.product_name, v.variant_name FROM product_variants v JOIN products p ON p.id = v.product_id AND p.institution_id = v.institution_id
         WHERE v.institution_id = ? AND p.fulfillment IN ({$placeholders})" . ($activeOnly ? " AND v.status = 'active' AND p.status = 'active'" : '') . '
         ORDER BY p.product_name, v.sort_order, v.id',
        array_merge([current_institution_id()], $fulfillments)
    ), 'id', static fn($r) => $r['product_name'] . ($r['variant_name'] !== 'Standard' ? ' · ' . $r['variant_name'] : ''));
}

// ---------------------------------------------------------------------------
// Service consumption rules
// ---------------------------------------------------------------------------

function save_consumption_rule(int $serviceVariantId, array $input): void
{
    if (!can('products.manage')) {
        fail_user('You do not have permission to manage products.');
    }
    $institutionId = (int) current_institution_id();
    $service = find_variant($serviceVariantId) ?? fail_user('Service not found.');
    if ($service['fulfillment'] !== 'service') {
        fail_user('Consumption rules apply to services only.');
    }
    $supply = find_variant(input_int($input, 'supply_variant_id') ?? 0) ?? fail_user('Choose the supply item.');
    if ($supply['fulfillment'] === 'service') {
        fail_user('The supply must be a stocked physical item (e.g. A4 bond paper).');
    }
    $per = input_str($input, 'quantity_per_unit', 12);
    if (!preg_match('/^\d{1,6}(\.\d{1,3})?$/', $per) || (float) $per <= 0) {
        fail_user('Enter how much of the supply one unit of the service uses (e.g. 1 or 0.5).');
    }
    db_query(
        "INSERT INTO service_consumption_rules (institution_id, service_variant_id, supply_variant_id, quantity_per_unit, status) VALUES (?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE quantity_per_unit = VALUES(quantity_per_unit), status = 'active'",
        [$institutionId, $serviceVariantId, $supply['id'], $per]
    );
    audit_log('product.consumption_rule_saved', 'product', (int) $service['product_id'], $institutionId, (int) auth_user()['id'],
        ['service_variant_id' => $serviceVariantId, 'supply_variant_id' => (int) $supply['id'], 'quantity_per_unit' => $per]);
}

function remove_consumption_rule(int $ruleId): void
{
    if (!can('products.manage')) {
        fail_user('You do not have permission to manage products.');
    }
    $institutionId = (int) current_institution_id();
    $rule = db_one('SELECT r.*, v.product_id FROM service_consumption_rules r JOIN product_variants v ON v.id = r.service_variant_id WHERE r.institution_id = ? AND r.id = ?', [$institutionId, $ruleId]) ?? fail_user('Rule not found.');
    db_query("UPDATE service_consumption_rules SET status = 'inactive' WHERE id = ?", [$ruleId]);
    audit_log('product.consumption_rule_removed', 'product', (int) $rule['product_id'], $institutionId, (int) auth_user()['id'], ['rule_id' => $ruleId], ['quantity_per_unit' => $rule['quantity_per_unit']]);
}

function consumption_rules(int $productId): array
{
    return db_all(
        "SELECT r.*, sv.variant_name AS service_variant, sp.product_name AS supply_product, su.variant_name AS supply_variant, sp.unit_of_measure AS supply_unit
         FROM service_consumption_rules r
         JOIN product_variants sv ON sv.id = r.service_variant_id AND sv.institution_id = r.institution_id
         JOIN product_variants su ON su.id = r.supply_variant_id AND su.institution_id = r.institution_id
         JOIN products sp ON sp.id = su.product_id AND sp.institution_id = su.institution_id
         WHERE r.institution_id = ? AND sv.product_id = ? AND r.status = 'active' ORDER BY sv.sort_order, sp.product_name",
        [current_institution_id(), $productId]
    );
}

// ---------------------------------------------------------------------------
// Inventory movements
// ---------------------------------------------------------------------------

/** Locks the variant row and returns its current on-hand quantity. */
function lock_variant_stock(int $variantId): int
{
    db_query('SELECT id FROM product_variants WHERE institution_id = ? AND id = ? FOR UPDATE', [current_institution_id(), $variantId]);
    return (int) db_value('SELECT COALESCE(SUM(quantity), 0) FROM inventory_movements WHERE institution_id = ? AND variant_id = ?', [current_institution_id(), $variantId]);
}

/**
 * Records one stock movement inside the caller's transaction. Outflows that would take
 * stock below zero are refused unless $allowNegative and the user holds inventory.override.
 */
function record_movement(int $variantId, string $type, int $quantity, string $date, ?string $reason, array $links = [], bool $allowNegative = false): int
{
    if (!db_in_transaction()) {
        throw new LogicException('record_movement() must run inside a transaction.');
    }
    if ($quantity === 0 || !isset(MOVEMENT_TYPES[$type])) {
        throw new LogicException('Invalid movement.');
    }
    $institutionId = (int) current_institution_id();
    $variant = find_variant($variantId) ?? fail_user('Item not found.');
    if ($variant['fulfillment'] === 'service') {
        fail_user($variant['label'] . ' is a service and has no stock.');
    }
    $onHand = lock_variant_stock($variantId);
    $after = $onHand + $quantity;
    $override = false;
    if ($quantity < 0 && $after < 0) {
        if (!$allowNegative) {
            fail_user('Not enough stock of ' . $variant['label'] . ': ' . $onHand . ' on hand, ' . (-$quantity) . ' needed.');
        }
        if (!can('inventory.override')) {
            fail_user('Only an administrator with inventory override authority can take stock below zero.');
        }
        $override = true;
    }
    db_query(
        'INSERT INTO inventory_movements (institution_id, variant_id, movement_type, quantity, unit_cost, movement_date, reason, batch_id, order_item_id, negative_override, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$institutionId, $variantId, $type, $quantity, isset($links['unit_cost']) ? cents_to_decimal($links['unit_cost']) : null, $date, $reason,
         $links['batch_id'] ?? null, $links['order_item_id'] ?? null, $override ? 1 : 0, (int) auth_user()['id']]
    );
    $movementId = (int) db()->lastInsertId();
    audit_log('inventory.' . $type, 'product', (int) $variant['product_id'], $institutionId, (int) auth_user()['id'],
        ['movement_id' => $movementId, 'variant_id' => $variantId, 'quantity' => $quantity, 'on_hand_after' => $after, 'negative_override' => $override] + array_filter($links, static fn($v) => $v !== null),
        ['on_hand' => $onHand], $reason);
    if ($quantity < 0 && $onHand > (int) $variant['reorder_level'] && $after <= (int) $variant['reorder_level']) {
        notify_permission($institutionId, 'inventory.manage', 'inventory', 'Low stock', $variant['label'] . ' is down to ' . $after . ' (reorder level ' . $variant['reorder_level'] . ').');
    }
    return $movementId;
}

/** Beginning inventory: once per variant, with its unit cost. */
function record_beginning_stock(int $variantId, array $input): int
{
    if (!can('inventory.manage')) {
        fail_user('You do not have permission to record stock.');
    }
    $quantity = (int) (input_int($input, 'quantity') ?? 0);
    if ($quantity <= 0) {
        fail_user('Enter the quantity counted.');
    }
    $date = input_date($input, 'movement_date') ?? fail_user('Enter the count date.');
    $note = input_str($input, 'reason', 500);
    if (mb_strlen($note) < 3) {
        fail_user('Describe the basis of the beginning inventory (e.g. stock count of 16 Oct).');
    }
    $cost = input_str($input, 'unit_cost', 20);
    $costCents = $cost === '' ? null : (parse_money($cost, true) ?? fail_user('Enter a valid unit cost.'));
    return db_transaction(function () use ($variantId, $quantity, $date, $note, $costCents) {
        lock_variant_stock($variantId);
        if (db_value("SELECT 1 FROM inventory_movements WHERE institution_id = ? AND variant_id = ? AND movement_type = 'beginning'", [current_institution_id(), $variantId])) {
            fail_user('Beginning inventory was already recorded for this item. Use a count adjustment for corrections.');
        }
        return record_movement($variantId, 'beginning', $quantity, $date, $note, ['unit_cost' => $costCents]);
    });
}

/** Damaged, lost, returned or count adjustment, with a required reason. */
function record_adjustment(int $variantId, array $input): int
{
    if (!can('inventory.adjust')) {
        fail_user('You do not have permission to adjust inventory.');
    }
    $type = input_enum($input, 'movement_type', array_keys(ADJUSTMENT_TYPES)) ?? fail_user('Choose the adjustment type.');
    $raw = input_str($input, 'quantity', 12);
    $quantity = preg_match('/^-?\d{1,9}$/', $raw) ? (int) $raw : 0;
    if ($quantity === 0 || ($type !== 'adjustment' && $quantity < 0)) {
        fail_user($type === 'adjustment' ? 'Enter the correction: positive to add, negative to remove.' : 'Enter the quantity as a positive number.');
    }
    $signed = ADJUSTMENT_TYPES[$type] === 0 ? $quantity : ADJUSTMENT_TYPES[$type] * $quantity;
    $date = input_date($input, 'movement_date') ?? fail_user('Enter the date.');
    if ($date > date('Y-m-d')) {
        fail_user('The date cannot be in the future.');
    }
    $reason = input_str($input, 'reason', 500);
    if (mb_strlen($reason) < 5) {
        fail_user('Give the reason for this adjustment.');
    }
    $allowNegative = input_str($input, 'allow_negative', 1) === '1';
    return db_transaction(fn() => record_movement($variantId, $type, $signed, $date, $reason, [], $allowNegative));
}

/** Stock levels for the inventory page, optionally filtered. */
function stock_levels(array $filters = []): array
{
    $where = ['v.institution_id = ?', "p.fulfillment <> 'service'"];
    $params = [current_institution_id()];
    if (!empty($filters['category'])) {
        $where[] = 'p.category_id = ?';
        $params[] = $filters['category'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(p.product_name LIKE ? OR p.sku LIKE ? OR v.variant_name LIKE ?)';
        $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
        array_push($params, $like, $like, $like);
    }
    if (empty($filters['inactive'])) {
        $where[] = "v.status = 'active' AND p.status = 'active'";
    }
    $rows = db_all(
        'SELECT v.id, v.variant_name, p.id AS product_id, p.sku, p.product_name, p.fulfillment, p.unit_of_measure, p.reorder_level, c.category_name,
                COALESCE(v.unit_cost, p.unit_cost) AS effective_cost, COALESCE(v.retail_price, p.retail_price) AS effective_price,
                ' . SQL_ON_HAND . ' AS on_hand, ' . SQL_RESERVED . ' AS reserved, ' . SQL_ON_ORDER . ' AS on_order
         FROM product_variants v JOIN products p ON p.id = v.product_id AND p.institution_id = v.institution_id
         LEFT JOIN product_categories c ON c.id = p.category_id AND c.institution_id = p.institution_id
         WHERE ' . implode(' AND ', $where) . ' ORDER BY p.product_name, v.sort_order, v.id',
        $params
    );
    foreach ($rows as &$row) {
        $row['available'] = (int) $row['on_hand'] - (int) $row['reserved'];
        $row['low_stock'] = $row['available'] <= (int) $row['reorder_level'];
    }
    unset($row);
    if (!empty($filters['low'])) {
        $rows = array_values(array_filter($rows, static fn($r) => $r['low_stock']));
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// Supplier purchases and production runs
// ---------------------------------------------------------------------------

function find_batch(int $batchId): ?array
{
    if (!can_any(['inventory.view', 'inventory.manage'])) {
        return null;
    }
    $batch = db_one(
        "SELECT b.*, p.product_name, p.sku, p.fulfillment, x.expense_number, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
         FROM stock_batches b JOIN products p ON p.id = b.product_id AND p.institution_id = b.institution_id
         LEFT JOIN expenses x ON x.id = b.expense_id AND x.institution_id = b.institution_id
         JOIN users u ON u.id = b.created_by
         WHERE b.institution_id = ? AND b.id = ?",
        [current_institution_id(), $batchId]
    );
    if ($batch) {
        $batch['items'] = db_all(
            'SELECT bi.*, v.variant_name, v.sort_order FROM stock_batch_items bi JOIN product_variants v ON v.id = bi.variant_id AND v.institution_id = bi.institution_id
             WHERE bi.institution_id = ? AND bi.batch_id = ? ORDER BY v.sort_order, v.id',
            [current_institution_id(), $batchId]
        );
    }
    return $batch;
}

/** Creates a draft supplier/production order with quantities per variant. */
function create_batch(int $productId, array $input): int
{
    if (!can('inventory.manage')) {
        fail_user('You do not have permission to order stock.');
    }
    $institutionId = (int) current_institution_id();
    $product = find_product($productId) ?? fail_user('Product not found.');
    if ($product['fulfillment'] === 'service') {
        fail_user('Services are not ordered from suppliers.');
    }
    $lines = [];
    foreach ($product['variants'] as $variant) {
        $quantity = (int) ($input['quantity'][$variant['id']] ?? 0);
        if ($quantity < 0) {
            fail_user('Quantities cannot be negative.');
        }
        if ($quantity > 0) {
            $costText = trim((string) ($input['unit_cost'][$variant['id']] ?? ''));
            $cost = $costText === '' ? to_cents($variant['effective_cost']) : (parse_money($costText, true) ?? fail_user('Enter a valid unit cost for ' . $variant['variant_name'] . '.'));
            $lines[] = [(int) $variant['id'], $quantity, $cost];
        }
    }
    if (!$lines) {
        fail_user('Enter the quantity to order for at least one variant.');
    }
    $supplier = input_str($input, 'supplier_name', 180) ?: null;
    $expected = input_date($input, 'expected_date');
    $notes = input_str($input, 'notes', 2000) ?: null;
    $userId = (int) auth_user()['id'];
    return db_transaction(function () use ($institutionId, $productId, $lines, $supplier, $expected, $notes, $userId) {
        db_query("INSERT INTO stock_batches (institution_id, batch_number, product_id, supplier_name, status, expected_date, notes, created_by) VALUES (?, ?, ?, ?, 'draft', ?, ?, ?)",
            [$institutionId, next_document_number($institutionId, 'stock_batch', 'PO'), $productId, $supplier, $expected, $notes, $userId]);
        $batchId = (int) db()->lastInsertId();
        foreach ($lines as [$variantId, $quantity, $cost]) {
            db_query('INSERT INTO stock_batch_items (institution_id, batch_id, variant_id, quantity_ordered, unit_cost) VALUES (?, ?, ?, ?, ?)', [$institutionId, $batchId, $variantId, $quantity, cents_to_decimal($cost)]);
        }
        audit_log('stock_batch.created', 'stock_batch', $batchId, $institutionId, $userId, ['product_id' => $productId, 'lines' => count($lines), 'supplier' => $supplier]);
        return $batchId;
    });
}

/** Marks a draft batch as ordered; open made-to-order orders for the product move to In Production. */
function mark_batch_ordered(int $batchId, array $input): void
{
    if (!can('inventory.manage')) {
        fail_user('You do not have permission to order stock.');
    }
    $institutionId = (int) current_institution_id();
    $date = input_date($input, 'ordered_at') ?? date('Y-m-d');
    db_transaction(function () use ($institutionId, $batchId, $date) {
        $batch = db_one('SELECT * FROM stock_batches WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $batchId]) ?? fail_user('Order not found.');
        if ($batch['status'] !== 'draft') {
            fail_user('Only draft orders can be placed.');
        }
        db_query("UPDATE stock_batches SET status = 'ordered', ordered_at = ? WHERE id = ?", [$date, $batchId]);
        $moved = db_query(
            "UPDATE sales_orders o SET o.order_status = 'in_production'
             WHERE o.institution_id = ? AND o.order_status = 'confirmed'
               AND EXISTS (SELECT 1 FROM sales_order_items i WHERE i.institution_id = o.institution_id AND i.order_id = o.id AND i.product_id = ? AND i.fulfillment = 'made_to_order')",
            [$institutionId, $batch['product_id']]
        )->rowCount();
        audit_log('stock_batch.ordered', 'stock_batch', $batchId, $institutionId, (int) auth_user()['id'], ['status' => 'ordered', 'ordered_at' => $date, 'orders_in_production' => $moved], ['status' => 'draft']);
    });
}

/** Records quantities received against a batch; only received quantities become stock. */
function receive_batch(int $batchId, array $input): void
{
    if (!can('inventory.manage')) {
        fail_user('You do not have permission to receive stock.');
    }
    $institutionId = (int) current_institution_id();
    $date = input_date($input, 'received_date') ?? fail_user('Enter the delivery date.');
    if ($date > date('Y-m-d')) {
        fail_user('The delivery date cannot be in the future.');
    }
    $note = input_str($input, 'reason', 500) ?: null;
    db_transaction(function () use ($institutionId, $batchId, $input, $date, $note) {
        $batch = db_one('SELECT * FROM stock_batches WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $batchId]) ?? fail_user('Order not found.');
        if (!in_array($batch['status'], ['ordered', 'partially_received'], true)) {
            fail_user('Place the order before receiving deliveries against it.');
        }
        $received = 0;
        foreach (db_all('SELECT * FROM stock_batch_items WHERE institution_id = ? AND batch_id = ? FOR UPDATE', [$institutionId, $batchId]) as $item) {
            $quantity = (int) ($input['received'][$item['id']] ?? 0);
            if ($quantity < 0) {
                fail_user('Received quantities cannot be negative.');
            }
            if ($quantity === 0) {
                continue;
            }
            if ($item['quantity_received'] + $quantity > $item['quantity_ordered']) {
                fail_user('More was received than ordered for one size. Record the extra as a separate order or adjustment.');
            }
            db_query('UPDATE stock_batch_items SET quantity_received = quantity_received + ? WHERE id = ?', [$quantity, $item['id']]);
            record_movement((int) $item['variant_id'], 'received', $quantity, $date, $note ?? ('Delivery for ' . $batch['batch_number']),
                ['batch_id' => $batchId, 'unit_cost' => to_cents($item['unit_cost'])]);
            $received += $quantity;
        }
        if ($received === 0) {
            fail_user('Enter the quantities received.');
        }
        $open = (int) db_value('SELECT COALESCE(SUM(quantity_ordered - quantity_received), 0) FROM stock_batch_items WHERE institution_id = ? AND batch_id = ?', [$institutionId, $batchId]);
        db_query('UPDATE stock_batches SET status = ? WHERE id = ?', [$open > 0 ? 'partially_received' : 'received', $batchId]);
        audit_log('stock_batch.received', 'stock_batch', $batchId, $institutionId, (int) auth_user()['id'], ['received' => $received, 'remaining' => $open, 'date' => $date]);
    });
}

function cancel_batch(int $batchId, string $reason): void
{
    if (!can('inventory.manage')) {
        fail_user('You do not have permission to manage stock orders.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('Give a reason.');
    }
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $batchId, $reason) {
        $batch = db_one('SELECT * FROM stock_batches WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $batchId]) ?? fail_user('Order not found.');
        if (!in_array($batch['status'], ['draft', 'ordered'], true)) {
            fail_user('An order with deliveries cannot be cancelled; it stays on record.');
        }
        db_query("UPDATE stock_batches SET status = 'cancelled' WHERE id = ?", [$batchId]);
        audit_log('stock_batch.cancelled', 'stock_batch', $batchId, $institutionId, (int) auth_user()['id'], ['status' => 'cancelled'], ['status' => $batch['status']], $reason);
    });
}

/** Links the expense that paid the supplier (recorded in the Expenses module). */
function link_batch_expense(int $batchId, ?int $expenseId): void
{
    if (!can('inventory.manage')) {
        fail_user('You do not have permission to manage stock orders.');
    }
    $institutionId = (int) current_institution_id();
    db_one('SELECT id FROM stock_batches WHERE institution_id = ? AND id = ?', [$institutionId, $batchId]) ?? fail_user('Order not found.');
    if ($expenseId && !db_value("SELECT 1 FROM expenses WHERE institution_id = ? AND id = ? AND status <> 'rejected'", [$institutionId, $expenseId])) {
        fail_user('Choose an existing expense.');
    }
    db_query('UPDATE stock_batches SET expense_id = ? WHERE id = ?', [$expenseId, $batchId]);
    audit_log('stock_batch.expense_linked', 'stock_batch', $batchId, $institutionId, (int) auth_user()['id'], ['expense_id' => $expenseId]);
}
