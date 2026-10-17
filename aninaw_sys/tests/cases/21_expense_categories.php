<?php
/** Adding an expense & budget category from the "New" button beside Category. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/institutions.php';

section('Expense categories');

test('an administrator adds a category once; other roles cannot', function () {
    act_as('admin', 'TST');
    $before = count(expense_categories());
    assert_same('Registration Fees', add_expense_category('  Registration   Fees '));
    assert_true(in_array('Registration Fees', expense_categories(), true), 'the new category is listed');
    assert_same('Registration Fees', add_expense_category('registration fees'), 'same name in other capitalisation returns the existing one');
    assert_same($before + 1, count(expense_categories()));
    assert_rejected(fn() => add_expense_category('x'), '2 to 100 characters');

    act_as('cashier', 'TST');
    assert_rejected(fn() => add_expense_category('Snacks'), 'administrator');
});
