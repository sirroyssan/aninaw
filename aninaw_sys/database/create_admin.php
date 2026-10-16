<?php
/**
 * ANINAW initial administrator setup (command line only).
 *
 * Creates the first Platform Super Administrator:
 *     php database/create_admin.php
 *
 * Creates (or reuses) an institution and its first Institution Administrator:
 *     php database/create_admin.php --institution
 *
 * The script cannot run from a browser (and database/ is blocked by .htaccess).
 * No default password exists: you type it, and only its hash is stored.
 * Once a Platform Super Administrator exists, platform mode refuses to run again
 * unless --allow-additional is given.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

$options = getopt('', ['institution', 'allow-additional', 'help']);
if (isset($options['help'])) {
    echo "Usage:\n  php database/create_admin.php                 Create the Platform Super Administrator\n";
    echo "  php database/create_admin.php --institution   Create an institution and its Institution Administrator\n";
    exit(0);
}

function out(string $text = ''): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

function fail(string $text): never
{
    fwrite(STDERR, 'Error: ' . $text . PHP_EOL);
    exit(1);
}

function ask(string $label, ?callable $validate = null, string $default = ''): string
{
    while (true) {
        fwrite(STDOUT, $label . ($default !== '' ? " [{$default}]" : '') . ': ');
        $value = trim((string) fgets(STDIN));
        if ($value === '' && $default !== '') {
            $value = $default;
        }
        $error = $validate ? $validate($value) : ($value === '' ? 'This field is required.' : null);
        if ($error === null) {
            return $value;
        }
        out('  ' . $error);
    }
}

/** Reads a password without echoing it where the terminal supports that. */
function ask_secret(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    $isTerminal = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
    $canHide = $isTerminal && DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec')
        && str_ends_with(trim((string) @shell_exec('stty 2>/dev/null && echo ok')), 'ok');
    if ($canHide) {
        shell_exec('stty -echo');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($canHide) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }
    return $value;
}

function ask_new_password(): string
{
    while (true) {
        $password = ask_secret('Password (min. 10 characters, letters and numbers)');
        if (strlen($password) < 10 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            out('  Use at least 10 characters including letters and numbers.');
            continue;
        }
        if ($password !== ask_secret('Confirm password')) {
            out('  Passwords do not match.');
            continue;
        }
        return $password;
    }
}

function role_id(string $code, string $scope): int
{
    $id = db_value('SELECT id FROM roles WHERE role_code = ? AND scope_type = ?', [$code, $scope]);
    if (!$id) {
        fail("Role '{$code}' was not found. Import database/schema.sql (it includes the role seed) first.");
    }
    return (int) $id;
}

/** Prompts for a new user's details and inserts them. Returns the user ID. */
function create_user_interactively(): int
{
    $username = ask('Username', static function (string $value): ?string {
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $value)) {
            return 'Use 3-100 letters, numbers, dots, dashes or underscores.';
        }
        return db_value('SELECT 1 FROM users WHERE username = ?', [$value]) ? 'That username is already taken.' : null;
    });
    $email = ask('E-mail', static function (string $value): ?string {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 190) {
            return 'Enter a valid e-mail address.';
        }
        return db_value('SELECT 1 FROM users WHERE email = ?', [$value]) ? 'That e-mail is already registered.' : null;
    });
    $firstName = ask('First name');
    $lastName = ask('Last name');
    $password = ask_new_password();

    db_query(
        "INSERT INTO users (username, email, password_hash, first_name, last_name, status, email_verified_at)
         VALUES (?, ?, ?, ?, ?, 'active', NOW())",
        [$username, mb_strtolower($email), password_hash($password, PASSWORD_DEFAULT), $firstName, $lastName]
    );
    return (int) db()->lastInsertId();
}

try {
    db();
} catch (DatabaseUnavailableException) {
    fail('Cannot connect to the database. Check DB_* settings in .env and that MySQL is running.');
}

