<?php
/**
 * Collection Summary: Event and section collection reports.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Collection Summary',
    'nav'         => 'reports',
    'section'     => 'Oversight',
    'permissions' => ['reports.view'],
    'context'     => 'institution',
    'description' => 'Event and section collection reports.',
    'features'    => [
        'Summarize collections by event and group',
        'Compare expected and collected amounts',
        'List unpaid participants',
    ],
]);
