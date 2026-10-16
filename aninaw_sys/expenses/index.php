<?php
/**
 * Expenses: Direct expense register.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Expenses',
    'nav'         => 'expenses',
    'section'     => 'Finance',
    'permissions' => ['expenses.view'],
    'context'     => 'institution',
    'description' => 'Direct expense register.',
    'features'    => [
        'List expenses by fund, event and status',
        'See receipts attached to each expense',
        'Track approvals and payments',
    ],
]);
