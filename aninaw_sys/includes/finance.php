<?php
/**
 * Accounting foundation: money handling, document numbers, institution settings,
 * ledger accounts, balanced posting, reversals and balances.
 *
 * Rules enforced here:
 * - Amounts are handled as integer centavos; no floating-point arithmetic.
 * - Every posting is one balanced journal entry (debits = credits) whose cash lines
 *   produce the matching cash-flow register rows in the same database transaction.
 * - Posted entries are never edited. Corrections are new reversing entries.
 * - A source record (payment, deposit, ...) can be posted only once.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Money (integer centavos)
// ---------------------------------------------------------------------------

const MONEY_MAX_CENTS = 999999999999; // 9,999,999,999.99 fits DECIMAL(12,2)

/** Parses user input like "1,250.50" into centavos. Returns null when invalid or not positive. */
function parse_money(mixed $input, bool $allowZero = false): ?int
{
    if (!is_string($input) && !is_int($input)) {
        return null;
    }
    $value = str_replace([',', ' ', '₱'], '', trim((string) $input));
    if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $value, $match)) {
        return null;
    }
    $cents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '0', 2, '0');
    if ($cents > MONEY_MAX_CENTS || (!$allowZero && $cents === 0)) {
        return null;
    }
    return $cents;
}

/** Converts a DECIMAL value from the database ("1234.50") to centavos exactly. */
function to_cents(mixed $decimal): int
{
    if ($decimal === null || $decimal === '') {
        return 0;
    }
    $value = (string) $decimal;
    $negative = str_starts_with($value, '-');
    $value = ltrim($value, '-');
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
    $cents = (int) $whole * 100 + (int) substr(str_pad($fraction, 2, '0'), 0, 2);
    return $negative ? -$cents : $cents;
}

