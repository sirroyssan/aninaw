<?php
/**
 * Store reports (products, inventory, orders, printing) for the shared report engine in
 * includes/reports.php. Same contract: 'build' returns [sql, params] for a SELECT without
 * ORDER BY / LIMIT, every query is scoped to the current institution, and figures come
 * from recorded movements, order lines (snapshot prices and costs) and posted payments.
 */
declare(strict_types=1);

require_once __DIR__ . '/services/orders.php';

/** Customer display name from the participants alias. */
function store_customer_sql(string $alias = 'pt'): string
{
    return "CONCAT({$alias}.first_name, ' ', {$alias}.last_name)";
}

/** Line amount net of the order discount (shared pro rata by line total), in pesos. */
const STORE_NET_LINE = 'ROUND(i.line_total - CASE WHEN o.subtotal > 0 THEN o.discount_amount * i.line_total / o.subtotal ELSE 0 END, 2)';

function store_report_definitions(): array
{
    $inventoryPerms = ['inventory.view', 'inventory.manage'];
    $orderPerms = ['orders.view', 'orders.manage'];
    $hub = 'products/reports.php';

    return [
        'store_inventory' => [
            'title' => 'Product Inventory Report', 'icon' => 'bi-boxes', 'permissions' => $inventoryPerms, 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Stock per product and size: on hand, reserved for orders, available, on order and stock value at current cost.',
            'filters' => ['category', 'product', 'status', 'q'],
            'statuses' => ['low' => 'At or below reorder level', 'out' => 'Nothing available', 'active' => 'Active items'], 'default_status' => 'active',
            'build' => static function (array $f): array {
                $where = ['v.institution_id = ?', "p.fulfillment <> 'service'"];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['category'], 'p.category_id = ?', $f['category']);
                report_where($where, $params, (bool) $f['product'], 'p.id = ?', $f['product']);
                report_where($where, $params, $f['status'] !== '', "v.status = 'active' AND p.status = 'active'");
                report_where($where, $params, $f['q'] !== '', '(p.sku LIKE ? OR p.product_name LIKE ? OR v.variant_name LIKE ?)', ...array_fill(0, 3, report_like($f['q'])));
                $inner = 'SELECT v.id, p.id AS product_id, p.sku, p.product_name, v.variant_name, v.sort_order, c.category_name, p.fulfillment, p.reorder_level, p.status,
                                 COALESCE(v.unit_cost, p.unit_cost) AS unit_cost, ' . SQL_ON_HAND . ' AS on_hand, ' . SQL_RESERVED . ' AS reserved, ' . SQL_ON_ORDER . ' AS on_order
                          FROM product_variants v JOIN products p ON p.id = v.product_id AND p.institution_id = v.institution_id
                          LEFT JOIN product_categories c ON c.id = p.category_id AND c.institution_id = p.institution_id
                          WHERE ' . implode(' AND ', $where);
                $outer = match ($f['status']) {
                    'low' => 'WHERE s.on_hand - s.reserved <= s.reorder_level',
                    'out' => 'WHERE s.on_hand - s.reserved <= 0',
                    default => '',
                };
                return ["SELECT s.*, s.on_hand - s.reserved AS available, ROUND(GREATEST(s.on_hand, 0) * s.unit_cost, 2) AS stock_value FROM ({$inner}) s {$outer}", $params];
            },
            'order' => 'product_name, sort_order, id',
            'columns' => ['sku' => ['SKU'], 'product_name' => ['Product'], 'variant_name' => ['Variant / size'], 'category_name' => ['Category'], 'fulfillment' => ['Sold as', 'label'],
                'on_hand' => ['On hand', 'int', true], 'reserved' => ['Reserved', 'int', true], 'available' => ['Available', 'int', true], 'on_order' => ['On order', 'int', true],
                'reorder_level' => ['Reorder at', 'int'], 'unit_cost' => ['Unit cost', 'money'], 'stock_value' => ['Stock value', 'money', true], 'status' => ['Status', 'status']],
            'link' => ['product_name', 'products/view.php?id=', 'product_id'],
            'note' => 'On hand is the sum of recorded stock movements. Reserved = confirmed order quantities not yet released. On order is not stock until received. Stock value uses the current unit cost.',
        ],
        'store_movements' => [
            'title' => 'Inventory Movement Report', 'icon' => 'bi-arrow-left-right', 'permissions' => $inventoryPerms, 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Every stock movement: beginning counts, deliveries, releases, supplies used, damage, losses, returns and adjustments with reasons.',
            'filters' => ['from', 'to', 'category', 'product', 'variant', 'status', 'q'],
            'statuses' => MOVEMENT_TYPES,
            'build' => static function (array $f): array {
                $where = ['m.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['from'], 'm.movement_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'm.movement_date <= ?', $f['to']);
                report_where($where, $params, (bool) $f['category'], 'p.category_id = ?', $f['category']);
                report_where($where, $params, (bool) $f['product'], 'p.id = ?', $f['product']);
                report_where($where, $params, (bool) $f['variant'], 'v.id = ?', $f['variant']);
                report_where($where, $params, $f['status'] !== '', 'm.movement_type = ?', $f['status']);
                report_where($where, $params, $f['q'] !== '', '(p.sku LIKE ? OR p.product_name LIKE ? OR m.reason LIKE ? OR b.batch_number LIKE ? OR o.order_number LIKE ?)', ...array_fill(0, 5, report_like($f['q'])));
                return ["SELECT m.id, m.movement_date, m.created_at, p.id AS product_id, p.sku, p.product_name, v.variant_name, m.movement_type, m.quantity, m.unit_cost,
                               COALESCE(b.batch_number, o.order_number) AS reference, m.reason, IF(m.negative_override, 'Yes', '') AS override, " . report_person('u') . " AS recorded_by
                        FROM inventory_movements m
                        JOIN product_variants v ON v.id = m.variant_id AND v.institution_id = m.institution_id
                        JOIN products p ON p.id = v.product_id AND p.institution_id = v.institution_id
                        LEFT JOIN stock_batches b ON b.id = m.batch_id AND b.institution_id = m.institution_id
                        LEFT JOIN sales_order_items i ON i.id = m.order_item_id AND i.institution_id = m.institution_id
                        LEFT JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
                        JOIN users u ON u.id = m.recorded_by
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'movement_date DESC, id DESC', 'date_label' => 'Movement date',
            'columns' => ['movement_date' => ['Date', 'date'], 'product_name' => ['Product'], 'variant_name' => ['Variant / size'], 'movement_type' => ['Movement', 'label'],
                'quantity' => ['Quantity', 'int', true], 'unit_cost' => ['Unit cost', 'money'], 'reference' => ['Reference'], 'reason' => ['Reason'], 'override' => ['Below-zero override'],
                'recorded_by' => ['Recorded by'], 'created_at' => ['Recorded', 'datetime']],
            'link' => ['product_name', 'products/view.php?id=', 'product_id'],
            'note' => 'Quantities are signed: additions are positive, deductions negative. The total is the net change for the filtered movements. Movements are never edited; corrections are new adjustments.',
        ],
        'store_shirt_sizes' => [
            'title' => 'Shirt Orders by Size', 'icon' => 'bi-rulers', 'permissions' => $orderPerms, 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Made-to-order quantities per product and size: ordered, pending confirmation, fully paid, released and still to release.',
            'filters' => ['from', 'to', 'product', 'status'],
            'statuses' => ['open' => 'Open orders only'],
            'build' => static function (array $f): array {
                $where = ['i.institution_id = ?', "i.fulfillment = 'made_to_order'", "o.order_status <> 'cancelled'"];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['from'], 'o.order_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'o.order_date <= ?', $f['to']);
                report_where($where, $params, (bool) $f['product'], 'i.product_id = ?', $f['product']);
                report_where($where, $params, $f['status'] === 'open', "o.order_status <> 'completed'");
                return ["SELECT v.id, p.id AS product_id, p.product_name, v.variant_name, v.sort_order, COUNT(DISTINCT o.id) AS orders, SUM(i.quantity) AS ordered,
                               SUM(CASE WHEN o.order_status = 'pending' THEN i.quantity ELSE 0 END) AS pending_qty,
                               SUM(CASE WHEN o.payment_status = 'fully_paid' THEN i.quantity ELSE 0 END) AS paid_qty,
                               SUM(i.quantity_released) AS released, SUM(i.quantity - i.quantity_released) AS unreleased, SUM(i.line_total) AS order_value
                        FROM sales_order_items i
                        JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
                        JOIN product_variants v ON v.id = i.variant_id AND v.institution_id = i.institution_id
                        JOIN products p ON p.id = i.product_id AND p.institution_id = i.institution_id
                        WHERE " . implode(' AND ', $where) . '
                        GROUP BY v.id, p.id, p.product_name, v.variant_name, v.sort_order', $params];
            },
            'order' => 'product_name, sort_order, id', 'date_label' => 'Order date',
            'columns' => ['product_name' => ['Product'], 'variant_name' => ['Size'], 'orders' => ['Orders', 'int', true], 'ordered' => ['Ordered', 'int', true], 'pending_qty' => ['Not yet confirmed', 'int', true],
                'paid_qty' => ['Fully paid', 'int', true], 'released' => ['Released', 'int', true], 'unreleased' => ['To release', 'int', true], 'order_value' => ['Order value', 'money', true]],
            'link' => ['product_name', 'orders/production.php?product=', 'product_id'],
            'note' => 'Cancelled orders are excluded. Order value is before order-level discounts. Use the production list to see what still has to be ordered from the printer.',
        ],
        'store_deliveries' => [
            'title' => 'Supplier and Production Deliveries', 'icon' => 'bi-truck', 'permissions' => $inventoryPerms, 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Purchase and production orders: quantities ordered and received, cost, and the linked expense.',
            'filters' => ['from', 'to', 'product', 'status', 'q'],
            'statuses' => BATCH_STATUSES,
            'build' => static function (array $f): array {
                $where = ['b.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['from'], 'COALESCE(b.ordered_at, DATE(b.created_at)) >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'COALESCE(b.ordered_at, DATE(b.created_at)) <= ?', $f['to']);
                report_where($where, $params, (bool) $f['product'], 'b.product_id = ?', $f['product']);
                report_where($where, $params, $f['status'] !== '', 'b.status = ?', $f['status']);
                report_where($where, $params, $f['q'] !== '', '(b.batch_number LIKE ? OR b.supplier_name LIKE ? OR p.product_name LIKE ?)', ...array_fill(0, 3, report_like($f['q'])));
                $sum = static fn(string $expr) => "(SELECT COALESCE(SUM({$expr}), 0) FROM stock_batch_items bi WHERE bi.institution_id = b.institution_id AND bi.batch_id = b.id)";
                return ["SELECT b.id, b.batch_number, COALESCE(b.ordered_at, DATE(b.created_at)) AS batch_date, b.expected_date, b.supplier_name, p.product_name, b.status,
                               {$sum('bi.quantity_ordered')} AS ordered, {$sum('bi.quantity_received')} AS received,
                               CASE WHEN b.status IN ('ordered', 'partially_received') THEN {$sum('bi.quantity_ordered - bi.quantity_received')} ELSE 0 END AS outstanding,
                               {$sum('bi.quantity_ordered * COALESCE(bi.unit_cost, 0)')} AS ordered_cost, {$sum('bi.quantity_received * COALESCE(bi.unit_cost, 0)')} AS received_cost,
                               x.expense_number, x.status AS expense_status
                        FROM stock_batches b
                        JOIN products p ON p.id = b.product_id AND p.institution_id = b.institution_id
                        LEFT JOIN expenses x ON x.id = b.expense_id AND x.institution_id = b.institution_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'batch_date DESC, id DESC', 'date_label' => 'Order date',
            'columns' => ['batch_date' => ['Date', 'date'], 'batch_number' => ['Order'], 'supplier_name' => ['Supplier / printer'], 'product_name' => ['Product'], 'ordered' => ['Ordered', 'int', true],
                'received' => ['Received', 'int', true], 'outstanding' => ['Still due', 'int', true], 'ordered_cost' => ['Ordered cost', 'money', true], 'received_cost' => ['Received cost', 'money', true],
                'expected_date' => ['Expected', 'date'], 'status' => ['Status', 'status'], 'expense_number' => ['Expense'], 'expense_status' => ['Expense status', 'status']],
            'link' => ['batch_number', 'inventory/batch.php?id=', 'id'],
            'note' => 'Quantities on order are not stock until received. Payment to the supplier is recorded as an expense and linked to the order; this report does not post anything.',
        ],
        'store_customer_orders' => [
            'title' => 'Customer Order History', 'icon' => 'bi-person-lines-fill', 'permissions' => $orderPerms, 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Orders per customer with items, totals, posted payments, balances and status.',
            'filters' => ['from', 'to', 'product', 'status', 'q'],
            'statuses' => ORDER_STATUSES,
            'build' => static function (array $f): array {
                $where = ['o.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['from'], 'o.order_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'o.order_date <= ?', $f['to']);
                report_where($where, $params, (bool) $f['product'], 'EXISTS (SELECT 1 FROM sales_order_items i WHERE i.institution_id = o.institution_id AND i.order_id = o.id AND i.product_id = ?)', $f['product']);
                report_where($where, $params, $f['status'] !== '', 'o.order_status = ?', $f['status']);
                report_where($where, $params, $f['q'] !== '', '(o.order_number LIKE ? OR pt.participant_number LIKE ? OR ' . store_customer_sql() . ' LIKE ?)', ...array_fill(0, 3, report_like($f['q'])));
                return ['SELECT o.id, o.order_date, o.order_number, pt.participant_number, ' . store_customer_sql() . " AS customer,
                               (SELECT GROUP_CONCAT(CONCAT(i.description, ' ×', i.quantity) ORDER BY i.id SEPARATOR ', ') FROM sales_order_items i WHERE i.institution_id = o.institution_id AND i.order_id = o.id) AS items,
                               CASE WHEN o.order_status = 'cancelled' THEN 0 ELSE o.total_amount END AS total_amount, " . store_order_paid_sql() . " AS paid, o.order_status, o.payment_status
                        FROM sales_orders o JOIN participants pt ON pt.id = o.customer_participant_id AND pt.institution_id = o.institution_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'customer, order_date DESC, id DESC', 'date_label' => 'Order date',
            'columns' => ['customer' => ['Customer'], 'participant_number' => ['ID no.'], 'order_date' => ['Date', 'date'], 'order_number' => ['Order'], 'items' => ['Items'],
                'total_amount' => ['Total', 'money', true], 'paid' => ['Paid (posted)', 'money', true],
                'balance' => ['Balance', 'money', true, static fn($r) => cents_to_decimal($r['order_status'] === 'cancelled' ? 0 : max(0, to_cents($r['total_amount']) - to_cents($r['paid'])))],
                'order_status' => ['Order', 'status'], 'payment_status' => ['Payment', 'status']],
            'link' => ['order_number', 'orders/view.php?id=', 'id'],
            'note' => 'Cancelled orders are listed with a zero total. Paid counts posted payments only; reversed payments are excluded.',
        ],
        'store_printing' => [
            'title' => 'Printing Services Transactions', 'icon' => 'bi-printer', 'permissions' => $orderPerms, 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Printing, photocopying, binding and other service jobs with pages, copies, amounts and job status.',
            'filters' => ['from', 'to', 'product', 'status', 'q'],
            'statuses' => SERVICE_STATUSES,
            'build' => static function (array $f): array {
                $where = ['i.institution_id = ?', "i.fulfillment = 'service'", "o.order_status <> 'cancelled'"];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['from'], 'COALESCE(i.service_date, o.order_date) >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'COALESCE(i.service_date, o.order_date) <= ?', $f['to']);
                report_where($where, $params, (bool) $f['product'], 'i.product_id = ?', $f['product']);
                report_where($where, $params, $f['status'] !== '', 'i.service_status = ?', $f['status']);
                report_where($where, $params, $f['q'] !== '', '(o.order_number LIKE ? OR i.description LIKE ? OR ' . store_customer_sql() . ' LIKE ?)', ...array_fill(0, 3, report_like($f['q'])));
                return ['SELECT i.id, o.id AS order_id, o.order_number, COALESCE(i.service_date, o.order_date) AS service_date, ' . store_customer_sql() . " AS customer, i.description,
                               i.job_pages, i.job_copies, i.quantity, i.pricing_unit, i.unit_price, i.additional_charge, i.line_total, ROUND(i.unit_cost * i.quantity, 2) AS cost,
                               i.service_status, o.payment_status, COALESCE(" . report_person('au') . ", '') AS assigned
                        FROM sales_order_items i
                        JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
                        JOIN participants pt ON pt.id = o.customer_participant_id AND pt.institution_id = o.institution_id
                        LEFT JOIN institution_memberships am ON am.id = i.assigned_membership_id AND am.institution_id = i.institution_id LEFT JOIN users au ON au.id = am.user_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'service_date DESC, id DESC', 'date_label' => 'Service date',
            'columns' => ['service_date' => ['Date', 'date'], 'order_number' => ['Order'], 'customer' => ['Customer'], 'description' => ['Service'], 'job_pages' => ['Pages', 'int'], 'job_copies' => ['Copies', 'int'],
                'quantity' => ['Billed units', 'int', true], 'pricing_unit' => ['Per'], 'unit_price' => ['Rate', 'money'], 'additional_charge' => ['Extra', 'money', true], 'line_total' => ['Amount', 'money', true],
                'cost' => ['Est. cost', 'money', true], 'service_status' => ['Job', 'status'], 'payment_status' => ['Payment', 'status'], 'assigned' => ['Assigned to']],
            'link' => ['order_number', 'orders/view.php?id=', 'order_id'],
            'note' => 'Amounts are the prices fixed on the order. Estimated cost uses the cost per unit recorded when the job was ordered.',
        ],
        'store_sales_collections' => [
            'title' => 'Sales and Collections Report', 'icon' => 'bi-cash-stack', 'permissions' => ['orders.view', 'orders.manage', 'reports.view'], 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Payments received for orders with receipt, fund, account and cashier.',
            'filters' => ['from', 'to', 'fund', 'account', 'status', 'q'],
            'statuses' => ['posted' => 'Posted', 'reversed' => 'Reversed'], 'default_status' => 'posted',
            'build' => static function (array $f): array {
                $where = ['sop.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['from'], 'p.payment_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'p.payment_date <= ?', $f['to']);
                report_where($where, $params, (bool) $f['fund'], 'o.fund_id = ?', $f['fund']);
                report_where($where, $params, (bool) $f['account'], 'p.financial_account_id = ?', $f['account']);
                report_where($where, $params, $f['status'] !== '', 'p.status = ?', $f['status']);
                report_where($where, $params, $f['q'] !== '', '(p.payment_number LIKE ? OR r.receipt_number LIKE ? OR o.order_number LIKE ? OR ' . store_customer_sql() . ' LIKE ?)', ...array_fill(0, 4, report_like($f['q'])));
                return ['SELECT p.id, p.payment_date, p.payment_number, r.receipt_number, o.id AS order_id, o.order_number, ' . store_customer_sql() . " AS customer, f.fund_name, fa.account_name,
                               p.payment_method, sop.amount, " . report_person('cu') . " AS received_by, p.status
                        FROM sales_order_payments sop
                        JOIN payments p ON p.id = sop.payment_id AND p.institution_id = sop.institution_id
                        JOIN sales_orders o ON o.id = sop.order_id AND o.institution_id = sop.institution_id
                        JOIN participants pt ON pt.id = o.customer_participant_id AND pt.institution_id = o.institution_id
                        JOIN fund_accounts f ON f.id = o.fund_id AND f.institution_id = o.institution_id
                        JOIN financial_accounts fa ON fa.id = p.financial_account_id AND fa.institution_id = p.institution_id
                        LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id
                        JOIN institution_memberships cm ON cm.id = p.received_by_membership_id JOIN users cu ON cu.id = cm.user_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'payment_date DESC, id DESC', 'date_label' => 'Payment date',
            'columns' => ['payment_date' => ['Date', 'date'], 'payment_number' => ['Payment'], 'receipt_number' => ['Receipt'], 'order_number' => ['Order'], 'customer' => ['Customer'], 'fund_name' => ['Fund'],
                'account_name' => ['Received into'], 'payment_method' => ['Method', 'label'], 'amount' => ['Amount', 'money', true], 'received_by' => ['Cashier'], 'status' => ['Status', 'status']],
            'link' => ['payment_number', 'collections/payment.php?id=', 'id'],
            'note' => 'Posted payments are recorded as sales or service revenue of the order\'s fund when received (cash basis). Reversed payments are shown only when that status is selected.',
        ],
        'store_unpaid' => [
            'title' => 'Unpaid and Partially Paid Orders', 'icon' => 'bi-hourglass-split', 'permissions' => $orderPerms, 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Open balances by order and customer, with how long each order has been waiting.',
            'filters' => ['from', 'to', 'product', 'status', 'q'],
            'statuses' => ['unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid'],
            'build' => static function (array $f): array {
                $where = ['o.institution_id = ?', "o.order_status <> 'cancelled'", "o.payment_status <> 'fully_paid'"];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['from'], 'o.order_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'o.order_date <= ?', $f['to']);
                report_where($where, $params, (bool) $f['product'], 'EXISTS (SELECT 1 FROM sales_order_items i WHERE i.institution_id = o.institution_id AND i.order_id = o.id AND i.product_id = ?)', $f['product']);
                report_where($where, $params, $f['status'] !== '', 'o.payment_status = ?', $f['status']);
                report_where($where, $params, $f['q'] !== '', '(o.order_number LIKE ? OR pt.participant_number LIKE ? OR ' . store_customer_sql() . ' LIKE ?)', ...array_fill(0, 3, report_like($f['q'])));
                return ['SELECT o.id, o.order_date, o.order_number, pt.participant_number, ' . store_customer_sql() . " AS customer, o.total_amount, " . store_order_paid_sql() . " AS paid,
                               DATEDIFF(CURDATE(), o.order_date) AS days_open, o.order_status, o.payment_status
                        FROM sales_orders o JOIN participants pt ON pt.id = o.customer_participant_id AND pt.institution_id = o.institution_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'order_date, id', 'date_label' => 'Order date',
            'columns' => ['order_date' => ['Date', 'date'], 'order_number' => ['Order'], 'customer' => ['Customer'], 'participant_number' => ['ID no.'], 'total_amount' => ['Total', 'money', true],
                'paid' => ['Paid (posted)', 'money', true], 'balance' => ['Balance', 'money', true, static fn($r) => cents_to_decimal(max(0, to_cents($r['total_amount']) - to_cents($r['paid'])))],
                'days_open' => ['Days open', 'int'], 'payment_status' => ['Payment', 'status'], 'order_status' => ['Order', 'status']],
            'link' => ['order_number', 'orders/view.php?id=', 'id'],
            'note' => 'An order is paid only by posted payments recorded against it. Items are released only after full payment.',
        ],
        'store_unclaimed' => [
            'title' => 'Released and Unclaimed Items', 'icon' => 'bi-bag-check', 'permissions' => $orderPerms, 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Items set aside for customers and not yet claimed, and items already handed over, with dates and who claimed them.',
            'filters' => ['from', 'to', 'product', 'status', 'q'],
            'statuses' => ['unclaimed' => 'Waiting to be claimed', 'released' => 'Released (fully or partly)'], 'default_status' => 'unclaimed',
            'build' => static function (array $f): array {
                $where = ['i.institution_id = ?', "o.order_status IN ('ready_for_release', 'partially_released', 'completed')"];
                $params = [$f['institution_id']];
                report_where($where, $params, $f['status'] === 'unclaimed', "i.quantity_released < i.quantity AND o.order_status IN ('ready_for_release', 'partially_released')");
                report_where($where, $params, $f['status'] === 'released', 'i.quantity_released > 0');
                report_where($where, $params, (bool) $f['from'], 'DATE(o.ready_at) >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'DATE(o.ready_at) <= ?', $f['to']);
                report_where($where, $params, (bool) $f['product'], 'i.product_id = ?', $f['product']);
                report_where($where, $params, $f['q'] !== '', '(o.order_number LIKE ? OR i.description LIKE ? OR ' . store_customer_sql() . ' LIKE ?)', ...array_fill(0, 3, report_like($f['q'])));
                $release = static fn(string $expr) => "(SELECT {$expr} FROM sales_order_releases r WHERE r.institution_id = i.institution_id AND r.order_item_id = i.id)";
                return ['SELECT i.id, o.id AS order_id, o.order_number, ' . store_customer_sql() . " AS customer, i.description, i.quantity, i.quantity_released,
                               i.quantity - i.quantity_released AS unclaimed, o.ready_at,
                               CASE WHEN i.quantity_released < i.quantity AND o.ready_at IS NOT NULL THEN DATEDIFF(CURDATE(), DATE(o.ready_at)) ELSE 0 END AS days_waiting,
                               {$release('MAX(r.released_at)')} AS last_released_at, {$release("GROUP_CONCAT(DISTINCT r.received_by_name SEPARATOR ', ')")} AS claimed_by, o.order_status, o.payment_status
                        FROM sales_order_items i
                        JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
                        JOIN participants pt ON pt.id = o.customer_participant_id AND pt.institution_id = o.institution_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'days_waiting DESC, ready_at, id', 'date_label' => 'Ready',
            'columns' => ['order_number' => ['Order'], 'customer' => ['Customer'], 'description' => ['Item'], 'quantity' => ['Qty', 'int', true], 'quantity_released' => ['Released', 'int', true],
                'unclaimed' => ['Unclaimed', 'int', true], 'ready_at' => ['Ready since', 'datetime'], 'days_waiting' => ['Days waiting', 'late'], 'last_released_at' => ['Last released', 'datetime'],
                'claimed_by' => ['Claimed by'], 'payment_status' => ['Payment', 'status'], 'order_status' => ['Order', 'status']],
            'link' => ['order_number', 'orders/view.php?id=', 'order_id'],
            'note' => 'Unclaimed items are set aside for the customer and stay reserved until released. Items are released only once the order is fully paid.',
        ],
        'store_profit' => [
            'title' => 'Product Costs and Gross Profit', 'icon' => 'bi-graph-up-arrow', 'permissions' => ['products.pricing', 'reports.view'], 'hub' => $hub, 'nav' => 'store-reports',
            'description' => 'Sales, cost and gross profit per product: expected on all active orders, actual on items released to customers.',
            'filters' => ['from', 'to', 'category', 'product'],
            'build' => static function (array $f): array {
                $where = ['i.institution_id = ?', "o.order_status <> 'cancelled'"];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['from'], 'o.order_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'o.order_date <= ?', $f['to']);
                report_where($where, $params, (bool) $f['category'], 'p.category_id = ?', $f['category']);
                report_where($where, $params, (bool) $f['product'], 'p.id = ?', $f['product']);
                $net = STORE_NET_LINE;
                return ["SELECT p.id, p.sku, p.product_name, c.category_name, p.fulfillment, SUM(i.quantity) AS qty_ordered,
                               SUM({$net}) AS net_sales, SUM(ROUND(i.unit_cost * i.quantity, 2)) AS cost,
                               SUM(i.quantity_released) AS qty_released,
                               SUM(ROUND({$net} * i.quantity_released / i.quantity, 2)) AS actual_sales, SUM(ROUND(i.unit_cost * i.quantity_released, 2)) AS actual_cost
                        FROM sales_order_items i
                        JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
                        JOIN products p ON p.id = i.product_id AND p.institution_id = i.institution_id
                        LEFT JOIN product_categories c ON c.id = p.category_id AND c.institution_id = p.institution_id
                        WHERE " . implode(' AND ', $where) . '
                        GROUP BY p.id, p.sku, p.product_name, c.category_name, p.fulfillment', $params];
            },
            'order' => 'product_name', 'date_label' => 'Order date',
            'columns' => ['sku' => ['SKU'], 'product_name' => ['Product'], 'category_name' => ['Category'], 'qty_ordered' => ['Qty ordered', 'int', true],
                'net_sales' => ['Sales (net of discounts)', 'money', true], 'cost' => ['Cost', 'money', true],
                'expected_profit' => ['Expected profit', 'money', true, static fn($r) => cents_to_decimal(to_cents($r['net_sales']) - to_cents($r['cost']))],
                'margin' => ['Expected margin', 'text', false, static fn($r) => to_cents($r['net_sales']) > 0 ? number_format((to_cents($r['net_sales']) - to_cents($r['cost'])) * 100 / to_cents($r['net_sales']), 1) . '%' : '—'],
                'qty_released' => ['Qty released', 'int', true], 'actual_sales' => ['Released sales', 'money', true], 'actual_cost' => ['Released cost', 'money', true],
                'actual_profit' => ['Actual profit', 'money', true, static fn($r) => cents_to_decimal(to_cents($r['actual_sales']) - to_cents($r['actual_cost']))]],
            'link' => ['product_name', 'products/view.php?id=', 'id'],
            'note' => 'Prices and costs are the snapshots saved on each order line, so later price changes do not alter past figures. Order discounts are shared across lines by amount. Expected = all orders not cancelled; actual = quantities released to customers (always fully paid). Stock written off as damaged or lost is not included; see the movement report.',
        ],
    ];
}

/** Posted payments recorded against order o. */
function store_order_paid_sql(): string
{
    return "(SELECT COALESCE(SUM(sop.amount), 0) FROM sales_order_payments sop JOIN payments p ON p.id = sop.payment_id AND p.institution_id = sop.institution_id AND p.status = 'posted'
             WHERE sop.institution_id = o.institution_id AND sop.order_id = o.id)";
}
