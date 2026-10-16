<?php
/**
 * Fund Statement: Individual fund ledger statement.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Fund Statement',
    'nav'         => 'funds',
    'section'     => 'Finance',
    'permissions' => ['funds.view'],
    'context'     => 'institution',
    'description' => 'Individual fund ledger statement.',
    'features'    => [
        'See every posted movement for the fund',
        'View the running balance',
        'Export the statement',
    ],
]);
