<?php
/**
 * Review Liquidation: Finance officer verification and approval.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Review Liquidation',
    'nav'         => 'liquidation',
    'section'     => 'Finance',
    'permissions' => ['liquidation.review'],
    'context'     => 'institution',
    'description' => 'Finance officer verification and approval.',
    'features'    => [
        'Verify or reject each expense',
        'Request corrections',
        'Approve the liquidation',
    ],
]);
