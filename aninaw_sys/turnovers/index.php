<?php
/**
 * Cash Turnovers: List cash turnover submissions.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Cash Turnovers',
    'nav'         => 'turnovers',
    'section'     => 'Collections',
    'permissions' => ['turnovers.view'],
    'context'     => 'institution',
    'description' => 'List cash turnover submissions.',
    'features'    => [
        'Filter turnovers by status, group and event',
        'See declared versus received amounts',
        'Open discrepancies',
    ],
]);
