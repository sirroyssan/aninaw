<?php
/**
 * Authentication: credential checks, login throttling, server-side sessions
 * (`user_sessions`), and institution/platform context resolution.
 */
declare(strict_types=1);

const LOGIN_INVALID_MESSAGE = 'Invalid login credentials.';

// A real bcrypt hash of a random value; verifying against it keeps response time
// similar whether or not the account exists.
const AUTH_DUMMY_HASH = '$2y$10$mmarW03jCVTFfxCA24BwJuIDlVfW2RGa0M0D6QxpMS7CkodijKezG';

/** Per-request authentication state, filled by auth_bootstrap_request(). */
function &auth_state(): array
{
    static $state = ['user' => null, 'context' => null, 'permissions' => []];
    return $state;
}

function auth_user(): ?array
{
    return auth_state()['user'];
}

function auth_context(): ?array
{
    return auth_state()['context'];
}

/** Institution ID of the active context, or null in platform context. */
function current_institution_id(): ?int
{
    $context = auth_context();
    return ($context && $context['type'] === 'institution') ? (int) $context['institution_id'] : null;
}

function current_membership_id(): ?int
{
    $context = auth_context();
    return ($context && $context['type'] === 'institution') ? (int) $context['membership_id'] : null;
}

// ---------------------------------------------------------------------------
// Login
// ---------------------------------------------------------------------------

function login_identifier_hash(string $identifier): string
{
    return hash('sha256', mb_strtolower(trim($identifier)));
}

/**
 * Returns whether login is currently blocked for this identifier or IP address.
 * Only failures since the identifier's last successful login count.
 *
 * @return array{blocked: bool, retry_after: int, failures: int}
 */
function login_throttle_status(string $identifierHash, string $ip): array
{
    $windowStart = date('Y-m-d H:i:s', time() - LOGIN_WINDOW_MINUTES * 60);

    $identifier = db_one(
        'SELECT COUNT(*) AS failures, MIN(attempted_at) AS oldest
         FROM login_attempts
         WHERE identifier_hash = ? AND success = 0 AND attempted_at >= ?
           AND attempted_at > COALESCE((SELECT MAX(s.attempted_at) FROM login_attempts s WHERE s.identifier_hash = ? AND s.success = 1), \'1970-01-02\')',
        [$identifierHash, $windowStart, $identifierHash]
    );
    $address = db_one(
        'SELECT COUNT(*) AS failures, MIN(attempted_at) AS oldest
         FROM login_attempts WHERE ip_address = ? AND success = 0 AND attempted_at >= ?',
        [$ip, $windowStart]
    );

    $identifierFailures = (int) ($identifier['failures'] ?? 0);
    $addressFailures = (int) ($address['failures'] ?? 0);
    $retryAfter = 0;

    if ($identifierFailures >= LOGIN_MAX_ATTEMPTS && $identifier['oldest']) {
        $retryAfter = max($retryAfter, strtotime($identifier['oldest']) + LOGIN_WINDOW_MINUTES * 60 - time());
    }
    if ($addressFailures >= LOGIN_MAX_IP_ATTEMPTS && $address['oldest']) {
        $retryAfter = max($retryAfter, strtotime($address['oldest']) + LOGIN_WINDOW_MINUTES * 60 - time());
    }

    return ['blocked' => $retryAfter > 0, 'retry_after' => max(0, $retryAfter), 'failures' => $identifierFailures];
}

function login_record_attempt(string $identifierHash, ?int $userId, bool $success): void
{
    db_query(
        'INSERT INTO login_attempts (user_id, identifier_hash, success, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)',
        [$userId, $identifierHash, $success ? 1 : 0, client_ip(), client_user_agent()]
    );
}

function auth_find_user_by_identifier(string $identifier): ?array
{
    $column = str_contains($identifier, '@') ? 'email' : 'username';
    return db_one(
        "SELECT id, username, email, password_hash, first_name, last_name, status
         FROM users WHERE {$column} = ? LIMIT 1",
        [$identifier]
    );
}

/**
 * Verifies credentials and, on success, starts the authenticated session.
 *
 * @return array{ok: bool, message: string, reason?: string, redirect?: string, retry_after?: int}
 */
function auth_attempt_login(string $identifier, string $password): array
{
    $identifier = trim($identifier);
    $identifierHash = login_identifier_hash($identifier);

    $throttle = login_throttle_status($identifierHash, client_ip());
    if ($throttle['blocked']) {
        $minutes = (int) ceil($throttle['retry_after'] / 60);
        audit_log('auth.login_throttled', 'login_attempt', null, null, null, ['identifier_hash' => $identifierHash]);
        return [
            'ok' => false,
            'reason' => 'throttled',
            'retry_after' => $throttle['retry_after'],
            'message' => sprintf('Too many failed sign-in attempts. Please wait %d minute%s and try again.', $minutes, $minutes === 1 ? '' : 's'),
        ];
    }

    $user = auth_find_user_by_identifier($identifier);
    $passwordMatches = password_verify($password, $user['password_hash'] ?? AUTH_DUMMY_HASH);

    if (!$user || !$passwordMatches) {
        login_record_attempt($identifierHash, $user ? (int) $user['id'] : null, false);
        audit_log('auth.login_failed', 'user', $user ? (int) $user['id'] : null, null, null, ['identifier_hash' => $identifierHash]);
        // Progressive delay slows down guessing without blocking legitimate users.
        usleep(min($throttle['failures'] + 1, 4) * 250000);
        return ['ok' => false, 'reason' => 'invalid', 'message' => LOGIN_INVALID_MESSAGE];
    }

    $userId = (int) $user['id'];

    // Account status is revealed only after the correct password has been given.
    if ($user['status'] !== 'active') {
        login_record_attempt($identifierHash, $userId, false);
        audit_log('auth.login_denied_status', 'user', $userId, null, $userId, ['status' => $user['status']]);
        $message = match ($user['status']) {
            'pending'  => 'Your account is awaiting activation. Please contact your institution administrator.',
            'locked'   => 'Your account is locked. Please contact your institution administrator.',
            default    => 'Your account is disabled. Please contact your institution administrator.',
        };
        return ['ok' => false, 'reason' => 'inactive', 'message' => $message];
    }

    $contexts = auth_available_contexts($userId);
    if ($contexts === []) {
        login_record_attempt($identifierHash, $userId, false);
        audit_log('auth.login_denied_no_access', 'user', $userId, null, $userId);
        return [
            'ok' => false,
            'reason' => 'no_access',
            'message' => 'Your account does not have active access to any institution. Please contact your institution administrator.',
        ];
    }

    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
    }

    auth_establish_session($userId);
    login_record_attempt($identifierHash, $userId, true);
    db_query('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
    audit_log('auth.login_success', 'user', $userId, null, $userId);

    if (count($contexts) === 1) {
        auth_select_context($userId, array_key_first($contexts));
        $destination = safe_redirect_path($_SESSION['intended_path'] ?? null);
        unset($_SESSION['intended_path']);
        return ['ok' => true, 'message' => 'Welcome back, ' . $user['first_name'] . '!', 'redirect' => url($destination)];
    }

    return ['ok' => true, 'message' => 'Welcome back, ' . $user['first_name'] . '!', 'redirect' => url('select_institution.php')];
}

/** Starts a fresh session bound to a server-side `user_sessions` row. */
function auth_establish_session(int $userId): void
{
    $intended = $_SESSION['intended_path'] ?? null;

    regenerate_session();
    $_SESSION = [];

    $token = bin2hex(random_bytes(32));
    db_query(
        'INSERT INTO user_sessions (user_id, session_token_hash, expires_at) VALUES (?, ?, ?)',
        [$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + SESSION_ABSOLUTE_TIMEOUT)]
    );

    $_SESSION['user_id'] = $userId;
    $_SESSION['session_token'] = $token;
    $_SESSION['login_at'] = time();
    $_SESSION['last_activity'] = time();
    if ($intended) {
        $_SESSION['intended_path'] = $intended;
    }
    rotate_csrf_token();
}

