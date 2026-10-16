<?php
/**
 * Fund Statement Report: Account ledger statements.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Fund Statement Report',
    'nav'         => 'reports',
    'section'     => 'Oversight',
    'permissions' => ['reports.view'],
    'context'     => 'institution',
    'description' => 'Account ledger statements.',
    'features'    => [
        'Generate statements per fund and period',
        'Show opening and closing positions',
        'Export for review',
    ],
]);
