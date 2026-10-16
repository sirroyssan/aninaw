<?php
/**
 * Events: List events.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Events',
    'nav'         => 'events',
    'section'     => 'Collections',
    'permissions' => ['events.view', 'events.manage'],
    'context'     => 'institution',
    'description' => 'List events.',
    'features'    => [
        'Filter events by status and date',
        'See collection progress per event',
        'Open event details',
    ],
]);
