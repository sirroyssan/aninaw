<?php
/**
 * Test harness (command line only). Rebuilds a dedicated test database from
 * database/schema.sql + migration 002, then lets tests act as any member.
 *
 *   DB_NAME=aninaw_test php tests/run_tests.php
 *
 * The database name must end with "_test"; the runner refuses anything else
 * so it can never touch real data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

putenv('DB_NAME=' . (getenv('DB_NAME') ?: 'aninaw_test'));
require_once dirname(__DIR__) . '/config/config.php';

if (!str_ends_with(DB_NAME, '_test')) {
    fwrite(STDERR, "Refusing to run: DB_NAME must end with _test (got " . DB_NAME . ")\n");
    exit(1);
}

/** Drops and recreates the test database from the shipped SQL files. */
function reset_test_database(): void
{
    $server = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT), DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
    $server->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $server->exec('USE `' . DB_NAME . '`');
    $server->exec((string) file_get_contents(APP_ROOT . '/database/schema.sql'));
    if (!$server->query("SHOW TABLES LIKE 'event_organizers'")->fetch()) {
        $server->exec((string) file_get_contents(APP_ROOT . '/database/migration_002_financial_modules.sql'));
    }
}

// ---------------------------------------------------------------------------
// Acting as a user
// ---------------------------------------------------------------------------

/** Switches the request state to a user working in an institution (or the platform). */
function act_as(string $username, ?string $institutionCode = null): void
{
    $user = db_one("SELECT id, username, email, first_name, middle_name, last_name, status, last_login_at FROM users WHERE username = ?", [$username]);
    if (!$user) {
        throw new RuntimeException("No user {$username}");
    }
    $user['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
    $contexts = auth_available_contexts((int) $user['id']);
    $key = $institutionCode === null ? 'platform' : 'inst:' . db_value('SELECT id FROM institutions WHERE institution_code = ?', [$institutionCode]);
    if (!isset($contexts[$key])) {
        throw new RuntimeException("{$username} has no access to {$key}");
    }
    $state = &auth_state();
    $state['user'] = $user;
    $state['context'] = $contexts[$key] + ['available_count' => count($contexts)];
    $state['permissions'] = auth_load_permissions((int) $user['id'], $contexts[$key]);
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

$GLOBALS['test_results'] = ['passed' => 0, 'failed' => 0, 'current' => ''];

function test(string $name, callable $body): void
{
    $GLOBALS['test_results']['current'] = $name;
    try {
        $body();
        fwrite(STDOUT, "  \033[32m✔\033[0m {$name}\n");
        $GLOBALS['test_results']['passed']++;
    } catch (Throwable $exception) {
        if (db_in_transaction()) {
            db()->rollBack();
        }
        fwrite(STDOUT, "  \033[31m✘ {$name}\033[0m\n      " . $exception::class . ': ' . $exception->getMessage() . ' (' . basename($exception->getFile()) . ':' . $exception->getLine() . ")\n");
        $GLOBALS['test_results']['failed']++;
    }
}

function section(string $title): void
{
    fwrite(STDOUT, "\n\033[1m{$title}\033[0m\n");
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Asserts that $body throws a UserFacingException whose message contains $fragment. */
function assert_rejected(callable $body, string $fragment = ''): void
{
    try {
        $body();
    } catch (UserFacingException $exception) {
        if (db_in_transaction()) {
            db()->rollBack();
        }
        if ($fragment !== '' && stripos($exception->getMessage(), $fragment) === false) {
            throw new RuntimeException("Rejected with unexpected message: {$exception->getMessage()}");
        }
        return;
    }
    throw new RuntimeException('Expected the action to be rejected, but it succeeded' . ($fragment ? " ({$fragment})" : ''));
}

/** Asserts that require_permission()/render_error_page() would stop the request. */
function assert_forbidden(string|array $permission): void
{
    assert_true(!can_any((array) $permission), 'Expected permission to be missing: ' . implode(', ', (array) $permission));
}

function finish_tests(): never
{
    $results = $GLOBALS['test_results'];
    fwrite(STDOUT, sprintf("\n%d passed, %d failed\n", $results['passed'], $results['failed']));
    exit($results['failed'] > 0 ? 1 : 0);
}
