<?php
/**
 * Product and service orders: college shirts, merchandise and printing jobs.
 *
 * Order status (fulfilment):
 *   pending → confirmed → in_production (made-to-order item ordered from the printer)
 *           → ready_for_release (stock set aside) → partially_released → completed
 *   any open order → cancelled (with a reason; only when nothing is paid or released)
 * Payment status is separate: unpaid → partially_paid → fully_paid, from posted payments only.
 *
 * Payments reuse ANINAW collections: a `payments` row (payer = the customer participant,
 * received into the cashier's own mapped account), an official receipt, and a balanced
 * journal entry Dr Cash / Cr Sales Revenue + Service Revenue in the order's fund.
 * A payment is linked to its order in sales_order_payments; reversals use reverse_payment().
 *
 * Every order line stores a snapshot of price, cost and fulfilment rule.
 */
declare(strict_types=1);

require_once __DIR__ . '/products.php';
require_once __DIR__ . '/payments.php';

const ORDER_STATUSES = [
    'pending' => 'Pending', 'confirmed' => 'Confirmed', 'in_production' => 'In Production', 'ready_for_release' => 'Ready for Release',
    'partially_released' => 'Partially Released', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
];
const ORDER_PAYMENT_STATUSES = ['unpaid' => 'Unpaid', 'partially_paid' => 'Partially Paid', 'fully_paid' => 'Fully Paid'];
const SERVICE_STATUSES = ['pending' => 'Pending', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'released' => 'Released'];
const ORDER_OPEN_STATUSES = ['pending', 'confirmed', 'in_production', 'ready_for_release', 'partially_released'];

// ---------------------------------------------------------------------------
// Customers
// ---------------------------------------------------------------------------

/** Active participants for the customer picker: [id => "Last, First (number)"]. */
function customer_options(): array
{
    return options_from_rows(db_all(
        "SELECT id, participant_number, first_name, last_name, participant_type FROM participants WHERE institution_id = ? AND status = 'active' ORDER BY last_name, first_name LIMIT 5000",
        [current_institution_id()]
    ), 'id', static fn($r) => $r['last_name'] . ', ' . $r['first_name'] . ' (' . $r['participant_number'] . ($r['participant_type'] !== 'student' ? ' · ' . $r['participant_type'] : '') . ')');
}

/** Registers a walk-in customer as a participant of type "other" (so receipts keep a named payer). */
function add_walkin_customer(array $input): int
{
    if (!can('orders.manage')) {
        fail_user('You do not have permission to add customers.');
    }
    $first = input_str($input, 'walkin_first_name', 100);
    $last = input_str($input, 'walkin_last_name', 100);
    if (mb_strlen($first) < 1 || mb_strlen($last) < 1) {
        fail_user('Enter the walk-in customer\'s first and last name.');
    }
    $phone = input_str($input, 'walkin_phone', 50) ?: null;
    $institutionId = (int) current_institution_id();
    $number = next_document_number($institutionId, 'walkin', 'WALK');
    db_query("INSERT INTO participants (institution_id, participant_number, first_name, last_name, phone, participant_type, status) VALUES (?, ?, ?, ?, ?, 'other', 'active')",
        [$institutionId, $number, $first, $last, $phone]);
    $id = (int) db()->lastInsertId();
    audit_log('participant.walkin_added', 'participant', $id, $institutionId, (int) auth_user()['id'], ['participant_number' => $number, 'name' => $first . ' ' . $last]);
    return $id;
}

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

function order_paid_cents(int $orderId): int
{
    return to_cents(db_value(
        "SELECT COALESCE(SUM(sop.amount), 0) FROM sales_order_payments sop JOIN payments p ON p.id = sop.payment_id AND p.institution_id = sop.institution_id AND p.status = 'posted'
         WHERE sop.institution_id = ? AND sop.order_id = ?",
        [current_institution_id(), $orderId]
    ));
}

