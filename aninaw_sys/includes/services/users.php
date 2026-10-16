<?php
/**
 * User and membership management for institution administrators.
 *
 * Rules:
 * - Accounts (users) are global; access is per institution (institution_memberships + roles).
 * - An institution administrator may edit an account's personal details, account status
 *   or password only when that account belongs to no other institution and holds no
 *   platform role; otherwise they manage only the membership in their own institution.
 * - Nobody changes their own roles or membership status (no self-escalation or lock-out).
 * - The last active Institution Administrator cannot be removed.
 * - Passwords are never set or seen by administrators: they issue one-time reset links.
 */
declare(strict_types=1);

const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,100}$/';

/** Membership + account row in the current institution. */
function find_membership_detail(int $membershipId): ?array
{
    return db_one(
        "SELECT m.id AS membership_id, m.membership_status, m.joined_at, m.created_at AS member_since,
                u.id AS user_id, u.username, u.email, u.first_name, u.middle_name, u.last_name, u.phone,
                u.status AS account_status, u.last_login_at
         FROM institution_memberships m JOIN users u ON u.id = m.user_id
         WHERE m.institution_id = ? AND m.id = ?",
        [current_institution_id(), $membershipId]
    );
}

/** Institution-scope roles that can be assigned. */
function assignable_roles(): array
{
    return db_all("SELECT id, role_code, role_name, description FROM roles WHERE scope_type = 'institution' ORDER BY id");
}

function membership_role_ids(int $membershipId): array
{
    return array_map('intval', db_query('SELECT role_id FROM membership_roles WHERE membership_id = ?', [$membershipId])->fetchAll(PDO::FETCH_COLUMN));
}

/** Whether this institution's administrators may edit the account itself. */
function account_editable_here(int $userId): bool
{
    $elsewhere = db_value(
        "SELECT COUNT(*) FROM institution_memberships WHERE user_id = ? AND institution_id <> ? AND membership_status IN ('active', 'pending', 'suspended')",
        [$userId, current_institution_id()]
    );
    $platform = db_value('SELECT COUNT(*) FROM platform_user_roles WHERE user_id = ?', [$userId]);
    return (int) $elsewhere === 0 && (int) $platform === 0;
}

function require_not_self(int $userId, string $action): void
{
    if ($userId === (int) auth_user()['id']) {
        fail_user("You cannot {$action} for your own account. Ask another administrator.");
    }
}

/** Active Institution Administrators other than the given membership. */
function other_active_admins(int $exceptMembershipId): int
{
    return (int) db_value(
        "SELECT COUNT(DISTINCT m.id) FROM institution_memberships m
         JOIN membership_roles mr ON mr.membership_id = m.id
         JOIN roles r ON r.id = mr.role_id AND r.role_code = 'institution_admin'
         JOIN users u ON u.id = m.user_id AND u.status = 'active'
         WHERE m.institution_id = ? AND m.membership_status = 'active' AND m.id <> ?",
        [current_institution_id(), $exceptMembershipId]
    );
}

function membership_is_admin(int $membershipId): bool
{
    return (bool) db_value(
        "SELECT 1 FROM membership_roles mr JOIN roles r ON r.id = mr.role_id AND r.role_code = 'institution_admin' WHERE mr.membership_id = ?",
        [$membershipId]
    );
}

function validate_person_input(array $input, ?int $ignoreUserId = null): array
{
    $data = [
        'username'    => input_str($input, 'username', 100),
        'email'       => mb_strtolower(input_str($input, 'email', 190)),
        'first_name'  => input_str($input, 'first_name', 100),
        'middle_name' => input_str($input, 'middle_name', 100),
        'last_name'   => input_str($input, 'last_name', 100),
        'phone'       => input_str($input, 'phone', 50),
    ];
    if (!preg_match(USERNAME_PATTERN, $data['username'])) {
        fail_user('Username must be 3-100 characters: letters, numbers, dots, dashes or underscores.');
    }
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        fail_user('Enter a valid e-mail address.');
    }
    if ($data['first_name'] === '' || $data['last_name'] === '') {
        fail_user('First and last name are required.');
    }
    if ($data['phone'] !== '' && !preg_match('/^[0-9+() .-]{6,50}$/', $data['phone'])) {
        fail_user('Enter a valid phone number.');
    }
    if (db_value('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$data['username'], $ignoreUserId ?? 0])) {
        fail_user('That username is already taken.');
    }
    if (db_value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$data['email'], $ignoreUserId ?? 0])) {
        fail_user('That e-mail address is already registered to another account.');
    }
    return $data;
}

/** Validates role IDs against institution-scope roles. */
function validate_role_ids(array $roleIds): array
{
    $valid = array_map('intval', array_column(assignable_roles(), 'id'));
    $roleIds = array_values(array_intersect($roleIds, $valid));
    if ($roleIds === []) {
        fail_user('Select at least one role.');
    }
    return $roleIds;
}