/** Centavos to a DECIMAL string for SQL parameters. */
function cents_to_decimal(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** Display format for centavos, e.g. ₱1,250.50. */
function money(int $cents, string $currency = 'PHP'): string
{
    $symbols = ['PHP' => '₱', 'USD' => '$', 'EUR' => '€'];
    $prefix = $symbols[$currency] ?? ($currency . ' ');
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    return $sign . $prefix . number_format(intdiv($cents, 100)) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** Currency of the active institution. */
function current_currency(): string
{
    return auth_context()['currency_code'] ?? 'PHP';
}

/** Shortcut: format a DECIMAL from the database in the institution currency. */
function m(mixed $decimal): string
{
    return money(to_cents($decimal), current_currency());
}

// ---------------------------------------------------------------------------
// Document numbers (number_sequences)
// ---------------------------------------------------------------------------

/**
 * Next number for a document type, e.g. "OR-2026-000042". Numbers are allocated
 * under a row lock and are never reused once the transaction commits.
 */
function next_document_number(int $institutionId, string $documentType, string $prefix): string
{
    db_query(
        'INSERT IGNORE INTO number_sequences (institution_id, document_type, current_number) VALUES (?, ?, 0)',
        [$institutionId, $documentType]
    );
    db_query(
        'UPDATE number_sequences SET current_number = LAST_INSERT_ID(current_number + 1) WHERE institution_id = ? AND document_type = ?',
        [$institutionId, $documentType]
    );
    $number = (int) db()->lastInsertId();
    return sprintf('%s-%s-%06d', $prefix, date('Y'), $number);
}

// ---------------------------------------------------------------------------
// Institution settings (institution_settings)
// ---------------------------------------------------------------------------

function institution_setting(int $institutionId, string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (!isset($cache[$institutionId])) {
        $cache[$institutionId] = db_query(
            'SELECT setting_key, setting_value FROM institution_settings WHERE institution_id = ?',
            [$institutionId]
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $cache[$institutionId][$key] ?? $default;
}

function save_institution_setting(int $institutionId, string $key, ?string $value, int $userId): void
{
    db_query(
        'INSERT INTO institution_settings (institution_id, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
        [$institutionId, $key, $value, $userId]
    );
}

// ---------------------------------------------------------------------------
// Ledger accounts (configurable per institution)
// ---------------------------------------------------------------------------

/** Default chart of accounts. Each institution can rename/renumber them in Institution Settings. */
const LEDGER_ACCOUNTS = [
    'cash_custody'  => ['1010', 'Cash on Hand', 'asset'],
    'bank'          => ['1020', 'Cash in Bank', 'asset'],
    'e_wallet'      => ['1030', 'E-Wallet Balances', 'asset'],
    'other'         => ['1090', 'Other Cash Accounts', 'asset'],
    'advances'      => ['1200', 'Cash Advances Receivable', 'asset'],
    'fund_balance'  => ['3000', 'Fund Balance', 'equity'],
    'collections'   => ['4000', 'Collections and Contributions', 'income'],
    'sales'         => ['4100', 'Sales Revenue', 'income'],
    'service_revenue' => ['4200', 'Service Revenue', 'income'],
    'other_income'  => ['4900', 'Other Income (Cash Overages)', 'income'],
    'expenses'      => ['5000', 'Expenses', 'expense'],
    'cash_shortage' => ['5900', 'Cash Shortages', 'expense'],
];

/** @return array{code: string, name: string, class: string} */
function ledger_account(int $institutionId, string $key): array
{
    if (!isset(LEDGER_ACCOUNTS[$key])) {
        throw new InvalidArgumentException('Unknown ledger account key ' . $key);
    }
    [$code, $name, $class] = LEDGER_ACCOUNTS[$key];
    return [
        'code'  => institution_setting($institutionId, "ledger.{$key}.code", $code),
        'name'  => institution_setting($institutionId, "ledger.{$key}.name", $name),
        'class' => $class,
    ];
}

/** Ledger key for the cash account of a financial account type. */
function cash_ledger_key(string $accountType): string
{
    return in_array($accountType, ['cash_custody', 'bank', 'e_wallet'], true) ? $accountType : 'other';
}

/** Account codes classified as cash (money physically held somewhere). */
function cash_account_codes(int $institutionId): array
{
    return array_map(static fn(string $key) => ledger_account($institutionId, $key)['code'], ['cash_custody', 'bank', 'e_wallet', 'other']);
}

// ---------------------------------------------------------------------------
// Posting
// ---------------------------------------------------------------------------

/**
 * Posts one balanced journal entry and its cash-flow rows. Must run inside a transaction.
 *
 * $entry = [
 *   'institution_id', 'date' (Y-m-d), 'description', 'source_type', 'source_id',
 *   'cash_flow_type' (cash_flow_transactions.transaction_type),
 *   'lines' => [[ 'fund_id', 'account' (ledger key), 'debit' (cents), 'credit' (cents),
 *                 'financial_account_id' (required for cash keys, null otherwise), 'description' ]],
 * ]
 * Every line on a cash account becomes a cash-flow row (debit = inflow, credit = outflow).
 * Pending cash-flow rows already recorded for the same source are promoted to posted
 * instead of being duplicated.
 */
function post_journal(array $entry): int
{
    if (!db()->inTransaction()) {
        throw new LogicException('post_journal() must run inside a transaction.');
    }

    $institutionId = (int) $entry['institution_id'];
    $userId = (int) auth_user()['id'];
    $lines = $entry['lines'];

    if (count($lines) < 2) {
        throw new LogicException('A journal entry needs at least two lines.');
    }
    $debits = 0;
    $credits = 0;
    foreach ($lines as $line) {
        $debit = (int) ($line['debit'] ?? 0);
        $credit = (int) ($line['credit'] ?? 0);
        if ($debit < 0 || $credit < 0 || ($debit > 0) === ($credit > 0)) {
            throw new LogicException('Each journal line must have either a debit or a credit.');
        }
        $debits += $debit;
        $credits += $credit;
    }
    if ($debits !== $credits) {
        throw new LogicException("Unbalanced journal entry: debits {$debits} credits {$credits}.");
    }

    // A source may be posted once (until that posting is reversed); corrections go through reverse_journal().
    $existing = db_value(
        "SELECT j.id FROM journal_entries j
         WHERE j.institution_id = ? AND j.source_type = ? AND j.source_id = ? AND j.reversal_of_id IS NULL AND j.status = 'posted'
           AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.institution_id = j.institution_id AND r.reversal_of_id = j.id)
         LIMIT 1 FOR UPDATE",
        [$institutionId, $entry['source_type'], $entry['source_id']]
    );
    if ($existing) {
        fail_user('This transaction has already been posted.');
    }

    $journalNumber = next_document_number($institutionId, 'journal_entry', 'JE');
    db_query(
        "INSERT INTO journal_entries (institution_id, journal_number, journal_date, description, source_type, source_id, status, created_by, posted_by, posted_at)
         VALUES (?, ?, ?, ?, ?, ?, 'posted', ?, ?, NOW())",
        [$institutionId, $journalNumber, $entry['date'], $entry['description'], $entry['source_type'], $entry['source_id'], $userId, $userId]
    );
    $journalId = (int) db()->lastInsertId();

    $cashRows = [];
    foreach ($lines as $line) {
        $account = ledger_account($institutionId, $line['account']);
        $isCash = in_array($line['account'], ['cash_custody', 'bank', 'e_wallet', 'other'], true);
        $financialAccountId = $line['financial_account_id'] ?? null;
        if ($isCash && !$financialAccountId) {
            throw new LogicException('Cash lines must name a financial account.');
        }
        db_query(
            'INSERT INTO journal_lines (institution_id, journal_entry_id, fund_id, financial_account_id, account_code, account_name, debit_amount, credit_amount, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $institutionId, $journalId, $line['fund_id'], $isCash ? $financialAccountId : null,
                $account['code'], $account['name'],
                cents_to_decimal((int) ($line['debit'] ?? 0)), cents_to_decimal((int) ($line['credit'] ?? 0)),
                $line['description'] ?? null,
            ]
        );
        if ($isCash) {
            $debit = (int) ($line['debit'] ?? 0);
            $cashRows[] = [
                'fund_id' => (int) $line['fund_id'],
                'financial_account_id' => (int) $financialAccountId,
                'direction' => $debit > 0 ? 'inflow' : 'outflow',
                'amount' => $debit > 0 ? $debit : (int) $line['credit'],
            ];
        }
    }

    record_cash_flows($institutionId, $entry['source_type'], (int) $entry['source_id'], $entry['cash_flow_type'], $entry['date'], $cashRows, 'posted');

    return $journalId;
}

/**
 * Writes cash-flow register rows. With status 'posted', matching pending rows for the
 * same source are promoted instead of duplicated.
 */
function record_cash_flows(int $institutionId, string $sourceType, int $sourceId, string $type, string $date, array $rows, string $status): void
{
    $userId = (int) auth_user()['id'];

    if ($status === 'posted') {
        $pending = db_all(
            "SELECT id, fund_id, financial_account_id, direction, amount FROM cash_flow_transactions
             WHERE institution_id = ? AND source_type = ? AND source_id = ? AND status = 'pending' FOR UPDATE",
            [$institutionId, $sourceType, $sourceId]
        );
        foreach ($rows as $index => $row) {
            foreach ($pending as $pendingIndex => $candidate) {
                if ((int) $candidate['fund_id'] === $row['fund_id'] && (int) $candidate['financial_account_id'] === $row['financial_account_id']
                    && $candidate['direction'] === $row['direction'] && to_cents($candidate['amount']) === $row['amount']) {
                    db_query("UPDATE cash_flow_transactions SET status = 'posted', transaction_date = ? WHERE id = ?", [$date, $candidate['id']]);
                    unset($rows[$index], $pending[$pendingIndex]);
                    break;
                }
            }
        }
        // Pending rows that no longer match the posting are withdrawn, not left dangling.
        foreach ($pending as $candidate) {
            db_query("UPDATE cash_flow_transactions SET status = 'reversed' WHERE id = ?", [$candidate['id']]);
        }
    }

    foreach ($rows as $row) {
        db_query(
            'INSERT INTO cash_flow_transactions (institution_id, transaction_number, fund_id, financial_account_id, transaction_date, transaction_type, direction, amount, source_type, source_id, status, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $institutionId, next_document_number($institutionId, 'cash_flow', 'CF'), $row['fund_id'], $row['financial_account_id'],
                $date, $type, $row['direction'], cents_to_decimal($row['amount']), $sourceType, $sourceId, $status, $userId,
            ]
        );
    }
}

/** Withdraws pending cash-flow rows of a source that was cancelled or rejected before posting. */
function cancel_pending_cash_flows(int $institutionId, string $sourceType, int $sourceId): void
{
    db_query(
        "UPDATE cash_flow_transactions SET status = 'reversed' WHERE institution_id = ? AND source_type = ? AND source_id = ? AND status = 'pending'",
        [$institutionId, $sourceType, $sourceId]
    );
}

/**
 * Reverses the posted entry of a source with a mirrored entry (debits and credits swapped)
 * and mirrored cash-flow rows. The original stays posted and visible. Must run in a transaction.
 */
function reverse_journal(int $institutionId, string $sourceType, int $sourceId, string $reason, ?string $date = null): int
{
    $original = db_one(
        "SELECT j.* FROM journal_entries j
         WHERE j.institution_id = ? AND j.source_type = ? AND j.source_id = ? AND j.reversal_of_id IS NULL AND j.status = 'posted'
           AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.institution_id = j.institution_id AND r.reversal_of_id = j.id)
         ORDER BY j.id DESC LIMIT 1 FOR UPDATE",
        [$institutionId, $sourceType, $sourceId]
    );
    if (!$original) {
        fail_user('There is no posted entry to reverse, or it has already been reversed.');
    }

    $userId = (int) auth_user()['id'];
    $date ??= date('Y-m-d');
    $number = next_document_number($institutionId, 'journal_entry', 'JE');
    db_query(
        "INSERT INTO journal_entries (institution_id, journal_number, journal_date, description, source_type, source_id, status, created_by, posted_by, posted_at, reversal_of_id)
         VALUES (?, ?, ?, ?, ?, ?, 'posted', ?, ?, NOW(), ?)",
        [$institutionId, $number, $date, 'Reversal of ' . $original['journal_number'] . ': ' . mb_substr($reason, 0, 500), $sourceType, $sourceId, $userId, $userId, $original['id']]
    );
    $reversalId = (int) db()->lastInsertId();

    $lines = db_all('SELECT * FROM journal_lines WHERE institution_id = ? AND journal_entry_id = ?', [$institutionId, $original['id']]);
    $cashRows = [];
    foreach ($lines as $line) {
        db_query(
            'INSERT INTO journal_lines (institution_id, journal_entry_id, fund_id, financial_account_id, account_code, account_name, debit_amount, credit_amount, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$institutionId, $reversalId, $line['fund_id'], $line['financial_account_id'], $line['account_code'], $line['account_name'], $line['credit_amount'], $line['debit_amount'], 'Reversal'],
        );
        if ($line['financial_account_id']) {
            $credit = to_cents($line['credit_amount']);
            $cashRows[] = [
                'fund_id' => (int) $line['fund_id'],
                'financial_account_id' => (int) $line['financial_account_id'],
                'direction' => $credit > 0 ? 'inflow' : 'outflow',
                'amount' => $credit > 0 ? $credit : to_cents($line['debit_amount']),
            ];
        }
    }

    $type = (string) (db_value(
        'SELECT transaction_type FROM cash_flow_transactions WHERE institution_id = ? AND source_type = ? AND source_id = ? LIMIT 1',
        [$institutionId, $sourceType, $sourceId]
    ) ?: 'adjustment');
    record_cash_flows($institutionId, $sourceType . '_reversal', $sourceId, $type, $date, $cashRows, 'posted');

    return $reversalId;
}

