<?php
/**
 * Record Expense: Record a direct cash purchase and upload its receipt.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Record Expense',
    'nav'         => 'expenses',
    'section'     => 'Finance',
    'permissions' => ['expenses.record'],
    'context'     => 'institution',
    'description' => 'Record a direct cash purchase and upload its receipt.',
    'features'    => [
        'Enter payee, amount and category',
        'Upload the receipt',
        'Submit for approval',
    ],
]);
