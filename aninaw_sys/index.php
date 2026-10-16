<?php
/**
 * Entry point: sends signed-in users to their dashboard and everyone else to the sign-in page.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

send_no_cache_headers();

$status = auth_bootstrap_request();
if ($status === 'authenticated') {
    redirect(auth_context() ? 'dashboard.php' : 'select_institution.php');
}
redirect($status === 'expired' ? 'login.php?expired=1' : 'login.php');
