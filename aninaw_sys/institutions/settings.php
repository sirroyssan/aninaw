<?php
/**
 * Institution Settings: Institution-specific settings.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Institution Settings',
    'nav'         => 'settings',
    'section'     => 'Administration',
    'permissions' => ['institution.settings.manage'],
    'context'     => 'institution',
    'description' => 'Institution-specific settings.',
    'features'    => [
        'Edit the institution profile and contact details',
        'Configure receipt and document numbering',
        'Set collection and approval preferences',
    ],
]);
