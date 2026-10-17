<?php
/** Changing the authorised period of a fund-account mapping. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/funds.php';

section('Funds: authorised period dates');

test('a mapping period can be changed but never so that recorded money falls outside it', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $mapping = (int) db_value('SELECT id FROM fund_account_mappings WHERE fund_id = ? AND financial_account_id = ? AND valid_until IS NULL', [$fx['fund'], $fx['cashier_box']]);
    $first = db_value('SELECT MIN(j.journal_date) FROM journal_lines l JOIN journal_entries j ON j.id = l.journal_entry_id WHERE l.fund_id = ? AND l.financial_account_id = ?', [$fx['fund'], $fx['cashier_box']]);
    assert_true($first !== null, 'the cashier box holds money of this fund');

    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-30 days')), 'valid_until' => date('Y-m-d', strtotime($first . ' -1 day'))]), 'Transactions of this fund were recorded');
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime($first . ' +1 day'))]), 'must still include');
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d'), 'valid_until' => date('Y-m-d', strtotime('-1 day'))]), 'before the start');

    update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-60 days')), 'valid_until' => date('Y-m-d', strtotime('+30 days'))]);
    assert_same([date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('+30 days'))], array_values(db_one('SELECT valid_from, valid_until FROM fund_account_mappings WHERE id = ?', [$mapping])));
    require_fund_account_mapping($fx['fund'], $fx['cashier_box'], date('Y-m-d', strtotime('-45 days')));

    // A later period of the same account may not be overlapped.
    add_fund_mapping($fx['fund'], ['financial_account_id' => (string) $fx['cashier_box'], 'valid_from' => date('Y-m-d', strtotime('+40 days'))]);
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-60 days')), 'valid_until' => '']), 'overlap');
    update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-60 days')), 'valid_until' => date('Y-m-d', strtotime('+39 days'))]);
    assert_true((bool) db_value("SELECT 1 FROM audit_logs WHERE action = 'fund.mapping_dates_changed' AND before_data LIKE '%valid_from%'"), 'old and new dates are audited');

    act_as('cashier', 'TST');
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-61 days'))]), 'permission');
    act_as('other_treasurer', 'OTH');
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-61 days'))]), 'not found');
});

test('an order from last month is recorded with its own date once the cash box period covers it', function () {
    require_once APP_ROOT . '/includes/services/orders.php';
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $box = save_financial_account(null, ['account_name' => 'Shirt Counter Box', 'account_type' => 'cash_custody', 'custodian_membership_id' => (string) $GLOBALS['members']['counter']]);
    add_fund_mapping($fx['general'], ['financial_account_id' => (string) $box, 'valid_from' => date('Y-m-d')]);
    $lastMonth = date('Y-m-d', strtotime('first day of last month +9 days'));
    $m = (int) db_value("SELECT id FROM product_variants WHERE product_id = ? AND variant_name = 'M'", [$fx['shirt']]);
    $input = ['customer_participant_id' => (string) $fx['p2'], 'order_date' => $lastMonth, 'lines' => [['variant_id' => (string) $m, 'quantity' => '1']],
        'pay_mode' => 'full', 'confirm_now' => '1', 'payment_method' => 'cash', 'financial_account_id' => (string) $box];

    act_as('counter', 'TST');
    $orders = (int) db_value('SELECT COUNT(*) FROM sales_orders');
    assert_rejected(fn() => create_order_with_payment($input), 'Change dates');
    assert_same($orders, (int) db_value('SELECT COUNT(*) FROM sales_orders'), 'nothing saved');

    act_as('treasurer', 'TST');
    $mapping = (int) db_value('SELECT id FROM fund_account_mappings WHERE fund_id = ? AND financial_account_id = ? AND valid_until IS NULL', [$fx['general'], $box]);
    update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-01', strtotime('first day of last month')), 'valid_until' => '']);

    act_as('counter', 'TST');
    $result = create_order_with_payment($input);
    assert_same($lastMonth, db_value('SELECT order_date FROM sales_orders WHERE id = ?', [$result['order_id']]));
    assert_same($lastMonth, substr((string) db_value('SELECT payment_date FROM payments WHERE id = ?', [$result['payment_id']]), 0, 10), 'payment dated last month');
    assert_same($lastMonth, db_value("SELECT journal_date FROM journal_entries WHERE source_type = 'payment' AND source_id = ?", [$result['payment_id']]), 'ledger dated last month');
    assert_same('fully_paid', db_value('SELECT payment_status FROM sales_orders WHERE id = ?', [$result['order_id']]));
});

test('counter sale: an in-stock item paid in full is handed over and deducted in one step', function () {
    $fx = $GLOBALS['fx'];
    $box = (int) db_value("SELECT id FROM financial_accounts WHERE account_name = 'Shirt Counter Box'");
    $paper = (int) db_value("SELECT id FROM product_variants WHERE product_id = ? AND variant_name = 'Standard'", [$fx['paper']]);
    $m = (int) db_value("SELECT id FROM product_variants WHERE product_id = ? AND variant_name = 'M'", [$fx['shirt']]);
    $pay = ['customer_participant_id' => (string) $fx['p2'], 'payment_method' => 'cash', 'financial_account_id' => (string) $box];

    act_as('counter', 'TST');
    $before = find_variant($paper)['on_hand'];
    $sale = create_order_with_payment($pay + ['lines' => [['variant_id' => (string) $paper, 'quantity' => '2']], 'pay_mode' => 'full', 'release_now' => '1']);
    $order = find_order($sale['order_id']);
    assert_same(['completed', 'fully_paid', 2], [$order['order_status'], $order['payment_status'], $sale['released']]);
    assert_same($before - 2, find_variant($paper)['on_hand'], 'stock deducted at the counter');
    assert_true((bool) $sale['payment_id'], 'receipt issued');

    $orders = (int) db_value('SELECT COUNT(*) FROM sales_orders');
    assert_rejected(fn() => create_order_with_payment($pay + ['lines' => [['variant_id' => (string) $m, 'quantity' => '1']], 'pay_mode' => 'down', 'pay_amount' => '100', 'release_now' => '1']), 'paid in full');
    assert_rejected(fn() => create_order_with_payment($pay + ['lines' => [['variant_id' => (string) $m, 'quantity' => '1']], 'pay_mode' => 'full', 'release_now' => '1']), 'Only items sold from stock');
    assert_same($orders, (int) db_value('SELECT COUNT(*) FROM sales_orders'), 'nothing saved when the hand-over is not possible');
});
