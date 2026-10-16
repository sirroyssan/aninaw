<?php
/**
 * Add Institution: Add an institution profile.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Add Institution',
    'nav'         => 'institutions',
    'section'     => 'Platform',
    'permissions' => ['platform.institutions.manage'],
    'context'     => 'platform',
    'description' => 'Add an institution profile.',
    'features'    => [
        'Register an institution code, name and type',
        'Set timezone and currency',
        'Activate or keep it pending',
    ],
]);
