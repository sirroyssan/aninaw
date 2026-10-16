<?php
/**
 * Liquidation: View liquidation summaries.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Liquidation',
    'nav'         => 'liquidation',
    'section'     => 'Finance',
    'permissions' => ['liquidation.submit', 'liquidation.review'],
    'context'     => 'institution',
    'description' => 'View liquidation summaries.',
    'features'    => [
        'See submitted and pending liquidations',
        'Compare released, spent and returned amounts',
        'Open receipts for review',
    ],
]);
