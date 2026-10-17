<?php
/**
 * Accounting ledger: journal browsing, trial balance, account ledgers, adjusting
 * entries and integrity checks against the operational records.
 *
 * Operational modules post through post_journal()/reverse_journal() (includes/finance.php).
 * This service adds manual adjusting entries, which follow a maker-checker flow:
 *
 *   prepared (journal_entries.status = 'draft', ledger.post)
 *     -> posted by a different ledger.post holder      (status 'posted', source 'adjustment')
 *     -> optionally reversed with a reason              (mirrored entry, original stays posted)
 *
 * Adjusting entries must balance within each fund and may not touch Cash Advances
 * Receivable (advances are settled through liquidation so the two always agree).
 * Entries created by a module can only be corrected through that module.
 */
declare(strict_types=1);

const JOURNAL_SOURCE_LABELS = [
    'payment'              => 'Payment',
    'turnover'             => 'Cash turnover',
    'turnover_discrepancy' => 'Turnover discrepancy',
    'deposit'              => 'Deposit',
    'fund_transfer'        => 'Fund transfer',
    'expense'              => 'Expense',
    'advance_release'      => 'Cash advance release',
    'advance_expense'      => 'Liquidated expense',
    'advance_return'       => 'Advance return',
    'opening_balance'      => 'Opening balance',
    'sales_order'          => 'Product / service order',
    'adjustment'           => 'Adjusting entry',
];

/** Ledger keys an adjusting entry may use. */
const ADJUSTABLE_LEDGER_KEYS = ['cash_custody', 'bank', 'e_wallet', 'other', 'fund_balance', 'collections', 'sales', 'service_revenue', 'other_income', 'expenses', 'cash_shortage'];

/** Chart of accounts for the institution: key => [code, name, class]. */
function ledger_chart(int $institutionId): array
{
    $chart = [];
    foreach (array_keys(LEDGER_ACCOUNTS) as $key) {
        $chart[$key] = ledger_account($institutionId, $key);
    }
    return $chart;
}

/** Chart entry for an account code, or null when the code is not in the chart. */
function ledger_account_by_code(int $institutionId, string $code): ?array
{
    foreach (ledger_chart($institutionId) as $key => $account) {
        if ($account['code'] === $code) {
            return $account + ['key' => $key];
        }
    }
    return null;
}

/** Normal balance side: assets and expenses are debit-normal. */
function ledger_debit_normal(string $class): bool
{
    return in_array($class, ['asset', 'expense'], true);
}

function journal_source_label(string $sourceType): string
{
    $reversal = str_ends_with($sourceType, '_reversal');
    $base = $reversal ? substr($sourceType, 0, -9) : $sourceType;
    return (JOURNAL_SOURCE_LABELS[$base] ?? humanize($base)) . ($reversal ? ' (reversal)' : '');
}

/** App-relative path of the page showing the source document of an entry or register row, or null. */
function journal_source_path(string $sourceType, int $sourceId): ?string
{
    $institutionId = (int) current_institution_id();
    $base = str_ends_with($sourceType, '_reversal') ? substr($sourceType, 0, -9) : $sourceType;
    $direct = [
        'payment' => 'collections/payment.php?id=', 'turnover' => 'turnovers/view.php?id=', 'deposit' => 'deposits/view.php?id=',
        'fund_transfer' => 'transfers/view.php?id=', 'expense' => 'expenses/view.php?id=', 'advance_release' => 'advances/view.php?id=',
        'adjustment' => 'ledger/entry.php?id=',
    ];
    if (isset($direct[$base])) {
        return $direct[$base] . $sourceId;
    }
    $lookup = [
        'advance_expense'      => ['SELECT cash_advance_id FROM cash_advance_expenses WHERE institution_id = ? AND id = ?', 'advances/view.php?id='],
        'advance_return'       => ['SELECT cash_advance_id FROM cash_advance_returns WHERE institution_id = ? AND id = ?', 'advances/view.php?id='],
        'turnover_discrepancy' => ['SELECT turnover_id FROM turnover_discrepancies WHERE institution_id = ? AND id = ?', 'turnovers/view.php?id='],
        'opening_balance'      => ['SELECT fund_id FROM fund_account_mappings WHERE institution_id = ? AND id = ?', 'funds/view.php?id='],
    ];
    if (!isset($lookup[$base])) {
        return null;
    }
    $parentId = db_value($lookup[$base][0], [$institutionId, $sourceId]);
    return $parentId ? $lookup[$base][1] . $parentId : null;
}

