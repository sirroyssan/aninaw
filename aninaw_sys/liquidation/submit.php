<?php
/**
 * Submit Liquidation: Itemize receipts and return unused cash.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Submit Liquidation',
    'nav'         => 'liquidation',
    'section'     => 'Finance',
    'permissions' => ['liquidation.submit'],
    'context'     => 'institution',
    'description' => 'Itemize receipts and return unused cash.',
    'features'    => [
        'Itemize expenses with receipts',
        'Record cash returned',
        'Submit for review',
    ],
]);
