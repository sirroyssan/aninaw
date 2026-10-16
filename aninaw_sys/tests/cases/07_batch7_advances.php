<?php
/** Batch 7: cash advances and liquidation (the spec's ₱10,000 example). */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/advances.php';

section('Batch 7: cash advances');

/** Simulates a recipient's expense line with an uploaded receipt (CLI tests cannot upload files). */
function fx_advance_expense(int $advanceId, string $amount, string $receiptContent): int
{
    db_query("INSERT INTO cash_advance_expenses (institution_id, cash_advance_id, expense_date, category, description, amount, verification_status) VALUES (?, ?, CURDATE(), 'Supplies', 'Event supplies', ?, 'pending')",
        [current_institution_id(), $advanceId, $amount]);
    $expenseId = (int) db()->lastInsertId();
    db_query('UPDATE cash_advance_expenses SET receipt_attachment_id = ? WHERE id = ?', [fixture_document('advance_expense', $expenseId, $receiptContent), $expenseId]);
    return $expenseId;
}

function fx_new_advance(string $amount): int
{
    act_as('organizer', 'TST');
    $id = request_advance(['fund_id' => (string) $GLOBALS['fx']['general'], 'requested_amount' => $amount, 'purpose' => 'Supplies and venue for the outreach program']);
    act_as('treasurer', 'TST');
    decide_advance($id, true, []);
    release_advance($id, ['financial_account_id' => (string) $GLOBALS['fx']['treasurer_box']]);
    return $id;
}

test('requests need approval by someone else; rejections need a reason', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $mapping = (int) db_value('SELECT id FROM fund_account_mappings WHERE fund_id = ? AND financial_account_id = ?', [$fx['general'], $fx['treasurer_box']]);
    post_opening_balance($fx['general'], ['financial_account_id' => (string) $fx['treasurer_box'], 'amount' => '20000', 'opening_date' => date('Y-m-d'), 'note' => 'General fund cash count']);
    act_as('organizer', 'TST');
    $advance = request_advance(['fund_id' => (string) $fx['general'], 'requested_amount' => '10,000', 'purpose' => 'Outreach program supplies and transport']);
    assert_rejected(fn() => decide_advance($advance, true, []), 'permission');
    act_as('treasurer', 'TST');
    assert_rejected(fn() => decide_advance($advance, true, ['approved_amount' => '12000']), 'cannot exceed');
    decide_advance($advance, true, ['approved_amount' => '10000']);
    $rejected = (function () {
        act_as('organizer', 'TST');
        return request_advance(['fund_id' => (string) $GLOBALS['fx']['general'], 'requested_amount' => '500', 'purpose' => 'Snacks for a meeting that was cancelled']);
    })();
    act_as('treasurer', 'TST');
    assert_rejected(fn() => decide_advance($rejected, false, []), 'reason');
    decide_advance($rejected, false, ['reason' => 'Not a valid activity']);
    assert_same('cancelled', db_value('SELECT status FROM cash_advances WHERE id = ?', [$rejected]));
    $GLOBALS['fx']['advance'] = $advance;
});

test('release creates an outstanding advance (a receivable, not an expense)', function () {
    $fx = $GLOBALS['fx'];
    act_as('organizer', 'TST');
    assert_rejected(fn() => release_advance($fx['advance'], ['financial_account_id' => (string) $fx['treasurer_box']]), 'permission');
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $spentBefore = fund_summary($fx['general'])['spent'];
    release_advance($fx['advance'], ['financial_account_id' => (string) $fx['treasurer_box']]);
    assert_same(1000000, ledger_key_balance($institutionId, 'advances', $fx['general']));
    assert_same($spentBefore, fund_summary($fx['general'])['spent'], 'no expense yet');
    assert_same(2000000, fund_summary($fx['general'])['total'], 'fund still owns the money');
    assert_same('released', db_value('SELECT status FROM cash_advances WHERE id = ?', [$fx['advance']]));
});