function find_order(int $orderId): ?array
{
    if (!can_any(['orders.view', 'orders.manage', 'collections.record'])) {
        return null;
    }
    $institutionId = (int) current_institution_id();
    $order = db_one(
        "SELECT o.*, pt.participant_number, pt.first_name, pt.last_name, pt.participant_type, pt.phone, pt.email, f.fund_name,
                CONCAT(u.first_name, ' ', u.last_name) AS created_by_name, CONCAT(du.first_name, ' ', du.last_name) AS discount_approved_by_name
         FROM sales_orders o
         JOIN participants pt ON pt.id = o.customer_participant_id AND pt.institution_id = o.institution_id
         JOIN fund_accounts f ON f.id = o.fund_id AND f.institution_id = o.institution_id
         JOIN users u ON u.id = o.created_by
         LEFT JOIN users du ON du.id = o.discount_approved_by
         WHERE o.institution_id = ? AND o.id = ?",
        [$institutionId, $orderId]
    );
    if (!$order) {
        return null;
    }
    $order['customer_name'] = $order['first_name'] . ' ' . $order['last_name'];
    $order['items'] = db_all(
        "SELECT i.*, p.sku, p.product_type, CONCAT(au.first_name, ' ', au.last_name) AS assigned_name
         FROM sales_order_items i JOIN products p ON p.id = i.product_id AND p.institution_id = i.institution_id
         LEFT JOIN institution_memberships am ON am.id = i.assigned_membership_id AND am.institution_id = i.institution_id
         LEFT JOIN users au ON au.id = am.user_id
         WHERE i.institution_id = ? AND i.order_id = ? ORDER BY i.id",
        [$institutionId, $orderId]
    );
    $order['payments'] = db_all(
        "SELECT p.id, p.payment_number, p.payment_date, p.payment_method, p.status, sop.amount, r.receipt_number, r.receipt_status, CONCAT(u.first_name, ' ', u.last_name) AS received_by_name
         FROM sales_order_payments sop JOIN payments p ON p.id = sop.payment_id AND p.institution_id = sop.institution_id
         LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id
         JOIN institution_memberships m ON m.id = p.received_by_membership_id JOIN users u ON u.id = m.user_id
         WHERE sop.institution_id = ? AND sop.order_id = ? ORDER BY p.payment_date, p.id",
        [$institutionId, $orderId]
    );
    $order['releases'] = db_all(
        "SELECT r.*, i.description, CONCAT(u.first_name, ' ', u.last_name) AS released_by_name FROM sales_order_releases r
         JOIN sales_order_items i ON i.id = r.order_item_id AND i.institution_id = r.institution_id JOIN users u ON u.id = r.released_by
         WHERE r.institution_id = ? AND r.order_id = ? ORDER BY r.released_at, r.id",
        [$institutionId, $orderId]
    );
    $order['paid_cents'] = order_paid_cents($orderId);
    $order['balance_cents'] = to_cents($order['total_amount']) - $order['paid_cents'];
    $order['cost_cents'] = array_sum(array_map(static fn($i) => $i['quantity'] * to_cents($i['unit_cost']), $order['items']));
    $order['expected_profit_cents'] = to_cents($order['total_amount']) - $order['cost_cents'];
    $order['partial_allowed'] = $order['items'] !== [] && !array_filter($order['items'], static fn($i) => !$i['allow_partial_payment']);
    return $order;
}

// ---------------------------------------------------------------------------
// Creating and editing
// ---------------------------------------------------------------------------

/** Quantity billed for a service line from pages × copies (per page), copies (per copy) or units. */
function service_billed_quantity(string $unit, ?int $pages, ?int $copies, ?int $units): int
{
    return match (true) {
        $unit === 'page' && $pages && $copies => $pages * $copies,
        $unit === 'page' && $pages => $pages,
        $unit === 'copy' && $copies => $copies,
        default => (int) $units,
    };
}

/** Validates order lines into snapshots. All lines must belong to one fund. */
function order_lines_input(array $input): array
{
    $lines = [];
    $fundId = null;
    foreach ((array) ($input['lines'] ?? []) as $rowKey => $row) {
        if (!is_array($row) || !input_int($row, 'variant_id')) {
            continue;
        }
        $variant = find_variant(input_int($row, 'variant_id')) ?? fail_user('One of the items no longer exists.');
        if ($variant['status'] !== 'active' || $variant['product_status'] !== 'active') {
            fail_user($variant['label'] . ' is not available for new orders.');
        }
        $number = count($lines) + 1;
        $isService = $variant['fulfillment'] === 'service';
        $pages = input_int($row, 'job_pages');
        $copies = input_int($row, 'job_copies');
        $quantity = $isService
            ? service_billed_quantity($variant['unit_of_measure'], $pages, $copies, input_int($row, 'quantity'))
            : (int) (input_int($row, 'quantity') ?? 0);
        if ($quantity <= 0 || $quantity > 100000) {
            fail_user("Line {$number} ({$variant['label']}): enter the quantity" . ($isService ? ', pages or copies.' : '.'));
        }
        $chargeText = input_str($row, 'additional_charge', 20);
        $charge = $chargeText === '' ? 0 : (parse_money($chargeText, true) ?? fail_user("Line {$number}: enter a valid additional charge."));
        $chargeNote = input_str($row, 'additional_charge_note', 180) ?: null;
        if ($charge > 0 && !$chargeNote) {
            fail_user("Line {$number}: describe the additional charge (e.g. binding, rush fee).");
        }
        $price = to_cents($variant['effective_price']);
        if ($fundId !== null && $fundId !== (int) $variant['fund_id']) {
            fail_user('All items in one order must go to the same fund. Create a separate order for ' . $variant['label'] . '.');
        }
        $fundId = (int) $variant['fund_id'];
        $assigned = $isService ? input_int($row, 'assigned_membership_id') : null;
        if ($assigned) {
            find_member($assigned);
        }
        $lines[] = [
            'product_id' => (int) $variant['product_id'], 'variant_id' => (int) $variant['id'], 'description' => mb_substr($variant['label'], 0, 255),
            'fulfillment' => $variant['fulfillment'], 'pricing_unit' => $variant['unit_of_measure'], 'allow_partial_payment' => (int) $variant['allow_partial_payment'],
            'quantity' => $quantity, 'unit_price' => $price, 'unit_cost' => to_cents($variant['effective_cost']),
            'additional_charge' => $charge, 'additional_charge_note' => $chargeNote, 'line_total' => $quantity * $price + $charge,
            'service_status' => $isService ? 'pending' : null, 'job_pages' => $isService ? $pages : null, 'job_copies' => $isService ? $copies : null,
            'job_instructions' => $isService ? (input_str($row, 'job_instructions', 2000) ?: null) : null,
            'service_date' => $isService ? (input_date($row, 'service_date') ?? date('Y-m-d')) : null, 'assigned_membership_id' => $assigned,
            'row_key' => preg_replace('/[^0-9]/', '', (string) $rowKey),
        ];
    }
    if (!$lines) {
        fail_user('Add at least one product or service to the order.');
    }
    if (count($lines) > 50) {
        fail_user('An order can have at most 50 lines.');
    }
    return [$lines, $fundId];
}

