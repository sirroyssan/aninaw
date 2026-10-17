<?php
/**
 * Institution onboarding (platform) and institution settings (institution administrators).
 */
declare(strict_types=1);

require_once __DIR__ . '/users.php';

const INSTITUTION_TIMEZONES = ['Asia/Manila', 'Asia/Singapore', 'Asia/Tokyo', 'UTC'];
const INSTITUTION_CURRENCIES = ['PHP' => 'Philippine Peso (PHP)', 'USD' => 'US Dollar (USD)', 'EUR' => 'Euro (EUR)'];

/** Settings shown on Institution Settings, with defaults. */
const INSTITUTION_SETTING_DEFAULTS = [
    'receipt.title'            => 'Acknowledgment Receipt',
    'receipt.footer'           => 'This is a system-generated acknowledgment of payment received.',
    'expense.categories'       => "Supplies\nFood\nTransportation\nPrinting\nVenue\nServices\nPrizes and Awards\nUtilities\nMiscellaneous",
    'advance.default_days'     => '15',
];

function validate_institution_input(array $input, ?int $ignoreId = null): array
{
    $data = [
        'institution_code' => strtoupper(input_str($input, 'institution_code', 50)),
        'institution_name' => input_str($input, 'institution_name', 200),
        'institution_type' => input_str($input, 'institution_type', 80),
        'email'            => mb_strtolower(input_str($input, 'email', 190)),
        'phone'            => input_str($input, 'phone', 50),
        'address'          => input_str($input, 'address', 1000),
        'timezone'         => input_enum($input, 'timezone', INSTITUTION_TIMEZONES, 'Asia/Manila'),
        'currency_code'    => input_enum($input, 'currency_code', array_keys(INSTITUTION_CURRENCIES), 'PHP'),
    ];
    if (!preg_match('/^[A-Z0-9_-]{2,50}$/', $data['institution_code'])) {
        fail_user('Institution code must be 2-50 letters, numbers, dashes or underscores.');
    }
    if ($data['institution_name'] === '') {
        fail_user('Institution name is required.');
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        fail_user('Enter a valid institution e-mail address.');
    }
    if (db_value('SELECT 1 FROM institutions WHERE institution_code = ? AND id <> ?', [$data['institution_code'], $ignoreId ?? 0])) {
        fail_user('That institution code is already used.');
    }
    return $data;
}

/**
 * Creates an institution (pending or active). Optionally makes an existing account
 * (by e-mail) or a new account its first Institution Administrator.
 *
 * @return array{institution_id: int, reset_link: ?string}
 */
function create_institution(array $input): array
{
    $data = validate_institution_input($input);
    $status = input_enum($input, 'status', ['pending', 'active'], 'pending');
    $adminEmail = mb_strtolower(input_str($input, 'admin_email', 190));

    return db_transaction(function () use ($data, $status, $adminEmail, $input) {
        db_query(
            'INSERT INTO institutions (institution_code, institution_name, institution_type, email, phone, address, timezone, currency_code, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$data['institution_code'], $data['institution_name'], $data['institution_type'] ?: null, $data['email'] ?: null,
             $data['phone'] ?: null, $data['address'] ?: null, $data['timezone'], $data['currency_code'], $status]
        );
        $institutionId = (int) db()->lastInsertId();
        audit_log('institution.created', 'institution', $institutionId, null, (int) auth_user()['id'], $data + ['status' => $status]);
        require_once __DIR__ . '/organization.php';
        seed_default_programs($institutionId);

        $resetLink = null;
        if ($adminEmail !== '') {
            $resetLink = add_institution_admin($institutionId, $adminEmail, $input);
        }
        return ['institution_id' => $institutionId, 'reset_link' => $resetLink];
    });
}

/**
 * Platform operation: gives an account the Institution Administrator role in an institution.
 * Creates the account when the e-mail is new. Returns a password link for new accounts.
 */
