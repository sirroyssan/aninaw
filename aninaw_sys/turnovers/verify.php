<?php
/**
 * Verify Turnover: Cashier turnover count and acknowledgment.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Verify Turnover',
    'nav'         => 'turnovers',
    'section'     => 'Collections',
    'permissions' => ['turnovers.receive'],
    'context'     => 'institution',
    'description' => 'Cashier turnover count and acknowledgment.',
    'features'    => [
        'Count and record the amount received',
        'Acknowledge or report a discrepancy',
        'Verify the turnover',
    ],
]);
