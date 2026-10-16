<?php
/**
 * Transfers: Inter-fund and inter-account transfers.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Transfers',
    'nav'         => 'transfers',
    'section'     => 'Finance',
    'permissions' => ['transfers.manage'],
    'context'     => 'institution',
    'description' => 'Inter-fund and inter-account transfers.',
    'features'    => [
        'Request transfers between funds',
        'Approve and post transfers',
        'Reverse a posted transfer with a reason',
    ],
]);
