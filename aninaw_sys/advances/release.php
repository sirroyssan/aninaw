<?php
/**
 * Release Cash Advance: Cashier disbursement authorization.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Release Cash Advance',
    'nav'         => 'advances',
    'section'     => 'Finance',
    'permissions' => ['advances.release'],
    'context'     => 'institution',
    'description' => 'Cashier disbursement authorization.',
    'features'    => [
        'Release approved advances',
        'Set the liquidation deadline',
        'Record the source account',
    ],
]);