/** Journal entry with lines, people, totals and reversal links; null when not in this institution. */
function find_journal_entry(int $entryId): ?array
{
    $institutionId = (int) current_institution_id();
    $entry = db_one(
        "SELECT j.*, CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name, CONCAT(pu.first_name, ' ', pu.last_name) AS posted_by_name,
                o.journal_number AS reversal_of_number,
                (SELECT r.id FROM journal_entries r WHERE r.institution_id = j.institution_id AND r.reversal_of_id = j.id LIMIT 1) AS reversed_by_id,
                (SELECT r.journal_number FROM journal_entries r WHERE r.institution_id = j.institution_id AND r.reversal_of_id = j.id LIMIT 1) AS reversed_by_number
         FROM journal_entries j
         JOIN users cu ON cu.id = j.created_by
         LEFT JOIN users pu ON pu.id = j.posted_by
         LEFT JOIN journal_entries o ON o.id = j.reversal_of_id AND o.institution_id = j.institution_id
         WHERE j.institution_id = ? AND j.id = ?",
        [$institutionId, $entryId]
    );
    if (!$entry) {
        return null;
    }
    $entry['lines'] = db_all(
        "SELECT l.*, f.fund_name, fa.account_name AS financial_account_name
         FROM journal_lines l
         JOIN fund_accounts f ON f.id = l.fund_id AND f.institution_id = l.institution_id
         LEFT JOIN financial_accounts fa ON fa.id = l.financial_account_id AND fa.institution_id = l.institution_id
         WHERE l.institution_id = ? AND l.journal_entry_id = ? ORDER BY l.id",
        [$institutionId, $entryId]
    );
    $entry['debit_cents'] = array_sum(array_map(static fn($l) => to_cents($l['debit_amount']), $entry['lines']));
    $entry['credit_cents'] = array_sum(array_map(static fn($l) => to_cents($l['credit_amount']), $entry['lines']));
    $entry['is_adjustment'] = $entry['source_type'] === 'adjustment' && !$entry['reversal_of_id'];
    return $entry;
}

// ---------------------------------------------------------------------------
// Adjusting entries
// ---------------------------------------------------------------------------

/**
 * Validates submitted adjusting lines. Returns normalised lines in centavos:
 * [fund_id, account (key), financial_account_id|null, debit, credit, description].
 */
function adjusting_lines_from_input(array $input): array
{
    $institutionId = (int) current_institution_id();
    $raw = $input['lines'] ?? [];
    if (!is_array($raw)) {
        fail_user('Add the entry lines.');
    }
    $lines = [];
    $perFund = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $debitText = input_str($row, 'debit', 20);
        $creditText = input_str($row, 'credit', 20);
        $fundId = input_int($row, 'fund_id');
        $key = input_str($row, 'account', 30);
        if ($debitText === '' && $creditText === '' && !$fundId && $key === '') {
            continue; // blank row left in the form
        }
        $number = count($lines) + 1;
        if (!in_array($key, ADJUSTABLE_LEDGER_KEYS, true)) {
            fail_user("Line {$number}: choose a ledger account.");
        }
        $debit = $debitText === '' ? 0 : (parse_money($debitText) ?? fail_user("Line {$number}: enter a valid debit amount."));
        $credit = $creditText === '' ? 0 : (parse_money($creditText) ?? fail_user("Line {$number}: enter a valid credit amount."));
        if (($debit > 0) === ($credit > 0)) {
            fail_user("Line {$number}: enter either a debit or a credit, not both.");
        }
        $fund = find_fund($fundId ?? 0, ['active', 'suspended']);
        $financialAccountId = null;
        if (in_array($key, ['cash_custody', 'bank', 'e_wallet', 'other'], true)) {
            $account = find_financial_account(input_int($row, 'financial_account_id') ?? 0, false);
            if (cash_ledger_key($account['account_type']) !== $key) {
                fail_user("Line {$number}: {$account['account_name']} is not a " . ledger_account($institutionId, $key)['name'] . ' account.');
            }
            require_fund_account_mapping((int) $fund['id'], (int) $account['id'], date('Y-m-d'));
            $financialAccountId = (int) $account['id'];
        }
        $lines[] = [
            'fund_id' => (int) $fund['id'], 'account' => $key, 'financial_account_id' => $financialAccountId,
            'debit' => $debit, 'credit' => $credit, 'description' => input_str($row, 'description', 255) ?: null,
        ];
        $perFund[$fund['id']] = ($perFund[$fund['id']] ?? 0) + $debit - $credit;
    }
    if (count($lines) < 2) {
        fail_user('An adjusting entry needs at least two lines.');
    }
    if (count($lines) > 30) {
        fail_user('An adjusting entry can have at most 30 lines.');
    }
    foreach ($perFund as $fundId => $difference) {
        if ($difference !== 0) {
            $name = db_value('SELECT fund_name FROM fund_accounts WHERE institution_id = ? AND id = ?', [$institutionId, $fundId]);
            fail_user("The lines for {$name} do not balance (difference " . money(abs($difference), current_currency()) . '). Each fund must balance on its own; move money between funds with a fund transfer.');
        }
    }
    return $lines;
}

