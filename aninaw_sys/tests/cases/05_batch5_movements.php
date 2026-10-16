<?php
/** Batch 5: deposits, fund transfers, register and reconciliation. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/movements.php';

section('Batch 5: deposits');

test('a deposit is pending until a different person verifies it, then moves the balance between accounts', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $cashierBefore = cash_balance($institutionId, $fx['fund'], $fx['cashier_box']);
    $bankBefore = cash_balance($institutionId, $fx['fund'], $fx['bank']);
    $fundBefore = cash_balance($institutionId, $fx['fund'], null);
    $collected = fund_summary($fx['fund'])['collected'];
    assert_rejected(fn() => record_deposit(['fund_id' => (string) $fx['fund'], 'source_account_id' => (string) $fx['cashier_box'], 'destination_account_id' => (string) $fx['bank'], 'amount' => '999999', 'deposit_date' => date('Y-m-d'), 'reference_number' => 'X']), 'Insufficient');
    $deposit = record_deposit(['fund_id' => (string) $fx['fund'], 'source_account_id' => (string) $fx['cashier_box'], 'destination_account_id' => (string) $fx['bank'], 'amount' => '100', 'deposit_date' => date('Y-m-d'), 'reference_number' => 'SLIP-001']);
    assert_same($cashierBefore, cash_balance($institutionId, $fx['fund'], $fx['cashier_box']), 'no change while pending');
    assert_rejected(fn() => confirm_deposit($deposit), 'someone other');
    act_as('treasurer2', 'TST');
    confirm_deposit($deposit);
    assert_same($cashierBefore - 10000, cash_balance($institutionId, $fx['fund'], $fx['cashier_box']));
    assert_same($bankBefore + 10000, cash_balance($institutionId, $fx['fund'], $fx['bank']));
    assert_same($fundBefore, cash_balance($institutionId, $fx['fund'], null), 'fund total unchanged');
    assert_same($collected, fund_summary($fx['fund'])['collected'], 'not income');
    assert_same(10000, fund_summary($fx['fund'])['deposited']);
    $GLOBALS['fx']['deposit'] = $deposit;
});

test('a confirmed deposit is reversed with a reason and remains on record', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $bankBefore = cash_balance((int) current_institution_id(), $fx['fund'], $fx['bank']);
    cancel_deposit($fx['deposit'], 'Bank returned the deposit');
    assert_same($bankBefore - 10000, cash_balance((int) current_institution_id(), $fx['fund'], $fx['bank']));
    assert_same('reversed', db_value('SELECT status FROM deposits WHERE id = ?', [$fx['deposit']]));
    assert_same(0, fund_summary($fx['fund'])['deposited'], 'deposited figure net of reversal');
});

section('Batch 5: fund transfers');

test('transfers need a mapped destination, block duplicates and self-approval', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $restricted = save_fund(null, ['fund_code' => 'RESTR', 'fund_name' => 'Restricted Donation', 'custodian_membership_id' => (string) $GLOBALS['members']['treasurer']]);
    set_fund_status($restricted, 'active', '');
    $base = ['from_fund_id' => (string) $fx['fund'], 'financial_account_id' => (string) $fx['cashier_box'], 'amount' => '50', 'transfer_date' => date('Y-m-d'), 'purpose' => 'Seed money for general operations per resolution 12'];
    assert_rejected(fn() => request_fund_transfer($base + ['to_fund_id' => (string) $restricted]), 'not authorised');
    $transfer = request_fund_transfer($base + ['to_fund_id' => (string) $fx['general']]);
    assert_rejected(fn() => request_fund_transfer($base + ['to_fund_id' => (string) $fx['general']]), 'identical transfer');
    assert_rejected(fn() => decide_fund_transfer($transfer, true, ''), 'transfer you requested');
    $GLOBALS['fx']['transfer'] = $transfer;
});

test('balances change only when an approved transfer is posted, without revenue or expense', function () {
    $fx = $GLOBALS['fx'];
    $institutionId = (int) db_value("SELECT id FROM institutions WHERE institution_code = 'TST'");
    $fundBefore = cash_balance($institutionId, $fx['fund'], null);
    $generalBefore = cash_balance($institutionId, $fx['general'], null);
    act_as('admin', 'TST');
    assert_true(can('transfers.approve'), 'admin approves transfers');
    decide_fund_transfer($fx['transfer'], true, 'Approved per resolution');
    assert_same($fundBefore, cash_balance($institutionId, $fx['fund'], null), 'approval alone changes nothing');
    act_as('treasurer', 'TST');
    post_fund_transfer($fx['transfer']);
    assert_same($fundBefore - 5000, cash_balance($institutionId, $fx['fund'], null));
    assert_same($generalBefore + 5000, cash_balance($institutionId, $fx['general'], null));
    $codes = db_query("SELECT DISTINCT l.account_code FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id WHERE j.source_type = 'fund_transfer' AND j.source_id = ?", [$fx['transfer']])->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['1010'], $codes, 'only cash lines: no income or expense');
    assert_rejected(fn() => post_fund_transfer($fx['transfer']), 'Only approved');
});

test('a posted transfer can be reversed with a reason', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $fundBefore = cash_balance((int) current_institution_id(), $fx['fund'], null);
    cancel_fund_transfer($fx['transfer'], 'Resolution rescinded');
    assert_same($fundBefore + 5000, cash_balance((int) current_institution_id(), $fx['fund'], null));
    assert_same('reversed', db_value('SELECT status FROM fund_transfers WHERE id = ?', [$fx['transfer']]));
});

test('read-only roles cannot move money', function () {
    act_as('auditor', 'TST');
    assert_rejected(fn() => record_deposit([]), 'permission');
    assert_rejected(fn() => request_fund_transfer([]), 'permission');
});

section('Batch 5: reconciliation');

test('ledger and register agree for every account', function () {
    act_as('treasurer', 'TST');
    foreach (reconciliation_accounts() as $row) {
        assert_same(to_cents($row['ledger_balance']), to_cents($row['register_balance']), 'account ' . $row['account_name']);
    }
    $unbalanced = db_value("SELECT COUNT(*) FROM (SELECT j.id FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id GROUP BY j.id HAVING SUM(l.debit_amount) <> SUM(l.credit_amount)) x");
    assert_same(0, (int) $unbalanced, 'every journal entry balances');
});

test('a physical count difference must be explained and does not edit balances', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $ledger = cash_balance((int) current_institution_id(), null, $fx['cashier_box']);
    assert_rejected(fn() => record_account_count($fx['cashier_box'], ['counted_amount' => cents_to_decimal($ledger + 100)]), 'Explain');
    $result = record_account_count($fx['cashier_box'], ['counted_amount' => cents_to_decimal($ledger + 100), 'notes' => 'Extra coin found in vault']);
    assert_same('1.00', $result['difference']);
    assert_same($ledger, cash_balance((int) current_institution_id(), null, $fx['cashier_box']), 'balance unchanged');
});
