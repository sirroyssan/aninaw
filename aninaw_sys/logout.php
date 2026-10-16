<?php
/**
 * Signs the user out. POST with a valid CSRF token only, so other sites cannot force a logout.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

send_security_headers();
send_no_cache_headers();

if (!is_post()) {
    redirect('index.php');
}

$status = auth_bootstrap_request();
if ($status !== 'authenticated') {
    redirect('login.php');
}

require_csrf();
auth_end_session('auth.logout');

if (wants_json()) {
    json_response(['ok' => true, 'redirect' => url('login.php?logged_out=1')]);
}
redirect('login.php?logged_out=1');