function insert_order_lines(int $institutionId, int $orderId, array $lines): array
{
    $ids = [];
    foreach ($lines as $line) {
        db_query(
            'INSERT INTO sales_order_items (institution_id, order_id, product_id, variant_id, description, fulfillment, pricing_unit, allow_partial_payment, quantity, unit_price, unit_cost,
                additional_charge, additional_charge_note, line_total, service_status, job_pages, job_copies, job_instructions, service_date, assigned_membership_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$institutionId, $orderId, $line['product_id'], $line['variant_id'], $line['description'], $line['fulfillment'], $line['pricing_unit'], $line['allow_partial_payment'],
             $line['quantity'], cents_to_decimal($line['unit_price']), cents_to_decimal($line['unit_cost']), cents_to_decimal($line['additional_charge']), $line['additional_charge_note'],
             cents_to_decimal($line['line_total']), $line['service_status'], $line['job_pages'], $line['job_copies'], $line['job_instructions'], $line['service_date'], $line['assigned_membership_id']]
        );
        $ids[] = (int) db()->lastInsertId();
    }
    return $ids;
}

/** Discount in cents from input, checked against authority and the subtotal. */
function order_discount_input(array $input, int $subtotal): array
{
    $text = input_str($input, 'discount_amount', 20);
    $discount = $text === '' ? 0 : (parse_money($text, true) ?? fail_user('Enter a valid discount amount.'));
    $reason = input_str($input, 'discount_reason', 255);
    if ($discount > 0) {
        if (!can('orders.discount')) {
            fail_user('Discounts need discount authority (orders.discount).');
        }
        if (mb_strlen($reason) < 5) {
            fail_user('Give the reason for the discount.');
        }
        if ($discount > $subtotal) {
            fail_user('The discount cannot exceed the order subtotal.');
        }
    }
    return [$discount, $discount > 0 ? $reason : null];
}

/** Creates an order (status Pending, Unpaid). Creating an order never records a payment. */
function create_order(array $input): int
{
    if (!can('orders.manage')) {
        fail_user('You do not have permission to create orders.');
    }
    $institutionId = (int) current_institution_id();
    [$lines, $fundId] = order_lines_input($input);
    $subtotal = array_sum(array_column($lines, 'line_total'));
    [$discount, $discountReason] = order_discount_input($input, $subtotal);
    $notes = input_str($input, 'notes', 2000) ?: null;
    $orderDate = input_date($input, 'order_date') ?? date('Y-m-d');
    if ($orderDate > date('Y-m-d')) {
        fail_user('The order date cannot be in the future.');
    }
    $userId = (int) auth_user()['id'];

    return db_transaction(function () use ($institutionId, $input, $lines, $fundId, $subtotal, $discount, $discountReason, $notes, $orderDate, $userId) {
        $customerId = input_str($input, 'customer_mode', 10) === 'walkin' ? add_walkin_customer($input) : (input_int($input, 'customer_participant_id') ?? 0);
        $customer = db_one("SELECT id, status FROM participants WHERE institution_id = ? AND id = ?", [$institutionId, $customerId]) ?? fail_user('Choose the customer.');
        if ($customer['status'] !== 'active') {
            fail_user('Orders can only be placed for active participants.');
        }
        $number = next_document_number($institutionId, 'sales_order', 'SO');
        db_query(
            "INSERT INTO sales_orders (institution_id, order_number, customer_participant_id, fund_id, order_date, order_status, payment_status, subtotal, discount_amount, discount_reason,
                discount_approved_by, total_amount, notes, created_by)
             VALUES (?, ?, ?, ?, ?, 'pending', 'unpaid', ?, ?, ?, ?, ?, ?, ?)",
            [$institutionId, $number, $customerId, $fundId, $orderDate, cents_to_decimal($subtotal), cents_to_decimal($discount), $discountReason,
             $discount > 0 ? $userId : null, cents_to_decimal($subtotal - $discount), $notes, $userId]
        );
        $orderId = (int) db()->lastInsertId();
        $itemIds = insert_order_lines($institutionId, $orderId, $lines);
        foreach ($itemIds as $index => $itemId) {
            $field = 'job_file_' . $lines[$index]['row_key'];
            if ($lines[$index]['fulfillment'] === 'service' && has_upload($field)) {
                store_attachment($field, 'order_item', $itemId);
            }
        }
        audit_log('order.created', 'sales_order', $orderId, $institutionId, $userId, [
            'order_number' => $number, 'customer_participant_id' => $customerId, 'subtotal' => cents_to_decimal($subtotal),
            'discount' => cents_to_decimal($discount), 'total' => cents_to_decimal($subtotal - $discount), 'lines' => count($lines),
        ], null, $discountReason);
        return $orderId;
    });
}

