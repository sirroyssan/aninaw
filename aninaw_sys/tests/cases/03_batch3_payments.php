<?php
/** Batch 3: payments, partial payments, receipts, reversals. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/payments.php';

section('Batch 3: payments and receipts');

/** Helper: pay an obligation of the fixture event as the given user. */
function fx_pay(string $username, int $participantId, array $alloc, array $extra = []): int
{
    act_as($username, 'TST');
    $fx = $GLOBALS['fx'];
    $account = ['collector' => 'collector_box', 'cashier' => 'cashier_box', 'treasurer' => 'treasurer_box'][$username] ?? 'collector_box';
    return record_payment(array_merge([
        'participant_id' => (string) $participantId, 'event_id' => (string) $fx['event'], 'payment_method' => 'cash',
        'financial_account_id' => (string) $fx[$account], 'payment_date' => date('Y-m-d'), 'payment_time' => date('H:i'), 'alloc' => $alloc,
    ], $extra));
}

function fx_obligation(int $participantId, string $ruleKey = 'rule'): int
{
    return (int) db_value('SELECT id FROM event_obligations WHERE event_id = ? AND participant_id = ? AND collection_rule_id = ?',
        [$GLOBALS['fx']['event'], $participantId, $GLOBALS['fx'][$ruleKey]]);
}

test('three partial payments of ₱50 settle a ₱150 obligation exactly', function () {
    $fx = $GLOBALS['fx'];
    $obligation = fx_obligation($fx['p1']);
    $statuses = [];
    $payments = [];
    foreach ([1, 2, 3] as $i) {
        $payments[] = fx_pay('collector', $fx['p1'], [$obligation => '50']);
        $statuses[] = db_value('SELECT status FROM event_obligations WHERE id = ?', [$obligation]);
    }
    assert_same(['partially_paid', 'partially_paid', 'paid'], $statuses);
    act_as('collector', 'TST');
    assert_same(15000, obligation_paid_cents($obligation));
    $receipts = db_query('SELECT receipt_number FROM receipts WHERE payment_id IN (' . implode(',', $payments) . ')')->fetchAll(PDO::FETCH_COLUMN);
    assert_same(3, count(array_unique($receipts)), 'unique receipt numbers');
    assert_same(15000, cash_balance(current_institution_id(), $fx['fund'], $fx['collector_box']), 'cash sits in the collector\'s custody');
    $GLOBALS['fx']['p1_payments'] = $payments;
});

test('a full payment clears the balance and each payment posts one balanced entry', function () {
    $fx = $GLOBALS['fx'];
    $obligation = fx_obligation($fx['p2']);
    $paymentId = fx_pay('cashier', $fx['p2'], [$obligation => '150']);
    assert_same('paid', db_value('SELECT status FROM event_obligations WHERE id = ?', [$obligation]));
    $sums = db_one("SELECT SUM(l.debit_amount) d, SUM(l.credit_amount) c FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id WHERE j.source_type = 'payment' AND j.source_id = ?", [$paymentId]);
    assert_same('150.00', $sums['d']);
    assert_same($sums['d'], $sums['c']);
    assert_same(1, (int) db_value("SELECT COUNT(*) FROM cash_flow_transactions WHERE source_type = 'payment' AND source_id = ? AND transaction_type = 'collection' AND direction = 'inflow'", [$paymentId]));
    $GLOBALS['fx']['p2_payment'] = $paymentId;
});

test('overpayment and paying a settled obligation again are refused', function () {
    $fx = $GLOBALS['fx'];
    $special = fx_obligation($fx['p2'], 'special_rule'); // ₱60
    assert_rejected(fn() => fx_pay('cashier', $fx['p2'], [$special => '60.01']), 'Overpayment');
    assert_rejected(fn() => fx_pay('cashier', $fx['p2'], [fx_obligation($fx['p2']) => '1']), 'already Paid');
    $before = (int) db_value('SELECT COUNT(*) FROM payments');
    assert_same($before, (int) db_value('SELECT COUNT(*) FROM payments'), 'nothing saved');
});

test('full-payment-only rules reject partial amounts', function () {
    $fx = $GLOBALS['fx'];
    act_as('organizer', 'TST');
    $rule = save_collection_rule($fx['event'], null, ['rule_name' => 'Shirt', 'amount_due' => '300', 'applies_to_type' => 'participant', 'applies_to_participant' => (string) $fx['p1']]);
    generate_obligations($fx['event']);
    $GLOBALS['fx']['shirt_rule'] = $rule;
    $obligation = fx_obligation($fx['p1'], 'shirt_rule');
    assert_rejected(fn() => fx_pay('collector', $fx['p1'], [$obligation => '100']), 'paid in full');
});