if (!db_value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'platform_user_roles'")) {
    fail('Table platform_user_roles is missing. Import the complete database/schema.sql first.');
}

// ---------------------------------------------------------------------------
// Institution + Institution Administrator
// ---------------------------------------------------------------------------
if (isset($options['institution'])) {
    out('ANINAW · Create institution and Institution Administrator');
    out(str_repeat('-', 56));

    $code = strtoupper(ask('Institution code (e.g. PCB)', static fn(string $v): ?string => preg_match('/^[A-Za-z0-9_-]{2,50}$/', $v) ? null : 'Use 2-50 letters, numbers, dashes or underscores.'));
    $institution = db_one('SELECT id, institution_name, status FROM institutions WHERE institution_code = ?', [$code]);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($institution) {
            out("Using existing institution: {$institution['institution_name']} ({$institution['status']}).");
            $institutionId = (int) $institution['id'];
            if ($institution['status'] !== 'active') {
                out('  Note: members can sign in only after the institution status is set to active.');
            }
        } else {
            $name = ask('Institution name');
            $type = ask('Institution type (e.g. School, College, Organization)', static fn(string $v): ?string => null, 'School');
            db_query(
                "INSERT INTO institutions (institution_code, institution_name, institution_type, status) VALUES (?, ?, ?, 'active')",
                [$code, $name, $type]
            );
            $institutionId = (int) $pdo->lastInsertId();
        }

        out('');
        out('Institution Administrator account');
        $existing = ask('Existing username to promote (leave blank to create a new user)', static fn(string $v): ?string => null, '');
        if ($existing !== '') {
            $userId = (int) db_value("SELECT id FROM users WHERE username = ? AND status = 'active'", [$existing]);
            if (!$userId) {
                throw new RuntimeException('No active user with that username.');
            }
        } else {
            $userId = create_user_interactively();
        }

        $membershipId = (int) db_value('SELECT id FROM institution_memberships WHERE institution_id = ? AND user_id = ?', [$institutionId, $userId]);
        if (!$membershipId) {
            db_query(
                "INSERT INTO institution_memberships (institution_id, user_id, membership_status, joined_at) VALUES (?, ?, 'active', NOW())",
                [$institutionId, $userId]
            );
            $membershipId = (int) $pdo->lastInsertId();
        } else {
            db_query("UPDATE institution_memberships SET membership_status = 'active' WHERE id = ?", [$membershipId]);
        }
        db_query('INSERT IGNORE INTO membership_roles (membership_id, role_id) VALUES (?, ?)', [$membershipId, role_id('institution_admin', 'institution')]);

        audit_log('setup.institution_admin_created', 'institution_membership', $membershipId, $institutionId, null, ['user_id' => $userId, 'source' => 'cli']);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        fail($exception->getMessage());
    }

    out('');
    out('Done. The Institution Administrator can now sign in.');
    exit(0);
}

// ---------------------------------------------------------------------------
// Platform Super Administrator
// ---------------------------------------------------------------------------
$platformRoleId = role_id('platform_super_admin', 'platform');
$existingAdmins = (int) db_value('SELECT COUNT(*) FROM platform_user_roles WHERE role_id = ?', [$platformRoleId]);
if ($existingAdmins > 0 && !isset($options['allow-additional'])) {
    fail('A Platform Super Administrator already exists. Re-run with --allow-additional to add another one deliberately.');
}

out('ANINAW · Create Platform Super Administrator');
out(str_repeat('-', 44));

$pdo = db();
$pdo->beginTransaction();
try {
    $userId = create_user_interactively();
    db_query('INSERT INTO platform_user_roles (user_id, role_id) VALUES (?, ?)', [$userId, $platformRoleId]);
    audit_log('setup.platform_admin_created', 'user', $userId, null, null, ['source' => 'cli']);
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    fail($exception->getMessage());
}

out('');
out('Done. Sign in at ' . (APP_URL !== '' ? APP_URL : 'http://localhost/aninaw_sys') . '/login.php');