/** Applies or changes the discount of an open order; the total can never fall below what is already paid. */
function apply_order_discount(int $orderId, array $input): void
{
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $orderId, $input) {
        $order = lock_order($orderId);
        if (!in_array($order['order_status'], ORDER_OPEN_STATUSES, true)) {
            fail_user('Discounts can only change on open orders.');
        }
        $subtotal = to_cents($order['subtotal']);
        [$discount, $reason] = order_discount_input($input, $subtotal);
        if ($discount === 0 && !can('orders.discount')) {
            fail_user('Discounts need discount authority (orders.discount).');
        }
        if ($subtotal - $discount < order_paid_cents($orderId)) {
            fail_user('The discounted total would be less than what the customer already paid. Reverse or refund a payment first.');
        }
        db_query('UPDATE sales_orders SET discount_amount = ?, discount_reason = ?, discount_approved_by = ?, total_amount = ? WHERE id = ?',
            [cents_to_decimal($discount), $reason, $discount > 0 ? (int) auth_user()['id'] : null, cents_to_decimal($subtotal - $discount), $orderId]);
        audit_log('order.discount_changed', 'sales_order', $orderId, $institutionId, (int) auth_user()['id'],
            ['discount' => cents_to_decimal($discount), 'total' => cents_to_decimal($subtotal - $discount)], ['discount' => $order['discount_amount'], 'total' => $order['total_amount']], $reason);
        refresh_order_payment_status($orderId);
    });
}

/** Locks the order row and returns it. */
function lock_order(int $orderId): array
{
    return db_one('SELECT * FROM sales_orders WHERE institution_id = ? AND id = ? FOR UPDATE', [current_institution_id(), $orderId]) ?? fail_user('Order not found.');
}

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------

/** Payment status from posted payments only. */
function refresh_order_payment_status(int $orderId): void
{
    $institutionId = (int) current_institution_id();
    $total = to_cents(db_value('SELECT total_amount FROM sales_orders WHERE institution_id = ? AND id = ?', [$institutionId, $orderId]));
    $paid = order_paid_cents($orderId);
    $status = $paid <= 0 ? 'unpaid' : ($paid >= $total ? 'fully_paid' : 'partially_paid');
    db_query('UPDATE sales_orders SET payment_status = ? WHERE institution_id = ? AND id = ?', [$status, $institutionId, $orderId]);
}

/** Order lines that payments touched: refreshes every order a payment settled (used after reversals). */
function refresh_orders_for_payment(int $paymentId): void
{
    foreach (db_query('SELECT order_id FROM sales_order_payments WHERE institution_id = ? AND payment_id = ?', [current_institution_id(), $paymentId])->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
        refresh_order_payment_status((int) $orderId);
        audit_log('order.payment_reversed', 'sales_order', (int) $orderId, (int) current_institution_id(), (int) auth_user()['id'], ['payment_id' => $paymentId]);
    }
}

/** Released lines move the order to Partially Released / Completed. */
function refresh_order_release_status(int $orderId): void
{
    $institutionId = (int) current_institution_id();
    $row = db_one('SELECT SUM(quantity) AS qty, SUM(quantity_released) AS released FROM sales_order_items WHERE institution_id = ? AND order_id = ?', [$institutionId, $orderId]);
    if ((int) $row['released'] <= 0) {
        return;
    }
    $status = (int) $row['released'] >= (int) $row['qty'] ? 'completed' : 'partially_released';
    db_query('UPDATE sales_orders SET order_status = ? WHERE institution_id = ? AND id = ?', [$status, $institutionId, $orderId]);
}

