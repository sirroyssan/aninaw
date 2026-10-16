<?php
/**
 * Keeps an active session alive when the user chooses "Stay signed in"
 * in the inactivity warning. POST + CSRF; returns JSON.
 */
declare(strict_types=1);

define('AUTH_ALLOW_NO_CONTEXT', true);
require_once __DIR__ . '/includes/auth_check.php';

if (!is_post()) {
    json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
}
require_csrf();

json_response(['ok' => true, 'idle_timeout' => SESSION_IDLE_TIMEOUT]);
