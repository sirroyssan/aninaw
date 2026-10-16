<?php
/**
 * Movements of existing money: deposits between financial accounts and transfers
 * between funds. Neither is income or expense.
 *
 * Deposit  (same fund):     Dr Cash (destination account) / Cr Cash (source account)
 * Transfer (same account):  Dr Cash (to-fund)              / Cr Cash (from-fund)
 *
 * Both are recorded first with pending register rows (no effect on balances) and only
 * posted after confirmation/approval by a different person.
 */
declare(strict_types=1);

require_once __DIR__ . '/funds.php';

// ---------------------------------------------------------------------------
// Deposits
// ---------------------------------------------------------------------------

/** Records a deposit of cash from one financial account into another (e.g. cash box -> bank). */
function record_deposit(array $input): int
{
    if (!can('deposits.manage')) {
        fail_user('You do not have permission to record deposits.');
    }
    $institutionId = (int) current_institution_id();
    $fundId = input_int($input, 'fund_id') ?? 0;
    $amount = parse_money(input_str($input, 'amount', 20)) ?? fail_user('Enter the deposited amount.');
    $date = input_date($input, 'deposit_date') ?? fail_user('Enter the deposit date.');
    $reference = input_str($input, 'reference_number', 150);
    if ($date > date('Y-m-d')) {
        fail_user('The deposit date cannot be in the future.');
    }
    if ($reference === '') {
        fail_user('Enter the bank or deposit slip reference number.');
    }

    return db_transaction(function () use ($institutionId, $fundId, $amount, $date, $reference, $input) {
        find_fund($fundId);
        $source = find_financial_account(input_int($input, 'source_account_id') ?? 0);
        $destination = find_financial_account(input_int($input, 'destination_account_id') ?? 0);
        if ((int) $source['id'] === (int) $destination['id']) {
            fail_user('Source and destination must be different accounts.');
        }
        require_usable_account($source);
        require_fund_account_mapping($fundId, (int) $source['id'], $date);
        require_fund_account_mapping($fundId, (int) $destination['id'], $date);
        require_available_cash($institutionId, $fundId, (int) $source['id'], $amount);

        $number = next_document_number($institutionId, 'deposit', 'DEP');
        db_query(
            "INSERT INTO deposits (institution_id, deposit_number, source_account_id, destination_account_id, deposit_date, amount, reference_number, status, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$institutionId, $number, $source['id'], $destination['id'], $date, cents_to_decimal($amount), $reference,
             has_upload('attachment') ? 'pending_confirmation' : 'recorded', auth_user()['id']]
        );
        $depositId = (int) db()->lastInsertId();
        record_cash_flows($institutionId, 'deposit', $depositId, 'deposit', $date, [
            ['fund_id' => $fundId, 'financial_account_id' => (int) $source['id'], 'direction' => 'outflow', 'amount' => $amount],
            ['fund_id' => $fundId, 'financial_account_id' => (int) $destination['id'], 'direction' => 'inflow', 'amount' => $amount],
        ], 'pending');
        if (has_upload('attachment')) {
            store_attachment('attachment', 'deposit', $depositId);
        }
        audit_log('deposit.recorded', 'deposit', $depositId, $institutionId, (int) auth_user()['id'],
            ['deposit_number' => $number, 'fund_id' => $fundId, 'source' => (int) $source['id'], 'destination' => (int) $destination['id'], 'amount' => cents_to_decimal($amount)]);
        notify_permission($institutionId, 'deposits.manage', 'deposit', 'Deposit awaiting verification', $number . ': ' . money($amount, current_currency()) . ' into ' . $destination['account_name'] . '.');
        return $depositId;
    });
}

