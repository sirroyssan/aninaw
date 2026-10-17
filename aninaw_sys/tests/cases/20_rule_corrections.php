<?php
/** Correcting a collection amount after payments; refunding or applying the overpayment. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/payments.php';
require_once APP_ROOT . '/includes/services/ledger.php';

section('Correcting an amount after payments');

test('lowering a paid rule updates every charge and books what was overpaid as owed back', function () {
    $fx = $GLOBALS['fx'];
    act_as('admin', 'TST');
    $people = [];
    foreach (['A' => 'Full', 'B' => 'Twice', 'C' => 'Part', 'D' => 'None'] as $n => $last) {
        $people[$n] = save_participant(null, ['participant_number' => 'RC-' . $n, 'first_name' => 'Pat', 'last_name' => $last, 'participant_type' => 'student', 'status' => 'active', 'no_middle_name' => '1']);
    }
    act_as('organizer', 'TST');
    $eventId = save_event(null, ['event_code' => 'raitefix', 'event_name' => 'Assembly with a typo', 'collection_deadline' => date('Y-m-d', strtotime('+10 days'))]);
    add_event_fund($eventId, ['fund_id' => (string) $fx['fund']]);
    $reg = save_collection_rule($eventId, null, ['rule_name' => 'Registration Fee', 'amount_due' => '1750', 'applies_to_type' => 'all', 'allow_partial_payment' => '1']);
    $gas = save_collection_rule($eventId, null, ['rule_name' => 'Gas', 'amount_due' => '300', 'applies_to_type' => 'all', 'allow_partial_payment' => '1']);
    add_event_people($eventId, ['participant_ids' => array_map('strval', array_values($people))]);
    set_event_status($eventId, 'approved', '');
    set_event_status($eventId, 'collecting', '');

    act_as('cashier', 'TST');
    $ob = static fn(int $p, int $rule) => (int) db_value('SELECT id FROM event_obligations WHERE event_id = ? AND participant_id = ? AND collection_rule_id = ?', [$eventId, $p, $rule]);
    $pay = static fn(int $p, array $alloc) => record_payment(['participant_id' => (string) $p, 'event_id' => (string) $eventId, 'payment_method' => 'cash', 'financial_account_id' => (string) $fx['cashier_box'],
        'payment_date' => date('Y-m-d'), 'payment_time' => date('H:i'), 'alloc' => $alloc]);
    $payA = $pay($people['A'], [$ob($people['A'], $reg) => '1750']);
    $payB = $pay($people['B'], [$ob($people['B'], $reg) => '1750']);
    $pay($people['C'], [$ob($people['C'], $reg) => '1000']);
    act_as('organizer', 'TST');
    assert_rejected(fn() => save_collection_rule($eventId, $reg, ['rule_name' => 'Registration Fee', 'amount_due' => '1500', 'applies_to_type' => 'all', 'allow_partial_payment' => '1']), 'locked');

    act_as('cashier', 'TST');
    assert_rejected(fn() => correct_rule_amount($eventId, $reg, '1500', 'Typo in the amount'), 'creator or its assigned organizers');
    act_as('organizer', 'TST');
    assert_rejected(fn() => correct_rule_amount($eventId, $reg, '1800', 'Typo in the amount'), 'lower than');
    assert_rejected(fn() => correct_rule_amount($eventId, $reg, '1500', 'typo'), 'reason');
    $result = correct_rule_amount($eventId, $reg, '1,500', 'Registration is 1500; 1750 was entered by mistake');
    assert_same(['charges' => 4, 'overpaid_people' => 2, 'overpaid_total' => 50000], $result);
    assert_same('1500.00', db_value('SELECT amount_due FROM collection_rules WHERE id = ?', [$reg]));
    assert_same(['paid', 'partially_paid', 'unpaid'], [db_value('SELECT status FROM event_obligations WHERE id = ?', [$ob($people['A'], $reg)]),
        db_value('SELECT status FROM event_obligations WHERE id = ?', [$ob($people['C'], $reg)]), db_value('SELECT status FROM event_obligations WHERE id = ?', [$ob($people['D'], $reg)])]);
    assert_same(25000, obligation_overpaid_cents($ob($people['A'], $reg)));
    assert_same(2, count(event_overpayments($eventId)));
    assert_same(['50000', '50000'], [(string) to_cents(db_value("SELECT SUM(l.debit_amount) FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id WHERE j.source_type = 'rule_correction' AND l.account_code = '4000'")),
        (string) to_cents(db_value("SELECT SUM(l.credit_amount) FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id WHERE j.source_type = 'rule_correction' AND l.account_code = '2100'"))]);
    $GLOBALS['fx']['rc'] = compact('eventId', 'reg', 'gas', 'people', 'payA', 'payB') + ['ob' => $ob];
});

test('an overpayment is applied to another charge of the same person; the receipt still adds up', function () {
    $rc = $GLOBALS['fx']['rc'];
    act_as('cashier', 'TST');
    $from = ($rc['ob'])($rc['people']['A'], $rc['reg']);
    $gas = ($rc['ob'])($rc['people']['A'], $rc['gas']);
    assert_rejected(fn() => apply_overpayment($from, ['amount' => '300', 'to_obligation_id' => (string) $gas]), 'Only');
    assert_rejected(fn() => apply_overpayment($from, ['amount' => '250', 'to_obligation_id' => (string) ($rc['ob'])($rc['people']['B'], $rc['gas'])]), 'same event');
    $adjustment = apply_overpayment($from, ['amount' => '250', 'to_obligation_id' => (string) $gas]);
    assert_same(0, obligation_overpaid_cents($from));
    assert_same(['paid', 'partially_paid'], [db_value('SELECT status FROM event_obligations WHERE id = ?', [$from]), db_value('SELECT status FROM event_obligations WHERE id = ?', [$gas])]);
    assert_same(25000, obligation_paid_cents($gas));
    $payment = find_payment($rc['payA']);
    $allocated = array_sum(array_map(static fn($a) => to_cents($a['allocated_amount']), $payment['allocations']));
    assert_same(to_cents($payment['amount']), $allocated, 'original receipt total = its particulars (1,500 + 250)');
    assert_same('apply', $payment['adjustments'][0]['adjustment_type']);
    assert_true(str_starts_with((string) db_value('SELECT adjustment_number FROM payment_adjustments WHERE id = ?', [$adjustment]), 'ADJ-'), 'ADJ number');
});

test('an overpayment is refunded in cash with a slip; that payment can no longer be reversed; the books balance', function () {
    $fx = $GLOBALS['fx'];
    $rc = $GLOBALS['fx']['rc'];
    $from = ($rc['ob'])($rc['people']['B'], $rc['reg']);
    act_as('cashier', 'TST');
    assert_rejected(fn() => refund_overpayment($from, ['amount' => '250', 'financial_account_id' => (string) $fx['cashier_box'], 'reason' => 'Overpaid registration']), 'treasurer');
    act_as('treasurer', 'TST');
    $cashBefore = cash_balance(current_institution_id(), $fx['fund'], $fx['cashier_box']);
    assert_rejected(fn() => refund_overpayment($from, ['amount' => '250', 'financial_account_id' => (string) $fx['cashier_box'], 'reason' => 'no']), 'reason');
    $refund = refund_overpayment($from, ['amount' => '250', 'financial_account_id' => (string) $fx['cashier_box'], 'reason' => 'Overpaid registration, returned in cash']);
    assert_same($cashBefore - 25000, cash_balance(current_institution_id(), $fx['fund'], $fx['cashier_box']), 'cash left the box');
    assert_same('paid', db_value('SELECT status FROM event_obligations WHERE id = ?', [$from]));
    assert_same(0, obligation_overpaid_cents($from));
    $slip = find_adjustment($refund);
    assert_true(str_starts_with($slip['adjustment_number'], 'RF-') && $slip['receipts'] !== null, 'refund slip names the original receipt');
    assert_rejected(fn() => refund_overpayment($from, ['amount' => '1', 'financial_account_id' => (string) $fx['cashier_box'], 'reason' => 'Again please']), 'not overpaid');
    assert_rejected(fn() => reverse_payment($rc['payB'], 'Trying to reverse after a refund'), 'cannot be reversed');
    assert_same(0, (int) db_value("SELECT COALESCE(SUM(l.credit_amount - l.debit_amount), 0) * 100 FROM journal_lines l JOIN journal_entries j ON j.id = l.journal_entry_id WHERE l.account_code = '2100' AND j.status = 'posted'"), 'nothing left owed back');
    foreach (ledger_integrity_checks() as $check) {
        assert_same([], $check['rows'], $check['title']);
    }
});
