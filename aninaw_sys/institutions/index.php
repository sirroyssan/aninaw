<?php
/**
 * Institutions: List institutions on the platform.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Institutions',
    'nav'         => 'institutions',
    'section'     => 'Platform',
    'permissions' => ['platform.institutions.view'],
    'context'     => 'platform',
    'description' => 'List institutions on the platform.',
    'features'    => [
        'Search and filter institutions by status',
        'See member counts and onboarding status',
        'Open an institution profile',
    ],
]);
