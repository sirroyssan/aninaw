<?php
/**
 * Deposits: Bank and e-wallet deposit records.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Deposits',
    'nav'         => 'deposits',
    'section'     => 'Finance',
    'permissions' => ['deposits.manage'],
    'context'     => 'institution',
    'description' => 'Bank and e-wallet deposit records.',
    'features'    => [
        'Record deposits from cash custody',
        'Confirm deposits against bank records',
        'Track pending confirmations',
    ],
]);