/** Creates or replaces a prepared (draft) adjusting entry. */
function save_adjusting_draft(array $input, ?int $entryId = null): int
{
    $institutionId = (int) current_institution_id();
    if (!can('ledger.post')) {
        fail_user('You do not have permission to prepare journal entries.');
    }
    $date = input_date($input, 'journal_date') ?? fail_user('Enter the entry date.');
    if ($date > date('Y-m-d')) {
        fail_user('The entry date cannot be in the future.');
    }
    $description = input_str($input, 'description', 2000);
    if (mb_strlen($description) < 10) {
        fail_user('Explain the reason for this adjustment (at least 10 characters).');
    }
    $lines = adjusting_lines_from_input($input);
    $userId = (int) auth_user()['id'];

    return db_transaction(function () use ($institutionId, $entryId, $date, $description, $lines, $userId) {
        if ($entryId) {
            $existing = db_one('SELECT * FROM journal_entries WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $entryId]);
            if (!$existing || $existing['status'] !== 'draft' || $existing['source_type'] !== 'adjustment') {
                fail_user('Only prepared adjusting entries can be edited.');
            }
            if ((int) $existing['created_by'] !== $userId) {
                fail_user('Only the person who prepared this entry can edit it.');
            }
            db_query('UPDATE journal_entries SET journal_date = ?, description = ? WHERE id = ?', [$date, $description, $entryId]);
            db_query('DELETE FROM journal_lines WHERE institution_id = ? AND journal_entry_id = ?', [$institutionId, $entryId]);
            $action = 'journal.draft_updated';
        } else {
            // The draft references itself until posted; source_id is set to its own id.
            db_query(
                "INSERT INTO journal_entries (institution_id, journal_number, journal_date, description, source_type, source_id, status, created_by)
                 VALUES (?, ?, ?, ?, 'adjustment', 0, 'draft', ?)",
                [$institutionId, next_document_number($institutionId, 'journal_entry', 'JE'), $date, $description, $userId]
            );
            $entryId = (int) db()->lastInsertId();
            db_query('UPDATE journal_entries SET source_id = id WHERE id = ?', [$entryId]);
            $action = 'journal.draft_prepared';
        }
        foreach ($lines as $line) {
            $account = ledger_account($institutionId, $line['account']);
            db_query(
                'INSERT INTO journal_lines (institution_id, journal_entry_id, fund_id, financial_account_id, account_code, account_name, debit_amount, credit_amount, description)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$institutionId, $entryId, $line['fund_id'], $line['financial_account_id'], $account['code'], $account['name'],
                 cents_to_decimal($line['debit']), cents_to_decimal($line['credit']), $line['description']]
            );
        }
        $total = array_sum(array_column($lines, 'debit'));
        audit_log($action, 'journal_entry', $entryId, $institutionId, $userId, ['journal_date' => $date, 'total' => cents_to_decimal($total), 'lines' => count($lines)]);
        notify_permission($institutionId, 'ledger.post', 'ledger', 'Adjusting entry to post',
            'An adjusting entry of ' . money($total, current_currency()) . ' was prepared and needs a second officer to post it.');
        return $entryId;
    });
}

