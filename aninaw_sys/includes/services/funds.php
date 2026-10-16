<?php
/**
 * Fund accounts (what the money is for), financial accounts (where the money is held),
 * the mappings between them, fund access assignments and controlled opening balances.
 *
 * Balances are never typed in: they come from posted journal lines (see includes/finance.php).
 */
declare(strict_types=1);

const FINANCIAL_ACCOUNT_TYPES = ['cash_custody' => 'Cash custody', 'bank' => 'Bank account', 'e_wallet' => 'E-wallet', 'other' => 'Other'];
const FUND_ACCESS_LEVELS = [
    'view' => 'View', 'collect' => 'Collect', 'receive_turnover' => 'Receive turnovers', 'spend' => 'Spend',
    'approve' => 'Approve', 'reconcile' => 'Reconcile', 'manage' => 'Manage',
];

// ---------------------------------------------------------------------------
// Fund visibility
// ---------------------------------------------------------------------------

/**
 * SQL condition (alias "f") limiting funds to those the member may see:
 * all funds with funds.view_all; otherwise funds they are custodian of or have an
 * active access assignment for.
 */
function fund_visibility(string $alias = 'f'): array
{
    if (can('funds.view_all')) {
        return ['1 = 1', []];
    }
    return [
        "({$alias}.custodian_membership_id = ? OR EXISTS (SELECT 1 FROM fund_access_assignments fa
            WHERE fa.institution_id = {$alias}.institution_id AND fa.fund_id = {$alias}.id AND fa.membership_id = ? AND fa.status = 'active'
              AND (fa.valid_from IS NULL OR fa.valid_from <= CURDATE()) AND (fa.valid_until IS NULL OR fa.valid_until >= CURDATE())))",
        [current_membership_id(), current_membership_id()],
    ];
}

function find_visible_fund(int $fundId): ?array
{
    [$visibility, $params] = fund_visibility('f');
    return db_one(
        "SELECT f.*, CONCAT(u.first_name, ' ', u.last_name) AS custodian_name
         FROM fund_accounts f
         JOIN institution_memberships m ON m.id = f.custodian_membership_id AND m.institution_id = f.institution_id
         JOIN users u ON u.id = m.user_id
         WHERE f.institution_id = ? AND f.id = ? AND {$visibility}",
        array_merge([current_institution_id(), $fundId], $params)
    );
}

/** [id => "Name (CODE)"] for funds in the given statuses. */
function fund_options(array $statuses = ['active']): array
{
    [$visibility, $params] = fund_visibility('f');
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    return options_from_rows(db_all(
        "SELECT f.id, f.fund_code, f.fund_name FROM fund_accounts f
         WHERE f.institution_id = ? AND f.status IN ({$placeholders}) AND {$visibility} ORDER BY f.fund_name",
        array_merge([current_institution_id()], $statuses, $params)
    ), 'id', static fn($row) => $row['fund_name'] . ' (' . $row['fund_code'] . ')');
}

// ---------------------------------------------------------------------------
// Fund accounts
// ---------------------------------------------------------------------------

