<?php
/**
 * Event Summary: Event summary and expected totals.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Event Summary',
    'nav'         => 'events',
    'section'     => 'Collections',
    'permissions' => ['events.view', 'events.manage'],
    'context'     => 'institution',
    'description' => 'Event summary and expected totals.',
    'features'    => [
        'Compare expected and collected amounts',
        'See progress per group',
        'Review linked funds and expenses',
    ],
]);