/** Posts a prepared adjusting entry. The poster must not be the preparer. */
function post_adjusting_entry(int $entryId): void
{
    $institutionId = (int) current_institution_id();
    if (!can('ledger.post')) {
        fail_user('You do not have permission to post journal entries.');
    }
    $userId = (int) auth_user()['id'];
    db_transaction(function () use ($institutionId, $entryId, $userId) {
        $entry = db_one('SELECT * FROM journal_entries WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $entryId]);
        if (!$entry || $entry['source_type'] !== 'adjustment' || $entry['status'] !== 'draft') {
            fail_user('This entry is not waiting to be posted. It may already have been posted.');
        }
        if ((int) $entry['created_by'] === $userId) {
            fail_user('You prepared this entry, so another officer must review and post it.');
        }
        $lines = db_all('SELECT * FROM journal_lines WHERE institution_id = ? AND journal_entry_id = ? ORDER BY id', [$institutionId, $entryId]);
        $chartByCode = [];
        foreach (ledger_chart($institutionId) as $key => $account) {
            $chartByCode[$account['code']] = $key;
        }

        // Re-validate against current state: balance per fund, accounts still in the chart, cash available.
        $perFund = [];
        $cashOut = [];
        $cashRows = [];
        foreach ($lines as $line) {
            $key = $chartByCode[$line['account_code']] ?? null;
            if (!$key || !in_array($key, ADJUSTABLE_LEDGER_KEYS, true)) {
                fail_user('Account ' . $line['account_code'] . ' is no longer in the chart of accounts. Ask the preparer to edit the entry.');
            }
            $debit = to_cents($line['debit_amount']);
            $credit = to_cents($line['credit_amount']);
            $perFund[$line['fund_id']] = ($perFund[$line['fund_id']] ?? 0) + $debit - $credit;
            if ($line['financial_account_id']) {
                $slot = $line['fund_id'] . ':' . $line['financial_account_id'];
                $cashOut[$slot] = ($cashOut[$slot] ?? 0) + $credit - $debit;
                $cashRows[] = [
                    'fund_id' => (int) $line['fund_id'], 'financial_account_id' => (int) $line['financial_account_id'],
                    'direction' => $debit > 0 ? 'inflow' : 'outflow', 'amount' => $debit > 0 ? $debit : $credit,
                ];
            }
        }
        if (count($lines) < 2 || array_filter($perFund)) {
            fail_user('This entry does not balance within each fund and cannot be posted.');
        }
        foreach ($cashOut as $slot => $net) {
            if ($net > 0) {
                [$fundId, $accountId] = array_map('intval', explode(':', $slot));
                require_available_cash($institutionId, $fundId, $accountId, $net);
            }
        }

        db_query("UPDATE journal_entries SET status = 'posted', posted_by = ?, posted_at = NOW() WHERE id = ?", [$userId, $entryId]);
        record_cash_flows($institutionId, 'adjustment', $entryId, 'adjustment', $entry['journal_date'], $cashRows, 'posted');
        $total = array_sum(array_map(static fn($l) => to_cents($l['debit_amount']), $lines));
        audit_log('journal.posted', 'journal_entry', $entryId, $institutionId, $userId, ['status' => 'posted', 'total' => cents_to_decimal($total)], ['status' => 'draft']);
        notify_user($institutionId, (int) $entry['created_by'], 'ledger', 'Adjusting entry posted', $entry['journal_number'] . ' was reviewed and posted.');
    });
}

/** Discards a prepared entry that was never posted (preparer, or a reviewer with a reason). */
function discard_adjusting_draft(int $entryId, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (!can('ledger.post')) {
        fail_user('You do not have permission to manage journal entries.');
    }
    $userId = (int) auth_user()['id'];
    db_transaction(function () use ($institutionId, $entryId, $reason, $userId) {
        $entry = db_one('SELECT * FROM journal_entries WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $entryId]);
        if (!$entry || $entry['source_type'] !== 'adjustment' || $entry['status'] !== 'draft') {
            fail_user('Only prepared entries that were never posted can be discarded.');
        }
        $isPreparer = (int) $entry['created_by'] === $userId;
        if (!$isPreparer && mb_strlen($reason) < 5) {
            fail_user('Give the preparer a reason for declining the entry.');
        }
        $lines = db_all('SELECT fund_id, financial_account_id, account_code, debit_amount, credit_amount FROM journal_lines WHERE institution_id = ? AND journal_entry_id = ?', [$institutionId, $entryId]);
        db_query('DELETE FROM journal_lines WHERE institution_id = ? AND journal_entry_id = ?', [$institutionId, $entryId]);
        db_query('DELETE FROM journal_entries WHERE institution_id = ? AND id = ?', [$institutionId, $entryId]);
        audit_log($isPreparer ? 'journal.draft_discarded' : 'journal.draft_declined', 'journal_entry', $entryId, $institutionId, $userId, null,
            ['journal_number' => $entry['journal_number'], 'description' => $entry['description'], 'lines' => $lines], $reason ?: null);
        if (!$isPreparer) {
            notify_user($institutionId, (int) $entry['created_by'], 'ledger', 'Adjusting entry declined', $entry['journal_number'] . ' was not posted: ' . $reason);
        }
    });
}

/** Reverses a posted adjusting entry with a mirrored entry. */
function reverse_adjusting_entry(int $entryId, string $reason): int
{
    $institutionId = (int) current_institution_id();
    if (!can('ledger.post')) {
        fail_user('You do not have permission to reverse journal entries.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('Give a reason for the reversal.');
    }
    return db_transaction(function () use ($institutionId, $entryId, $reason) {
        $entry = db_one('SELECT * FROM journal_entries WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $entryId]);
        if (!$entry || $entry['source_type'] !== 'adjustment' || $entry['reversal_of_id'] || $entry['status'] !== 'posted') {
            fail_user('Only posted adjusting entries can be reversed here. Other entries are corrected from their source document.');
        }
        if (db_value('SELECT 1 FROM journal_entries WHERE institution_id = ? AND reversal_of_id = ?', [$institutionId, $entryId])) {
            fail_user('This entry has already been reversed.');
        }
        // Reversing would take cash out of an account that has since been spent.
        $lines = db_all('SELECT * FROM journal_lines WHERE institution_id = ? AND journal_entry_id = ? AND financial_account_id IS NOT NULL', [$institutionId, $entryId]);
        $cashOut = [];
        foreach ($lines as $line) {
            $slot = $line['fund_id'] . ':' . $line['financial_account_id'];
            $cashOut[$slot] = ($cashOut[$slot] ?? 0) + to_cents($line['debit_amount']) - to_cents($line['credit_amount']);
        }
        foreach ($cashOut as $slot => $net) {
            if ($net > 0) {
                [$fundId, $accountId] = array_map('intval', explode(':', $slot));
                require_available_cash($institutionId, $fundId, $accountId, $net);
            }
        }
        $reversalId = reverse_journal($institutionId, 'adjustment', $entryId, $reason);
        audit_log('journal.reversed', 'journal_entry', $entryId, $institutionId, (int) auth_user()['id'], ['reversal_entry_id' => $reversalId], null, $reason);
        return $reversalId;
    });
}

// ---------------------------------------------------------------------------
// Balances
// ---------------------------------------------------------------------------

/**
 * Trial balance from posted lines: one row per account code (optionally per fund),
 * with debit/credit totals and the balance on its normal side.
 */
function trial_balance(?int $fundId = null, ?string $asOf = null): array
{
    $institutionId = (int) current_institution_id();
    $where = ['l.institution_id = ?'];
    $params = [$institutionId];
    if ($fundId) {
        $where[] = 'l.fund_id = ?';
        $params[] = $fundId;
    }
    if ($asOf) {
        $where[] = 'j.journal_date <= ?';
        $params[] = $asOf;
    }
    $rows = db_all(
        "SELECT l.account_code, MAX(l.account_name) AS account_name, SUM(l.debit_amount) AS debits, SUM(l.credit_amount) AS credits
         FROM journal_lines l
         JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
         WHERE " . implode(' AND ', $where) . '
         GROUP BY l.account_code ORDER BY l.account_code',
        $params
    );
    foreach ($rows as &$row) {
        $chart = ledger_account_by_code($institutionId, $row['account_code']);
        $row['account_name'] = $chart['name'] ?? $row['account_name'];
        $row['class'] = $chart['class'] ?? 'unclassified';
        $net = to_cents($row['debits']) - to_cents($row['credits']);
        $row['debit_balance'] = $net > 0 ? $net : 0;
        $row['credit_balance'] = $net < 0 ? -$net : 0;
    }
    unset($row);
    $classOrder = ['asset' => 1, 'liability' => 2, 'equity' => 3, 'income' => 4, 'expense' => 5];
    usort($rows, static fn($a, $b) => [$classOrder[$a['class']] ?? 9, $a['account_code']] <=> [$classOrder[$b['class']] ?? 9, $b['account_code']]);
    return $rows;
}

// ---------------------------------------------------------------------------
// Integrity: ledger vs operational records
// ---------------------------------------------------------------------------

/**
 * Runs the ledger integrity checks. Each check returns rows describing problems;
 * an empty list means the check passed.
 */
function ledger_integrity_checks(): array
{
    $institutionId = (int) current_institution_id();
    // A completed source record must have exactly one live (unreversed) posting.
    $livePosting = static fn(string $sourceType, string $alias) => "EXISTS (SELECT 1 FROM journal_entries j WHERE j.institution_id = {$alias}.institution_id AND j.source_type = '{$sourceType}' AND j.source_id = {$alias}.id AND j.status = 'posted' AND j.reversal_of_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.institution_id = j.institution_id AND r.reversal_of_id = j.id))";

    $checks = [];

    $checks['unbalanced'] = [
        'title' => 'Every posted entry balances',
        'help'  => 'Total debits equal total credits on each journal entry.',
        'rows'  => db_all(
            "SELECT j.id, j.journal_number AS reference, CONCAT('Debits ', SUM(l.debit_amount), ' vs credits ', SUM(l.credit_amount)) AS detail
             FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id AND l.institution_id = j.institution_id
             WHERE j.institution_id = ? AND j.status = 'posted' GROUP BY j.id, j.journal_number
             HAVING SUM(l.debit_amount) <> SUM(l.credit_amount) OR COUNT(*) < 2",
            [$institutionId]
        ),
        'link' => 'ledger/entry.php?id=',
    ];

    $checks['fund_unbalanced'] = [
        'title' => 'Each fund balances',
        'help'  => 'Debits equal credits within every fund (funds are self-balancing).',
        'rows'  => db_all(
            "SELECT f.id, f.fund_name AS reference, CONCAT('Debits ', SUM(l.debit_amount), ' vs credits ', SUM(l.credit_amount)) AS detail
             FROM journal_lines l JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
             JOIN fund_accounts f ON f.id = l.fund_id AND f.institution_id = l.institution_id
             WHERE l.institution_id = ? GROUP BY f.id, f.fund_name HAVING SUM(l.debit_amount) <> SUM(l.credit_amount)",
            [$institutionId]
        ),
        'link' => 'funds/view.php?id=',
    ];

    $checks['cash_register'] = [
        'title' => 'Cash register matches the ledger',
        'help'  => 'For every fund and financial account, posted cash journal lines equal posted cash-flow rows.',
        'rows'  => db_all(
            "SELECT x.financial_account_id AS id, CONCAT(f.fund_name, ' · ', fa.account_name) AS reference,
                    CONCAT('Ledger ', SUM(x.ledger), ' vs register ', SUM(x.register)) AS detail
             FROM (
                SELECT l.fund_id, l.financial_account_id, SUM(l.debit_amount - l.credit_amount) AS ledger, 0 AS register
                FROM journal_lines l JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
                WHERE l.institution_id = ? AND l.financial_account_id IS NOT NULL GROUP BY l.fund_id, l.financial_account_id
                UNION ALL
                SELECT c.fund_id, c.financial_account_id, 0, SUM(CASE WHEN c.direction = 'inflow' THEN c.amount ELSE -c.amount END)
                FROM cash_flow_transactions c WHERE c.institution_id = ? AND c.status = 'posted' GROUP BY c.fund_id, c.financial_account_id
             ) x
             JOIN fund_accounts f ON f.id = x.fund_id AND f.institution_id = ?
             JOIN financial_accounts fa ON fa.id = x.financial_account_id AND fa.institution_id = ?
             GROUP BY x.fund_id, x.financial_account_id, f.fund_name, fa.account_name HAVING SUM(x.ledger) <> SUM(x.register)",
            [$institutionId, $institutionId, $institutionId, $institutionId]
        ),
        'link' => 'funds/account.php?id=',
    ];

    $sources = [
        'payments'  => ['Posted payments have a journal entry', "SELECT p.id, p.payment_number AS reference, CONCAT('Payment of ', p.amount) AS detail FROM payments p WHERE p.institution_id = ? AND p.status = 'posted' AND NOT %s", 'payment', 'p', 'collections/payment.php?id='],
        'expenses'  => ['Paid expenses have a journal entry', "SELECT x.id, x.expense_number AS reference, CONCAT('Expense of ', x.amount) AS detail FROM expenses x WHERE x.institution_id = ? AND x.status = 'paid' AND NOT %s", 'expense', 'x', 'expenses/view.php?id='],
        'deposits'  => ['Confirmed deposits have a journal entry', "SELECT d.id, d.deposit_number AS reference, CONCAT('Deposit of ', d.amount) AS detail FROM deposits d WHERE d.institution_id = ? AND d.status = 'confirmed' AND NOT %s", 'deposit', 'd', 'deposits/view.php?id='],
        'transfers' => ['Completed fund transfers have a journal entry', "SELECT t.id, t.transfer_number AS reference, CONCAT('Transfer of ', t.amount) AS detail FROM fund_transfers t WHERE t.institution_id = ? AND t.status = 'posted' AND NOT %s", 'fund_transfer', 't', 'transfers/view.php?id='],
        'turnovers' => ['Verified turnovers have a journal entry', "SELECT t.id, t.turnover_number AS reference, CONCAT('Turnover of ', t.received_amount) AS detail FROM cash_turnovers t WHERE t.institution_id = ? AND t.status IN ('verified', 'discrepancy') AND t.received_amount > 0 AND NOT %s", 'turnover', 't', 'turnovers/view.php?id='],
        'releases'  => ['Released advances have a journal entry', "SELECT a.id, a.advance_number AS reference, CONCAT('Release of ', a.released_amount) AS detail FROM cash_advances a WHERE a.institution_id = ? AND a.status NOT IN ('draft', 'pending_approval', 'approved', 'cancelled') AND NOT %s", 'advance_release', 'a', 'advances/view.php?id='],
        'liquidated'=> ['Verified liquidation expenses have a journal entry', "SELECT x.cash_advance_id AS id, CONCAT('Expense line #', x.id) AS reference, CONCAT('Amount ', x.amount) AS detail FROM cash_advance_expenses x WHERE x.institution_id = ? AND x.verification_status = 'verified' AND NOT %s", 'advance_expense', 'x', 'advances/view.php?id='],
        'returns'   => ['Confirmed advance returns have a journal entry', "SELECT r.cash_advance_id AS id, r.return_number AS reference, CONCAT('Return of ', r.returned_amount) AS detail FROM cash_advance_returns r WHERE r.institution_id = ? AND r.status = 'confirmed' AND NOT %s", 'advance_return', 'r', 'advances/view.php?id='],
    ];
    if (function_exists('corrections_ready') && corrections_ready()) {
        $sources['adjustments'] = ['Refunds and applied overpayments have a journal entry', "SELECT a.event_id AS id, a.adjustment_number AS reference, CONCAT(a.adjustment_type, ' of ', a.amount) AS detail FROM payment_adjustments a WHERE a.institution_id = ? AND NOT %s", 'payment_adjustment', 'a', 'events/overpayments.php?event='];
        $sources['corrections'] = ['Amount corrections with overpayments have a journal entry', "SELECT c.event_id AS id, CONCAT('Correction #', c.id) AS reference, CONCAT('Overpaid ', c.overpaid_total) AS detail FROM rule_corrections c WHERE c.institution_id = ? AND c.overpaid_total > 0 AND NOT %s", 'rule_correction', 'c', 'events/overpayments.php?event='];
    }
    foreach ($sources as $key => [$title, $sql, $sourceType, $alias, $link]) {
        $checks[$key] = [
            'title' => $title,
            'help'  => 'Operational records in a completed state must be posted to the ledger exactly once.',
            'rows'  => db_all(sprintf($sql, $livePosting($sourceType, $alias)), [$institutionId]),
            'link'  => $link,
        ];
    }

    // Advances receivable in the ledger vs released − verified − confirmed returns per fund.
    $advanceRows = [];
    $operational = db_all(
        "SELECT a.fund_id, f.fund_name,
                SUM(a.released_amount) - COALESCE(SUM((SELECT SUM(x.amount) FROM cash_advance_expenses x WHERE x.institution_id = a.institution_id AND x.cash_advance_id = a.id AND x.verification_status = 'verified')), 0)
                  - COALESCE(SUM((SELECT SUM(r.returned_amount) FROM cash_advance_returns r WHERE r.institution_id = a.institution_id AND r.cash_advance_id = a.id AND r.status = 'confirmed')), 0) AS outstanding
         FROM cash_advances a JOIN fund_accounts f ON f.id = a.fund_id AND f.institution_id = a.institution_id
         WHERE a.institution_id = ? AND a.status NOT IN ('draft', 'pending_approval', 'approved', 'cancelled') GROUP BY a.fund_id, f.fund_name",
        [$institutionId]
    );
    $byFund = [];
    foreach ($operational as $row) {
        $byFund[(int) $row['fund_id']] = ['name' => $row['fund_name'], 'operational' => to_cents($row['outstanding']), 'ledger' => 0];
    }
    foreach (db_all(
        "SELECT l.fund_id, f.fund_name, SUM(l.debit_amount - l.credit_amount) AS balance FROM journal_lines l
         JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
         JOIN fund_accounts f ON f.id = l.fund_id AND f.institution_id = l.institution_id
         WHERE l.institution_id = ? AND l.account_code = ? GROUP BY l.fund_id, f.fund_name",
        [$institutionId, ledger_account($institutionId, 'advances')['code']]
    ) as $row) {
        $byFund[(int) $row['fund_id']] ??= ['name' => $row['fund_name'], 'operational' => 0, 'ledger' => 0];
        $byFund[(int) $row['fund_id']]['ledger'] = to_cents($row['balance']);
    }
    foreach ($byFund as $fundId => $row) {
        if ($row['operational'] !== $row['ledger']) {
            $advanceRows[] = ['id' => $fundId, 'reference' => $row['name'],
                'detail' => 'Ledger ' . cents_to_decimal($row['ledger']) . ' vs advances outstanding ' . cents_to_decimal($row['operational'])];
        }
    }
    $checks['advances'] = [
        'title' => 'Advances receivable matches the advance records',
        'help'  => 'Per fund: ledger Cash Advances Receivable = released − verified expenses − confirmed returns.',
        'rows'  => $advanceRows,
        'link'  => 'funds/view.php?id=',
    ];

    $checks['drafts'] = [
        'title' => 'No stale prepared entries',
        'help'  => 'Adjusting entries prepared more than 7 days ago and still not posted.',
        'rows'  => db_all(
            "SELECT id, journal_number AS reference, CONCAT('Prepared ', DATE_FORMAT(created_at, '%Y-%m-%d')) AS detail FROM journal_entries
             WHERE institution_id = ? AND status = 'draft' AND created_at < (NOW() - INTERVAL 7 DAY)",
            [$institutionId]
        ),
        'link' => 'ledger/entry.php?id=',
    ];

    return $checks;
}
