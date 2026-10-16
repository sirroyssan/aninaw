<?php
/** Batch 4: cash turnovers, custody, acknowledgments and discrepancies. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/turnovers.php';

section('Batch 4: cash turnovers');

function fx_assignment(): int
{
    return (int) db_value('SELECT id FROM event_group_assignments WHERE event_id = ? AND group_id = ?', [$GLOBALS['fx']['event'], $GLOBALS['fx']['groupId']]);
}

test('a collector submits an authorised turnover of their own payments', function () {
    $fx = $GLOBALS['fx'];
    act_as('collector', 'TST');
    $eligible = array_column(turnover_eligible_payments($fx['event'], $fx['groupId']), 'id');
    sort($eligible);
    $expected = $fx['p1_payments'];
    sort($expected);
    assert_same($expected, array_map('intval', $eligible), 'exactly the collector\'s three posted payments');
    $turnover = submit_turnover(['assignment_id' => (string) fx_assignment(), 'payment_ids' => array_map('strval', $fx['p1_payments']), 'declared_amount' => '150']);
    assert_same('submitted', db_value('SELECT status FROM cash_turnovers WHERE id = ?', [$turnover]));
    assert_same((int) $GLOBALS['members']['cashier'], (int) db_value('SELECT received_by_membership_id FROM cash_turnovers WHERE id = ?', [$turnover]), 'receiver comes from the assignment');
    $GLOBALS['fx']['turnover'] = $turnover;
});

test('the same payment cannot be turned over twice', function () {
    $fx = $GLOBALS['fx'];
    act_as('collector', 'TST');
    assert_rejected(fn() => submit_turnover(['assignment_id' => (string) fx_assignment(), 'payment_ids' => [(string) $fx['p1_payments'][0]], 'declared_amount' => '50']), 'already in another turnover');
});

test('only the assigned cashier may count, and only the sender may acknowledge', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    assert_rejected(fn() => record_turnover_count($fx['turnover'], ['received_amount' => '150', 'destination_account_id' => (string) $fx['treasurer_box']]), 'assigned receiving cashier');
    act_as('cashier', 'TST');
    assert_rejected(fn() => record_turnover_count($fx['turnover'], ['received_amount' => '150', 'destination_account_id' => (string) $fx['treasurer_box']]), 'custody account you hold');
    record_turnover_count($fx['turnover'], ['received_amount' => '150', 'destination_account_id' => (string) $fx['cashier_box']]);
    assert_rejected(fn() => acknowledge_turnover($fx['turnover'], true, ''), 'Only the collector');
});

test('acknowledgment moves custody without counting the money again as income', function () {
    $fx = $GLOBALS['fx'];
    act_as('collector', 'TST');
    $institutionId = (int) current_institution_id();
    $collectedBefore = fund_summary($fx['fund'])['collected'];
    $collectorBefore = cash_balance($institutionId, $fx['fund'], $fx['collector_box']);
    $cashierBefore = cash_balance($institutionId, $fx['fund'], $fx['cashier_box']);
    $fundBefore = cash_balance($institutionId, $fx['fund'], null);
    acknowledge_turnover($fx['turnover'], true, 'Counted together');
    assert_same('verified', db_value('SELECT status FROM cash_turnovers WHERE id = ?', [$fx['turnover']]));
    assert_same($collectorBefore - 15000, cash_balance($institutionId, $fx['fund'], $fx['collector_box']));
    assert_same($cashierBefore + 15000, cash_balance($institutionId, $fx['fund'], $fx['cashier_box']));
    assert_same($fundBefore, cash_balance($institutionId, $fx['fund'], null), 'fund total unchanged');
    $summary = fund_summary($fx['fund']);
    assert_same($collectedBefore, $summary['collected'], 'collections not double counted');
    assert_same(15000, $summary['turned_over']);
    assert_same(0, (int) db_value("SELECT COUNT(*) FROM cash_flow_transactions WHERE source_type = 'turnover' AND source_id = ? AND status = 'pending'", [$fx['turnover']]));
    assert_same(2, (int) db_value("SELECT COUNT(*) FROM turnover_acknowledgments WHERE turnover_id = ?", [$fx['turnover']]), 'both parties acknowledged');
});

test('shortages are recorded as discrepancies; declared and received stay distinct', function () {
    $fx = $GLOBALS['fx'];
    $shirt = fx_obligation($fx['p1'], 'shirt_rule');
    $payment = fx_pay('collector', $fx['p1'], [$shirt => '300']);
    act_as('collector', 'TST');
    $turnover = submit_turnover(['assignment_id' => (string) fx_assignment(), 'payment_ids' => [(string) $payment], 'declared_amount' => '300']);
    act_as('cashier', 'TST');
    assert_rejected(fn() => record_turnover_count($turnover, ['received_amount' => '280', 'destination_account_id' => (string) $fx['cashier_box']]), 'Describe the difference');
    record_turnover_count($turnover, ['received_amount' => '280', 'destination_account_id' => (string) $fx['cashier_box'], 'count_notes' => 'Two ₱10 coins missing']);
    act_as('collector', 'TST');
    acknowledge_turnover($turnover, true, 'Agree with count');
    $row = db_one('SELECT * FROM cash_turnovers WHERE id = ?', [$turnover]);
    assert_same('discrepancy', $row['status']);
    assert_same('300.00', $row['declared_amount'], 'declared amount kept');
    assert_same('280.00', $row['received_amount'], 'received amount kept');
    $discrepancy = db_one('SELECT * FROM turnover_discrepancies WHERE turnover_id = ?', [$turnover]);
    assert_same(['300.00', '280.00', '-20.00', 'open'], [$discrepancy['expected_amount'], $discrepancy['actual_amount'], $discrepancy['difference_amount'], $discrepancy['status']]);
    assert_same(2000, cash_balance((int) current_institution_id(), $fx['fund'], $fx['collector_box']), 'the unaccounted ₱20 stays on the collector\'s books');
    $GLOBALS['fx'] += ['discrepancy' => (int) $discrepancy['id'], 'turnover2' => $turnover];
});

test('discrepancies are resolved only by an authorised third person, with a ledger entry', function () {
    $fx = $GLOBALS['fx'];
    act_as('cashier', 'TST');
    assert_rejected(fn() => resolve_discrepancy($fx['discrepancy'], 'write_off', 'Cashier writes off own count'), 'permission');
    act_as('auditor', 'TST');
    assert_rejected(fn() => resolve_discrepancy($fx['discrepancy'], 'write_off', 'Auditor is read only'), 'permission');
    act_as('treasurer', 'TST');
    assert_rejected(fn() => resolve_discrepancy($fx['discrepancy'], 'write_off', 'short'), '10 characters');
    resolve_discrepancy($fx['discrepancy'], 'write_off', 'Approved write-off of ₱20 coin shortage by finance committee');
    assert_same('accepted_exception', db_value('SELECT status FROM turnover_discrepancies WHERE id = ?', [$fx['discrepancy']]));
    assert_same(0, cash_balance((int) current_institution_id(), $fx['fund'], $fx['collector_box']));
    assert_same(2000, ledger_key_balance((int) current_institution_id(), 'cash_shortage', $fx['fund']));
    assert_rejected(fn() => resolve_discrepancy($fx['discrepancy'], 'settled', 'Second resolution attempt'), 'already');
});

test('a disputed count goes back for recount; cancelling frees the payments', function () use ($members) {
    $fx = $GLOBALS['fx'];
    act_as('admin', 'TST');
    $p5 = save_participant(null, ['participant_number' => '2026-005', 'first_name' => 'Eli', 'last_name' => 'Yap']);
    add_group_members($fx['groupId'], [$p5], '', '');
    act_as('organizer', 'TST');
    generate_obligations($fx['event']);
    $payment = fx_pay('collector', $p5, [(int) db_value('SELECT id FROM event_obligations WHERE participant_id = ? AND collection_rule_id = ?', [$p5, $fx['rule']]) => '150']);
    act_as('collector', 'TST');
    $turnover = submit_turnover(['assignment_id' => (string) fx_assignment(), 'payment_ids' => [(string) $payment], 'declared_amount' => '150']);
    act_as('cashier', 'TST');
    record_turnover_count($turnover, ['received_amount' => '100', 'destination_account_id' => (string) $fx['cashier_box'], 'count_notes' => 'Counted 100 only']);
    act_as('collector', 'TST');
    acknowledge_turnover($turnover, false, 'I handed over 150, please recount');
    assert_same('submitted', db_value('SELECT status FROM cash_turnovers WHERE id = ?', [$turnover]));
    assert_same(null, db_value('SELECT received_amount FROM cash_turnovers WHERE id = ?', [$turnover]));
    assert_same(0, (int) db_value("SELECT COUNT(*) FROM cash_flow_transactions WHERE source_type = 'turnover' AND source_id = ? AND status = 'pending'", [$turnover]));
    cancel_turnover($turnover, 'Will resubmit tomorrow');
    assert_same([$payment], array_map('intval', array_column(turnover_eligible_payments($fx['event'], $fx['groupId']), 'id')), 'payment available again');
});

test('payments inside a turnover cannot be reversed', function () {
    act_as('treasurer', 'TST');
    assert_rejected(fn() => reverse_payment($GLOBALS['fx']['p1_payments'][0], 'Wrong student'), 'part of turnover');
});