function role_names(array $roleIds): array
{
    if (!$roleIds) {
        return [];
    }
    return db_query('SELECT role_name FROM roles WHERE id IN (' . implode(',', array_fill(0, count($roleIds), '?')) . ') ORDER BY id', $roleIds)->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Adds a person to the institution. An existing account (matched by exact e-mail)
 * receives a new membership; otherwise a new account is created with an unusable
 * password and a one-time link for the person to set their own.
 *
 * @return array{membership_id: int, reset_link: ?string, existing: bool}
 */
function create_member(array $input): array
{
    $institutionId = (int) current_institution_id();
    $email = mb_strtolower(input_str($input, 'email', 190));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail_user('Enter a valid e-mail address.');
    }
    $roleIds = validate_role_ids(input_ids($input, 'roles'));

    return db_transaction(function () use ($input, $institutionId, $email, $roleIds) {
        $existing = db_one('SELECT id, status FROM users WHERE email = ? FOR UPDATE', [$email]);
        $resetLink = null;

        if ($existing) {
            $userId = (int) $existing['id'];
            if (db_value('SELECT 1 FROM institution_memberships WHERE institution_id = ? AND user_id = ?', [$institutionId, $userId])) {
                fail_user('This person is already a member of this institution. Open their record to change roles or status.');
            }
        } else {
            $data = validate_person_input($input);
            db_query(
                "INSERT INTO users (username, email, password_hash, first_name, middle_name, last_name, phone, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active')",
                [$data['username'], $data['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                 $data['first_name'], $data['middle_name'] ?: null, $data['last_name'], $data['phone'] ?: null]
            );
            $userId = (int) db()->lastInsertId();
            $resetLink = password_reset_link(create_password_reset_token($userId));
        }

        db_query(
            "INSERT INTO institution_memberships (institution_id, user_id, membership_status, joined_at) VALUES (?, ?, 'active', NOW())",
            [$institutionId, $userId]
        );
        $membershipId = (int) db()->lastInsertId();
        foreach ($roleIds as $roleId) {
            db_query('INSERT INTO membership_roles (membership_id, role_id) VALUES (?, ?)', [$membershipId, $roleId]);
        }

        audit_log($existing ? 'membership.created' : 'user.created', 'institution_membership', $membershipId, $institutionId, (int) auth_user()['id'],
            ['user_id' => $userId, 'email' => $email, 'roles' => role_names($roleIds), 'existing_account' => (bool) $existing]);
        notify_user($institutionId, $userId, 'account', 'Welcome to ' . auth_context()['label'],
            'You were added with the role(s): ' . implode(', ', role_names($roleIds)) . '.');

        return ['membership_id' => $membershipId, 'reset_link' => $resetLink, 'existing' => (bool) $existing];
    });
}

function update_member_profile(int $membershipId, array $input): void
{
    $member = find_membership_detail($membershipId) ?? fail_user('Member not found.');
    if (!account_editable_here((int) $member['user_id'])) {
        fail_user('This account is also used in another institution or by the platform, so its personal details can only be changed by the account owner or a platform administrator.');
    }
    $data = validate_person_input($input, (int) $member['user_id']);
    $before = array_intersect_key($member, $data);

    db_query(
        'UPDATE users SET username = ?, email = ?, first_name = ?, middle_name = ?, last_name = ?, phone = ? WHERE id = ?',
        [$data['username'], $data['email'], $data['first_name'], $data['middle_name'] ?: null, $data['last_name'], $data['phone'] ?: null, $member['user_id']]
    );
    audit_log('user.updated', 'user', (int) $member['user_id'], current_institution_id(), (int) auth_user()['id'], $data, $before);
}

/** Changes the membership status in this institution (active / suspended / ended). */
function set_membership_status(int $membershipId, string $status, string $reason): void
{
    if (!in_array($status, ['active', 'suspended', 'ended'], true)) {
        fail_user('Invalid status.');
    }
    $member = find_membership_detail($membershipId) ?? fail_user('Member not found.');
    require_not_self((int) $member['user_id'], 'change the membership status');
    if ($status !== 'active' && membership_is_admin($membershipId) && other_active_admins($membershipId) === 0) {
        fail_user('This is the only active Institution Administrator. Assign another administrator first.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('Please give a reason for the change.');
    }

    db_query('UPDATE institution_memberships SET membership_status = ? WHERE id = ? AND institution_id = ?', [$status, $membershipId, current_institution_id()]);
    audit_log('membership.status_changed', 'institution_membership', $membershipId, current_institution_id(), (int) auth_user()['id'],
        ['membership_status' => $status], ['membership_status' => $member['membership_status']], $reason);
    notify_user((int) current_institution_id(), (int) $member['user_id'], 'account', 'Membership ' . $status,
        'Your access to ' . auth_context()['label'] . ' is now ' . $status . '.');
}

/** Enables or disables the whole account (only when it belongs to this institution alone). */
function set_account_status(int $membershipId, string $status, string $reason): void
{
    if (!in_array($status, ['active', 'disabled'], true)) {
        fail_user('Invalid status.');
    }
    $member = find_membership_detail($membershipId) ?? fail_user('Member not found.');
    require_not_self((int) $member['user_id'], 'change the account status');
    if (!account_editable_here((int) $member['user_id'])) {
        fail_user('This account is used elsewhere. Suspend the membership in this institution instead.');
    }
    if ($status === 'disabled' && membership_is_admin($membershipId) && other_active_admins($membershipId) === 0) {
        fail_user('This is the only active Institution Administrator. Assign another administrator first.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('Please give a reason for the change.');
    }

    db_transaction(function () use ($member, $status, $reason) {
        db_query('UPDATE users SET status = ? WHERE id = ?', [$status, $member['user_id']]);
        if ($status === 'disabled') {
            db_query('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$member['user_id']]);
        }
        audit_log('user.status_changed', 'user', (int) $member['user_id'], current_institution_id(), (int) auth_user()['id'],
            ['status' => $status], ['status' => $member['account_status']], $reason);
    });
}

/** Replaces the member's institution roles. */
function set_member_roles(int $membershipId, array $roleIds): void
{
    $member = find_membership_detail($membershipId) ?? fail_user('Member not found.');
    require_not_self((int) $member['user_id'], 'change roles');
    $roleIds = validate_role_ids($roleIds);
    $adminRoleId = (int) db_value("SELECT id FROM roles WHERE role_code = 'institution_admin'");

    db_transaction(function () use ($member, $membershipId, $roleIds, $adminRoleId) {
        $before = membership_role_ids($membershipId);
        if (in_array($adminRoleId, $before, true) && !in_array($adminRoleId, $roleIds, true) && other_active_admins($membershipId) === 0) {
            fail_user('This is the only active Institution Administrator. Assign another administrator first.');
        }
        db_query('DELETE FROM membership_roles WHERE membership_id = ?', [$membershipId]);
        foreach ($roleIds as $roleId) {
            db_query('INSERT INTO membership_roles (membership_id, role_id) VALUES (?, ?)', [$membershipId, $roleId]);
        }
        audit_log('membership.roles_changed', 'institution_membership', $membershipId, current_institution_id(), (int) auth_user()['id'],
            ['roles' => role_names($roleIds)], ['roles' => role_names($before)]);
        notify_user((int) current_institution_id(), (int) $member['user_id'], 'account', 'Your roles changed',
            'Your roles in ' . auth_context()['label'] . ' are now: ' . implode(', ', role_names($roleIds)) . '.');
    });
}

// ---------------------------------------------------------------------------
// Password reset links (password_reset_tokens)
// ---------------------------------------------------------------------------

/** Creates a one-time token (only its hash is stored) and invalidates older ones. */
function create_password_reset_token(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    db_query('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
    db_query(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
        [$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + RESET_TOKEN_HOURS * 3600)]
    );
    return $token;
}

function password_reset_link(string $token): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $origin = APP_URL !== '' ? (string) preg_replace('#^(https?://[^/]+).*$#', '$1', APP_URL) : ((is_https() ? 'https://' : 'http://') . $host);
    return $origin . url('password_reset.php?token=' . $token);
}

/** Issues a reset link for a member whose account this institution may manage. */
function issue_member_reset_link(int $membershipId): string
{
    $member = find_membership_detail($membershipId) ?? fail_user('Member not found.');
    if (!account_editable_here((int) $member['user_id'])) {
        fail_user('This account is also used in another institution or by the platform. The account owner or a platform administrator must reset the password.');
    }
    if ($member['account_status'] !== 'active') {
        fail_user('Activate the account before issuing a password link.');
    }
    $link = db_transaction(fn() => password_reset_link(create_password_reset_token((int) $member['user_id'])));
    audit_log('user.password_link_issued', 'user', (int) $member['user_id'], current_institution_id(), (int) auth_user()['id']);
    return $link;
}

/** Validates a raw token; returns the token row joined with the user, or null. */
function find_valid_reset_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    return db_one(
        "SELECT t.id, t.user_id, u.first_name, u.username FROM password_reset_tokens t
         JOIN users u ON u.id = t.user_id AND u.status = 'active'
         WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()",
        [hash('sha256', $token)]
    );
}

function password_policy_error(string $password): ?string
{
    if (strlen($password) < 10 || strlen($password) > 200 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Use 10 or more characters including letters and numbers.';
    }
    return null;
}

/** Sets the password from a valid token, consumes it and signs out all sessions. */
function complete_password_reset(string $token, string $password, string $confirmation): void
{
    $row = find_valid_reset_token($token);
    if (!$row) {
        fail_user('This link is invalid or has expired. Ask your institution administrator for a new one.');
    }
    if ($error = password_policy_error($password)) {
        fail_user($error);
    }
    if (!hash_equals($password, $confirmation)) {
        fail_user('The passwords do not match.');
    }
    db_transaction(function () use ($row, $password) {
        $consumed = db_query('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL', [$row['id']])->rowCount();
        if ($consumed !== 1) {
            fail_user('This link has already been used.');
        }
        db_query('UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
        db_query('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL', [$row['user_id']]);
        audit_log('user.password_reset', 'user', (int) $row['user_id'], null, (int) $row['user_id']);
    });
}