function add_institution_admin(int $institutionId, string $email, array $input): ?string
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail_user('Enter a valid administrator e-mail address.');
    }
    $user = db_one('SELECT id FROM users WHERE email = ?', [$email]);
    $resetLink = null;
    if ($user) {
        $userId = (int) $user['id'];
    } else {
        $person = validate_person_input([
            'username' => input_str($input, 'admin_username', 100),
            'email' => $email,
            'first_name' => input_str($input, 'admin_first_name', 100),
            'last_name' => input_str($input, 'admin_last_name', 100),
        ]);
        db_query(
            "INSERT INTO users (username, email, password_hash, first_name, last_name, status) VALUES (?, ?, ?, ?, ?, 'active')",
            [$person['username'], $person['email'], password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $person['first_name'], $person['last_name']]
        );
        $userId = (int) db()->lastInsertId();
        $resetLink = password_reset_link(create_password_reset_token($userId));
    }

    $membershipId = (int) db_value('SELECT id FROM institution_memberships WHERE institution_id = ? AND user_id = ?', [$institutionId, $userId]);
    if (!$membershipId) {
        db_query("INSERT INTO institution_memberships (institution_id, user_id, membership_status, joined_at) VALUES (?, ?, 'active', NOW())", [$institutionId, $userId]);
        $membershipId = (int) db()->lastInsertId();
    } else {
        db_query("UPDATE institution_memberships SET membership_status = 'active' WHERE id = ?", [$membershipId]);
    }
    db_query("INSERT IGNORE INTO membership_roles (membership_id, role_id) SELECT ?, id FROM roles WHERE role_code = 'institution_admin'", [$membershipId]);
    audit_log('institution.admin_assigned', 'institution_membership', $membershipId, $institutionId, (int) auth_user()['id'], ['user_id' => $userId, 'email' => $email]);
    return $resetLink;
}

function update_institution(int $institutionId, array $input): void
{
    $before = db_one('SELECT * FROM institutions WHERE id = ?', [$institutionId]) ?? fail_user('Institution not found.');
    $data = validate_institution_input($input, $institutionId);
    if ($data['currency_code'] !== $before['currency_code'] && institution_has_transactions($institutionId)) {
        fail_user('The currency cannot be changed after financial transactions have been recorded.');
    }
    db_query(
        'UPDATE institutions SET institution_code = ?, institution_name = ?, institution_type = ?, email = ?, phone = ?, address = ?, timezone = ?, currency_code = ? WHERE id = ?',
        [$data['institution_code'], $data['institution_name'], $data['institution_type'] ?: null, $data['email'] ?: null,
         $data['phone'] ?: null, $data['address'] ?: null, $data['timezone'], $data['currency_code'], $institutionId]
    );
    audit_log('institution.updated', 'institution', $institutionId, null, (int) auth_user()['id'], $data, array_intersect_key($before, $data));
}

function institution_has_transactions(int $institutionId): bool
{
    return (bool) db_value('SELECT 1 FROM journal_entries WHERE institution_id = ? LIMIT 1', [$institutionId]);
}

function set_institution_status(int $institutionId, string $status, string $reason): void
{
    if (!in_array($status, ['pending', 'active', 'suspended', 'closed'], true)) {
        fail_user('Invalid status.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('Please give a reason for the status change.');
    }
    $before = db_value('SELECT status FROM institutions WHERE id = ?', [$institutionId]) ?? fail_user('Institution not found.');
    db_query('UPDATE institutions SET status = ? WHERE id = ?', [$status, $institutionId]);
    // Recorded at platform level (institution_id NULL) and against the institution.
    audit_log('institution.status_changed', 'institution', $institutionId, null, (int) auth_user()['id'], ['status' => $status], ['status' => $before], $reason);
}

/** Counts only: platform administrators do not see institution finances. */
function institution_summary(int $institutionId): array
{
    return [
        'members'      => (int) db_value("SELECT COUNT(*) FROM institution_memberships WHERE institution_id = ? AND membership_status = 'active'", [$institutionId]),
        'participants' => (int) db_value("SELECT COUNT(*) FROM participants WHERE institution_id = ? AND status = 'active'", [$institutionId]),
        'groups'       => (int) db_value("SELECT COUNT(*) FROM `groups` WHERE institution_id = ? AND status = 'active'", [$institutionId]),
        'events'       => (int) db_value('SELECT COUNT(*) FROM events WHERE institution_id = ?', [$institutionId]),
        'funds'        => (int) db_value("SELECT COUNT(*) FROM fund_accounts WHERE institution_id = ? AND status = 'active'", [$institutionId]),
        'admins'       => db_all(
            "SELECT u.first_name, u.last_name, u.email, m.membership_status FROM institution_memberships m
             JOIN users u ON u.id = m.user_id
             JOIN membership_roles mr ON mr.membership_id = m.id
             JOIN roles r ON r.id = mr.role_id AND r.role_code = 'institution_admin'
             WHERE m.institution_id = ? ORDER BY u.last_name",
            [$institutionId]
        ),
    ];
}

// ---------------------------------------------------------------------------
// Institution settings (institution administrators)
// ---------------------------------------------------------------------------

function institution_settings_values(int $institutionId): array
{
    $values = [];
    foreach (INSTITUTION_SETTING_DEFAULTS as $key => $default) {
        $values[$key] = institution_setting($institutionId, $key, $default);
    }
    foreach (LEDGER_ACCOUNTS as $key => [$code, $name]) {
        $account = ledger_account($institutionId, $key);
        $values["ledger.{$key}.code"] = $account['code'];
        $values["ledger.{$key}.name"] = $account['name'];
    }
    return $values;
}

/** Expense / budget categories configured for the institution. */
function expense_categories(): array
{
    $raw = institution_setting((int) current_institution_id(), 'expense.categories', INSTITUTION_SETTING_DEFAULTS['expense.categories']);
    $categories = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) $raw) ?: []))));
    return $categories ?: ['Miscellaneous'];
}

