<?php
/**
 * ANINAW bootstrap: environment settings, constants and shared includes.
 * Every entry point (page or CLI script) requires this file first.
 */
declare(strict_types=1);

if (defined('ANINAW_BOOTSTRAPPED')) {
    return;
}
define('ANINAW_BOOTSTRAPPED', true);
define('APP_ROOT', dirname(__DIR__));

/**
 * Reads KEY=VALUE pairs from the .env file in the project root.
 * Real environment variables take precedence over the file.
 */
function env(string $key, ?string $default = null): ?string
{
    static $fileValues = null;

    if ($fileValues === null) {
        $fileValues = [];
        $envFile = APP_ROOT . '/.env';
        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = array_map('trim', explode('=', $line, 2));
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                    $value = substr($value, 1, -1);
                }
                $fileValues[$name] = $value;
            }
        }
    }

    $fromEnvironment = getenv($key);
    if ($fromEnvironment !== false) {
        return $fromEnvironment;
    }
    return $fileValues[$key] ?? $default;
}

define('APP_NAME', 'ANINAW');
define('APP_FULL_NAME', 'Accountability, Networked Institutional Numbers, Accounts, and Workflows');
define('APP_TAGLINE', 'Every Fund Accounted For. Every Transaction Transparent.');
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', APP_ENV === 'development' && env('APP_DEBUG', 'false') === 'true');
define('APP_URL', rtrim((string) env('APP_URL', ''), '/'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Asia/Manila'));

define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'aninaw_db'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// Seconds without activity before the session ends, and the hard cap per login.
define('SESSION_IDLE_TIMEOUT', max(300, (int) env('SESSION_IDLE_TIMEOUT', '1800')));
define('SESSION_ABSOLUTE_TIMEOUT', max(SESSION_IDLE_TIMEOUT, (int) env('SESSION_ABSOLUTE_TIMEOUT', '28800')));

// Failed attempts allowed per identifier / per IP address within the window.
define('LOGIN_MAX_ATTEMPTS', max(3, (int) env('LOGIN_MAX_ATTEMPTS', '5')));
define('LOGIN_MAX_IP_ATTEMPTS', max(10, (int) env('LOGIN_MAX_IP_ATTEMPTS', '30')));
define('LOGIN_WINDOW_MINUTES', max(1, (int) env('LOGIN_WINDOW_MINUTES', '15')));

define('LOG_PATH', APP_ROOT . '/logs');

date_default_timezone_set(APP_TIMEZONE);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once APP_ROOT . '/includes/functions.php';
require_once APP_ROOT . '/config/db.php';
require_once APP_ROOT . '/config/session.php';
require_once APP_ROOT . '/includes/audit.php';
require_once APP_ROOT . '/includes/permissions.php';
require_once APP_ROOT . '/includes/auth.php';

set_exception_handler('handle_uncaught_exception');