// ---------------------------------------------------------------------------
// Contexts (platform administration or an institution membership)
// ---------------------------------------------------------------------------

/**
 * Lists every context the user may work in, read fresh from the database.
 * Keys are "platform" or "inst:{institution_id}".
 */
function auth_available_contexts(int $userId): array
{
    $contexts = [];

    $platformRoles = db_all(
        "SELECT r.role_code, r.role_name
         FROM platform_user_roles pur
         JOIN roles r ON r.id = pur.role_id AND r.scope_type = 'platform'
         WHERE pur.user_id = ?
         ORDER BY r.role_name",
        [$userId]
    );
    if ($platformRoles) {
        $contexts['platform'] = [
            'key'   => 'platform',
            'type'  => 'platform',
            'label' => 'Platform Administration',
            'code'  => 'PLATFORM',
            'roles' => $platformRoles,
        ];
    }

    $memberships = db_all(
        "SELECT m.id AS membership_id, i.id AS institution_id, i.institution_code, i.institution_name,
                i.institution_type, i.currency_code, r.role_code, r.role_name
         FROM institution_memberships m
         JOIN institutions i ON i.id = m.institution_id AND i.status = 'active'
         JOIN membership_roles mr ON mr.membership_id = m.id
         JOIN roles r ON r.id = mr.role_id AND r.scope_type = 'institution'
         WHERE m.user_id = ? AND m.membership_status = 'active'
         ORDER BY i.institution_name, r.id",
        [$userId]
    );
    foreach ($memberships as $row) {
        $key = 'inst:' . $row['institution_id'];
        $contexts[$key] ??= [
            'key'              => $key,
            'type'             => 'institution',
            'institution_id'   => (int) $row['institution_id'],
            'membership_id'    => (int) $row['membership_id'],
            'label'            => $row['institution_name'],
            'code'             => $row['institution_code'],
            'institution_type' => $row['institution_type'],
            'currency_code'    => $row['currency_code'],
            'roles'            => [],
        ];
        $contexts[$key]['roles'][] = ['role_code' => $row['role_code'], 'role_name' => $row['role_name']];
    }

    return $contexts;
}

