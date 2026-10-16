<?php
/**
 * Collections: View participant payment balances.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Collections',
    'nav'         => 'collections',
    'section'     => 'Collections',
    'permissions' => ['collections.view'],
    'context'     => 'institution',
    'description' => 'View participant payment balances.',
    'features'    => [
        'Search participants and their balances',
        'Filter by event, group or status',
        'Open payment history',
    ],
]);
