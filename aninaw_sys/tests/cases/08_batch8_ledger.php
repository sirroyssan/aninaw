<?php
/** Batch 8: ledger integrity, adjusting entries (maker-checker), reversals, trial balance, isolation. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/ledger.php';

section('Batch 8: ledger');

/** Lines for an adjusting entry; amounts are strings as typed in the form. */
function fx_adjustment(array $lines, string $description = 'Reclassify supplies recorded under the wrong account'): array
{
    return ['journal_date' => date('Y-m-d'), 'description' => $description, 'lines' => $lines];
}

test('after batches 1–7 every integrity check passes: the ledger agrees with every module', function () {
    act_as('treasurer', 'TST');
    $failed = array_filter(ledger_integrity_checks(), static fn($check) => $check['rows']);
    assert_same([], array_map(static fn($check) => $check['title'] . ': ' . json_encode($check['rows']), $failed));
});

test('the trial balance balances and its cash equals the cash balances', function () {
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $rows = trial_balance();
    assert_same(array_sum(array_column($rows, 'debit_balance')), array_sum(array_column($rows, 'credit_balance')));
    $cashCodes = cash_account_codes($institutionId);
    $cash = 0;
    foreach ($rows as $row) {
        if (in_array($row['account_code'], $cashCodes, true)) {
            $cash += $row['debit_balance'] - $row['credit_balance'];
        }
    }
    assert_same(cash_balance($institutionId, null, null), $cash);
    $general = trial_balance($GLOBALS['fx']['general']);
    assert_same(array_sum(array_column($general, 'debit_balance')), array_sum(array_column($general, 'credit_balance')), 'each fund balances on its own');
});

test('adjusting entries are validated: balanced per fund, one side per line, proper cash accounts, a reason', function () {
    $fx = $GLOBALS['fx'];
    act_as('auditor', 'TST');
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([])), 'permission');
    act_as('treasurer', 'TST');
    $g = (string) $fx['general'];
    $f = (string) $fx['fund'];
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'expenses', 'debit' => '50'], ['fund_id' => $g, 'account' => 'cash_shortage', 'credit' => '40'],
    ])), 'do not balance');
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'expenses', 'debit' => '50'], ['fund_id' => $f, 'account' => 'cash_shortage', 'credit' => '50'],
    ])), 'Each fund must balance');
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'expenses', 'debit' => '50', 'credit' => '50'], ['fund_id' => $g, 'account' => 'cash_shortage', 'credit' => '50'],
    ])), 'not both');
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'advances', 'debit' => '50'], ['fund_id' => $g, 'account' => 'fund_balance', 'credit' => '50'],
    ])), 'ledger account');
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'bank', 'financial_account_id' => (string) $fx['treasurer_box'], 'debit' => '50'], ['fund_id' => $g, 'account' => 'other_income', 'credit' => '50'],
    ])), 'is not a');
    $unmapped = save_financial_account(null, ['account_name' => 'Unmapped Pouch', 'account_type' => 'cash_custody', 'custodian_membership_id' => (string) $GLOBALS['members']['treasurer']]);
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'cash_custody', 'financial_account_id' => (string) $unmapped, 'debit' => '50'], ['fund_id' => $g, 'account' => 'other_income', 'credit' => '50'],
    ])), 'Map');
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'expenses', 'debit' => '50'], ['fund_id' => $g, 'account' => 'cash_shortage', 'credit' => '50'],
    ], 'fix')), 'reason');
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'expenses', 'debit' => '1.005'], ['fund_id' => $g, 'account' => 'cash_shortage', 'credit' => '1.005'],
    ])), 'valid debit');
});

test('a prepared entry changes nothing until a different officer posts it', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $g = (string) $fx['general'];
    $cashBefore = cash_balance($institutionId, $fx['general'], $fx['treasurer_box']);
    $incomeBefore = ledger_key_balance($institutionId, 'other_income', $fx['general']);
    $flowsBefore = (int) db_value("SELECT COUNT(*) FROM cash_flow_transactions WHERE institution_id = ? AND status = 'posted'", [$institutionId]);

    $entry = save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'cash_custody', 'financial_account_id' => (string) $fx['treasurer_box'], 'debit' => '25.50', 'description' => 'Overage found in count'],
        ['fund_id' => $g, 'account' => 'other_income', 'credit' => '25.50'],
    ], 'Cash count overage per count sheet CS-0007'));
    assert_same('draft', db_value('SELECT status FROM journal_entries WHERE id = ?', [$entry]));
    assert_same($cashBefore, cash_balance($institutionId, $fx['general'], $fx['treasurer_box']), 'draft does not move cash');
    assert_same($incomeBefore, ledger_key_balance($institutionId, 'other_income', $fx['general']));
    assert_rejected(fn() => post_adjusting_entry($entry), 'another officer');

    act_as('auditor', 'TST');
    assert_rejected(fn() => post_adjusting_entry($entry), 'permission');

    act_as('treasurer2', 'TST');
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => $g, 'account' => 'expenses', 'debit' => '1'], ['fund_id' => $g, 'account' => 'cash_shortage', 'credit' => '1'],
    ]), $entry), 'Only the person');
    post_adjusting_entry($entry);
    assert_same(2550, cash_balance($institutionId, $fx['general'], $fx['treasurer_box']) - $cashBefore);
    assert_same(-2550, ledger_key_balance($institutionId, 'other_income', $fx['general']) - $incomeBefore, 'income is credit-normal');
    assert_same($flowsBefore + 1, (int) db_value("SELECT COUNT(*) FROM cash_flow_transactions WHERE institution_id = ? AND status = 'posted'", [$institutionId]), 'register row added');
    assert_same('adjustment', db_value("SELECT transaction_type FROM cash_flow_transactions WHERE institution_id = ? AND source_type = 'adjustment' AND source_id = ?", [$institutionId, $entry]));
    assert_rejected(fn() => post_adjusting_entry($entry), 'not waiting');
    assert_true((bool) db_value("SELECT 1 FROM audit_logs WHERE entity_type = 'journal_entry' AND entity_id = ? AND action = 'journal.posted'", [$entry]), 'posting audited');
    assert_same([], array_filter(ledger_integrity_checks(), static fn($check) => $check['rows']), 'still consistent');
    $GLOBALS['fx']['adjustment'] = $entry;
});

