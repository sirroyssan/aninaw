<?php
/**
 * Audit Trail: System security and transaction logs.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Audit Trail',
    'nav'         => 'audit',
    'section'     => 'Oversight',
    'permissions' => ['audit.view', 'platform.audit.view'],
    'context'     => 'any',
    'description' => 'System security and transaction logs.',
    'features'    => [
        'Search audit events by user, action and date',
        'See sign-in and permission events',
        'Review before/after data of changes',
    ],
]);