/** Switches the session to a context the user is authorised for. Returns false otherwise. */
function auth_select_context(int $userId, string $contextKey): bool
{
    $contexts = auth_available_contexts($userId);
    if (!isset($contexts[$contextKey])) {
        audit_log('auth.context_denied', 'user', $userId, null, $userId, ['requested' => mb_substr($contextKey, 0, 40)]);
        return false;
    }

    $previous = $_SESSION['context_key'] ?? null;
    regenerate_session();
    $_SESSION['context_key'] = $contextKey;
    rotate_csrf_token();

    $context = $contexts[$contextKey];
    audit_log(
        $previous ? 'auth.context_switched' : 'auth.context_selected',
        $context['type'] === 'platform' ? 'platform' : 'institution',
        $context['type'] === 'platform' ? null : $context['institution_id'],
        $context['type'] === 'platform' ? null : $context['institution_id'],
        $userId,
        ['context' => $contextKey, 'previous' => $previous]
    );
    return true;
}

/** Permission codes granted by the user's roles in the given context. */
function auth_load_permissions(int $userId, array $context): array
{
    if ($context['type'] === 'platform') {
        $codes = db_query(
            "SELECT DISTINCT p.permission_code
             FROM platform_user_roles pur
             JOIN roles r ON r.id = pur.role_id AND r.scope_type = 'platform'
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE pur.user_id = ?",
            [$userId]
        )->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $codes = db_query(
            "SELECT DISTINCT p.permission_code
             FROM institution_memberships m
             JOIN membership_roles mr ON mr.membership_id = m.id
             JOIN roles r ON r.id = mr.role_id AND r.scope_type = 'institution'
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE m.id = ? AND m.user_id = ? AND m.institution_id = ? AND m.membership_status = 'active'",
            [$context['membership_id'], $userId, $context['institution_id']]
        )->fetchAll(PDO::FETCH_COLUMN);
    }
    return array_fill_keys($codes, true);
}

// ---------------------------------------------------------------------------
// Per-request validation
// ---------------------------------------------------------------------------

/**
 * Validates the current session against timeouts and the `user_sessions` row,
 * then reloads the user, context and permissions from the database.
 *
 * @return string "guest" | "expired" | "authenticated"
 */
function auth_bootstrap_request(): string
{
    start_secure_session();

    if (empty($_SESSION['user_id']) || empty($_SESSION['session_token'])) {
        return 'guest';
    }

    $now = time();
    $idle = $now - (int) ($_SESSION['last_activity'] ?? 0);
    $age = $now - (int) ($_SESSION['login_at'] ?? 0);
    if ($idle > SESSION_IDLE_TIMEOUT || $age > SESSION_ABSOLUTE_TIMEOUT) {
        auth_end_session('auth.session_expired');
        return 'expired';
    }

    $userId = (int) $_SESSION['user_id'];
    $sessionRow = db_one(
        'SELECT id FROM user_sessions
         WHERE session_token_hash = ? AND user_id = ? AND revoked_at IS NULL AND expires_at > NOW()',
        [hash('sha256', (string) $_SESSION['session_token']), $userId]
    );
    $user = $sessionRow ? db_one(
        'SELECT id, username, email, first_name, middle_name, last_name, status, last_login_at'
        . (db_column_exists('users', 'must_change_password') ? ', must_change_password' : ', 0 AS must_change_password')
        . " FROM users WHERE id = ? AND status = 'active'",
        [$userId]
    ) : null;

    if (!$user) {
        auth_end_session('auth.session_invalidated');
        return 'expired';
    }

    $_SESSION['last_activity'] = $now;
    $user['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);

    $state = &auth_state();
    $state['user'] = $user;
    $state['context'] = null;
    $state['permissions'] = [];

    $contextKey = $_SESSION['context_key'] ?? null;
    if (is_string($contextKey)) {
        $contexts = auth_available_contexts($userId);
        if (isset($contexts[$contextKey])) {
            $state['context'] = $contexts[$contextKey];
            $state['context']['available_count'] = count($contexts);
            $state['permissions'] = auth_load_permissions($userId, $contexts[$contextKey]);
        } else {
            // Membership, role or institution status changed since selection.
            unset($_SESSION['context_key']);
            audit_log('auth.context_revoked', 'user', $userId, null, $userId, ['context' => $contextKey]);
        }
    }

    return 'authenticated';
}

/** Revokes the server-side session row and destroys the PHP session. */
function auth_end_session(string $auditAction = 'auth.logout'): void
{
    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $token = $_SESSION['session_token'] ?? null;

    if ($userId && is_string($token)) {
        try {
            db_query(
                'UPDATE user_sessions SET revoked_at = NOW() WHERE session_token_hash = ? AND user_id = ? AND revoked_at IS NULL',
                [hash('sha256', $token), $userId]
            );
        } catch (Throwable $exception) {
            log_error('Session revoke failed', ['message' => $exception->getMessage()]);
        }
        audit_log($auditAction, 'user', $userId, null, $userId);
    }

    destroy_session();
}