/** Confirms a pending order. Stock lines are reserved now, so enough must be available. */
function confirm_order(int $orderId): void
{
    if (!can('orders.manage')) {
        fail_user('You do not have permission to confirm orders.');
    }
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $orderId) {
        $order = lock_order($orderId);
        if ($order['order_status'] !== 'pending') {
            fail_user('Only pending orders can be confirmed.');
        }
        $items = db_all('SELECT * FROM sales_order_items WHERE institution_id = ? AND order_id = ?', [$institutionId, $orderId]);
        $needed = [];
        foreach ($items as $item) {
            if ($item['fulfillment'] === 'stock') {
                $needed[(int) $item['variant_id']] = ($needed[(int) $item['variant_id']] ?? 0) + (int) $item['quantity'];
            }
        }
        foreach ($needed as $variantId => $quantity) {
            lock_variant_stock($variantId);
            $variant = find_variant($variantId);
            if ($variant['available'] < $quantity) {
                fail_user('Not enough ' . $variant['label'] . ' available: ' . max(0, $variant['available']) . ' left, ' . $quantity . ' ordered. Receive stock first or reduce the order.');
            }
        }
        $hasMade = (bool) array_filter($items, static fn($i) => $i['fulfillment'] === 'made_to_order');
        $inProduction = $hasMade && (bool) db_value(
            "SELECT 1 FROM stock_batches b JOIN sales_order_items i ON i.product_id = b.product_id AND i.institution_id = b.institution_id
             WHERE b.institution_id = ? AND i.order_id = ? AND i.fulfillment = 'made_to_order' AND b.status IN ('ordered', 'partially_received') LIMIT 1",
            [$institutionId, $orderId]
        );
        $status = $inProduction ? 'in_production' : 'confirmed';
        db_query('UPDATE sales_orders SET order_status = ? WHERE id = ?', [$status, $orderId]);
        audit_log('order.confirmed', 'sales_order', $orderId, $institutionId, (int) auth_user()['id'], ['order_status' => $status], ['order_status' => 'pending']);
    });
}

/** Marks a confirmed order ready: made-to-order items are set aside from received stock. */
function mark_order_ready(int $orderId): void
{
    if (!can('orders.manage')) {
        fail_user('You do not have permission to manage orders.');
    }
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $orderId) {
        $order = lock_order($orderId);
        if (!in_array($order['order_status'], ['confirmed', 'in_production'], true)) {
            fail_user('Only confirmed or in-production orders can be marked ready.');
        }
        $items = db_all('SELECT * FROM sales_order_items WHERE institution_id = ? AND order_id = ?', [$institutionId, $orderId]);
        foreach ($items as $item) {
            if ($item['fulfillment'] === 'service' && !in_array($item['service_status'], ['completed', 'released'], true)) {
                fail_user($item['description'] . ' is not completed yet.');
            }
        }
        $needed = [];
        foreach ($items as $item) {
            if ($item['fulfillment'] === 'made_to_order') {
                $needed[(int) $item['variant_id']] = ($needed[(int) $item['variant_id']] ?? 0) + (int) $item['quantity'] - (int) $item['quantity_released'];
            }
        }
        foreach ($needed as $variantId => $quantity) {
            lock_variant_stock($variantId);
            $variant = find_variant($variantId);
            if ($variant['available'] < $quantity) {
                fail_user('Only ' . max(0, $variant['available']) . ' ' . $variant['label'] . ' received and unassigned; this order needs ' . $quantity . '.');
            }
        }
        db_query("UPDATE sales_orders SET order_status = 'ready_for_release', ready_at = NOW() WHERE id = ?", [$orderId]);
        audit_log('order.ready', 'sales_order', $orderId, $institutionId, (int) auth_user()['id'], ['order_status' => 'ready_for_release'], ['order_status' => $order['order_status']]);
    });
}

/** Marks waiting orders for a product ready, oldest first, while received stock lasts. Returns the count. */
function allocate_ready_orders(int $productId): int
{
    if (!can('orders.manage')) {
        fail_user('You do not have permission to manage orders.');
    }
    $orders = db_query(
        "SELECT DISTINCT o.id FROM sales_orders o JOIN sales_order_items i ON i.order_id = o.id AND i.institution_id = o.institution_id
         WHERE o.institution_id = ? AND i.product_id = ? AND i.fulfillment = 'made_to_order' AND o.order_status IN ('confirmed', 'in_production')
         ORDER BY o.id",
        [current_institution_id(), $productId]
    )->fetchAll(PDO::FETCH_COLUMN);
    $count = 0;
    foreach ($orders as $orderId) {
        try {
            mark_order_ready((int) $orderId);
            $count++;
        } catch (UserFacingException) {
            if (db_in_transaction()) {
                db()->rollBack();
            }
        }
    }
    return $count;
}

