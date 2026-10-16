<?php
/**
 * Roles & Permissions: Assign institution roles and permissions.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Roles & Permissions',
    'nav'         => 'roles',
    'section'     => 'Administration',
    'permissions' => ['roles.assign'],
    'context'     => 'institution',
    'description' => 'Assign institution roles and permissions.',
    'features'    => [
        'Assign one or more roles to a member',
        'Review what each role is allowed to do',
        'See role changes in the audit trail',
    ],
]);
