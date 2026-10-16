<?php
/**
 * Cash Flow: Complete cash movement register.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Cash Flow',
    'nav'         => 'cash-flow',
    'section'     => 'Finance',
    'permissions' => ['cashflow.view'],
    'context'     => 'institution',
    'description' => 'Complete cash movement register.',
    'features'    => [
        'See every inflow and outflow',
        'Filter by fund, account, type and date',
        'Trace each entry to its source record',
    ],
]);
