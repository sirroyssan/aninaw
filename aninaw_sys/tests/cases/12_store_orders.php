<?php
/** Products & services module: catalogue, inventory, shirt orders, printing jobs, payments, cancellation. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/orders.php';
require_once APP_ROOT . '/includes/services/ledger.php';

section('Store: products, inventory, orders');

$GLOBALS['members']['orderstaff'] = fixture_member((int) db_value("SELECT id FROM institutions WHERE institution_code = 'TST'"), fixture_user('orderstaff', 'Oscar', 'Staff'), ['order_staff']);
$GLOBALS['members']['counter'] = fixture_member((int) db_value("SELECT id FROM institutions WHERE institution_code = 'TST'"), fixture_user('counter', 'Cara', 'Counter'), ['order_staff', 'cashier']);
$GLOBALS['members']['custodian'] = fixture_member((int) db_value("SELECT id FROM institutions WHERE institution_code = 'TST'"), fixture_user('custodian', 'Cody', 'Keeper'), ['inventory_custodian']);

function fx_variant(int $productId, string $name): int
{
    return (int) db_value('SELECT id FROM product_variants WHERE product_id = ? AND variant_name = ?', [$productId, $name]);
}

function fx_pay_order(int $orderId, string $amount): int
{
    act_as('cashier', 'TST');
    return record_order_payment($orderId, ['amount' => $amount, 'payment_method' => 'cash', 'payment_date' => date('Y-m-d'), 'financial_account_id' => (string) $GLOBALS['fx']['cashier_box']]);
}

test('only product managers add products; prices need price authority; SKUs are unique', function () {
    $fund = (string) $GLOBALS['fx']['general'];
    $shirt = ['sku' => 'SHIRT-26', 'product_name' => 'College Shirt 2026', 'product_type' => 'physical', 'fulfillment' => 'made_to_order', 'fund_id' => $fund,
        'unit_of_measure' => 'piece', 'unit_cost' => '370', 'retail_price' => '450', 'allow_partial_payment' => '1', 'variants' => 'S, M, L'];
    act_as('orderstaff', 'TST');
    assert_rejected(fn() => save_product(null, $shirt), 'permission');
    act_as('admin', 'TST');
    $category = save_category(null, ['category_name' => 'Merchandise']);
    $shirtId = save_product(null, $shirt + ['category_id' => (string) $category]);
    assert_same(3, count(product_variants($shirtId)));
    assert_rejected(fn() => save_product(null, $shirt), 'already uses');
    $paperId = save_product(null, ['sku' => 'PAPER-A4', 'product_name' => 'A4 Bond Paper', 'product_type' => 'physical', 'fulfillment' => 'stock', 'fund_id' => $fund,
        'unit_of_measure' => 'sheet', 'unit_cost' => '0.50', 'retail_price' => '1', 'reorder_level' => '20']);
    $printId = save_product(null, ['sku' => 'PRINT', 'product_name' => 'Printing', 'product_type' => 'service', 'fund_id' => $fund,
        'unit_of_measure' => 'page', 'unit_cost' => '1.50', 'retail_price' => '3', 'variants' => 'A4 B&W, A4 Colour']);
    assert_same('service', db_value('SELECT fulfillment FROM products WHERE id = ?', [$printId]), 'services are always on demand');
    save_consumption_rule(fx_variant($printId, 'A4 B&W'), ['supply_variant_id' => (string) fx_variant($paperId, 'Standard'), 'quantity_per_unit' => '1']);
    act_as('treasurer', 'TST');
    assert_rejected(fn() => save_product($shirtId, $shirt), 'permission');
    act_as('other_treasurer', 'OTH');
    assert_same(null, find_product($shirtId), 'other institutions cannot see it');
    $GLOBALS['fx'] += ['shirt' => $shirtId, 'paper' => $paperId, 'print' => $printId];
});

test('stock cannot go negative without an administrator exception; adjustments need a reason', function () {
    $paper = fx_variant($GLOBALS['fx']['paper'], 'Standard');
    act_as('custodian', 'TST');
    record_beginning_stock($paper, ['quantity' => '100', 'movement_date' => date('Y-m-d'), 'reason' => 'Count of the supply room']);
    assert_rejected(fn() => record_beginning_stock($paper, ['quantity' => '5', 'movement_date' => date('Y-m-d'), 'reason' => 'Again']), 'already recorded');
    assert_rejected(fn() => record_adjustment($paper, ['movement_type' => 'damaged', 'quantity' => '3', 'movement_date' => date('Y-m-d'), 'reason' => '']), 'reason');
    assert_rejected(fn() => record_adjustment($paper, ['movement_type' => 'lost', 'quantity' => '150', 'movement_date' => date('Y-m-d'), 'reason' => 'Ream missing after inventory']), 'Not enough stock');
    assert_rejected(fn() => record_adjustment($paper, ['movement_type' => 'lost', 'quantity' => '150', 'movement_date' => date('Y-m-d'), 'reason' => 'Ream missing after inventory', 'allow_negative' => '1']), 'override');
    record_adjustment($paper, ['movement_type' => 'adjustment', 'quantity' => '-4', 'movement_date' => date('Y-m-d'), 'reason' => 'Recount found 96 sheets']);
    assert_same(96, find_variant($paper)['on_hand']);
    $movement = db_one('SELECT * FROM inventory_movements WHERE variant_id = ? ORDER BY id DESC LIMIT 1', [$paper]);
    assert_same(-4, (int) $movement['quantity']);
    assert_same('Recount found 96 sheets', $movement['reason']);
    assert_true((int) $movement['recorded_by'] > 0, 'responsible user recorded');
});

test('a shirt order: snapshot prices, partial payment, production, release only when paid', function () {
    $fx = $GLOBALS['fx'];
    $m = fx_variant($fx['shirt'], 'M');
    $l = fx_variant($fx['shirt'], 'L');
    act_as('orderstaff', 'TST');
    $order = create_order(['customer_participant_id' => (string) $fx['p1'], 'lines' => [['variant_id' => (string) $m, 'quantity' => '2'], ['variant_id' => (string) $l, 'quantity' => '1']]]);
    $o = find_order($order);
    assert_same('pending', $o['order_status']);
    assert_same('unpaid', $o['payment_status'], 'creating an order is not a payment');
    assert_same('1350.00', $o['total_amount']);
    assert_same(24000, $o['expected_profit_cents'], '3 × (450 − 370)');

    act_as('admin', 'TST');
    save_product($fx['shirt'], ['sku' => 'SHIRT-26', 'product_name' => 'College Shirt 2026', 'product_type' => 'physical', 'fulfillment' => 'made_to_order',
        'fund_id' => (string) $fx['general'], 'unit_of_measure' => 'piece', 'unit_cost' => '380', 'retail_price' => '480', 'allow_partial_payment' => '1']);
    assert_same('450.00', db_value('SELECT unit_price FROM sales_order_items WHERE order_id = ? LIMIT 1', [$order]), 'price changes never touch existing orders');

    act_as('orderstaff', 'TST');
    assert_rejected(fn() => record_order_payment($order, ['amount' => '500', 'payment_method' => 'cash', 'payment_date' => date('Y-m-d')]), 'permission');
    assert_rejected(fn() => fx_pay_order($order, '2000'), 'more than the balance');
    fx_pay_order($order, '500');
    assert_same('partially_paid', db_value('SELECT payment_status FROM sales_orders WHERE id = ?', [$order]));

    act_as('orderstaff', 'TST');
    confirm_order($order);
    assert_same('confirmed', db_value('SELECT order_status FROM sales_orders WHERE id = ?', [$order]));
    act_as('custodian', 'TST');
    $batch = create_batch($fx['shirt'], ['supplier_name' => 'PrintHub', 'quantity' => [$m => '2', $l => '1']]);
    assert_same(0, find_variant($m)['on_hand'], 'ordered shirts are not stock');
    mark_batch_ordered($batch, []);
    assert_same('in_production', db_value('SELECT order_status FROM sales_orders WHERE id = ?', [$order]));
    $items = db_query('SELECT variant_id, id FROM stock_batch_items WHERE batch_id = ?', [$batch])->fetchAll(PDO::FETCH_KEY_PAIR);
    receive_batch($batch, ['received_date' => date('Y-m-d'), 'received' => [$items[$m] => '1']]);
    act_as('orderstaff', 'TST');
    assert_rejected(fn() => mark_order_ready($order), 'needs 2');
    act_as('custodian', 'TST');
    receive_batch($batch, ['received_date' => date('Y-m-d'), 'received' => [$items[$m] => '1', $items[$l] => '1']]);
    assert_same('received', db_value('SELECT status FROM stock_batches WHERE id = ?', [$batch]));
    act_as('orderstaff', 'TST');
    assert_same(1, allocate_ready_orders($fx['shirt']));
    assert_same(0, find_variant($m)['available'], 'set aside for the customer');
    assert_rejected(fn() => release_order_items($order, ['received_by_name' => 'Sam Garcia', 'release' => array_fill_keys(array_column(find_order($order)['items'], 'id'), 1)]), 'unpaid balance');
    fx_pay_order($order, '850');
    assert_same('fully_paid', db_value('SELECT payment_status FROM sales_orders WHERE id = ?', [$order]));
    act_as('orderstaff', 'TST');
    $lines = find_order($order)['items'];
    release_order_items($order, ['received_by_name' => 'Sam Garcia', 'release' => [$lines[0]['id'] => '1']]);
    assert_same('partially_released', db_value('SELECT order_status FROM sales_orders WHERE id = ?', [$order]));
    release_order_items($order, ['received_by_name' => 'Sam Garcia', 'release' => [$lines[0]['id'] => '1', $lines[1]['id'] => '1']]);
    assert_same('completed', db_value('SELECT order_status FROM sales_orders WHERE id = ?', [$order]));
    assert_same(0, find_variant($m)['on_hand'] + find_variant($l)['on_hand'], 'released shirts left stock');
    assert_rejected(fn() => release_order_items($order, ['received_by_name' => 'Sam Garcia', 'release' => [$lines[0]['id'] => '1']]), 'Nothing can be released');

    act_as('treasurer', 'TST');
    assert_same(-135000, ledger_key_balance((int) current_institution_id(), 'sales', $fx['general']), 'sales revenue credited ₱1,350');
    $GLOBALS['fx']['shirt_order'] = $order;
});

test('a printing job: pages × copies, full payment, supplies consumed on completion', function () {
    $fx = $GLOBALS['fx'];
    $bw = fx_variant($fx['print'], 'A4 B&W');
    $paper = fx_variant($fx['paper'], 'Standard');
    act_as('orderstaff', 'TST');
    assert_rejected(fn() => create_order(['customer_mode' => 'walkin', 'walkin_first_name' => 'Walk', 'walkin_last_name' => 'In',
        'lines' => [['variant_id' => (string) $bw, 'job_pages' => '10', 'job_copies' => '3', 'additional_charge' => '25']]]), 'describe the additional charge');
    $order = create_order(['customer_mode' => 'walkin', 'walkin_first_name' => 'Walk', 'walkin_last_name' => 'In', 'walkin_phone' => '0917',
        'lines' => [['variant_id' => (string) $bw, 'job_pages' => '10', 'job_copies' => '3', 'additional_charge' => '25', 'additional_charge_note' => 'Binding', 'job_instructions' => 'Staple per copy']]]);
    $o = find_order($order);
    assert_same('other', $o['participant_type'], 'walk-in registered as a participant');
    assert_same(30, (int) $o['items'][0]['quantity']);
    assert_same('115.00', $o['total_amount'], '30 × 3.00 + 25.00 binding');
    assert_same(false, $o['partial_allowed']);
    assert_rejected(fn() => update_service_job((int) $o['items'][0]['id'], 'in_progress', null), 'Confirm the order');
    confirm_order($order);
    assert_rejected(fn() => fx_pay_order($order, '50'), 'paid in full');
    fx_pay_order($order, '115');
    act_as('orderstaff', 'TST');
    update_service_job((int) $o['items'][0]['id'], 'in_progress', $GLOBALS['members']['orderstaff']);
    $before = find_variant($paper)['on_hand'];
    update_service_job((int) $o['items'][0]['id'], 'completed', null);
    assert_same($before - 30, find_variant($paper)['on_hand'], '30 sheets consumed by the rule');
    release_order_items($order, ['received_by_name' => 'Walk In', 'release' => [$o['items'][0]['id'] => '30']]);
    assert_same('completed', db_value('SELECT order_status FROM sales_orders WHERE id = ?', [$order]));
    assert_same('released', db_value('SELECT service_status FROM sales_order_items WHERE order_id = ?', [$order]));
    act_as('treasurer', 'TST');
    assert_same(-11500, ledger_key_balance((int) current_institution_id(), 'service_revenue', $fx['general']));
});

test('stock items are reserved at confirmation and cannot be oversold', function () {
    $fx = $GLOBALS['fx'];
    $paper = fx_variant($fx['paper'], 'Standard');
    act_as('orderstaff', 'TST');
    $available = find_variant($paper)['available'];
    $big = create_order(['customer_participant_id' => (string) $fx['p2'], 'lines' => [['variant_id' => (string) $paper, 'quantity' => (string) ($available + 1)]]]);
    assert_rejected(fn() => confirm_order($big), 'Not enough');
    $small = create_order(['customer_participant_id' => (string) $fx['p2'], 'lines' => [['variant_id' => (string) $paper, 'quantity' => '10']]]);
    confirm_order($small);
    assert_same($available - 10, find_variant($paper)['available']);
    assert_same($available, find_variant($paper)['on_hand'] - 0 - (find_variant($paper)['reserved'] - 10), 'on hand unchanged until release');
    $GLOBALS['fx']['paper_order'] = $small;
    $GLOBALS['fx']['big_order'] = $big;
});

test('discounts need authority and a reason, and never undercut what was paid', function () {
    $order = $GLOBALS['fx']['paper_order'];
    act_as('orderstaff', 'TST');
    assert_rejected(fn() => apply_order_discount($order, ['discount_amount' => '2', 'discount_reason' => 'Loyal customer']), 'discount authority');
    fx_pay_order($order, '10');
    act_as('admin', 'TST');
    assert_rejected(fn() => apply_order_discount($order, ['discount_amount' => '2', 'discount_reason' => '']), 'reason');
    assert_rejected(fn() => apply_order_discount($order, ['discount_amount' => '2', 'discount_reason' => 'Student council rate']), 'already paid');
});

test('cancelling: paid orders need their payment reversed first; reservations are freed', function () {
    $fx = $GLOBALS['fx'];
    $order = $fx['paper_order'];
    $paper = fx_variant($fx['paper'], 'Standard');
    act_as('orderstaff', 'TST');
    assert_rejected(fn() => cancel_order($order, 'Customer changed mind'), 'permission');
    act_as('admin', 'TST');
    assert_rejected(fn() => cancel_order($order, 'Customer changed mind'), 'Reverse (refund)');
    act_as('treasurer', 'TST');
    $paymentId = (int) db_value('SELECT payment_id FROM sales_order_payments WHERE order_id = ?', [$order]);
    reverse_payment($paymentId, 'Refunded: customer changed mind');
    assert_same('unpaid', db_value('SELECT payment_status FROM sales_orders WHERE id = ?', [$order]), 'reversal reopens the balance');
    assert_same('voided', db_value('SELECT receipt_status FROM receipts WHERE payment_id = ?', [$paymentId]));
    $reservedBefore = find_variant($paper)['reserved'];
    act_as('admin', 'TST');
    cancel_order($order, 'Customer changed mind');
    assert_same($reservedBefore - 10, find_variant($paper)['reserved'], 'reservation freed');
    assert_rejected(fn() => fx_pay_order($order, '10'), 'cancelled');
    act_as('admin', 'TST');
    assert_rejected(fn() => cancel_order($fx['shirt_order'], 'Too late'), 'already released');
});

test('payment with the order: full or down payment, confirmed in one step, all or nothing', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $box = save_financial_account(null, ['account_name' => 'Store Counter Box', 'account_type' => 'cash_custody', 'custodian_membership_id' => (string) $GLOBALS['members']['counter']]);
    add_fund_mapping($fx['general'], ['financial_account_id' => (string) $box, 'valid_from' => date('Y-m-d')]);
    $m = fx_variant($fx['shirt'], 'M');
    $paper = fx_variant($fx['paper'], 'Standard');
    $orders = static fn() => (int) db_value('SELECT COUNT(*) FROM sales_orders');
    $payments = static fn() => (int) db_value('SELECT COUNT(*) FROM payments');
    $pay = ['payment_method' => 'cash', 'financial_account_id' => (string) $box];

    act_as('orderstaff', 'TST');
    assert_rejected(fn() => create_order_with_payment(['customer_participant_id' => (string) $fx['p1'], 'lines' => [['variant_id' => (string) $m, 'quantity' => '1']], 'pay_mode' => 'full'] + $pay), 'cannot receive payments');
    $later = create_order_with_payment(['customer_participant_id' => (string) $fx['p1'], 'lines' => [['variant_id' => (string) $m, 'quantity' => '1']], 'pay_mode' => 'later', 'confirm_now' => '0']);
    assert_same(['pending', 'unpaid', null], [db_value('SELECT order_status FROM sales_orders WHERE id = ?', [$later['order_id']]), db_value('SELECT payment_status FROM sales_orders WHERE id = ?', [$later['order_id']]), $later['payment_id']]);

    act_as('counter', 'TST');
    $before = ledger_key_balance((int) current_institution_id(), 'sales', $fx['general']);
    $down = create_order_with_payment(['customer_participant_id' => (string) $fx['p2'], 'lines' => [['variant_id' => (string) $m, 'quantity' => '2']],
        'pay_mode' => 'down', 'pay_amount' => '300', 'confirm_now' => '1'] + $pay);
    $order = find_order($down['order_id']);
    assert_true(in_array($order['order_status'], ['confirmed', 'in_production'], true), 'confirmed together with the down payment');
    assert_same(['partially_paid', 30000, to_cents($order['total_amount']) - 30000], [$order['payment_status'], $order['paid_cents'], $order['balance_cents']]);
    assert_true((bool) db_value("SELECT 1 FROM receipts WHERE payment_id = ? AND receipt_status = 'issued'", [$down['payment_id']]), 'official receipt issued');
    assert_same($before - 30000, ledger_key_balance((int) current_institution_id(), 'sales', $fx['general']), 'down payment posted as sales revenue');
    assert_same(30000, cash_balance((int) current_institution_id(), $fx['general'], $box), 'cash is in the counter box');

    // Items that must be paid in full: no down payment, and nothing is saved.
    [$o, $p] = [$orders(), $payments()];
    assert_rejected(fn() => create_order_with_payment(['customer_participant_id' => (string) $fx['p2'], 'lines' => [['variant_id' => (string) $paper, 'quantity' => '2']],
        'pay_mode' => 'down', 'pay_amount' => '1', 'confirm_now' => '1'] + $pay), 'down payment is not allowed');
    // Confirmation fails (not enough stock): the order and the payment are both rolled back.
    $tooMany = find_variant($paper)['available'] + 1;
    assert_rejected(fn() => create_order_with_payment(['customer_participant_id' => (string) $fx['p2'], 'lines' => [['variant_id' => (string) $paper, 'quantity' => (string) $tooMany]],
        'pay_mode' => 'full', 'confirm_now' => '1'] + $pay));
    assert_same([$o, $p], [$orders(), $payments()], 'nothing saved when any step fails');

    $full = create_order_with_payment(['customer_participant_id' => (string) $fx['p2'], 'lines' => [['variant_id' => (string) $paper, 'quantity' => '3']], 'pay_mode' => 'full', 'confirm_now' => '1'] + $pay);
    assert_same(['confirmed', 'fully_paid'], [db_value('SELECT order_status FROM sales_orders WHERE id = ?', [$full['order_id']]), db_value('SELECT payment_status FROM sales_orders WHERE id = ?', [$full['order_id']])]);
    assert_same(0, find_order($full['order_id'])['balance_cents']);
});

test('store reports agree with stock, orders and posted payments', function () {
    require_once APP_ROOT . '/includes/reports.php';
    act_as('treasurer', 'TST');
    $fx = $GLOBALS['fx'];
    $values = ['from' => date('Y-01-01'), 'to' => date('Y-m-d'), 'fund' => $fx['general'], 'account' => $fx['cashier_box'], 'q' => 'a',
        'product' => $fx['shirt'], 'category' => (int) db_value('SELECT category_id FROM products WHERE id = ?', [$fx['shirt']]), 'variant' => fx_variant($fx['shirt'], 'M')];
    foreach (store_report_definitions() as $key => $definition) {
        foreach ($definition['filters'] as $filter) {
            fx_report($key, [$filter => $filter === 'status' ? array_key_first($definition['statuses']) : $values[$filter]]);
        }
    }

    foreach (fx_report('store_inventory') as $row) {
        $variant = find_variant((int) $row['id']);
        assert_same($variant['on_hand'], (int) $row['on_hand'], $row['sku'] . ' on hand');
        assert_same($variant['reserved'], (int) $row['reserved'], $row['sku'] . ' reserved');
    }
    $movements = fx_report('store_movements', ['variant' => fx_variant($fx['paper'], 'Standard')]);
    assert_same(find_variant(fx_variant($fx['paper'], 'Standard'))['on_hand'], array_sum(array_map('intval', array_column($movements, 'quantity'))), 'movements add up to stock on hand');

    $posted = (int) round((float) db_value("SELECT COALESCE(SUM(sop.amount), 0) * 100 FROM sales_order_payments sop JOIN payments p ON p.id = sop.payment_id WHERE p.status = 'posted' AND sop.institution_id = ?", [current_institution_id()]));
    assert_same($posted, fx_sum(fx_report('store_sales_collections', ['status' => 'posted']), 'amount'), 'collections = posted order payments');
    assert_same(-$posted, ledger_key_balance((int) current_institution_id(), 'sales') + ledger_key_balance((int) current_institution_id(), 'service_revenue'), 'store revenue in the ledger = posted order payments');

    $unpaid = array_column(fx_report('store_unpaid'), 'id');
    assert_true(!in_array($fx['shirt_order'], array_map('intval', $unpaid), true), 'fully paid orders are not listed as unpaid');

    $shirt = array_values(array_filter(fx_report('store_profit'), static fn($r) => (int) $r['id'] === $fx['shirt']))[0];
    $lines = db_one("SELECT SUM(i.line_total) AS sales, SUM(i.unit_cost * i.quantity) AS cost FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id
                     WHERE i.product_id = ? AND o.order_status <> 'cancelled' AND o.discount_amount = 0", [$fx['shirt']]);
    assert_same(to_cents($lines['sales']) - to_cents($lines['cost']), to_cents($shirt['expected_profit']), 'expected profit from line snapshots');
    assert_same(24000, to_cents($shirt['actual_profit']), 'actual profit on the three released shirts: 3 × (450 − 370)');

    act_as('other_treasurer', 'OTH');
    foreach (array_keys(store_report_definitions()) as $key) {
        assert_same([], fx_report($key), $key . ' shows nothing from another institution');
    }
});

test('orders stay inside the institution and the ledger still agrees with every module', function () {
    act_as('other_treasurer', 'OTH');
    assert_same(null, find_order($GLOBALS['fx']['shirt_order']));
    act_as('treasurer', 'TST');
    assert_same([], array_keys(array_filter(ledger_integrity_checks(), static fn($check) => $check['rows'])));
    $rows = trial_balance();
    assert_same(array_sum(array_column($rows, 'debit_balance')), array_sum(array_column($rows, 'credit_balance')));
});
