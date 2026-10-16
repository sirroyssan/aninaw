<?php
/**
 * Users: List system users and participants.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Users',
    'nav'         => 'users',
    'section'     => 'Administration',
    'permissions' => ['users.view', 'platform.users.view'],
    'context'     => 'any',
    'description' => 'List system users and participants.',
    'features'    => [
        'Search members by name, status or role',
        'See membership status',
        'Open a member to edit or assign roles',
    ],
]);