function save_fund(?int $fundId, array $input): int
{
    $institutionId = (int) current_institution_id();
    $data = [
        'fund_code'               => strtoupper(input_str($input, 'fund_code', 60)),
        'fund_name'               => input_str($input, 'fund_name', 180),
        'fund_category'           => input_str($input, 'fund_category', 100),
        'purpose'                 => input_str($input, 'purpose', 2000),
        'custodian_membership_id' => input_int($input, 'custodian_membership_id'),
    ];
    if (!preg_match('/^[A-Z0-9_.-]{2,60}$/', $data['fund_code']) || $data['fund_name'] === '') {
        fail_user('Fund code (2-60 letters, numbers, dots, dashes) and name are required.');
    }
    if (!$data['custodian_membership_id']) {
        fail_user('Select the person accountable for this fund.');
    }
    $custodian = find_member($data['custodian_membership_id']);
    if ($custodian['membership_status'] !== 'active') {
        fail_user('The fund custodian must be an active member.');
    }
    if (db_value('SELECT 1 FROM fund_accounts WHERE institution_id = ? AND fund_code = ? AND id <> ?', [$institutionId, $data['fund_code'], $fundId ?? 0])) {
        fail_user('That fund code is already used.');
    }
    $values = [$data['fund_code'], $data['fund_name'], $data['fund_category'] ?: null, $data['purpose'] ?: null, $data['custodian_membership_id']];

    if ($fundId) {
        $before = db_one('SELECT fund_code, fund_name, fund_category, purpose, custodian_membership_id FROM fund_accounts WHERE institution_id = ? AND id = ?', [$institutionId, $fundId]) ?? fail_user('Fund not found.');
        db_query('UPDATE fund_accounts SET fund_code = ?, fund_name = ?, fund_category = ?, purpose = ?, custodian_membership_id = ? WHERE institution_id = ? AND id = ?', [...$values, $institutionId, $fundId]);
        audit_log('fund.updated', 'fund_account', $fundId, $institutionId, (int) auth_user()['id'], $data, $before);
        return $fundId;
    }
    db_query(
        "INSERT INTO fund_accounts (fund_code, fund_name, fund_category, purpose, custodian_membership_id, institution_id, opening_balance, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, 0, 'draft', ?)",
        [...$values, $institutionId, auth_user()['id']]
    );
    $id = (int) db()->lastInsertId();
    audit_log('fund.created', 'fund_account', $id, $institutionId, (int) auth_user()['id'], $data);
    notify_membership($institutionId, $data['custodian_membership_id'], 'fund', 'Fund custodian', 'You are the accountable custodian of the fund ' . $data['fund_name'] . '.');
    return $id;
}

