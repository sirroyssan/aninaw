<?php
/**
 * Session start with hardened cookie settings.
 * Timeout and server-side session validation live in includes/auth.php.
 */
declare(strict_types=1);

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    if (PHP_VERSION_ID < 80400) {
        // Longer session IDs; these settings are deprecated (fixed at a safe default) from PHP 8.4.
        ini_set('session.sid_length', '48');
        ini_set('session.sid_bits_per_character', '6');
    }
    // Let PHP's garbage collector keep sessions at least as long as our absolute timeout.
    ini_set('session.gc_maxlifetime', (string) SESSION_ABSOLUTE_TIMEOUT);

    session_name('ANINAWSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_base_path() . '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/** Issues a new session ID and discards the old one (use after privilege changes). */
function regenerate_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/** Clears session data and expires the cookie. */
function destroy_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?: 'Lax',
    ]);
    session_destroy();
}
