<?php
/**
 * Reconciliation: Multi-custodian cash and advance reconciliation.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Reconciliation',
    'nav'         => 'reconciliation',
    'section'     => 'Finance',
    'permissions' => ['reconciliation.manage'],
    'context'     => 'institution',
    'description' => 'Multi-custodian cash and advance reconciliation.',
    'features'    => [
        'Compare custodian cash with the register',
        'Reconcile advances and returns',
        'Resolve discrepancies',
    ],
]);
