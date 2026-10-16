<?php
/**
 * Funds: View fund accounts and opening balances.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Funds',
    'nav'         => 'funds',
    'section'     => 'Finance',
    'permissions' => ['funds.view'],
    'context'     => 'institution',
    'description' => 'View fund accounts and opening balances.',
    'features'    => [
        'List fund accounts with posted positions',
        'Filter by category and status',
        'Open a fund ledger statement',
    ],
]);
