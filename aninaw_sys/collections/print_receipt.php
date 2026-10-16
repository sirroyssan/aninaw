<?php
/**
 * Print Receipt: Generate or print an official payment receipt.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Print Receipt',
    'nav'         => 'collections',
    'section'     => 'Collections',
    'permissions' => ['receipts.issue'],
    'context'     => 'institution',
    'description' => 'Generate or print an official payment receipt.',
    'features'    => [
        'Print or download an official receipt',
        'Reprint with a copy watermark',
        'Void a receipt with a recorded reason',
    ],
]);
