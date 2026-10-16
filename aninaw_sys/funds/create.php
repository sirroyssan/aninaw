<?php
/**
 * Create Fund: Create a new fund account (e.g. RAITE Fund 2026).
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Create Fund',
    'nav'         => 'funds',
    'section'     => 'Finance',
    'permissions' => ['funds.manage'],
    'context'     => 'institution',
    'description' => 'Create a new fund account (e.g. RAITE Fund 2026).',
    'features'    => [
        'Set code, name, purpose and custodian',
        'Record the opening balance and date',
        'Map the fund to cash, bank or e-wallet accounts',
    ],
]);