/** True when the source has a posted entry that has not been reversed. */
function source_is_posted(int $institutionId, string $sourceType, int $sourceId): bool
{
    return (bool) db_value(
        "SELECT 1 FROM journal_entries j
         WHERE j.institution_id = ? AND j.source_type = ? AND j.source_id = ? AND j.reversal_of_id IS NULL AND j.status = 'posted'
           AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.institution_id = j.institution_id AND r.reversal_of_id = j.id)",
        [$institutionId, $sourceType, $sourceId]
    );
}

// ---------------------------------------------------------------------------
// Balances (from posted journal lines: the source of truth)
// ---------------------------------------------------------------------------

/** Cash held for a fund in a financial account, in centavos. Pass null to sum across. */
function cash_balance(int $institutionId, ?int $fundId, ?int $financialAccountId): int
{
    $sql = "SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0)
            FROM journal_lines l
            JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
            WHERE l.institution_id = ? AND l.financial_account_id IS NOT NULL";
    $params = [$institutionId];
    if ($fundId !== null) {
        $sql .= ' AND l.fund_id = ?';
        $params[] = $fundId;
    }
    if ($financialAccountId !== null) {
        $sql .= ' AND l.financial_account_id = ?';
        $params[] = $financialAccountId;
    }
    return to_cents(db_value($sql, $params));
}

