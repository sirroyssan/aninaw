<?php
/**
 * Edit User: Edit user details.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Edit User',
    'nav'         => 'users',
    'section'     => 'Administration',
    'permissions' => ['users.manage'],
    'context'     => 'institution',
    'description' => 'Edit user details.',
    'features'    => [
        'Update name, e-mail and phone',
        'Suspend or reactivate a membership',
        'Reset the user\'s password',
    ],
]);