function find_deposit(int $depositId): ?array
{
    $deposit = db_one(
        "SELECT d.*, s.account_name AS source_name, s.account_type AS source_type, s.custodian_membership_id AS source_custodian,
                t.account_name AS destination_name, t.account_type AS destination_type,
                CONCAT(ru.first_name, ' ', ru.last_name) AS recorded_by_name, CONCAT(cu.first_name, ' ', cu.last_name) AS confirmed_by_name,
                (SELECT c.fund_id FROM cash_flow_transactions c WHERE c.institution_id = d.institution_id AND c.source_type = 'deposit' AND c.source_id = d.id LIMIT 1) AS fund_id
         FROM deposits d
         JOIN financial_accounts s ON s.id = d.source_account_id AND s.institution_id = d.institution_id
         JOIN financial_accounts t ON t.id = d.destination_account_id AND t.institution_id = d.institution_id
         JOIN users ru ON ru.id = d.recorded_by
         LEFT JOIN users cu ON cu.id = d.confirmed_by
         WHERE d.institution_id = ? AND d.id = ?",
        [current_institution_id(), $depositId]
    );
    if ($deposit) {
        $deposit['fund_name'] = db_value('SELECT fund_name FROM fund_accounts WHERE id = ?', [$deposit['fund_id']]);
    }
    return $deposit;
}