test('accounts must be the recorder\'s own and match the payment method', function () {
    $fx = $GLOBALS['fx'];
    $obligation = fx_obligation($fx['p2'], 'special_rule');
    assert_rejected(fn() => fx_pay('collector', $fx['p2'], [$obligation => '10'], ['financial_account_id' => (string) $fx['treasurer_box']]), 'not the custodian');
    assert_rejected(fn() => fx_pay('treasurer', $fx['p2'], [$obligation => '10'], ['financial_account_id' => (string) $fx['bank']]), 'cash custody');
    assert_rejected(fn() => fx_pay('treasurer', $fx['p2'], [$obligation => '10'], ['financial_account_id' => (string) $fx['bank'], 'payment_method' => 'bank_transfer']), 'reference');
    $id = fx_pay('treasurer', $fx['p2'], [$obligation => '60'], ['financial_account_id' => (string) $fx['bank'], 'payment_method' => 'bank_transfer', 'external_reference' => 'BPI-99812']);
    assert_same('posted', db_value('SELECT status FROM payments WHERE id = ?', [$id]));
});

test('collectors cannot record payments for participants outside their groups', function () {
    $fx = $GLOBALS['fx'];
    act_as('organizer', 'TST');
    $rule = save_collection_rule($fx['event'], null, ['rule_name' => 'Outside fee', 'amount_due' => '20', 'applies_to_type' => 'participant', 'applies_to_participant' => (string) $fx['p4']]);
    generate_obligations($fx['event']);
    $obligation = (int) db_value('SELECT id FROM event_obligations WHERE collection_rule_id = ?', [$rule]);
    assert_rejected(fn() => fx_pay('collector', $fx['p4'], [$obligation => '20']), 'not in your groups');
});

test('users without permission cannot record or reverse payments', function () {
    act_as('auditor', 'TST');
    assert_rejected(fn() => record_payment([]), 'permission');
    assert_rejected(fn() => reverse_payment($GLOBALS['fx']['p2_payment'], 'Should not work'), 'permission');
    act_as('collector', 'TST');
    assert_rejected(fn() => reverse_payment($GLOBALS['fx']['p2_payment'], 'Should not work'), 'permission');
});

test('reversal voids the receipt, restores the obligation and is traceable', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $paymentId = $fx['p2_payment'];
    $cashBefore = cash_balance(current_institution_id(), $fx['fund'], $fx['cashier_box']);
    assert_rejected(fn() => reverse_payment($paymentId, 'x'), 'reason');
    reverse_payment($paymentId, 'Recorded against the wrong student');
    assert_same('reversed', db_value('SELECT status FROM payments WHERE id = ?', [$paymentId]));
    assert_same('voided', db_value('SELECT receipt_status FROM receipts WHERE payment_id = ?', [$paymentId]));
    assert_same('unpaid', db_value('SELECT status FROM event_obligations WHERE id = ?', [fx_obligation($fx['p2'])]));
    assert_same($cashBefore - 15000, cash_balance(current_institution_id(), $fx['fund'], $fx['cashier_box']));
    assert_same(2, (int) db_value("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'payment' AND source_id = ?", [$paymentId]), 'original + reversal kept');
    assert_rejected(fn() => reverse_payment($paymentId, 'Again please'), 'Only posted');
    // Receipt numbers are never reused.
    $newPayment = fx_pay('cashier', $fx['p2'], [fx_obligation($fx['p2']) => '150']);
    $numbers = db_query('SELECT receipt_number FROM receipts WHERE payment_id IN (?, ?)', [$paymentId, $newPayment])->fetchAll(PDO::FETCH_COLUMN);
    assert_same(2, count(array_unique($numbers)));
});

test('collected totals exclude reversed payments', function () {
    act_as('treasurer', 'TST');
    $summary = fund_summary($GLOBALS['fx']['fund']);
    $posted = to_cents(db_value("SELECT SUM(amount) FROM payments WHERE status = 'posted'"));
    assert_same($posted, $summary['collected']);
});

test('amounts in words', function () {
    assert_same('One Hundred Fifty Pesos and 00/100', amount_in_words(15000));
    assert_same('One Thousand Two Hundred Fifty-Five Pesos and 50/100', amount_in_words(125550));
});