/** Balance of one ledger account key for a fund (debit-positive), in centavos. */
function ledger_key_balance(int $institutionId, string $key, ?int $fundId = null): int
{
    $sql = "SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0)
            FROM journal_lines l
            JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
            WHERE l.institution_id = ? AND l.account_code = ?";
    $params = [$institutionId, ledger_account($institutionId, $key)['code']];
    if ($fundId !== null) {
        $sql .= ' AND l.fund_id = ?';
        $params[] = $fundId;
    }
    return to_cents(db_value($sql, $params));
}

/**
 * Locks a financial account row and checks that a fund has enough cash in it.
 * Call inside the transaction that will post the outflow.
 */
function require_available_cash(int $institutionId, int $fundId, int $financialAccountId, int $amountCents): void
{
    db_query('SELECT id FROM financial_accounts WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $financialAccountId]);
    $available = cash_balance($institutionId, $fundId, $financialAccountId);
    if ($available < $amountCents) {
        fail_user('Insufficient funds: only ' . money($available, current_currency()) . ' of this fund is held in the selected account.');
    }
}

// ---------------------------------------------------------------------------
// Lookups scoped to the institution
// ---------------------------------------------------------------------------

/** Fund row in the current institution, or a user-facing error. */
function find_fund(int $fundId, array $statuses = ['active']): array
{
    $fund = db_one('SELECT * FROM fund_accounts WHERE institution_id = ? AND id = ?', [current_institution_id(), $fundId]);
    if (!$fund) {
        fail_user('The selected fund was not found.');
    }
    if ($statuses && !in_array($fund['status'], $statuses, true)) {
        fail_user('The fund "' . $fund['fund_name'] . '" is ' . $fund['status'] . ' and cannot be used for this action.');
    }
    return $fund;
}

function find_financial_account(int $accountId, bool $activeOnly = true): array
{
    $account = db_one('SELECT * FROM financial_accounts WHERE institution_id = ? AND id = ?', [current_institution_id(), $accountId]);
    if (!$account) {
        fail_user('The selected financial account was not found.');
    }
    if ($activeOnly && $account['status'] !== 'active') {
        fail_user('The account "' . $account['account_name'] . '" is ' . $account['status'] . '.');
    }
    return $account;
}

/** Whether a fund may hold money in a financial account on a date (fund_account_mappings). */
function fund_account_is_mapped(int $institutionId, int $fundId, int $accountId, string $date): bool
{
    return (bool) db_value(
        'SELECT 1 FROM fund_account_mappings
         WHERE institution_id = ? AND fund_id = ? AND financial_account_id = ?
           AND valid_from <= ? AND (valid_until IS NULL OR valid_until >= ?)',
        [$institutionId, $fundId, $accountId, $date, $date]
    );
}

function require_fund_account_mapping(int $fundId, int $accountId, string $date): void
{
    $institutionId = (int) current_institution_id();
    if (fund_account_is_mapped($institutionId, $fundId, $accountId, $date)) {
        return;
    }
    $account = (string) db_value('SELECT account_name FROM financial_accounts WHERE institution_id = ? AND id = ?', [$institutionId, $accountId]);
    $fund = (string) db_value('SELECT fund_name FROM fund_accounts WHERE institution_id = ? AND id = ?', [$institutionId, $fundId]);
    $periods = array_map(
        static fn($row) => format_date($row['valid_from']) . ' – ' . ($row['valid_until'] ? format_date($row['valid_until']) : 'open'),
        db_all('SELECT valid_from, valid_until FROM fund_account_mappings WHERE institution_id = ? AND fund_id = ? AND financial_account_id = ? ORDER BY valid_from', [$institutionId, $fundId, $accountId])
    );
    fail_user('"' . $account . '" may hold ' . $fund . ' money only ' . ($periods ? 'from ' . implode(', ', $periods) : '(it is not linked to this fund)')
        . ', so a transaction dated ' . format_date($date) . ' cannot be recorded in it. '
        . ($periods ? 'To record past transactions, a treasurer can move the start date earlier: Financial Accounts → ' . $account . ' → Change dates.' : 'Link it under Funds → ' . $fund . ' first.'));
}

/**
 * Financial accounts the current member may move money through: accounts they are
 * custodian of, or every account when they manage funds.
 */
function usable_financial_accounts(?int $fundId = null, ?string $date = null): array
{
    $sql = "SELECT fa.*, CONCAT(u.first_name, ' ', u.last_name) AS custodian_name
            FROM financial_accounts fa
            LEFT JOIN institution_memberships m ON m.id = fa.custodian_membership_id AND m.institution_id = fa.institution_id
            LEFT JOIN users u ON u.id = m.user_id
            WHERE fa.institution_id = ? AND fa.status = 'active'";
    $params = [current_institution_id()];
    if (!can('funds.manage')) {
        $sql .= ' AND fa.custodian_membership_id = ?';
        $params[] = current_membership_id();
    }
    if ($fundId !== null) {
        $date ??= date('Y-m-d');
        $sql .= ' AND EXISTS (SELECT 1 FROM fund_account_mappings fm WHERE fm.institution_id = fa.institution_id AND fm.fund_id = ?
                    AND fm.financial_account_id = fa.id AND fm.valid_from <= ? AND (fm.valid_until IS NULL OR fm.valid_until >= ?))';
        array_push($params, $fundId, $date, $date);
    }
    return db_all($sql . ' ORDER BY fa.account_name', $params);
}