/** Verification: a different person confirms the deposit against the bank record; it is then posted. */
function confirm_deposit(int $depositId): void
{
    $institutionId = (int) current_institution_id();
    if (!can('deposits.manage')) {
        fail_user('You do not have permission to confirm deposits.');
    }
    db_transaction(function () use ($institutionId, $depositId) {
        db_query('SELECT id FROM deposits WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $depositId]);
        $deposit = find_deposit($depositId) ?? fail_user('Deposit not found.');
        if (!in_array($deposit['status'], ['recorded', 'pending_confirmation'], true)) {
            fail_user('This deposit is already ' . humanize($deposit['status']) . '.');
        }
        if ((int) $deposit['recorded_by'] === (int) auth_user()['id']) {
            fail_user('A deposit must be verified by someone other than the person who recorded it.');
        }
        $amount = to_cents($deposit['amount']);
        require_available_cash($institutionId, (int) $deposit['fund_id'], (int) $deposit['source_account_id'], $amount);
        post_journal([
            'institution_id' => $institutionId,
            'date' => $deposit['deposit_date'],
            'description' => 'Deposit ' . $deposit['deposit_number'] . ' from ' . $deposit['source_name'] . ' to ' . $deposit['destination_name'] . ' (ref ' . $deposit['reference_number'] . ')',
            'source_type' => 'deposit',
            'source_id' => $depositId,
            'cash_flow_type' => 'deposit',
            'lines' => [
                ['fund_id' => (int) $deposit['fund_id'], 'account' => cash_ledger_key($deposit['destination_type']), 'financial_account_id' => (int) $deposit['destination_account_id'], 'debit' => $amount],
                ['fund_id' => (int) $deposit['fund_id'], 'account' => cash_ledger_key($deposit['source_type']), 'financial_account_id' => (int) $deposit['source_account_id'], 'credit' => $amount],
            ],
        ]);
        db_query("UPDATE deposits SET status = 'confirmed', confirmed_by = ?, confirmed_at = NOW() WHERE id = ?", [auth_user()['id'], $depositId]);
        audit_log('deposit.confirmed', 'deposit', $depositId, $institutionId, (int) auth_user()['id'], ['status' => 'confirmed'], ['status' => $deposit['status']]);
    });
}

/** Rejects an unconfirmed deposit, or reverses a confirmed one, with a reason. */
function cancel_deposit(int $depositId, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (!can('deposits.manage')) {
        fail_user('You do not have permission to change deposits.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('A reason is required.');
    }
    db_transaction(function () use ($institutionId, $depositId, $reason) {
        db_query('SELECT id FROM deposits WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $depositId]);
        $deposit = find_deposit($depositId) ?? fail_user('Deposit not found.');
        if ($deposit['status'] === 'reversed') {
            fail_user('This deposit is already reversed.');
        }
        if ($deposit['status'] === 'confirmed') {
            require_available_cash($institutionId, (int) $deposit['fund_id'], (int) $deposit['destination_account_id'], to_cents($deposit['amount']));
            reverse_journal($institutionId, 'deposit', $depositId, $reason);
        } else {
            cancel_pending_cash_flows($institutionId, 'deposit', $depositId);
        }
        db_query("UPDATE deposits SET status = 'reversed' WHERE id = ?", [$depositId]);
        audit_log('deposit.reversed', 'deposit', $depositId, $institutionId, (int) auth_user()['id'], ['status' => 'reversed'], ['status' => $deposit['status']], $reason);
    });
}

// ---------------------------------------------------------------------------
// Fund transfers
// ---------------------------------------------------------------------------

/** Requests a transfer between two funds held in the same financial account. */
function request_fund_transfer(array $input): int
{
    if (!can('transfers.manage')) {
        fail_user('You do not have permission to request transfers.');
    }
    $institutionId = (int) current_institution_id();
    $fromId = input_int($input, 'from_fund_id') ?? 0;
    $toId = input_int($input, 'to_fund_id') ?? 0;
    $amount = parse_money(input_str($input, 'amount', 20)) ?? fail_user('Enter the amount to transfer.');
    $date = input_date($input, 'transfer_date') ?? fail_user('Enter the transfer date.');
    $purpose = input_str($input, 'purpose', 2000);
    $accountId = input_int($input, 'financial_account_id') ?? 0;
    if ($fromId === $toId) {
        fail_user('Choose two different funds.');
    }
    if (mb_strlen($purpose) < 10) {
        fail_user('Describe the purpose and authority for this transfer (at least 10 characters).');
    }

    return db_transaction(function () use ($institutionId, $fromId, $toId, $amount, $date, $purpose, $accountId) {
        $from = find_fund($fromId);
        $to = find_fund($toId);
        $account = find_financial_account($accountId);
        require_fund_account_mapping($fromId, $accountId, $date);
        // Unauthorised destination: the receiving fund must be allowed to hold money in this account.
        if (!fund_account_is_mapped($institutionId, $toId, $accountId, $date)) {
            fail_user($to['fund_name'] . ' is not authorised to hold money in ' . $account['account_name'] . '. Map the account to that fund first.');
        }
        if (db_value(
            "SELECT 1 FROM fund_transfers WHERE institution_id = ? AND from_fund_id = ? AND to_fund_id = ? AND amount = ? AND transfer_date = ?
               AND status IN ('submitted', 'approved') LIMIT 1",
            [$institutionId, $fromId, $toId, cents_to_decimal($amount), $date]
        )) {
            fail_user('An identical transfer request is already waiting for approval.');
        }
        if (cash_balance($institutionId, $fromId, $accountId) < $amount) {
            fail_user('Insufficient funds: ' . $from['fund_name'] . ' holds only ' . money(cash_balance($institutionId, $fromId, $accountId), current_currency()) . ' in ' . $account['account_name'] . '.');
        }

        $number = next_document_number($institutionId, 'fund_transfer', 'FT');
        db_query(
            "INSERT INTO fund_transfers (institution_id, transfer_number, from_fund_id, to_fund_id, amount, transfer_date, purpose, status, requested_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', ?)",
            [$institutionId, $number, $fromId, $toId, cents_to_decimal($amount), $date, $purpose, auth_user()['id']]
        );
        $transferId = (int) db()->lastInsertId();
        record_cash_flows($institutionId, 'fund_transfer', $transferId, 'transfer', $date, [
            ['fund_id' => $fromId, 'financial_account_id' => $accountId, 'direction' => 'outflow', 'amount' => $amount],
            ['fund_id' => $toId, 'financial_account_id' => $accountId, 'direction' => 'inflow', 'amount' => $amount],
        ], 'pending');
        if (has_upload('attachment')) {
            store_attachment('attachment', 'fund_transfer', $transferId);
        }
        audit_log('transfer.requested', 'fund_transfer', $transferId, $institutionId, (int) auth_user()['id'],
            ['transfer_number' => $number, 'from' => $fromId, 'to' => $toId, 'amount' => cents_to_decimal($amount), 'account' => $accountId]);
        notify_permission($institutionId, 'transfers.approve', 'transfer', 'Transfer awaiting approval',
            $number . ': ' . money($amount, current_currency()) . ' from ' . $from['fund_name'] . ' to ' . $to['fund_name'] . '.');
        return $transferId;
    });
}

function find_transfer(int $transferId): ?array
{
    $transfer = db_one(
        "SELECT t.*, ff.fund_name AS from_fund_name, tf.fund_name AS to_fund_name,
                CONCAT(ru.first_name, ' ', ru.last_name) AS requested_by_name, CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name
         FROM fund_transfers t
         JOIN fund_accounts ff ON ff.id = t.from_fund_id AND ff.institution_id = t.institution_id
         JOIN fund_accounts tf ON tf.id = t.to_fund_id AND tf.institution_id = t.institution_id
         JOIN users ru ON ru.id = t.requested_by
         LEFT JOIN users au ON au.id = t.approved_by
         WHERE t.institution_id = ? AND t.id = ?",
        [current_institution_id(), $transferId]
    );
    if ($transfer) {
        $account = db_one(
            "SELECT fa.id, fa.account_name, fa.account_type FROM cash_flow_transactions c JOIN financial_accounts fa ON fa.id = c.financial_account_id
             WHERE c.institution_id = ? AND c.source_type = 'fund_transfer' AND c.source_id = ? ORDER BY c.id LIMIT 1",
            [current_institution_id(), $transferId]
        );
        $transfer['account'] = $account;
    }
    return $transfer;
}

/** Approval decision by a different person (transfers.approve). Approve, or reject with a reason. */
function decide_fund_transfer(int $transferId, bool $approve, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (!can('transfers.approve')) {
        fail_user('You do not have permission to approve transfers.');
    }
    db_transaction(function () use ($institutionId, $transferId, $approve, $reason) {
        db_query('SELECT id FROM fund_transfers WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $transferId]);
        $transfer = find_transfer($transferId) ?? fail_user('Transfer not found.');
        if ($transfer['status'] !== 'submitted') {
            fail_user('This transfer is ' . humanize($transfer['status']) . ', not waiting for approval.');
        }
        if ((int) $transfer['requested_by'] === (int) auth_user()['id']) {
            fail_user('You cannot approve a transfer you requested.');
        }
        if (!$approve) {
            if (mb_strlen($reason) < 5) {
                fail_user('Give the reason for rejecting.');
            }
            cancel_pending_cash_flows($institutionId, 'fund_transfer', $transferId);
            db_query("UPDATE fund_transfers SET status = 'rejected', approved_by = ? WHERE id = ?", [auth_user()['id'], $transferId]);
            audit_log('transfer.rejected', 'fund_transfer', $transferId, $institutionId, (int) auth_user()['id'], ['status' => 'rejected'], ['status' => 'submitted'], $reason);
        } else {
            db_query("UPDATE fund_transfers SET status = 'approved', approved_by = ? WHERE id = ?", [auth_user()['id'], $transferId]);
            audit_log('transfer.approved', 'fund_transfer', $transferId, $institutionId, (int) auth_user()['id'], ['status' => 'approved'], ['status' => 'submitted'], $reason ?: null);
        }
        $requester = db_value('SELECT m.id FROM institution_memberships m WHERE m.institution_id = ? AND m.user_id = ?', [$institutionId, $transfer['requested_by']]);
        if ($requester) {
            notify_membership($institutionId, (int) $requester, 'transfer', 'Transfer ' . ($approve ? 'approved' : 'rejected'), $transfer['transfer_number'] . ($approve ? ' was approved and can be posted.' : ' was rejected: ' . $reason));
        }
    });
}

/** Posts an approved transfer to the ledger. Balances change only now. */
function post_fund_transfer(int $transferId): void
{
    $institutionId = (int) current_institution_id();
    if (!can('transfers.manage')) {
        fail_user('You do not have permission to post transfers.');
    }
    db_transaction(function () use ($institutionId, $transferId) {
        db_query('SELECT id FROM fund_transfers WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $transferId]);
        $transfer = find_transfer($transferId) ?? fail_user('Transfer not found.');
        if ($transfer['status'] !== 'approved') {
            fail_user('Only approved transfers can be posted.');
        }
        $account = $transfer['account'] ?? fail_user('The transfer account is missing.');
        $amount = to_cents($transfer['amount']);
        find_fund((int) $transfer['to_fund_id']);
        require_available_cash($institutionId, (int) $transfer['from_fund_id'], (int) $account['id'], $amount);
        $key = cash_ledger_key($account['account_type']);
        post_journal([
            'institution_id' => $institutionId,
            'date' => $transfer['transfer_date'],
            'description' => 'Fund transfer ' . $transfer['transfer_number'] . ' from ' . $transfer['from_fund_name'] . ' to ' . $transfer['to_fund_name'] . ': ' . mb_substr($transfer['purpose'], 0, 300),
            'source_type' => 'fund_transfer',
            'source_id' => $transferId,
            'cash_flow_type' => 'transfer',
            'lines' => [
                ['fund_id' => (int) $transfer['to_fund_id'], 'account' => $key, 'financial_account_id' => (int) $account['id'], 'debit' => $amount],
                ['fund_id' => (int) $transfer['from_fund_id'], 'account' => $key, 'financial_account_id' => (int) $account['id'], 'credit' => $amount],
            ],
        ]);
        db_query("UPDATE fund_transfers SET status = 'posted', posted_at = NOW() WHERE id = ?", [$transferId]);
        audit_log('transfer.posted', 'fund_transfer', $transferId, $institutionId, (int) auth_user()['id'], ['status' => 'posted'], ['status' => 'approved']);
    });
}

/** Withdraws a request (requester, before posting) or reverses a posted transfer (with reason). */
function cancel_fund_transfer(int $transferId, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (!can('transfers.manage')) {
        fail_user('You do not have permission to change transfers.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('A reason is required.');
    }
    db_transaction(function () use ($institutionId, $transferId, $reason) {
        db_query('SELECT id FROM fund_transfers WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $transferId]);
        $transfer = find_transfer($transferId) ?? fail_user('Transfer not found.');
        if ($transfer['status'] === 'posted') {
            require_available_cash($institutionId, (int) $transfer['to_fund_id'], (int) $transfer['account']['id'], to_cents($transfer['amount']));
            reverse_journal($institutionId, 'fund_transfer', $transferId, $reason);
            $status = 'reversed';
        } elseif (in_array($transfer['status'], ['draft', 'submitted', 'approved'], true)) {
            cancel_pending_cash_flows($institutionId, 'fund_transfer', $transferId);
            $status = 'rejected';
        } else {
            fail_user('This transfer is already ' . humanize($transfer['status']) . '.');
        }
        db_query('UPDATE fund_transfers SET status = ? WHERE id = ?', [$status, $transferId]);
        audit_log('transfer.' . ($status === 'reversed' ? 'reversed' : 'withdrawn'), 'fund_transfer', $transferId, $institutionId, (int) auth_user()['id'], ['status' => $status], ['status' => $transfer['status']], $reason);
    });
}

// ---------------------------------------------------------------------------
// Reconciliation
// ---------------------------------------------------------------------------

/**
 * Per financial account: ledger cash balance vs. posted register balance (they must agree),
 * pending movements, and the most recent physical count.
 */
function reconciliation_accounts(): array
{
    $institutionId = (int) current_institution_id();
    $rows = db_all(
        "SELECT fa.id, fa.account_name, fa.account_type, fa.status, CONCAT(u.first_name, ' ', u.last_name) AS custodian_name,
                (SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0) FROM journal_lines l
                   JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
                  WHERE l.institution_id = fa.institution_id AND l.financial_account_id = fa.id) AS ledger_balance,
                (SELECT COALESCE(SUM(CASE WHEN c.direction = 'inflow' THEN c.amount ELSE -c.amount END), 0) FROM cash_flow_transactions c
                  WHERE c.institution_id = fa.institution_id AND c.financial_account_id = fa.id AND c.status = 'posted') AS register_balance,
                (SELECT COALESCE(SUM(CASE WHEN c.direction = 'inflow' THEN c.amount ELSE -c.amount END), 0) FROM cash_flow_transactions c
                  WHERE c.institution_id = fa.institution_id AND c.financial_account_id = fa.id AND c.status = 'pending') AS pending_net
         FROM financial_accounts fa
         LEFT JOIN institution_memberships m ON m.id = fa.custodian_membership_id AND m.institution_id = fa.institution_id
         LEFT JOIN users u ON u.id = m.user_id
         WHERE fa.institution_id = ? AND fa.status <> 'closed'
         ORDER BY fa.account_type, fa.account_name",
        [$institutionId]
    );
    foreach ($rows as &$row) {
        $row['last_count'] = db_one(
            "SELECT a.after_data, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.institution_id = ? AND a.entity_type = 'financial_account' AND a.entity_id = ? AND a.action = 'reconciliation.counted'
             ORDER BY a.id DESC LIMIT 1",
            [$institutionId, $row['id']]
        );
        if ($row['last_count']) {
            $row['last_count']['data'] = json_decode((string) $row['last_count']['after_data'], true) ?: [];
        }
    }
    unset($row);
    return $rows;
}

/**
 * Records a physical cash count or statement balance for an account and compares it with
 * the ledger. Differences are documented here; correcting them requires an authorised
 * adjusting entry in the ledger (no balance is edited directly).
 */
function record_account_count(int $accountId, array $input): array
{
    $institutionId = (int) current_institution_id();
    if (!can('reconciliation.manage')) {
        fail_user('You do not have permission to reconcile accounts.');
    }
    $account = find_financial_account($accountId, false);
    $counted = parse_money(input_str($input, 'counted_amount', 20), true);
    if ($counted === null) {
        fail_user('Enter the counted or statement amount.');
    }
    $asOf = input_date($input, 'as_of') ?? date('Y-m-d');
    $notes = input_str($input, 'notes', 1000);
    $ledger = to_cents(db_value(
        "SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0) FROM journal_lines l
         JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
         WHERE l.institution_id = ? AND l.financial_account_id = ? AND j.journal_date <= ?",
        [$institutionId, $accountId, $asOf]
    ));
    $difference = $counted - $ledger;
    if ($difference !== 0 && mb_strlen($notes) < 5) {
        fail_user('The count differs from the ledger by ' . money($difference, current_currency()) . '. Explain the difference in the notes.');
    }
    $result = ['as_of' => $asOf, 'counted' => cents_to_decimal($counted), 'ledger' => cents_to_decimal($ledger), 'difference' => cents_to_decimal($difference), 'notes' => $notes];
    audit_log('reconciliation.counted', 'financial_account', $accountId, $institutionId, (int) auth_user()['id'], $result, null, $notes ?: null);
    if ($difference !== 0) {
        notify_permission($institutionId, 'ledger.post', 'reconciliation', 'Account count difference',
            $account['account_name'] . ': counted ' . money($counted, current_currency()) . ', ledger ' . money($ledger, current_currency()) . '.');
    }
    return $result;
}