/** Cancels an open order with a reason. Payments must be reversed first; released items block it. */
function cancel_order(int $orderId, string $reason): void
{
    if (!can('orders.cancel')) {
        fail_user('You do not have permission to cancel orders.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('Give the reason for cancelling.');
    }
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $orderId, $reason) {
        $order = lock_order($orderId);
        if (!in_array($order['order_status'], ['pending', 'confirmed', 'in_production', 'ready_for_release'], true)) {
            fail_user($order['order_status'] === 'cancelled' ? 'The order is already cancelled.' : 'Items were already released. Record returns to stock instead of cancelling.');
        }
        if (order_paid_cents($orderId) > 0) {
            fail_user('This order has posted payments. Reverse (refund) them first, so the cash and receipts stay traceable.');
        }
        db_query("UPDATE sales_orders SET order_status = 'cancelled', cancelled_reason = ? WHERE id = ?", [$reason, $orderId]);
        audit_log('order.cancelled', 'sales_order', $orderId, $institutionId, (int) auth_user()['id'], ['order_status' => 'cancelled'], ['order_status' => $order['order_status']], $reason);
    });
}

// ---------------------------------------------------------------------------
// Payments
// ---------------------------------------------------------------------------

/**
 * Records a payment for an order through ANINAW collections. Full payment unless every
 * line allows partial payments; never more than the balance.
 */
function record_order_payment(int $orderId, array $input): int
{
    if (!can('collections.record')) {
        fail_user('You do not have permission to receive payments.');
    }
    $institutionId = (int) current_institution_id();
    $method = input_enum($input, 'payment_method', array_keys(PAYMENT_METHODS)) ?? fail_user('Select the payment method.');
    $reference = input_str($input, 'external_reference', 150);
    if ($method !== 'cash' && $reference === '') {
        fail_user('Enter the transaction reference number for ' . PAYMENT_METHODS[$method] . ' payments.');
    }
    $date = input_date($input, 'payment_date') ?? fail_user('Enter the payment date.');
    if ($date > date('Y-m-d')) {
        fail_user('The payment date cannot be in the future.');
    }
    $paymentDate = $date . ' ' . ($date === date('Y-m-d') ? date('H:i:s') : '12:00:00');
    $amount = parse_money(input_str($input, 'amount', 20)) ?? fail_user('Enter the amount received.');

    return db_transaction(function () use ($institutionId, $orderId, $input, $method, $reference, $paymentDate, $date, $amount) {
        $order = lock_order($orderId);
        if (!in_array($order['order_status'], ORDER_OPEN_STATUSES, true) && $order['order_status'] !== 'completed') {
            fail_user('Payments cannot be recorded on a cancelled order.');
        }
        $full = find_order($orderId);
        $balance = $full['balance_cents'];
        if ($balance <= 0) {
            fail_user('This order is already fully paid.');
        }
        if ($amount > $balance) {
            fail_user('The amount is more than the balance of ' . money($balance, current_currency()) . '.');
        }
        if ($amount < $balance && !$full['partial_allowed']) {
            fail_user('This order must be paid in full (' . money($balance, current_currency()) . '). Partial payments are allowed only when every item allows them.');
        }
        $account = find_financial_account(input_int($input, 'financial_account_id') ?? 0);
        require_usable_account($account);
        if (!in_array($account['account_type'], PAYMENT_METHOD_ACCOUNT_TYPES[$method], true)) {
            fail_user(PAYMENT_METHODS[$method] . ' payments must be received into a ' . implode(' or ', array_map('humanize', PAYMENT_METHOD_ACCOUNT_TYPES[$method])) . ' account.');
        }
        $fundId = (int) $order['fund_id'];
        require_fund_account_mapping($fundId, (int) $account['id'], $date);

        // Revenue split between products and services in proportion to the order lines.
        $serviceTotal = array_sum(array_map(static fn($i) => $i['fulfillment'] === 'service' ? to_cents($i['line_total']) : 0, $full['items']));
        $subtotal = to_cents($order['subtotal']);
        $serviceShare = $subtotal > 0 ? intdiv($amount * $serviceTotal, $subtotal) : 0;
        $salesShare = $amount - $serviceShare;

        $paymentNumber = next_document_number($institutionId, 'payment', 'PAY');
        db_query(
            "INSERT INTO payments (institution_id, payment_number, payer_participant_id, received_by_membership_id, financial_account_id, payment_date, payment_method, amount, external_reference, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?)",
            [$institutionId, $paymentNumber, $order['customer_participant_id'], current_membership_id(), $account['id'], $paymentDate, $method, cents_to_decimal($amount), $reference ?: null, 'Order ' . $order['order_number']]
        );
        $paymentId = (int) db()->lastInsertId();
        db_query('INSERT INTO sales_order_payments (institution_id, order_id, payment_id, amount) VALUES (?, ?, ?, ?)', [$institutionId, $orderId, $paymentId, cents_to_decimal($amount)]);
        $receiptNumber = next_document_number($institutionId, 'receipt', 'AR');
        db_query("INSERT INTO receipts (institution_id, receipt_number, payment_id, issued_by_membership_id, issued_at, receipt_status) VALUES (?, ?, ?, ?, NOW(), 'issued')",
            [$institutionId, $receiptNumber, $paymentId, current_membership_id()]);

        $lines = [['fund_id' => $fundId, 'account' => cash_ledger_key($account['account_type']), 'financial_account_id' => (int) $account['id'], 'debit' => $amount]];
        if ($salesShare > 0) {
            $lines[] = ['fund_id' => $fundId, 'account' => 'sales', 'credit' => $salesShare, 'description' => 'Order ' . $order['order_number']];
        }
        if ($serviceShare > 0) {
            $lines[] = ['fund_id' => $fundId, 'account' => 'service_revenue', 'credit' => $serviceShare, 'description' => 'Order ' . $order['order_number']];
        }
        post_journal([
            'institution_id' => $institutionId,
            'date' => $date,
            'description' => 'Payment ' . $paymentNumber . ' (receipt ' . $receiptNumber . ') from ' . $full['customer_name'] . ' for order ' . $order['order_number'],
            'source_type' => 'payment',
            'source_id' => $paymentId,
            'cash_flow_type' => 'collection',
            'lines' => $lines,
        ]);
        refresh_order_payment_status($orderId);
        audit_log('payment.recorded', 'payment', $paymentId, $institutionId, (int) auth_user()['id'], [
            'payment_number' => $paymentNumber, 'receipt_number' => $receiptNumber, 'amount' => cents_to_decimal($amount), 'order_id' => $orderId,
            'participant_id' => (int) $order['customer_participant_id'], 'fund_id' => $fundId, 'financial_account_id' => (int) $account['id'], 'method' => $method,
        ]);
        audit_log('order.payment_recorded', 'sales_order', $orderId, $institutionId, (int) auth_user()['id'], ['payment_id' => $paymentId, 'amount' => cents_to_decimal($amount), 'balance_after' => cents_to_decimal($full['balance_cents'] - $amount)]);
        return $paymentId;
    });
}