function require_usable_account(array $account): void
{
    if (!can('funds.manage') && (int) $account['custodian_membership_id'] !== current_membership_id()) {
        fail_user('You are not the custodian of "' . $account['account_name'] . '".');
    }
}

function financial_account_label(array $account): string
{
    return $account['account_name'] . ' (' . humanize($account['account_type']) . (!empty($account['custodian_name']) ? ' · ' . $account['custodian_name'] : '') . ')';
}

/** Active members of the institution, optionally limited to those holding a permission. */
function institution_members(?string $permission = null): array
{
    $sql = "SELECT m.id AS membership_id, u.id AS user_id, u.first_name, u.last_name, u.email
            FROM institution_memberships m
            JOIN users u ON u.id = m.user_id AND u.status = 'active'
            WHERE m.institution_id = ? AND m.membership_status = 'active'";
    $params = [current_institution_id()];
    if ($permission !== null) {
        $sql .= " AND EXISTS (SELECT 1 FROM membership_roles mr
                    JOIN role_permissions rp ON rp.role_id = mr.role_id
                    JOIN permissions p ON p.id = rp.permission_id AND p.permission_code = ?
                    WHERE mr.membership_id = m.id)";
        $params[] = $permission;
    }
    return db_all($sql . ' ORDER BY u.last_name, u.first_name', $params);
}

