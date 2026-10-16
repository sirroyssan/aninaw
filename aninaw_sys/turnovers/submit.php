<?php
/**
 * Submit Turnover: Collector turnover preparation.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Submit Turnover',
    'nav'         => 'turnovers',
    'section'     => 'Collections',
    'permissions' => ['turnovers.submit'],
    'context'     => 'institution',
    'description' => 'Collector turnover preparation.',
    'features'    => [
        'Select payments to include',
        'Declare the amount handed over',
        'Submit to the assigned cashier',
    ],
]);
