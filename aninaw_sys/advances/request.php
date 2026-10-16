<?php
/**
 * Request Cash Advance: Submit an advance or borrowing request.
 * Module scheduled for a later milestone. Access rules are already enforced.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/module_page.php';

render_module_page([
    'title'       => 'Request Cash Advance',
    'nav'         => 'advances',
    'section'     => 'Finance',
    'permissions' => ['advances.request'],
    'context'     => 'institution',
    'description' => 'Submit an advance or borrowing request.',
    'features'    => [
        'State the purpose and amount',
        'Choose the fund and event',
        'Send for approval',
    ],
]);