// ---------------------------------------------------------------------------
// Release and service jobs
// ---------------------------------------------------------------------------

/**
 * Releases items to the customer. The order must be fully paid. Made-to-order items need
 * the order to be ready (set aside); stock items were reserved at confirmation; services
 * must be completed. Physical items leave stock.
 */
function release_order_items(int $orderId, array $input): int
{
    if (!can('orders.manage')) {
        fail_user('You do not have permission to release orders.');
    }
    $institutionId = (int) current_institution_id();
    $receivedBy = input_str($input, 'received_by_name', 180);
    if (mb_strlen($receivedBy) < 2) {
        fail_user('Enter the name of the person claiming the items.');
    }
    return db_transaction(function () use ($institutionId, $orderId, $input, $receivedBy) {
        $order = lock_order($orderId);
        if (!in_array($order['order_status'], ['confirmed', 'in_production', 'ready_for_release', 'partially_released'], true)) {
            fail_user($order['order_status'] === 'pending' ? 'Confirm the order before releasing items.' : 'Nothing can be released from a ' . humanize($order['order_status']) . ' order.');
        }
        if (order_paid_cents($orderId) < to_cents($order['total_amount'])) {
            fail_user('The order has an unpaid balance. Record the payment before releasing items.');
        }
        $released = 0;
        foreach (db_all('SELECT * FROM sales_order_items WHERE institution_id = ? AND order_id = ? FOR UPDATE', [$institutionId, $orderId]) as $item) {
            $quantity = (int) ($input['release'][$item['id']] ?? 0);
            if ($quantity === 0) {
                continue;
            }
            $left = (int) $item['quantity'] - (int) $item['quantity_released'];
            if ($quantity < 0 || $quantity > $left) {
                fail_user('Release at most ' . $left . ' of ' . $item['description'] . '.');
            }
            $movementId = null;
            if ($item['fulfillment'] === 'service') {
                if ($item['service_status'] !== 'completed') {
                    fail_user($item['description'] . ' is not completed yet.');
                }
                $quantity = $left;
                db_query("UPDATE sales_order_items SET quantity_released = quantity, service_status = 'released' WHERE id = ?", [$item['id']]);
            } else {
                if ($item['fulfillment'] === 'made_to_order' && !in_array($order['order_status'], ['ready_for_release', 'partially_released'], true)) {
                    fail_user('Mark the order ready (shirts received and set aside) before releasing ' . $item['description'] . '.');
                }
                $movementId = record_movement((int) $item['variant_id'], 'released', -$quantity, date('Y-m-d'), 'Released to ' . $receivedBy . ' for ' . $order['order_number'], ['order_item_id' => (int) $item['id']]);
                db_query('UPDATE sales_order_items SET quantity_released = quantity_released + ? WHERE id = ?', [$quantity, $item['id']]);
            }
            db_query('INSERT INTO sales_order_releases (institution_id, order_id, order_item_id, quantity, received_by_name, released_by, released_at, movement_id) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)',
                [$institutionId, $orderId, $item['id'], $quantity, $receivedBy, (int) auth_user()['id'], $movementId]);
            $released += $quantity;
        }
        if ($released === 0) {
            fail_user('Enter the quantities to release.');
        }
        refresh_order_release_status($orderId);
        audit_log('order.released', 'sales_order', $orderId, $institutionId, (int) auth_user()['id'], ['quantity' => $released, 'received_by' => $receivedBy]);
        return $released;
    });
}

