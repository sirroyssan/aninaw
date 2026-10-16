<?php
/**
 * Cash Advances: View active and overdue cash advances.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Cash Advances',
    'nav'         => 'advances',
    'section'     => 'Finance',
    'permissions' => ['advances.view', 'advances.request', 'self.advances.view'],
    'context'     => 'institution',
    'description' => 'View active and overdue cash advances.',
    'features'    => [
        'See advances by status and deadline',
        'Track outstanding balances',
        'Open liquidation details',
    ],
]);
