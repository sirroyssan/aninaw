<?php
/**
 * Reports: Reports dashboard.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Reports',
    'nav'         => 'reports',
    'section'     => 'Oversight',
    'permissions' => ['reports.view'],
    'context'     => 'institution',
    'description' => 'Reports dashboard.',
    'features'    => [
        'Open collection, fund and audit reports',
        'Filter by period, fund and event',
        'Export reports',
    ],
]);