/**
 * Adds one expense & budget category (e.g. from the "New" button beside Category) and returns
 * its name. An existing category with the same name (any capitalisation) is returned as is.
 */
function add_expense_category(string $name): string
{
    if (!can('institution.settings.manage')) {
        fail_user('Only an institution administrator can add categories.');
    }
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
        fail_user('Enter a category name of 2 to 100 characters.', 'category');
    }
    $categories = expense_categories();
    foreach ($categories as $existing) {
        if (mb_strtolower($existing) === mb_strtolower($name)) {
            return $existing;
        }
    }
    if (count($categories) >= 60) {
        fail_user('There are already 60 categories. Remove unused ones in Institution Settings first.');
    }
    $institutionId = (int) current_institution_id();
    $userId = (int) auth_user()['id'];
    $before = implode("\n", $categories);
    $after = implode("\n", array_merge($categories, [$name]));
    save_institution_setting($institutionId, 'expense.categories', $after, $userId);
    audit_log('institution.settings_changed', 'institution', $institutionId, $institutionId, $userId, ['expense.categories' => $after], ['expense.categories' => $before]);
    return $name;
}

function save_institution_settings(array $input): void
{
    $institutionId = (int) current_institution_id();
    $userId = (int) auth_user()['id'];
    $before = institution_settings_values($institutionId);

    $values = [
        'receipt.title'        => input_str($input, 'receipt_title', 80),
        'receipt.footer'       => input_str($input, 'receipt_footer', 500),
        'expense.categories'   => implode("\n", array_slice(array_values(array_unique(array_filter(array_map(
            static fn($c) => mb_substr(trim($c), 0, 100), preg_split('/\R/', input_str($input, 'expense_categories', 4000)) ?: []
        )))), 0, 60)),
        'advance.default_days' => (string) max(1, min(180, (int) input_str($input, 'advance_default_days', 3))),
    ];
    if ($values['receipt.title'] === '') {
        fail_user('Receipt title is required.');
    }
    if ($values['expense.categories'] === '') {
        fail_user('Enter at least one expense category.');
    }

    $ledgerLocked = institution_has_transactions($institutionId);
    $codes = [];
    foreach (LEDGER_ACCOUNTS as $key => $defaults) {
        $code = input_str($input, "ledger_{$key}_code", 60);
        $name = input_str($input, "ledger_{$key}_name", 180);
        if (!preg_match('/^[A-Za-z0-9.-]{1,60}$/', $code) || $name === '') {
            fail_user('Every ledger account needs a code (letters, numbers, dots, dashes) and a name.');
        }
        if (isset($codes[$code])) {
            fail_user("Ledger code {$code} is used twice. Each account needs its own code.");
        }
        $codes[$code] = true;
        if ($ledgerLocked && $code !== $before["ledger.{$key}.code"]) {
            fail_user('Ledger account codes cannot change after transactions are posted (names can). Existing entries would no longer match.');
        }
        $values["ledger.{$key}.code"] = $code;
        $values["ledger.{$key}.name"] = $name;
    }

    db_transaction(function () use ($values, $institutionId, $userId) {
        foreach ($values as $key => $value) {
            save_institution_setting($institutionId, $key, $value, $userId);
        }
    });
    $changed = array_filter($values, static fn($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH);
    if ($changed) {
        audit_log('institution.settings_changed', 'institution', $institutionId, $institutionId, $userId, $changed, array_intersect_key($before, $changed));
    }
}
