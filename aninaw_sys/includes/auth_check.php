<?php
/**
 * Include at the top of every protected page.
 *
 * - Rejects guests and expired sessions (redirect, or 401 JSON for AJAX).
 * - Requires a selected institution/platform context, unless the page defines
 *   AUTH_ALLOW_NO_CONTEXT before including this file.
 * - Leaves the user, context and permissions available through auth_user(),
 *   auth_context() and can().
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

send_security_headers();
send_no_cache_headers();

$authStatus = auth_bootstrap_request();

if ($authStatus !== 'authenticated') {
    if (wants_json()) {
        json_response(['ok' => false, 'message' => 'Your session has ended. Please sign in again.', 'reason' => 'unauthenticated'], 401);
    }

    start_secure_session();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $_SESSION['intended_path'] = (string) ($_SERVER['REQUEST_URI'] ?? '');
    }
    redirect($authStatus === 'expired' ? 'login.php?expired=1' : 'login.php');
}

if (auth_context() === null && !defined('AUTH_ALLOW_NO_CONTEXT')) {
    redirect('select_institution.php');
}