/** Moves a service job along; completing it deducts the supplies its consumption rules name. */
function update_service_job(int $itemId, string $status, ?int $assignedMembershipId): void
{
    if (!can('orders.manage')) {
        fail_user('You do not have permission to manage service jobs.');
    }
    if (!in_array($status, ['in_progress', 'completed'], true)) {
        fail_user('Unknown job status.');
    }
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $itemId, $status, $assignedMembershipId) {
        $item = db_one('SELECT * FROM sales_order_items WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $itemId]) ?? fail_user('Job not found.');
        $order = lock_order((int) $item['order_id']);
        if ($item['fulfillment'] !== 'service') {
            fail_user('Only service lines have job statuses.');
        }
        if (!in_array($order['order_status'], ['confirmed', 'in_production', 'ready_for_release', 'partially_released'], true)) {
            fail_user($order['order_status'] === 'pending' ? 'Confirm the order before starting the job.' : 'This order is ' . humanize($order['order_status']) . '.');
        }
        $allowed = ['pending' => ['in_progress', 'completed'], 'in_progress' => ['completed']];
        if (!in_array($status, $allowed[$item['service_status']] ?? [], true)) {
            fail_user('The job is already ' . humanize((string) $item['service_status']) . '.');
        }
        if ($assignedMembershipId) {
            find_member($assignedMembershipId);
        }
        $consumed = [];
        if ($status === 'completed') {
            foreach (db_all("SELECT * FROM service_consumption_rules WHERE institution_id = ? AND service_variant_id = ? AND status = 'active'", [$institutionId, $item['variant_id']]) as $rule) {
                $quantity = (int) ceil((int) $item['quantity'] * (float) $rule['quantity_per_unit'] - 1e-9);
                if ($quantity > 0) {
                    record_movement((int) $rule['supply_variant_id'], 'consumed', -$quantity, date('Y-m-d'), 'Used for ' . $order['order_number'] . ' · ' . $item['description'], ['order_item_id' => $itemId]);
                    $consumed[(int) $rule['supply_variant_id']] = $quantity;
                }
            }
        }
        db_query('UPDATE sales_order_items SET service_status = ?, assigned_membership_id = COALESCE(?, assigned_membership_id), completed_at = ? WHERE id = ?',
            [$status, $assignedMembershipId, $status === 'completed' ? date('Y-m-d H:i:s') : null, $itemId]);
        audit_log('order.job_' . $status, 'sales_order', (int) $item['order_id'], $institutionId, (int) auth_user()['id'],
            ['item_id' => $itemId, 'service_status' => $status, 'consumed' => $consumed], ['service_status' => $item['service_status']]);
    });
}

// ---------------------------------------------------------------------------
// Production planning (made-to-order)
// ---------------------------------------------------------------------------

/**
 * Consolidated production list per made-to-order product and size: customer demand by
 * stage, on order with suppliers, on hand, and what still has to be ordered.
 */
function production_summary(?int $productId = null): array
{
    $institutionId = (int) current_institution_id();
    $params = [$institutionId];
    $filter = '';
    if ($productId) {
        $filter = ' AND p.id = ?';
        $params[] = $productId;
    }
    $rows = db_all(
        "SELECT p.id AS product_id, p.product_name, v.id AS variant_id, v.variant_name, v.sort_order,
                (SELECT COALESCE(SUM(i.quantity), 0) FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
                  WHERE i.institution_id = v.institution_id AND i.variant_id = v.id AND o.order_status = 'pending') AS pending_qty,
                (SELECT COALESCE(SUM(i.quantity - i.quantity_released), 0) FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
                  WHERE i.institution_id = v.institution_id AND i.variant_id = v.id AND o.order_status IN ('confirmed', 'in_production')) AS to_fill,
                " . SQL_RESERVED . " AS ready_qty,
                (SELECT COALESCE(SUM(i.quantity_released), 0) FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
                  WHERE i.institution_id = v.institution_id AND i.variant_id = v.id AND o.order_status <> 'cancelled') AS released_qty,
                " . SQL_ON_HAND . " AS on_hand, " . SQL_ON_ORDER . " AS on_order
         FROM product_variants v JOIN products p ON p.id = v.product_id AND p.institution_id = v.institution_id
         WHERE v.institution_id = ? AND p.fulfillment = 'made_to_order' AND p.status = 'active'{$filter}
         ORDER BY p.product_name, v.sort_order, v.id",
        $params
    );
    foreach ($rows as &$row) {
        foreach (['pending_qty', 'to_fill', 'ready_qty', 'released_qty', 'on_hand', 'on_order'] as $key) {
            $row[$key] = (int) $row[$key];
        }
        $row['free_stock'] = $row['on_hand'] - $row['ready_qty'];
        $row['to_order'] = max(0, $row['to_fill'] - max(0, $row['free_stock']) - $row['on_order']);
    }
    unset($row);
    return $rows;
}