test('posting re-checks available cash so an adjustment cannot overdraw an account', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $available = cash_balance($institutionId, $fx['general'], $fx['treasurer_box']);
    $entry = save_adjusting_draft(fx_adjustment([
        ['fund_id' => (string) $fx['general'], 'account' => 'cash_shortage', 'debit' => cents_to_decimal($available + 100)],
        ['fund_id' => (string) $fx['general'], 'account' => 'cash_custody', 'financial_account_id' => (string) $fx['treasurer_box'], 'credit' => cents_to_decimal($available + 100)],
    ], 'Shortage larger than the cash actually held'));
    act_as('treasurer2', 'TST');
    assert_rejected(fn() => post_adjusting_entry($entry), 'Insufficient');
    assert_rejected(fn() => discard_adjusting_draft($entry, ''), 'reason');
    discard_adjusting_draft($entry, 'Amount exceeds the cash on hand; recount first');
    assert_same(null, db_value('SELECT id FROM journal_entries WHERE id = ?', [$entry]), 'unposted draft removed');
    assert_true((bool) db_value("SELECT 1 FROM audit_logs WHERE entity_type = 'journal_entry' AND entity_id = ? AND action = 'journal.draft_declined'", [$entry]), 'decline audited with the lines');
});

test('posted entries are corrected by reversal, never edited or discarded', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $entry = $fx['adjustment'];
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => (string) $fx['general'], 'account' => 'expenses', 'debit' => '1'], ['fund_id' => (string) $fx['general'], 'account' => 'cash_shortage', 'credit' => '1'],
    ]), $entry), 'Only prepared');
    assert_rejected(fn() => discard_adjusting_draft($entry, 'remove it'), 'never posted');
    assert_rejected(fn() => reverse_adjusting_entry($entry, ''), 'reason');
    $cashBefore = cash_balance($institutionId, $fx['general'], $fx['treasurer_box']);
    $reversal = reverse_adjusting_entry($entry, 'Overage was a miscount, recounted');
    assert_same(-2550, cash_balance($institutionId, $fx['general'], $fx['treasurer_box']) - $cashBefore);
    assert_same('posted', db_value('SELECT status FROM journal_entries WHERE id = ?', [$entry]), 'original stays posted');
    assert_same($entry, (int) db_value('SELECT reversal_of_id FROM journal_entries WHERE id = ?', [$reversal]));
    assert_rejected(fn() => reverse_adjusting_entry($entry, 'again'), 'already been reversed');
    assert_rejected(fn() => reverse_adjusting_entry($reversal, 'reverse the reversal'), 'Only posted adjusting');
    $paymentEntry = (int) db_value("SELECT id FROM journal_entries WHERE institution_id = ? AND source_type = 'payment' LIMIT 1", [$institutionId]);
    assert_rejected(fn() => reverse_adjusting_entry($paymentEntry, 'not allowed here'), 'source document');
    assert_same([], array_filter(ledger_integrity_checks(), static fn($check) => $check['rows']), 'still consistent after reversal');
});

test('entries and adjustments are isolated per institution', function () {
    $entry = $GLOBALS['fx']['adjustment'];
    act_as('other_treasurer', 'OTH');
    assert_same(null, find_journal_entry($entry));
    assert_rejected(fn() => reverse_adjusting_entry($entry, 'cross-tenant attempt'), 'Only posted adjusting');
    assert_rejected(fn() => save_adjusting_draft(fx_adjustment([
        ['fund_id' => (string) $GLOBALS['fx']['general'], 'account' => 'expenses', 'debit' => '1'], ['fund_id' => (string) $GLOBALS['fx']['general'], 'account' => 'cash_shortage', 'credit' => '1'],
    ])), 'fund');
    assert_same([], trial_balance());
});

test('the integrity checks detect records that bypassed the ledger', function () {
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    // Simulate a corrupted import: an expense marked paid with no posting, inside a rolled-back transaction.
    db()->beginTransaction();
    try {
        db_query("UPDATE expenses SET status = 'paid' WHERE institution_id = ? AND status <> 'paid' LIMIT 1", [$institutionId]);
        db_query("INSERT INTO cash_flow_transactions (institution_id, transaction_number, fund_id, financial_account_id, transaction_date, transaction_type, direction, amount, source_type, source_id, status, recorded_by)
                  VALUES (?, 'CF-TEST', ?, ?, NOW(), 'adjustment', 'inflow', 1.00, 'adjustment', 0, 'posted', ?)",
            [$institutionId, $GLOBALS['fx']['general'], $GLOBALS['fx']['treasurer_box'], auth_user()['id']]);
        $checks = ledger_integrity_checks();
        assert_true((bool) $checks['expenses']['rows'], 'unposted paid expense flagged');
        assert_true((bool) $checks['cash_register']['rows'], 'register row without ledger line flagged');
    } finally {
        db()->rollBack();
    }
});