test('unverified expenses and unconfirmed returns do not reduce the outstanding balance', function () {
    $fx = $GLOBALS['fx'];
    act_as('organizer', 'TST');
    assert_rejected(fn() => save_advance_expense($fx['advance'], null, ['amount' => '100', 'expense_date' => date('Y-m-d'), 'category' => 'Supplies', 'description' => 'Paper']), 'Attach the receipt');
    $expense = fx_advance_expense($fx['advance'], '7500.00', 'advance-receipt-1');
    assert_rejected(fn() => record_advance_return($fx['advance'], ['returned_amount' => '20000', 'financial_account_id' => (string) $fx['treasurer_box']]), 'at most');
    $return = record_advance_return($fx['advance'], ['returned_amount' => '2500', 'financial_account_id' => (string) $fx['treasurer_box']]);
    $figures = advance_figures($fx['advance']);
    assert_same(1000000, $figures['outstanding_cents'], 'still the full advance');
    submit_liquidation($fx['advance']);
    $GLOBALS['fx'] += ['advance_expense' => $expense, 'advance_return' => $return];
});

test('verified expenses and confirmed returns liquidate the advance exactly (₱10,000 − ₱7,500 − ₱2,500 = ₱0)', function () {
    $fx = $GLOBALS['fx'];
    act_as('organizer', 'TST');
    assert_rejected(fn() => review_advance_expense($fx['advance_expense'], 'verified', ''), 'permission');
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    review_advance_expense($fx['advance_expense'], 'verified', '');
    assert_same(250000, advance_figures($fx['advance'])['outstanding_cents']);
    assert_rejected(fn() => complete_liquidation_review($fx['advance']), 'Decide every');
    assert_rejected(fn() => review_advance_expense($fx['advance_expense'], 'verified', ''), 'already verified');
    confirm_advance_return($fx['advance_return'], true, '');
    $figures = advance_figures($fx['advance']);
    assert_same([0, 750000, 250000], [$figures['outstanding_cents'], $figures['verified_cents'], $figures['returned_cents']]);
    assert_same('liquidated', db_value('SELECT status FROM cash_advances WHERE id = ?', [$fx['advance']]));
    assert_same(0, ledger_key_balance($institutionId, 'advances', $fx['general']));
    assert_same(750000, fund_summary($fx['general'])['spent'], 'only the verified expense is spending');
});

test('the same receipt cannot be liquidated twice', function () {
    act_as('organizer', 'TST');
    assert_true(find_duplicate_document(hash('sha256', 'advance-receipt-1')) !== null, 'duplicate receipt detected');
    assert_same(1, (int) db_value("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'advance_expense' AND source_id = ?", [$GLOBALS['fx']['advance_expense']]), 'posted once');
});

test('an advance cannot be marked liquidated while an unexplained balance remains', function () {
    $advance = fx_new_advance('1000');
    act_as('organizer', 'TST');
    fx_advance_expense($advance, '600.00', 'advance-receipt-2');
    submit_liquidation($advance);
    act_as('treasurer', 'TST');
    $expense = (int) db_value('SELECT id FROM cash_advance_expenses WHERE cash_advance_id = ?', [$advance]);
    review_advance_expense($expense, 'verified', '');
    assert_rejected(fn() => complete_liquidation_review($advance), 'unexplained balance');
    assert_same('submitted_for_review', db_value('SELECT status FROM cash_advances WHERE id = ?', [$advance]));
    return_liquidation($advance, 'Return the remaining ₱400 or add receipts');
    assert_same('returned_for_correction', db_value('SELECT status FROM cash_advances WHERE id = ?', [$advance]));
});

test('expenses above the advance are recorded as an excess for review, not paid automatically', function () {
    $advance = fx_new_advance('1000');
    act_as('organizer', 'TST');
    fx_advance_expense($advance, '1200.00', 'advance-receipt-3');
    submit_liquidation($advance);
    act_as('treasurer', 'TST');
    $cashBefore = cash_balance((int) current_institution_id(), $GLOBALS['fx']['general'], null);
    review_advance_expense((int) db_value('SELECT id FROM cash_advance_expenses WHERE cash_advance_id = ?', [$advance]), 'verified', '');
    $figures = advance_figures($advance);
    assert_same([0, 20000], [$figures['outstanding_cents'], $figures['excess_cents']]);
    assert_same('liquidated', db_value('SELECT status FROM cash_advances WHERE id = ?', [$advance]));
    assert_same($cashBefore, cash_balance((int) current_institution_id(), $GLOBALS['fx']['general'], null), 'no automatic reimbursement');
});

test('recipients cannot review or confirm their own liquidation', function () {
    act_as('organizer', 'TST');
    $state = &auth_state();
    $state['permissions']['liquidation.review'] = true;
    assert_rejected(fn() => review_advance_expense((int) db_value('SELECT MAX(id) FROM cash_advance_expenses'), 'verified', ''), 'own liquidation');
});
