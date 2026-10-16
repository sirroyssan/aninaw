<?php
/**
 * Add User: Add a new user.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Add User',
    'nav'         => 'users',
    'section'     => 'Administration',
    'permissions' => ['users.manage'],
    'context'     => 'institution',
    'description' => 'Add a new user.',
    'features'    => [
        'Create a login with a temporary password',
        'Add the user as an institution member',
        'Link the login to a participant record',
    ],
]);