function member_options(?string $permission = null): array
{
    return options_from_rows(institution_members($permission), 'membership_id', static fn($row) => person_name($row) . ' · ' . $row['email']);
}

/** Membership row in the current institution, or a user-facing error. */
function find_member(int $membershipId): array
{
    $member = db_one(
        "SELECT m.id AS membership_id, m.membership_status, u.id AS user_id, u.first_name, u.last_name, u.email
         FROM institution_memberships m JOIN users u ON u.id = m.user_id
         WHERE m.institution_id = ? AND m.id = ?",
        [current_institution_id(), $membershipId]
    );
    if (!$member) {
        fail_user('The selected member was not found in this institution.');
    }
    return $member;
}

/** Whether a member's roles grant a permission in the current institution. */
function member_has_permission(int $membershipId, string $permission): bool
{
    return (bool) db_value(
        "SELECT 1 FROM institution_memberships m
         JOIN membership_roles mr ON mr.membership_id = m.id
         JOIN role_permissions rp ON rp.role_id = mr.role_id
         JOIN permissions p ON p.id = rp.permission_id AND p.permission_code = ?
         WHERE m.id = ? AND m.institution_id = ? AND m.membership_status = 'active' LIMIT 1",
        [$permission, $membershipId, current_institution_id()]
    );
}