/** Status changes: draft/pending -> active (approval), active <-> suspended, -> closed (empty funds only). */
function set_fund_status(int $fundId, string $status, string $reason): void
{
    $institutionId = (int) current_institution_id();
    $allowed = [
        'draft'     => ['pending', 'active'],
        'pending'   => ['active', 'draft'],
        'active'    => ['suspended', 'closed'],
        'suspended' => ['active', 'closed'],
        'closed'    => [],
    ];
    db_transaction(function () use ($institutionId, $fundId, $status, $reason, $allowed) {
        $fund = db_one('SELECT * FROM fund_accounts WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $fundId]) ?? fail_user('Fund not found.');
        if (!in_array($status, $allowed[$fund['status']] ?? [], true)) {
            fail_user('A ' . $fund['status'] . ' fund cannot be changed to ' . $status . '.');
        }
        if (in_array($status, ['suspended', 'closed'], true) && mb_strlen($reason) < 5) {
            fail_user('Please give a reason.');
        }
        if ($status === 'closed') {
            $cash = cash_balance($institutionId, $fundId, null);
            $advances = ledger_key_balance($institutionId, 'advances', $fundId);
            if ($cash !== 0 || $advances !== 0) {
                fail_user('A fund can only be closed when it holds no cash and has no outstanding advances. Transfer or spend the remaining ' . money($cash + $advances, current_currency()) . ' first.');
            }
        }
        if ($status === 'active' && $fund['approved_by'] === null) {
            db_query('UPDATE fund_accounts SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?', [$status, auth_user()['id'], $fundId]);
        } else {
            db_query('UPDATE fund_accounts SET status = ? WHERE id = ?', [$status, $fundId]);
        }
        audit_log('fund.status_changed', 'fund_account', $fundId, $institutionId, (int) auth_user()['id'], ['status' => $status], ['status' => $fund['status']], $reason ?: null);
    });
}

/** Grants a member an access level on a fund (fund managers / authorised handlers). */
function grant_fund_access(int $fundId, array $input): void
{
    $institutionId = (int) current_institution_id();
    find_fund($fundId, []);
    $membershipId = input_int($input, 'membership_id') ?? 0;
    $level = input_enum($input, 'permission_level', array_keys(FUND_ACCESS_LEVELS)) ?? fail_user('Select an access level.');
    $from = input_date($input, 'valid_from');
    $until = input_date($input, 'valid_until');
    find_member($membershipId);
    if ($from && $until && $until < $from) {
        fail_user('The end date must be after the start date.');
    }
    db_query(
        "INSERT INTO fund_access_assignments (institution_id, fund_id, membership_id, permission_level, valid_from, valid_until, status)
         VALUES (?, ?, ?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE valid_from = VALUES(valid_from), valid_until = VALUES(valid_until), status = 'active'",
        [$institutionId, $fundId, $membershipId, $level, $from, $until]
    );
    audit_log('fund.access_granted', 'fund_account', $fundId, $institutionId, (int) auth_user()['id'], ['membership_id' => $membershipId, 'level' => $level, 'valid_from' => $from, 'valid_until' => $until]);
}

function revoke_fund_access(int $assignmentId): void
{
    $institutionId = (int) current_institution_id();
    $row = db_one('SELECT * FROM fund_access_assignments WHERE institution_id = ? AND id = ?', [$institutionId, $assignmentId]) ?? fail_user('Assignment not found.');
    db_query("UPDATE fund_access_assignments SET status = 'inactive' WHERE id = ?", [$assignmentId]);
    audit_log('fund.access_revoked', 'fund_account', (int) $row['fund_id'], $institutionId, (int) auth_user()['id'], ['membership_id' => (int) $row['membership_id'], 'level' => $row['permission_level']]);
}

// ---------------------------------------------------------------------------
// Financial accounts and mappings
// ---------------------------------------------------------------------------

function save_financial_account(?int $accountId, array $input): int
{
    $institutionId = (int) current_institution_id();
    $data = [
        'account_name'            => input_str($input, 'account_name', 180),
        'account_type'            => input_enum($input, 'account_type', array_keys(FINANCIAL_ACCOUNT_TYPES)),
        'account_reference'       => input_str($input, 'account_reference', 255),
        'custodian_membership_id' => input_int($input, 'custodian_membership_id'),
    ];
    if ($data['account_name'] === '' || !$data['account_type']) {
        fail_user('Account name and type are required.');
    }
    if ($data['custodian_membership_id']) {
        if (find_member($data['custodian_membership_id'])['membership_status'] !== 'active') {
            fail_user('The custodian must be an active member.');
        }
    } elseif ($data['account_type'] === 'cash_custody') {
        fail_user('Cash custody accounts need a custodian: the person physically holding the cash.');
    }
    $currency = (string) db_value('SELECT currency_code FROM institutions WHERE id = ?', [$institutionId]);

    if ($accountId) {
        $before = db_one('SELECT account_name, account_type, account_reference, custodian_membership_id FROM financial_accounts WHERE institution_id = ? AND id = ?', [$institutionId, $accountId]) ?? fail_user('Account not found.');
        if ($before['account_type'] !== $data['account_type'] && db_value('SELECT 1 FROM journal_lines WHERE institution_id = ? AND financial_account_id = ? LIMIT 1', [$institutionId, $accountId])) {
            fail_user('The account type cannot change after transactions were posted to it.');
        }
        db_query(
            'UPDATE financial_accounts SET account_name = ?, account_type = ?, account_reference = ?, custodian_membership_id = ? WHERE institution_id = ? AND id = ?',
            [$data['account_name'], $data['account_type'], $data['account_reference'] ?: null, $data['custodian_membership_id'], $institutionId, $accountId]
        );
        audit_log('financial_account.updated', 'financial_account', $accountId, $institutionId, (int) auth_user()['id'], $data, $before);
        if ((int) $before['custodian_membership_id'] !== (int) $data['custodian_membership_id'] && $data['custodian_membership_id']) {
            notify_membership($institutionId, $data['custodian_membership_id'], 'custody', 'Account custody assigned', 'You are now the custodian of ' . $data['account_name'] . '.');
        }
        return $accountId;
    }
    db_query(
        "INSERT INTO financial_accounts (institution_id, account_name, account_type, account_reference, custodian_membership_id, currency_code, status) VALUES (?, ?, ?, ?, ?, ?, 'active')",
        [$institutionId, $data['account_name'], $data['account_type'], $data['account_reference'] ?: null, $data['custodian_membership_id'], $currency]
    );
    $id = (int) db()->lastInsertId();
    audit_log('financial_account.created', 'financial_account', $id, $institutionId, (int) auth_user()['id'], $data);
    if ($data['custodian_membership_id']) {
        notify_membership($institutionId, $data['custodian_membership_id'], 'custody', 'Account custody assigned', 'You are the custodian of ' . $data['account_name'] . '.');
    }
    return $id;
}

function set_financial_account_status(int $accountId, string $status, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (!in_array($status, ['active', 'inactive', 'closed'], true)) {
        fail_user('Invalid status.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('Please give a reason.');
    }
    $account = db_one('SELECT * FROM financial_accounts WHERE institution_id = ? AND id = ?', [$institutionId, $accountId]) ?? fail_user('Account not found.');
    if ($account['status'] === 'closed') {
        fail_user('A closed account cannot be reopened. Create a new account instead.');
    }
    if ($status === 'closed' && cash_balance($institutionId, null, $accountId) !== 0) {
        fail_user('Only an empty account can be closed. Deposit or transfer its balance first.');
    }
    db_query('UPDATE financial_accounts SET status = ? WHERE id = ?', [$status, $accountId]);
    audit_log('financial_account.status_changed', 'financial_account', $accountId, $institutionId, (int) auth_user()['id'], ['status' => $status], ['status' => $account['status']], $reason);
}

/** Authorises a financial account to hold a fund's money from a date. */
function add_fund_mapping(int $fundId, array $input): void
{
    $institutionId = (int) current_institution_id();
    find_fund($fundId, ['draft', 'pending', 'active']);
    $accountId = input_int($input, 'financial_account_id') ?? 0;
    find_financial_account($accountId);
    $from = input_date($input, 'valid_from') ?? date('Y-m-d');
    $until = input_date($input, 'valid_until');
    if ($until !== null && $until < $from) {
        fail_user('The end date must be after the start date.');
    }
    $overlap = db_value(
        'SELECT 1 FROM fund_account_mappings WHERE institution_id = ? AND fund_id = ? AND financial_account_id = ?
           AND valid_from <= COALESCE(?, "9999-12-31") AND (valid_until IS NULL OR valid_until >= ?)',
        [$institutionId, $fundId, $accountId, $until, $from]
    );
    if ($overlap) {
        fail_user('This account is already mapped to the fund for an overlapping period.');
    }
    db_query('INSERT INTO fund_account_mappings (institution_id, fund_id, financial_account_id, valid_from, valid_until) VALUES (?, ?, ?, ?, ?)',
        [$institutionId, $fundId, $accountId, $from, $until]);
    audit_log('fund.mapping_added', 'fund_account', $fundId, $institutionId, (int) auth_user()['id'], ['financial_account_id' => $accountId, 'valid_from' => $from, 'valid_until' => $until]);
}

/** Ends a mapping today. Money already held stays reportable; new postings are refused. */
function end_fund_mapping(int $mappingId): void
{
    $institutionId = (int) current_institution_id();
    $row = db_one('SELECT * FROM fund_account_mappings WHERE institution_id = ? AND id = ?', [$institutionId, $mappingId]) ?? fail_user('Mapping not found.');
    $endDate = max($row['valid_from'], date('Y-m-d', strtotime('-1 day')));
    db_query('UPDATE fund_account_mappings SET valid_until = ? WHERE id = ?', [$endDate, $mappingId]);
    audit_log('fund.mapping_ended', 'fund_account', (int) $row['fund_id'], $institutionId, (int) auth_user()['id'], ['financial_account_id' => (int) $row['financial_account_id'], 'valid_until' => $endDate]);
}

/**
 * Lets an account hold the fund's money again, with no end date. A mapping that is still
 * running or ended no earlier than yesterday is extended; one that ended earlier gets a new
 * period from today, so the days it was ended stay closed to back-dated postings.
 * Returns 'extended' or 'remapped'.
 */
function reopen_fund_mapping(int $mappingId): string
{
    $institutionId = (int) current_institution_id();
    $row = db_one('SELECT * FROM fund_account_mappings WHERE institution_id = ? AND id = ?', [$institutionId, $mappingId]) ?? fail_user('Mapping not found.');
    find_fund((int) $row['fund_id'], ['draft', 'pending', 'active']);
    $account = find_financial_account((int) $row['financial_account_id']);
    if ($account['status'] !== 'active') {
        fail_user('"' . $account['account_name'] . '" is not active. Reactivate the account first.');
    }
    if ($row['valid_until'] === null) {
        fail_user('This mapping has no end date; the account can already be used.');
    }
    $today = date('Y-m-d');
    $later = db_value(
        'SELECT 1 FROM fund_account_mappings WHERE institution_id = ? AND fund_id = ? AND financial_account_id = ? AND id <> ? AND (valid_until IS NULL OR valid_until >= ?)',
        [$institutionId, $row['fund_id'], $row['financial_account_id'], $mappingId, $row['valid_from']]
    );
    if ($later) {
        fail_user('This account already has a later mapping to the fund. Use that one instead.');
    }
    if ($row['valid_until'] >= date('Y-m-d', strtotime('-1 day'))) {
        db_query('UPDATE fund_account_mappings SET valid_until = NULL WHERE id = ?', [$mappingId]);
        $result = 'extended';
    } else {
        db_query('INSERT INTO fund_account_mappings (institution_id, fund_id, financial_account_id, valid_from, valid_until) VALUES (?, ?, ?, ?, NULL)',
            [$institutionId, $row['fund_id'], $row['financial_account_id'], $today]);
        $result = 'remapped';
    }
    audit_log('fund.mapping_reopened', 'fund_account', (int) $row['fund_id'], $institutionId, (int) auth_user()['id'],
        ['financial_account_id' => (int) $row['financial_account_id'], 'how' => $result, 'from' => $result === 'extended' ? $row['valid_from'] : $today, 'valid_until' => null],
        ['valid_from' => $row['valid_from'], 'valid_until' => $row['valid_until']]);
    return $result;
}

// ---------------------------------------------------------------------------
// Opening balances (controlled)
// ---------------------------------------------------------------------------

/**
 * Posts an opening balance for a fund in one mapped financial account:
 * Dr Cash (account) / Cr Fund Balance. One per fund-account mapping; a mistake is
 * corrected by reversing it with a reason and posting again.
 */
function post_opening_balance(int $fundId, array $input): void
{
    $institutionId = (int) current_institution_id();
    $amount = parse_money(input_str($input, 'amount', 20)) ?? fail_user('Enter a valid opening amount greater than zero.');
    $date = input_date($input, 'opening_date') ?? fail_user('Enter the opening date.');
    $note = input_str($input, 'note', 500);
    if ($note === '') {
        fail_user('Describe the basis of the opening balance (e.g. count sheet, bank statement as of date).');
    }
    $accountId = input_int($input, 'financial_account_id') ?? 0;

    db_transaction(function () use ($institutionId, $fundId, $amount, $date, $note, $accountId) {
        $fund = db_one('SELECT * FROM fund_accounts WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $fundId]) ?? fail_user('Fund not found.');
        if (!in_array($fund['status'], ['draft', 'pending', 'active'], true)) {
            fail_user('Opening balances can only be recorded for draft or active funds.');
        }
        $account = find_financial_account($accountId);
        $mapping = db_one(
            'SELECT id FROM fund_account_mappings WHERE institution_id = ? AND fund_id = ? AND financial_account_id = ? AND valid_from <= ? AND (valid_until IS NULL OR valid_until >= ?)',
            [$institutionId, $fundId, $accountId, $date, $date]
        ) ?? fail_user('Map the account to this fund (covering the opening date) before recording its opening balance.');

        post_journal([
            'institution_id' => $institutionId,
            'date' => $date,
            'description' => 'Opening balance of ' . $fund['fund_name'] . ' in ' . $account['account_name'] . ': ' . $note,
            'source_type' => 'opening_balance',
            'source_id' => (int) $mapping['id'],
            'cash_flow_type' => 'opening_balance',
            'lines' => [
                ['fund_id' => $fundId, 'account' => cash_ledger_key($account['account_type']), 'financial_account_id' => $accountId, 'debit' => $amount],
                ['fund_id' => $fundId, 'account' => 'fund_balance', 'credit' => $amount],
            ],
        ]);
        refresh_fund_opening_balance($fundId);
        audit_log('fund.opening_balance_posted', 'fund_account', $fundId, $institutionId, (int) auth_user()['id'],
            ['financial_account_id' => $accountId, 'amount' => cents_to_decimal($amount), 'date' => $date, 'note' => $note]);
    });
}

function reverse_opening_balance(int $mappingId, string $reason): void
{
    if (mb_strlen($reason) < 5) {
        fail_user('Please give a reason for the reversal.');
    }
    $institutionId = (int) current_institution_id();
    $mapping = db_one('SELECT * FROM fund_account_mappings WHERE institution_id = ? AND id = ?', [$institutionId, $mappingId]) ?? fail_user('Mapping not found.');
    db_transaction(function () use ($institutionId, $mapping, $reason) {
        reverse_journal($institutionId, 'opening_balance', (int) $mapping['id'], $reason);
        refresh_fund_opening_balance((int) $mapping['fund_id']);
        audit_log('fund.opening_balance_reversed', 'fund_account', (int) $mapping['fund_id'], $institutionId, (int) auth_user()['id'], ['mapping_id' => (int) $mapping['id']], null, $reason);
    });
}

/** Keeps fund_accounts.opening_balance/opening_date equal to the posted opening entries (display only). */
function refresh_fund_opening_balance(int $fundId): void
{
    $institutionId = (int) current_institution_id();
    $row = db_one(
        "SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0) AS amount, MIN(j.journal_date) AS opened
         FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id AND l.institution_id = j.institution_id AND l.financial_account_id IS NOT NULL
         WHERE j.institution_id = ? AND j.source_type = 'opening_balance' AND j.status = 'posted' AND l.fund_id = ?",
        [$institutionId, $fundId]
    );
    db_query('UPDATE fund_accounts SET opening_balance = ?, opening_date = ? WHERE institution_id = ? AND id = ?', [$row['amount'], to_cents($row['amount']) !== 0 ? $row['opened'] : null, $institutionId, $fundId]);
}

// ---------------------------------------------------------------------------
// Fund reporting
// ---------------------------------------------------------------------------

/**
 * Answers: how much belongs to the fund, where it is held, who is accountable,
 * how much was collected, turned over, deposited and spent, and what remains.
 * All figures come from posted journal entries / posted register rows.
 */
function fund_summary(int $fundId): array
{
    $institutionId = (int) current_institution_id();
    $incomeCodes = array_map(static fn(string $key) => ledger_account($institutionId, $key)['code'], ['collections', 'sales', 'service_revenue']);
    $expenseCodes = [ledger_account($institutionId, 'expenses')['code'], ledger_account($institutionId, 'cash_shortage')['code']];

    $sumLines = static function (string $condition, array $params) use ($institutionId, $fundId): int {
        return to_cents(db_value(
            "SELECT COALESCE(SUM(l.credit_amount - l.debit_amount), 0) FROM journal_lines l
             JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
             WHERE l.institution_id = ? AND l.fund_id = ? AND {$condition}",
            array_merge([$institutionId, $fundId], $params)
        ));
    };
    $registerSum = static function (string $type, string $sourceType) use ($institutionId, $fundId): int {
        return to_cents(db_value(
            "SELECT COALESCE(SUM(CASE WHEN source_type = ? THEN amount ELSE -amount END), 0) FROM cash_flow_transactions
             WHERE institution_id = ? AND fund_id = ? AND status = 'posted' AND transaction_type = ? AND direction = 'inflow'
               AND source_type IN (?, ?)",
            [$sourceType, $institutionId, $fundId, $type, $sourceType, $sourceType . '_reversal']
        ));
    };

    $cash = cash_balance($institutionId, $fundId, null);
    $advances = ledger_key_balance($institutionId, 'advances', $fundId);
    $holdings = db_all(
        "SELECT fa.id, fa.account_name, fa.account_type, fa.status, CONCAT(u.first_name, ' ', u.last_name) AS custodian_name,
                COALESCE(SUM(l.debit_amount - l.credit_amount), 0) AS balance
         FROM journal_lines l
         JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
         JOIN financial_accounts fa ON fa.id = l.financial_account_id AND fa.institution_id = l.institution_id
         LEFT JOIN institution_memberships m ON m.id = fa.custodian_membership_id AND m.institution_id = fa.institution_id
         LEFT JOIN users u ON u.id = m.user_id
         WHERE l.institution_id = ? AND l.fund_id = ?
         GROUP BY fa.id, fa.account_name, fa.account_type, fa.status, custodian_name
         HAVING balance <> 0 ORDER BY balance DESC",
        [$institutionId, $fundId]
    );

    return [
        'collected'    => $sumLines('l.account_code IN (?, ?, ?)', $incomeCodes),
        'spent'        => -$sumLines('l.account_code IN (?, ?)', $expenseCodes),
        'turned_over'  => $registerSum('transfer', 'turnover'),
        'deposited'    => $registerSum('deposit', 'deposit'),
        'opening'      => $sumLines('l.account_code = ?', [ledger_account($institutionId, 'fund_balance')['code']]),
        'cash'         => $cash,
        'advances'     => $advances,
        'total'        => $cash + $advances,
        'holdings'     => $holdings,
    ];
}
