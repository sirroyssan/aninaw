<?php
/**
 * Create Event: Create an event and its collection rules.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Create Event',
    'nav'         => 'events',
    'section'     => 'Collections',
    'permissions' => ['events.manage'],
    'context'     => 'institution',
    'description' => 'Create an event and its collection rules.',
    'features'    => [
        'Set event dates and collection deadline',
        'Define amounts due for all, a group or a participant',
        'Generate participant obligations',
    ],
]);
