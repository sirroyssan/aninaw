<?php
/**
 * Record Payment: Record an individual participant payment.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Record Payment',
    'nav'         => 'collections',
    'section'     => 'Collections',
    'permissions' => ['collections.record'],
    'context'     => 'institution',
    'description' => 'Record an individual participant payment.',
    'features'    => [
        'Select the participant and obligations',
        'Record cash, GCash or bank payments',
        'Issue the official receipt',
    ],
]);
