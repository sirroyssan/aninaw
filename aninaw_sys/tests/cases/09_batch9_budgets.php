<?php
/** Batch 9: budgets, approval, revisions and budget vs actual. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/budgets.php';
require_once APP_ROOT . '/includes/services/expenses.php';

section('Batch 9: budgets');

function fx_budget_input(array $overrides = []): array
{
    return array_merge([
        'budget_name' => 'General operations ' . date('Y'), 'fund_id' => (string) $GLOBALS['fx']['general'],
        'start_date' => date('Y-01-01'), 'end_date' => date('Y-12-31'),
        'lines' => [['category' => 'Supplies', 'allocated_amount' => '500'], ['category' => 'Printing', 'allocated_amount' => '200', 'description' => 'Programs and certificates']],
    ], $overrides);
}

/** Category row of a budget-vs-actual report. */
function fx_budget_row(int $budgetId, string $category): array
{
    act_as('treasurer', 'TST');
    foreach (budget_vs_actual(find_budget($budgetId))['rows'] as $row) {
        if ($row['category'] === $category) {
            return $row;
        }
    }
    return ['approved' => 0, 'actual' => 0, 'pending' => 0, 'budgeted' => false, 'over' => false];
}

test('budget lines are validated: known categories, one line each, a valid period', function () {
    act_as('auditor', 'TST');
    assert_rejected(fn() => save_budget(null, fx_budget_input()), 'permission');
    act_as('treasurer', 'TST');
    assert_rejected(fn() => save_budget(null, fx_budget_input(['lines' => []])), 'at least one');
    assert_rejected(fn() => save_budget(null, fx_budget_input(['lines' => [['category' => 'Yachts', 'allocated_amount' => '5']]])), 'category');
    assert_rejected(fn() => save_budget(null, fx_budget_input(['lines' => [['category' => 'Supplies', 'allocated_amount' => '5'], ['category' => 'Supplies', 'allocated_amount' => '6']]])), 'twice');
    assert_rejected(fn() => save_budget(null, fx_budget_input(['lines' => [['category' => 'Supplies', 'allocated_amount' => '-5']]])), 'valid amount');
    assert_rejected(fn() => save_budget(null, fx_budget_input(['end_date' => date('Y') - 1 . '-01-01'])), 'ends before');
    assert_rejected(fn() => save_budget(null, fx_budget_input(['event_id' => (string) $GLOBALS['fx']['event']])), 'not linked');
});

test('budgets are approved by someone else; returns need a reason; approved budgets are not edited', function () {
    act_as('treasurer', 'TST');
    $budget = save_budget(null, fx_budget_input());
    assert_same('700.00', db_value('SELECT total_amount FROM budgets WHERE id = ?', [$budget]), 'total is the sum of the lines');
    submit_budget($budget);
    assert_rejected(fn() => decide_budget($budget, true, ''), 'permission');
    assert_rejected(fn() => save_budget($budget, fx_budget_input()), 'Only draft');
    act_as('admin', 'TST');
    assert_rejected(fn() => decide_budget($budget, false, ''), 'reason');
    decide_budget($budget, false, 'Add a line for printing estimates');
    assert_same('draft', db_value('SELECT status FROM budgets WHERE id = ?', [$budget]));
    act_as('treasurer', 'TST');
    save_budget($budget, fx_budget_input());
    submit_budget($budget);
    act_as('admin', 'TST');
    decide_budget($budget, true, '');
    assert_same('approved', db_value('SELECT status FROM budgets WHERE id = ?', [$budget]));
    assert_rejected(fn() => decide_budget($budget, true, ''), 'not awaiting');
    $GLOBALS['fx']['budget'] = $budget;
});

