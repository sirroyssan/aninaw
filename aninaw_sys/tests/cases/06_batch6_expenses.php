<?php
/** Batch 6: expenses and approvals. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/expenses.php';

section('Batch 6: expenses');

function fx_expense(array $overrides = []): int
{
    $fx = $GLOBALS['fx'];
    return save_expense(null, array_merge([
        'fund_id' => (string) $fx['fund'], 'event_id' => (string) $fx['event'], 'expense_date' => date('Y-m-d'), 'payee' => 'Print Shop',
        'category' => 'Supplies', 'description' => 'Tarpaulin for ICS Fest', 'amount' => '30', 'payment_method' => 'cash', 'invoice_reference' => 'INV-001',
    ], $overrides));
}

function fx_attach_receipt(int $expenseId, string $content): void
{
    $attachment = fixture_document('expense', $expenseId, $content);
    db_query('UPDATE expenses SET receipt_attachment_id = ? WHERE id = ?', [$attachment, $expenseId]);
}

test('expenses need a receipt to be submitted, and pending expenses do not affect posted totals', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $cashBefore = cash_balance($institutionId, $fx['fund'], null);
    $spentBefore = fund_summary($fx['fund'])['spent'];
    $expense = fx_expense();
    assert_true(str_starts_with((string) db_value('SELECT description FROM expenses WHERE id = ?', [$expense]), 'Invoice/receipt no.: INV-001'), 'invoice reference kept');
    assert_rejected(fn() => submit_expense($expense), 'Attach the official receipt');
    fx_attach_receipt($expense, 'receipt-one');
    submit_expense($expense);
    assert_same($cashBefore, cash_balance($institutionId, $fx['fund'], null), 'submitted: no change');
    assert_same($spentBefore, fund_summary($fx['fund'])['spent']);
    $GLOBALS['fx']['expense'] = $expense;
});

test('approvers cannot approve their own expenses; unauthorised users cannot approve', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    assert_rejected(fn() => decide_expense($fx['expense'], true, ''), 'you recorded');
    act_as('cashier', 'TST');
    assert_rejected(fn() => decide_expense($fx['expense'], true, ''), 'permission');
    act_as('auditor', 'TST');
    assert_rejected(fn() => decide_expense($fx['expense'], true, ''), 'permission');
    act_as('treasurer2', 'TST');
    decide_expense($fx['expense'], true, 'Within budget');
    assert_same('approved', db_value('SELECT status FROM expenses WHERE id = ?', [$fx['expense']]));
});

test('approved expenses post only when paid, from an authorised account with enough cash', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $cashBefore = cash_balance($institutionId, $fx['fund'], $fx['treasurer_box']);
    $spentBefore = fund_summary($fx['fund'])['spent'];
    pay_expense($fx['expense'], ['financial_account_id' => (string) $fx['treasurer_box']]);
    assert_same('paid', db_value('SELECT status FROM expenses WHERE id = ?', [$fx['expense']]));
    assert_same($cashBefore - 3000, cash_balance($institutionId, $fx['fund'], $fx['treasurer_box']));
    assert_same($spentBefore + 3000, fund_summary($fx['fund'])['spent']);
    assert_rejected(fn() => pay_expense($fx['expense'], ['financial_account_id' => (string) $fx['treasurer_box']]), 'Only approved');
    $big = fx_expense(['amount' => '99999', 'payee' => 'Venue']);
    fx_attach_receipt($big, 'receipt-big');
    submit_expense($big);
    act_as('treasurer2', 'TST');
    decide_expense($big, true, '');
    act_as('treasurer', 'TST');
    assert_rejected(fn() => pay_expense($big, ['financial_account_id' => (string) $fx['treasurer_box']]), 'Insufficient');
    act_as('collector', 'TST');
    assert_rejected(fn() => pay_expense($big, ['financial_account_id' => (string) $fx['collector_box']]), 'permission');
});

test('rejected expenses carry a reason and return to draft when edited', function () {
    act_as('treasurer', 'TST');
    $expense = fx_expense(['payee' => 'Snacks Co', 'category' => 'Food', 'description' => 'Snacks for volunteers']);
    fx_attach_receipt($expense, 'receipt-snacks');
    submit_expense($expense);
    act_as('treasurer2', 'TST');
    assert_rejected(fn() => decide_expense($expense, false, ''), 'Explain');
    decide_expense($expense, false, 'Not an approved budget item');
    assert_same('Not an approved budget item', db_value("SELECT reason FROM audit_logs WHERE entity_type = 'expense' AND entity_id = ? AND action = 'expense.rejected'", [$expense]));
    act_as('treasurer', 'TST');
    save_expense($expense, ['fund_id' => (string) $GLOBALS['fx']['fund'], 'expense_date' => date('Y-m-d'), 'payee' => 'Snacks Co', 'category' => 'Food', 'description' => 'Snacks, revised', 'amount' => '20']);
    assert_same('draft', db_value('SELECT status FROM expenses WHERE id = ?', [$expense]));
});

test('reversed expenses remain traceable and restore the fund', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $cashBefore = cash_balance((int) current_institution_id(), $fx['fund'], null);
    reverse_expense($fx['expense'], 'Supplier refunded the payment');
    assert_same($cashBefore + 3000, cash_balance((int) current_institution_id(), $fx['fund'], null));
    assert_same(2, (int) db_value("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'expense' AND source_id = ?", [$fx['expense']]));
    assert_same('reversed', db_value('SELECT status FROM expenses WHERE id = ?', [$fx['expense']]));
});

test('event expenses must use the event\'s fund; categories come from settings', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    assert_rejected(fn() => fx_expense(['fund_id' => (string) $fx['general']]), 'must be charged');
    assert_rejected(fn() => fx_expense(['category' => 'Invented']), 'categories');
});

test('the same receipt cannot support two expenses', function () {
    act_as('treasurer', 'TST');
    assert_true(find_duplicate_document(hash('sha256', 'receipt-one')) !== null, 'duplicate detected');
    assert_same(null, find_duplicate_document(hash('sha256', 'never-used')));
});
