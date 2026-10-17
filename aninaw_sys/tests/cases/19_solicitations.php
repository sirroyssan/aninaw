<?php
/** Solicitations: donations received now, pledges received later, reversal, cancellation. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/payments.php';

section('Solicitations');

test('a donation received now gets a receipt and is booked as Donations & Solicitations income of the fund', function () {
    $fx = $GLOBALS['fx'];
    act_as('cashier', 'TST');
    $cashBefore = cash_balance(current_institution_id(), $fx['fund'], $fx['cashier_box']);
    $result = create_donation(['mode' => 'received', 'event_id' => (string) $fx['event'], 'fund_id' => (string) $fx['fund'], 'donor_kind' => 'company', 'donor_org_name' => 'abc hardware',
        'purpose' => 'Sponsorship for transportation', 'amount' => '5,000', 'payment_method' => 'cash', 'financial_account_id' => (string) $fx['cashier_box'], 'payment_date' => date('Y-m-d'), 'payment_time' => date('H:i')]);
    $donation = find_donation($result['donation_id']);
    assert_same('received', $donation['status']);
    assert_same('ABC HARDWARE', $donation['donor_name'], 'company name only, in capitals');
    assert_same('other', db_value('SELECT participant_type FROM participants WHERE id = ?', [$donation['donor_participant_id']]));
    assert_same(500000, $donation['received_cents']);
    assert_same($cashBefore + 500000, cash_balance(current_institution_id(), $fx['fund'], $fx['cashier_box']), 'cash in the cashier\'s box for the fund');
    $payment = find_payment($result['payment_id']);
    assert_true($payment['receipt_number'] !== null, 'official receipt issued');
    assert_same($donation['donation_number'], $payment['donation']['donation_number'], 'receipt shows the solicitation');
    assert_same('5000.00', db_value("SELECT l.credit_amount FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id WHERE j.source_type = 'payment' AND j.source_id = ? AND l.account_code = '4300'", [$result['payment_id']]));
    assert_same((int) $fx['fund'], (int) db_value("SELECT l.fund_id FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id WHERE j.source_type = 'payment' AND j.source_id = ? AND l.account_code = '4300'", [$result['payment_id']]));
});

test('a pledge is received in parts, cannot be over-received, and re-opens when a payment is reversed', function () {
    $fx = $GLOBALS['fx'];
    act_as('cashier', 'TST');
    $pledge = create_donation(['mode' => 'pledge', 'fund_id' => (string) $fx['fund'], 'donor_kind' => 'alumni', 'donor_first_name' => 'Ana', 'donor_last_name' => 'Alumna', 'amount' => '1000', 'expected_date' => date('Y-m-d', strtotime('+7 days'))]);
    assert_same(null, $pledge['payment_id'], 'nothing posted for a pledge');
    $id = $pledge['donation_id'];
    assert_same('pledged', find_donation($id)['status']);
    $pay = static fn(string $amount) => receive_donation($id, ['amount' => $amount, 'payment_method' => 'cash', 'financial_account_id' => (string) $fx['cashier_box'], 'payment_date' => date('Y-m-d')]);
    $first = $pay('400');
    assert_same('partially_received', find_donation($id)['status']);
    assert_rejected(fn() => $pay('700'), 'more than');
    $pay('600');
    $donation = find_donation($id);
    assert_same('received', $donation['status']);
    assert_same(0, $donation['outstanding_cents']);
    assert_rejected(fn() => $pay('1'), 'fully received');

    act_as('treasurer', 'TST');
    reverse_payment($first, 'Wrong donor recorded');
    assert_same('partially_received', find_donation($id)['status'], 'reversal re-opens the pledge');
    assert_same(40000, find_donation($id)['outstanding_cents']);
});

test('pledges are cancelled with a reason, or closed keeping what was received', function () {
    $fx = $GLOBALS['fx'];
    act_as('cashier', 'TST');
    $empty = create_donation(['mode' => 'pledge', 'fund_id' => (string) $fx['fund'], 'donor_kind' => 'parent', 'donor_first_name' => 'Pia', 'donor_last_name' => 'Parent', 'amount' => '300'])['donation_id'];
    assert_rejected(fn() => cancel_donation($empty, 'no'), 'reason');
    cancel_donation($empty, 'Donor withdrew');
    assert_same('cancelled', find_donation($empty)['status']);
    assert_rejected(fn() => receive_donation($empty, ['amount' => '10', 'payment_method' => 'cash', 'financial_account_id' => (string) $fx['cashier_box'], 'payment_date' => date('Y-m-d')]), 'cancelled');

    $partial = create_donation(['mode' => 'pledge', 'fund_id' => (string) $fx['fund'], 'donor_participant_id' => (string) $fx['p1'], 'amount' => '500'])['donation_id'];
    receive_donation($partial, ['amount' => '200', 'payment_method' => 'cash', 'financial_account_id' => (string) $fx['cashier_box'], 'payment_date' => date('Y-m-d')]);
    cancel_donation($partial, 'Could only give part');
    $donation = find_donation($partial);
    assert_same(['received', '200.00'], [$donation['status'], $donation['pledged_amount']], 'closed at what was received');
});

test('solicitations need permission and are limited to the institution', function () {
    $fx = $GLOBALS['fx'];
    act_as('student', 'TST');
    assert_rejected(fn() => create_donation(['mode' => 'pledge', 'fund_id' => (string) $fx['fund'], 'donor_first_name' => 'X', 'donor_last_name' => 'Y', 'amount' => '10']), 'permission');
    act_as('other_treasurer', 'OTH');
    assert_same(null, find_donation((int) db_value('SELECT MIN(id) FROM donations')));
});