test('budget vs actual: only paid or verified spending is actual; pending is shown apart', function () {
    $fx = $GLOBALS['fx'];
    $budget = $fx['budget'];
    $before = fx_budget_row($budget, 'Printing');
    act_as('treasurer', 'TST');
    $expense = fx_expense(['fund_id' => (string) $fx['general'], 'event_id' => '', 'category' => 'Printing', 'amount' => '250', 'description' => 'Certificates for volunteers']);
    assert_same($before['pending'], fx_budget_row($budget, 'Printing')['pending'], 'drafts are not pending spending');
    fx_attach_receipt($expense, 'budget-printing-receipt');
    act_as('treasurer', 'TST');
    submit_expense($expense);
    $pending = fx_budget_row($budget, 'Printing');
    assert_same($before['pending'] + 25000, $pending['pending']);
    assert_same($before['actual'], $pending['actual'], 'pending is not actual');
    act_as('admin', 'TST');
    decide_expense($expense, true, '');
    act_as('treasurer', 'TST');
    pay_expense($expense, ['financial_account_id' => (string) $fx['treasurer_box'], 'paid_date' => date('Y-m-d')]);
    $paid = fx_budget_row($budget, 'Printing');
    assert_same($before['actual'] + 25000, $paid['actual']);
    assert_same($before['pending'], $paid['pending']);
    assert_true($paid['over'], 'printing is over its 200.00 budget');
    assert_same(20000 - $paid['actual'], $paid['remaining']);

    $food = fx_expense(['fund_id' => (string) $fx['general'], 'event_id' => '', 'category' => 'Food', 'amount' => '40', 'description' => 'Snacks for the planning meeting']);
    fx_attach_receipt($food, 'budget-food-receipt');
    act_as('treasurer', 'TST');
    submit_expense($food);
    $unbudgeted = fx_budget_row($budget, 'Food');
    assert_same(false, $unbudgeted['budgeted'], 'spending outside the lines is listed as not budgeted');
    assert_same(4000, $unbudgeted['pending']);
    assert_same('700.00', db_value('SELECT total_amount FROM budgets WHERE id = ?', [$budget]), 'viewing never changes the budget');
});

test('revisions need approval authority and a reason; the previous figures are kept', function () {
    $budget = $GLOBALS['fx']['budget'];
    $lines = [['category' => 'Supplies', 'allocated_amount' => '500'], ['category' => 'Printing', 'allocated_amount' => '300']];
    act_as('treasurer', 'TST');
    assert_rejected(fn() => revise_budget($budget, ['lines' => $lines, 'reason' => 'More certificates needed']), 'approvers');
    act_as('admin', 'TST');
    assert_rejected(fn() => revise_budget($budget, ['lines' => $lines, 'reason' => 'more']), 'reason');
    assert_rejected(fn() => revise_budget($budget, ['lines' => fx_budget_input()['lines'], 'reason' => 'No real change here at all']), 'Nothing changed');
    revise_budget($budget, ['lines' => $lines, 'reason' => 'More certificates needed for volunteers']);
    assert_same('800.00', db_value('SELECT total_amount FROM budgets WHERE id = ?', [$budget]));
    $audit = db_one("SELECT before_data, after_data, reason FROM audit_logs WHERE entity_type = 'budget' AND entity_id = ? AND action = 'budget.revised'", [$budget]);
    assert_same('700.00', json_decode($audit['before_data'], true)['total_amount']);
    assert_same('800.00', json_decode($audit['after_data'], true)['total_amount']);
    assert_same('approved', db_value('SELECT status FROM budgets WHERE id = ?', [$budget]));
});

test('closing and cancelling follow the status rules', function () {
    act_as('treasurer', 'TST');
    $draft = save_budget(null, fx_budget_input(['budget_name' => 'Trip that was called off']));
    assert_rejected(fn() => set_budget_status($draft, 'closed', 'Period ended'), 'Only approved');
    assert_rejected(fn() => set_budget_status($draft, 'cancelled', ''), 'reason');
    set_budget_status($draft, 'cancelled', 'Trip called off');
    assert_rejected(fn() => submit_budget($draft), 'Only draft');
    assert_rejected(fn() => set_budget_status($GLOBALS['fx']['budget'], 'cancelled', 'Changed my mind'), 'Only draft or pending');
});

test('budgets are visible only within the institution and to people with access to the fund or event', function () {
    $budget = $GLOBALS['fx']['budget'];
    act_as('other_treasurer', 'OTH');
    assert_same(null, find_budget($budget));
    act_as('student', 'TST');
    assert_same(null, find_budget($budget), 'no budget permission');
    act_as('organizer', 'TST');
    assert_same(null, find_budget($budget), 'organizer cannot see a fund budget they have no access to');
    $eventBudget = save_budget(null, fx_budget_input(['budget_name' => 'ICS Fest budget', 'fund_id' => (string) $GLOBALS['fx']['fund'], 'event_id' => (string) $GLOBALS['fx']['event']]));
    assert_true(find_budget($eventBudget) !== null, 'organizer sees the budget of the event they manage');
});
