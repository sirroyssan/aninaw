<?php
/**
 * Cash advances and liquidation.
 *
 *  request (pending_approval) -> approve | reject (cancelled)
 *  release:        Dr Cash Advances Receivable / Cr Cash            (released)
 *  recipient adds expenses (pending) and returns of unused cash (pending_verification),
 *  then submits the liquidation                                      (submitted_for_review)
 *  reviewer verifies each expense:  Dr Expenses / Cr Advances Receivable
 *  custodian/reviewer confirms each return:  Dr Cash / Cr Advances Receivable
 *  reviewer returns for correction                                   (returned_for_correction)
 *  liquidated only when released − verified expenses − confirmed returns <= 0 and nothing is pending.
 *
 * Unverified expenses and unconfirmed returns never reduce the outstanding balance.
 * Verified expenses above the advance are reported as an excess subject to
 * reimbursement review; nothing is paid automatically.
 */
declare(strict_types=1);

require_once __DIR__ . '/expenses.php';

const ADVANCE_TYPES = ['activity_advance' => 'Activity advance', 'temporary_borrowing' => 'Temporary borrowing'];
const ADVANCE_OPEN_STATUSES = ['released', 'partially_liquidated', 'submitted_for_review', 'returned_for_correction', 'overdue'];

function find_advance(int $advanceId): ?array
{
    $advance = db_one(
        "SELECT a.*, f.fund_name, e.event_name, fa.account_name AS released_from,
                ru.id AS recipient_user_id, CONCAT(ru.first_name, ' ', ru.last_name) AS recipient_name,
                CONCAT(qu.first_name, ' ', qu.last_name) AS requested_by_name, CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name,
                CONCAT(lu.first_name, ' ', lu.last_name) AS released_by_name
         FROM cash_advances a
         JOIN fund_accounts f ON f.id = a.fund_id AND f.institution_id = a.institution_id
         LEFT JOIN events e ON e.id = a.event_id AND e.institution_id = a.institution_id
         LEFT JOIN financial_accounts fa ON fa.id = a.financial_account_id AND fa.institution_id = a.institution_id
         JOIN institution_memberships rm ON rm.id = a.recipient_membership_id JOIN users ru ON ru.id = rm.user_id
         JOIN users qu ON qu.id = a.requested_by
         LEFT JOIN users au ON au.id = a.approved_by
         LEFT JOIN users lu ON lu.id = a.released_by
         WHERE a.institution_id = ? AND a.id = ?",
        [current_institution_id(), $advanceId]
    );
    if (!$advance) {
        return null;
    }
    $advance['is_recipient'] = (int) $advance['recipient_membership_id'] === current_membership_id();
    if (!$advance['is_recipient'] && !can_any(['advances.view', 'advances.approve', 'advances.release', 'liquidation.review'])) {
        return null;
    }
    return $advance + advance_figures($advanceId);
}

/** Released, verified, submitted, confirmed returns and outstanding (centavos). */
function advance_figures(int $advanceId): array
{
    $institutionId = (int) current_institution_id();
    $released = to_cents(db_value('SELECT released_amount FROM cash_advances WHERE institution_id = ? AND id = ?', [$institutionId, $advanceId]));
    $expenses = db_one(
        "SELECT COALESCE(SUM(CASE WHEN verification_status = 'verified' THEN amount END), 0) AS verified,
                COALESCE(SUM(CASE WHEN verification_status IN ('pending', 'needs_correction') THEN amount END), 0) AS pending,
                SUM(verification_status IN ('pending', 'needs_correction')) AS pending_count
         FROM cash_advance_expenses WHERE institution_id = ? AND cash_advance_id = ?",
        [$institutionId, $advanceId]
    );
    $returns = db_one(
        "SELECT COALESCE(SUM(CASE WHEN status = 'confirmed' THEN returned_amount END), 0) AS confirmed,
                COALESCE(SUM(CASE WHEN status = 'pending_verification' THEN returned_amount END), 0) AS pending,
                SUM(status = 'pending_verification') AS pending_count
         FROM cash_advance_returns WHERE institution_id = ? AND cash_advance_id = ?",
        [$institutionId, $advanceId]
    );
    $verified = to_cents($expenses['verified']);
    $returned = to_cents($returns['confirmed']);
    $balance = $released - $verified - $returned;
    return [
        'released_cents'        => $released,
        'verified_cents'        => $verified,
        'pending_expense_cents' => to_cents($expenses['pending']),
        'pending_expense_count' => (int) $expenses['pending_count'],
        'returned_cents'        => $returned,
        'pending_return_cents'  => to_cents($returns['pending']),
        'pending_return_count'  => (int) $returns['pending_count'],
        'outstanding_cents'     => max(0, $balance),
        'excess_cents'          => max(0, -$balance),
    ];
}

function lock_advance(int $advanceId): array
{
    db_query('SELECT id FROM cash_advances WHERE institution_id = ? AND id = ? FOR UPDATE', [current_institution_id(), $advanceId]);
    return find_advance($advanceId) ?? fail_user('Cash advance not found.');
}

/** Status derived from the figures once the advance has been released. */
function refresh_advance_status(int $advanceId): void
{
    $institutionId = (int) current_institution_id();
    $advance = db_one('SELECT status, liquidation_deadline FROM cash_advances WHERE institution_id = ? AND id = ?', [$institutionId, $advanceId]);
    if (!$advance || !in_array($advance['status'], array_merge(ADVANCE_OPEN_STATUSES, ['liquidated']), true)) {
        return;
    }
    $figures = advance_figures($advanceId);
    $nothingPending = $figures['pending_expense_count'] === 0 && $figures['pending_return_count'] === 0;
    if ($figures['outstanding_cents'] === 0 && $nothingPending) {
        $status = 'liquidated';
    } elseif (in_array($advance['status'], ['submitted_for_review', 'returned_for_correction'], true)) {
        $status = $advance['status'];
    } elseif ($advance['liquidation_deadline'] && $advance['liquidation_deadline'] < date('Y-m-d')) {
        $status = 'overdue';
    } elseif ($figures['verified_cents'] > 0 || $figures['returned_cents'] > 0) {
        $status = 'partially_liquidated';
    } else {
        $status = 'released';
    }
    if ($status !== $advance['status']) {
        db_query('UPDATE cash_advances SET status = ? WHERE id = ?', [$status, $advanceId]);
        if ($status === 'liquidated') {
            audit_log('advance.liquidated', 'cash_advance', $advanceId, $institutionId, auth_user() ? (int) auth_user()['id'] : null, ['status' => 'liquidated'] + array_map('cents_to_decimal', array_intersect_key($figures, ['released_cents' => 1, 'verified_cents' => 1, 'returned_cents' => 1, 'excess_cents' => 1])));
        }
    }
}

// ---------------------------------------------------------------------------
// Request, approval, release
// ---------------------------------------------------------------------------

function request_advance(array $input): int
{
    if (!can('advances.request')) {
        fail_user('You do not have permission to request cash advances.');
    }
    $institutionId = (int) current_institution_id();
    $fundId = input_int($input, 'fund_id') ?? 0;
    $eventId = input_int($input, 'event_id');
    $amount = parse_money(input_str($input, 'requested_amount', 20)) ?? fail_user('Enter the amount requested.');
    $purpose = input_str($input, 'purpose', 3000);
    $type = input_enum($input, 'advance_type', array_keys(ADVANCE_TYPES), 'activity_advance');
    if (mb_strlen($purpose) < 10) {
        fail_user('Describe the purpose and planned expenses (at least 10 characters).');
    }
    find_fund($fundId);
    if ($eventId) {
        $event = find_event($eventId) ?? fail_user('Event not found.');
        if (!in_array($fundId, array_map(static fn($row) => (int) $row['fund_id'], event_funds($eventId)), true)) {
            fail_user('Advances for ' . $event['event_name'] . ' must come from one of its funds.');
        }
    }
    return db_transaction(function () use ($institutionId, $fundId, $eventId, $amount, $purpose, $type) {
        $number = next_document_number($institutionId, 'cash_advance', 'CA');
        db_query(
            "INSERT INTO cash_advances (institution_id, advance_number, fund_id, event_id, recipient_membership_id, purpose, advance_type, requested_amount, request_date, status, requested_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'pending_approval', ?)",
            [$institutionId, $number, $fundId, $eventId, current_membership_id(), $purpose, $type, cents_to_decimal($amount), auth_user()['id']]
        );
        $advanceId = (int) db()->lastInsertId();
        audit_log('advance.requested', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['advance_number' => $number, 'fund_id' => $fundId, 'amount' => cents_to_decimal($amount)]);
        notify_permission($institutionId, 'advances.approve', 'advance', 'Cash advance request', $number . ': ' . auth_user()['full_name'] . ' requests ' . money($amount, current_currency()) . '.');
        return $advanceId;
    });
}

function decide_advance(int $advanceId, bool $approve, array $input): void
{
    $institutionId = (int) current_institution_id();
    if (!can('advances.approve')) {
        fail_user('You do not have permission to approve cash advances.');
    }
    $reason = input_str($input, 'reason', 1000);
    db_transaction(function () use ($institutionId, $advanceId, $approve, $input, $reason) {
        $advance = lock_advance($advanceId);
        if ($advance['status'] !== 'pending_approval') {
            fail_user('This advance is ' . humanize($advance['status']) . ', not waiting for approval.');
        }
        if ($advance['is_recipient'] || (int) $advance['requested_by'] === (int) auth_user()['id']) {
            fail_user('You cannot approve your own cash advance.');
        }
        if ($approve) {
            $approved = parse_money(input_str($input, 'approved_amount', 20)) ?? to_cents($advance['requested_amount']);
            if ($approved > to_cents($advance['requested_amount'])) {
                fail_user('The approved amount cannot exceed the requested amount.');
            }
            db_query("UPDATE cash_advances SET status = 'approved', approved_amount = ?, approved_by = ? WHERE id = ?", [cents_to_decimal($approved), auth_user()['id'], $advanceId]);
            audit_log('advance.approved', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['approved_amount' => cents_to_decimal($approved)], ['status' => 'pending_approval'], $reason ?: null);
            notify_membership($institutionId, (int) $advance['recipient_membership_id'], 'advance', 'Cash advance approved', $advance['advance_number'] . ' was approved for ' . money($approved, current_currency()) . '.');
            notify_permission($institutionId, 'advances.release', 'advance', 'Advance ready for release', $advance['advance_number'] . ' is approved and awaiting disbursement.');
        } else {
            if (mb_strlen($reason) < 5) {
                fail_user('Give the reason for rejecting the request.');
            }
            // The schema has no "rejected" status for advances; a rejected request is cancelled with the reason recorded.
            db_query("UPDATE cash_advances SET status = 'cancelled', approved_by = ? WHERE id = ?", [auth_user()['id'], $advanceId]);
            audit_log('advance.rejected', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['status' => 'cancelled'], ['status' => 'pending_approval'], $reason);
            notify_membership($institutionId, (int) $advance['recipient_membership_id'], 'advance', 'Cash advance rejected', $advance['advance_number'] . ': ' . $reason);
        }
    });
}

/** Disbursement by an authorised custodian (not the recipient). Posts the receivable. */
function release_advance(int $advanceId, array $input): void
{
    $institutionId = (int) current_institution_id();
    if (!can('advances.release')) {
        fail_user('You do not have permission to release cash advances.');
    }
    db_transaction(function () use ($institutionId, $advanceId, $input) {
        $advance = lock_advance($advanceId);
        if ($advance['status'] !== 'approved') {
            fail_user('Only approved advances can be released.');
        }
        if ($advance['is_recipient']) {
            fail_user('You cannot release a cash advance to yourself.');
        }
        $account = find_financial_account(input_int($input, 'financial_account_id') ?? 0);
        require_usable_account($account);
        require_fund_account_mapping((int) $advance['fund_id'], (int) $account['id'], date('Y-m-d'));
        $amount = to_cents($advance['approved_amount']);
        require_available_cash($institutionId, (int) $advance['fund_id'], (int) $account['id'], $amount);
        find_fund((int) $advance['fund_id']);
        $days = (int) institution_setting($institutionId, 'advance.default_days', '15');
        $deadline = input_date($input, 'liquidation_deadline') ?? date('Y-m-d', strtotime("+{$days} days"));
        if ($deadline < date('Y-m-d')) {
            fail_user('The liquidation deadline cannot be in the past.');
        }

        post_journal([
            'institution_id' => $institutionId,
            'date' => date('Y-m-d'),
            'description' => 'Cash advance ' . $advance['advance_number'] . ' released to ' . $advance['recipient_name'],
            'source_type' => 'advance_release',
            'source_id' => $advanceId,
            'cash_flow_type' => 'advance_release',
            'lines' => [
                ['fund_id' => (int) $advance['fund_id'], 'account' => 'advances', 'debit' => $amount, 'description' => $advance['recipient_name']],
                ['fund_id' => (int) $advance['fund_id'], 'account' => cash_ledger_key($account['account_type']), 'financial_account_id' => (int) $account['id'], 'credit' => $amount],
            ],
        ]);
        db_query("UPDATE cash_advances SET status = 'released', released_amount = ?, released_at = NOW(), released_by = ?, financial_account_id = ?, liquidation_deadline = ? WHERE id = ?",
            [cents_to_decimal($amount), auth_user()['id'], $account['id'], $deadline, $advanceId]);
        audit_log('advance.released', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['released_amount' => cents_to_decimal($amount), 'financial_account_id' => (int) $account['id'], 'liquidation_deadline' => $deadline]);
        notify_membership($institutionId, (int) $advance['recipient_membership_id'], 'advance', 'Cash advance released',
            $advance['advance_number'] . ': ' . money($amount, current_currency()) . ' released. Liquidate by ' . format_date($deadline) . '.');
    });
}

/** Withdraws a request that has not been released (requester or approver). */
function cancel_advance(int $advanceId, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (mb_strlen($reason) < 5) {
        fail_user('A reason is required.');
    }
    db_transaction(function () use ($institutionId, $advanceId, $reason) {
        $advance = lock_advance($advanceId);
        if (!in_array($advance['status'], ['draft', 'pending_approval', 'approved'], true)) {
            fail_user('Released advances cannot be cancelled; they must be liquidated.');
        }
        if (!$advance['is_recipient'] && !can('advances.approve')) {
            fail_user('Only the requester or an approver can cancel this request.');
        }
        db_query("UPDATE cash_advances SET status = 'cancelled' WHERE id = ?", [$advanceId]);
        audit_log('advance.cancelled', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['status' => 'cancelled'], ['status' => $advance['status']], $reason);
    });
}

// ---------------------------------------------------------------------------
// Liquidation by the recipient
// ---------------------------------------------------------------------------

function require_recipient_can_liquidate(array $advance): void
{
    if (!$advance['is_recipient'] || !can_any(['liquidation.submit', 'advances.request'])) {
        fail_user('Only the recipient of this advance can submit its liquidation.');
    }
    if (!in_array($advance['status'], ['released', 'partially_liquidated', 'returned_for_correction', 'overdue'], true)) {
        fail_user('Liquidation items can only be added while the advance is open (not under review or liquidated).');
    }
}

/** Adds (or corrects) an expense supported by a receipt. Upload field: "receipt". */
function save_advance_expense(int $advanceId, ?int $expenseId, array $input): int
{
    $institutionId = (int) current_institution_id();
    $amount = parse_money(input_str($input, 'amount', 20)) ?? fail_user('Enter the expense amount.');
    $date = input_date($input, 'expense_date') ?? fail_user('Enter the expense date.');
    $category = input_str($input, 'category', 100);
    $description = input_str($input, 'description', 2000);
    $vendor = input_str($input, 'vendor_name', 180);
    if (!in_array($category, expense_categories(), true)) {
        fail_user('Choose one of the expense categories.');
    }
    if (mb_strlen($description) < 3) {
        fail_user('Describe what was bought.');
    }
    if ($date > date('Y-m-d')) {
        fail_user('The expense date cannot be in the future.');
    }

    return db_transaction(function () use ($institutionId, $advanceId, $expenseId, $amount, $date, $category, $description, $vendor) {
        $advance = lock_advance($advanceId);
        require_recipient_can_liquidate($advance);
        if ($advance['released_at'] && $date < substr($advance['released_at'], 0, 10)) {
            fail_user('Expenses must be dated on or after the release date (' . format_date($advance['released_at']) . ').');
        }
        if ($expenseId) {
            $existing = db_one('SELECT * FROM cash_advance_expenses WHERE institution_id = ? AND id = ? AND cash_advance_id = ? FOR UPDATE', [$institutionId, $expenseId, $advanceId]) ?? fail_user('Expense not found.');
            if (!in_array($existing['verification_status'], ['pending', 'needs_correction'], true)) {
                fail_user('Verified or rejected items cannot be edited.');
            }
            db_query("UPDATE cash_advance_expenses SET expense_date = ?, category = ?, vendor_name = ?, description = ?, amount = ?, verification_status = 'pending' WHERE id = ?",
                [$date, $category, $vendor ?: null, $description, cents_to_decimal($amount), $expenseId]);
            audit_log('advance.expense_updated', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['expense_id' => $expenseId, 'amount' => cents_to_decimal($amount)], ['amount' => $existing['amount']]);
        } else {
            if (!has_upload('receipt')) {
                fail_user('Attach the receipt for this expense.');
            }
            db_query(
                "INSERT INTO cash_advance_expenses (institution_id, cash_advance_id, expense_date, category, vendor_name, description, amount, verification_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
                [$institutionId, $advanceId, $date, $category, $vendor ?: null, $description, cents_to_decimal($amount)]
            );
            $expenseId = (int) db()->lastInsertId();
            audit_log('advance.expense_added', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['expense_id' => $expenseId, 'amount' => cents_to_decimal($amount), 'category' => $category]);
        }
        if (has_upload('receipt')) {
            $attachmentId = store_attachment('receipt', 'advance_expense', $expenseId, true);
            db_query('UPDATE cash_advance_expenses SET receipt_attachment_id = ? WHERE id = ?', [$attachmentId, $expenseId]);
        }
        return $expenseId;
    });
}

/** Removes an unverified expense line the recipient entered by mistake. */
function delete_advance_expense(int $advanceId, int $expenseId): void
{
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $advanceId, $expenseId) {
        $advance = lock_advance($advanceId);
        require_recipient_can_liquidate($advance);
        $row = db_one('SELECT * FROM cash_advance_expenses WHERE institution_id = ? AND id = ? AND cash_advance_id = ? FOR UPDATE', [$institutionId, $expenseId, $advanceId]) ?? fail_user('Expense not found.');
        if (!in_array($row['verification_status'], ['pending', 'needs_correction'], true)) {
            fail_user('Verified or rejected items stay on record.');
        }
        db_query('UPDATE cash_advance_expenses SET receipt_attachment_id = NULL WHERE id = ?', [$expenseId]);
        foreach (db_all("SELECT id, storage_key FROM file_attachments WHERE institution_id = ? AND entity_type = 'advance_expense' AND entity_id = ?", [$institutionId, $expenseId]) as $file) {
            db_query('DELETE FROM file_attachments WHERE id = ?', [$file['id']]);
            @unlink(APP_ROOT . '/' . $file['storage_key']);
        }
        db_query('DELETE FROM cash_advance_expenses WHERE id = ?', [$expenseId]);
        audit_log('advance.expense_removed', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], null, ['expense_id' => $expenseId, 'amount' => $row['amount'], 'description' => $row['description']]);
    });
}

/** Records cash the recipient hands back. It counts only after the receiving custodian confirms it. */
function record_advance_return(int $advanceId, array $input): int
{
    $institutionId = (int) current_institution_id();
    $amount = parse_money(input_str($input, 'returned_amount', 20)) ?? fail_user('Enter the amount returned.');
    $method = input_enum($input, 'payment_method', array_keys(EXPENSE_METHODS), 'cash');
    $reference = input_str($input, 'reference_number', 150);
    if ($method !== 'cash' && $reference === '') {
        fail_user('Enter the transaction reference for non-cash returns.');
    }
    return db_transaction(function () use ($institutionId, $advanceId, $amount, $method, $reference, $input) {
        $advance = lock_advance($advanceId);
        require_recipient_can_liquidate($advance);
        $account = find_financial_account(input_int($input, 'financial_account_id') ?? 0);
        require_fund_account_mapping((int) $advance['fund_id'], (int) $account['id'], date('Y-m-d'));
        if (!$account['custodian_membership_id']) {
            fail_user('Return the cash to an account with a custodian who can confirm receipt.');
        }
        if ((int) $account['custodian_membership_id'] === current_membership_id()) {
            fail_user('Return the money to someone else\'s custody, not your own account.');
        }
        $pendingExpenses = $advance['pending_expense_cents'];
        $maximum = $advance['outstanding_cents'] - $advance['pending_return_cents'];
        if ($amount > $maximum) {
            fail_user('You can return at most ' . money(max(0, $maximum), current_currency()) . ' (the unliquidated balance' . ($pendingExpenses ? ' before pending expenses are verified' : '') . ').');
        }
        $number = next_document_number($institutionId, 'advance_return', 'RET');
        db_query(
            "INSERT INTO cash_advance_returns (institution_id, cash_advance_id, return_number, financial_account_id, returned_amount, returned_at, received_by_membership_id, payment_method, reference_number, status, notes)
             VALUES (?, ?, ?, ?, ?, NOW(), ?, ?, ?, 'pending_verification', ?)",
            [$institutionId, $advanceId, $number, $account['id'], cents_to_decimal($amount), $account['custodian_membership_id'], $method, $reference ?: null, input_str($input, 'notes', 1000) ?: null]
        );
        $returnId = (int) db()->lastInsertId();
        if (has_upload('attachment')) {
            store_attachment('attachment', 'advance_return', $returnId);
        }
        audit_log('advance.return_recorded', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['return_id' => $returnId, 'return_number' => $number, 'amount' => cents_to_decimal($amount)]);
        notify_membership($institutionId, (int) $account['custodian_membership_id'], 'advance', 'Returned cash to confirm',
            $number . ': ' . auth_user()['full_name'] . ' returned ' . money($amount, current_currency()) . ' for ' . $advance['advance_number'] . '.');
        return $returnId;
    });
}

/** The receiving custodian (or a liquidation reviewer) confirms a return; it is then posted. */
function confirm_advance_return(int $returnId, bool $confirm, string $reason): void
{
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $returnId, $confirm, $reason) {
        $return = db_one('SELECT * FROM cash_advance_returns WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $returnId]) ?? fail_user('Return not found.');
        $advance = lock_advance((int) $return['cash_advance_id']);
        $isReceiver = (int) $return['received_by_membership_id'] === current_membership_id();
        if (!$isReceiver && !can('liquidation.review')) {
            fail_user('Only the receiving custodian or a liquidation reviewer can confirm this return.');
        }
        if ($advance['is_recipient']) {
            fail_user('You cannot confirm a return of your own advance.');
        }
        if ($return['status'] !== 'pending_verification') {
            fail_user('This return is already ' . humanize($return['status']) . '.');
        }
        if (!$confirm) {
            if (mb_strlen($reason) < 5) {
                fail_user('Explain why the return is not confirmed.');
            }
            db_query("UPDATE cash_advance_returns SET status = 'reversed', notes = CONCAT(COALESCE(notes, ''), ?) WHERE id = ?", ["\nNot confirmed: " . $reason, $returnId]);
            audit_log('advance.return_rejected', 'cash_advance', (int) $advance['id'], $institutionId, (int) auth_user()['id'], ['return_id' => $returnId], null, $reason);
            return;
        }
        $account = find_financial_account((int) $return['financial_account_id'], false);
        $amount = to_cents($return['returned_amount']);
        post_journal([
            'institution_id' => $institutionId,
            'date' => date('Y-m-d'),
            'description' => 'Return ' . $return['return_number'] . ' of unused cash on advance ' . $advance['advance_number'] . ' from ' . $advance['recipient_name'],
            'source_type' => 'advance_return',
            'source_id' => $returnId,
            'cash_flow_type' => 'advance_return',
            'lines' => [
                ['fund_id' => (int) $advance['fund_id'], 'account' => cash_ledger_key($account['account_type']), 'financial_account_id' => (int) $account['id'], 'debit' => $amount],
                ['fund_id' => (int) $advance['fund_id'], 'account' => 'advances', 'credit' => $amount, 'description' => $advance['recipient_name']],
            ],
        ]);
        db_query("UPDATE cash_advance_returns SET status = 'confirmed', acknowledgment_number = ? WHERE id = ?", [next_document_number($institutionId, 'return_ack', 'ACK'), $returnId]);
        audit_log('advance.return_confirmed', 'cash_advance', (int) $advance['id'], $institutionId, (int) auth_user()['id'], ['return_id' => $returnId, 'amount' => cents_to_decimal($amount)]);
        refresh_advance_status((int) $advance['id']);
        notify_membership($institutionId, (int) $advance['recipient_membership_id'], 'advance', 'Return confirmed', $return['return_number'] . ' (' . money($amount, current_currency()) . ') was confirmed.');
    });
}

/** Recipient submits the liquidation for review. */
function submit_liquidation(int $advanceId): void
{
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $advanceId) {
        $advance = lock_advance($advanceId);
        require_recipient_can_liquidate($advance);
        if ($advance['pending_expense_count'] === 0 && $advance['pending_return_count'] === 0) {
            fail_user('Add the expenses with receipts (and any returned cash) before submitting.');
        }
        $missing = (int) db_value("SELECT COUNT(*) FROM cash_advance_expenses WHERE institution_id = ? AND cash_advance_id = ? AND verification_status IN ('pending', 'needs_correction') AND receipt_attachment_id IS NULL", [$institutionId, $advanceId]);
        if ($missing) {
            fail_user($missing . ' expense(s) have no receipt attached.');
        }
        db_query("UPDATE cash_advances SET status = 'submitted_for_review' WHERE id = ?", [$advanceId]);
        audit_log('advance.liquidation_submitted', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['status' => 'submitted_for_review'], ['status' => $advance['status']]);
        notify_permission($institutionId, 'liquidation.review', 'liquidation', 'Liquidation to review', $advance['advance_number'] . ' from ' . $advance['recipient_name'] . ' is ready for review.');
    });
}

// ---------------------------------------------------------------------------
// Review
// ---------------------------------------------------------------------------

/** Reviewer decision on one expense line: verified (posted), rejected or needs_correction. */
function review_advance_expense(int $expenseId, string $decision, string $remarks): void
{
    $institutionId = (int) current_institution_id();
    if (!can('liquidation.review')) {
        fail_user('You do not have permission to review liquidations.');
    }
    if (!in_array($decision, ['verified', 'rejected', 'needs_correction'], true)) {
        fail_user('Choose verify, reject or needs correction.');
    }
    if ($decision !== 'verified' && mb_strlen($remarks) < 5) {
        fail_user('Explain what is wrong with this item.');
    }
    db_transaction(function () use ($institutionId, $expenseId, $decision, $remarks) {
        $row = db_one('SELECT * FROM cash_advance_expenses WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $expenseId]) ?? fail_user('Expense not found.');
        $advance = lock_advance((int) $row['cash_advance_id']);
        if ($advance['is_recipient']) {
            fail_user('You cannot review your own liquidation.');
        }
        if (!in_array($advance['status'], ['submitted_for_review', 'released', 'partially_liquidated', 'overdue', 'returned_for_correction'], true)) {
            fail_user('This advance is not open for review.');
        }
        if (!in_array($row['verification_status'], ['pending', 'needs_correction'], true)) {
            fail_user('This item was already ' . $row['verification_status'] . '.');
        }
        if ($decision === 'verified') {
            if (!$row['receipt_attachment_id']) {
                fail_user('An expense without a receipt cannot be verified.');
            }
            $amount = to_cents($row['amount']);
            post_journal([
                'institution_id' => $institutionId,
                'date' => date('Y-m-d'),
                'description' => 'Liquidated expense on advance ' . $advance['advance_number'] . ': ' . mb_substr($row['description'], 0, 200),
                'source_type' => 'advance_expense',
                'source_id' => $expenseId,
                'cash_flow_type' => 'adjustment',
                'lines' => [
                    ['fund_id' => (int) $advance['fund_id'], 'account' => 'expenses', 'debit' => $amount, 'description' => $row['category']],
                    ['fund_id' => (int) $advance['fund_id'], 'account' => 'advances', 'credit' => $amount, 'description' => $advance['recipient_name']],
                ],
            ]);
        }
        db_query('UPDATE cash_advance_expenses SET verification_status = ?, verified_by = ?, verified_at = NOW(), remarks = ? WHERE id = ?',
            [$decision, auth_user()['id'], $remarks ?: null, $expenseId]);
        audit_log('advance.expense_' . $decision, 'cash_advance', (int) $advance['id'], $institutionId, (int) auth_user()['id'], ['expense_id' => $expenseId, 'amount' => $row['amount']], null, $remarks ?: null);
        refresh_advance_status((int) $advance['id']);
    });
}

/** Sends the liquidation back to the recipient with the corrections needed. */
function return_liquidation(int $advanceId, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (!can('liquidation.review')) {
        fail_user('You do not have permission to review liquidations.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('Explain what must be corrected.');
    }
    db_transaction(function () use ($institutionId, $advanceId, $reason) {
        $advance = lock_advance($advanceId);
        if ($advance['is_recipient']) {
            fail_user('You cannot review your own liquidation.');
        }
        if ($advance['status'] !== 'submitted_for_review') {
            fail_user('Only liquidations under review can be returned.');
        }
        db_query("UPDATE cash_advances SET status = 'returned_for_correction' WHERE id = ?", [$advanceId]);
        audit_log('advance.liquidation_returned', 'cash_advance', $advanceId, $institutionId, (int) auth_user()['id'], ['status' => 'returned_for_correction'], ['status' => 'submitted_for_review'], $reason);
        notify_membership($institutionId, (int) $advance['recipient_membership_id'], 'liquidation', 'Liquidation returned for correction', $advance['advance_number'] . ': ' . $reason);
    });
}

/**
 * Closes the review: the advance becomes liquidated only when nothing is pending and the
 * outstanding balance is zero; otherwise it stays open (partially liquidated).
 */
function complete_liquidation_review(int $advanceId): string
{
    $institutionId = (int) current_institution_id();
    if (!can('liquidation.review')) {
        fail_user('You do not have permission to review liquidations.');
    }
    return db_transaction(function () use ($institutionId, $advanceId) {
        $advance = lock_advance($advanceId);
        if ($advance['is_recipient']) {
            fail_user('You cannot review your own liquidation.');
        }
        if ($advance['pending_expense_count'] > 0 || $advance['pending_return_count'] > 0) {
            fail_user('Decide every expense and return before completing the review.');
        }
        if ($advance['outstanding_cents'] > 0) {
            fail_user('An unexplained balance of ' . money($advance['outstanding_cents'], current_currency()) . ' remains. The recipient must account for it with receipts or return the cash before the advance can be marked liquidated.');
        }
        db_query("UPDATE cash_advances SET status = 'partially_liquidated' WHERE id = ? AND status IN ('submitted_for_review', 'returned_for_correction')", [$advanceId]);
        refresh_advance_status($advanceId);
        $status = (string) db_value('SELECT status FROM cash_advances WHERE id = ?', [$advanceId]);
        notify_membership($institutionId, (int) $advance['recipient_membership_id'], 'liquidation', 'Liquidation ' . humanize($status), $advance['advance_number'] . ' is now ' . humanize($status) . ($advance['excess_cents'] ? '. Expenses exceeding the advance (' . money($advance['excess_cents'], current_currency()) . ') are subject to reimbursement review.' : '.'));
        return $status;
    });
}

// ---------------------------------------------------------------------------
// Queues (release, liquidation submit/review, summaries)
// ---------------------------------------------------------------------------

/**
 * Paged advance list with liquidation figures. $where/$params are extra
 * conditions on alias a (cash_advances); the institution filter is always applied.
 *
 * @return array{0: array, 1: array} rows and pagination
 */
function advance_queue(array $where, array $params, string $order = 'a.id DESC'): array
{
    array_unshift($where, 'a.institution_id = ?');
    array_unshift($params, current_institution_id());
    $whereSql = implode(' AND ', $where);
    $pagination = paginate((int) db_value("SELECT COUNT(*) FROM cash_advances a WHERE {$whereSql}", $params));
    $rows = db_all(
        "SELECT a.*, f.fund_name, CONCAT(u.first_name, ' ', u.last_name) AS recipient_name,
                (SELECT COALESCE(SUM(x.amount), 0) FROM cash_advance_expenses x WHERE x.institution_id = a.institution_id AND x.cash_advance_id = a.id AND x.verification_status = 'verified') AS verified,
                (SELECT COALESCE(SUM(x.amount), 0) FROM cash_advance_expenses x WHERE x.institution_id = a.institution_id AND x.cash_advance_id = a.id AND x.verification_status IN ('pending', 'needs_correction')) AS pending_expenses,
                (SELECT COUNT(*) FROM cash_advance_expenses x WHERE x.institution_id = a.institution_id AND x.cash_advance_id = a.id AND x.verification_status = 'pending') AS pending_lines,
                (SELECT COALESCE(SUM(r.returned_amount), 0) FROM cash_advance_returns r WHERE r.institution_id = a.institution_id AND r.cash_advance_id = a.id AND r.status = 'confirmed') AS returned,
                (SELECT COALESCE(SUM(r.returned_amount), 0) FROM cash_advance_returns r WHERE r.institution_id = a.institution_id AND r.cash_advance_id = a.id AND r.status = 'pending_verification') AS pending_returns
         FROM cash_advances a JOIN fund_accounts f ON f.id = a.fund_id JOIN institution_memberships m ON m.id = a.recipient_membership_id JOIN users u ON u.id = m.user_id
         WHERE {$whereSql} ORDER BY {$order}
         LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
        $params
    );
    foreach ($rows as &$row) {
        $row['outstanding_cents'] = to_cents($row['released_amount']) - to_cents($row['verified']) - to_cents($row['returned']);
    }
    unset($row);
    return [$rows, $pagination];
}

/** Table for advance_queue() rows. $mode: 'release' shows approved amounts, otherwise liquidation figures. */
function advance_queue_table(array $rows, string $mode, string $actionLabel = 'Open'): string
{
    $currency = current_currency();
    $head = $mode === 'release'
        ? '<th>Advance</th><th>Recipient</th><th>Requested</th><th class="text-end">Approved</th><th></th>'
        : '<th>Advance</th><th>Recipient</th><th class="text-end">Released</th><th class="text-end">Verified · Returned</th><th class="text-end">Outstanding</th><th>Status · Deadline</th><th></th>';
    $html = '<div class="table-responsive"><table class="table table-clean align-middle mb-0"><thead><tr>' . $head . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr><td><div class="fw-semibold">' . e($row['advance_number']) . '</div><div class="text-muted-sm text-truncate" style="max-width:180px">' . e($row['fund_name'] . ' · ' . $row['purpose']) . '</div></td>'
            . '<td>' . e($row['recipient_name']) . '</td>';
        if ($mode === 'release') {
            $html .= '<td class="text-muted-sm">' . e(format_date($row['request_date'])) . '</td>'
                . '<td class="text-end fw-semibold">' . e(m($row['approved_amount'])) . '</td>';
        } else {
            $outstanding = $row['outstanding_cents'];
            $pending = to_cents($row['pending_expenses']) + to_cents($row['pending_returns']);
            $html .= '<td class="text-end">' . e(m($row['released_amount'])) . '</td>'
                . '<td class="text-end text-muted-sm">' . e(m($row['verified']) . ' · ' . m($row['returned'])) . '</td>'
                . '<td class="text-end"><span class="fw-semibold">' . e(money(max(0, $outstanding), $currency)) . '</span>'
                . ($outstanding < 0 ? '<div class="text-muted-sm">excess ' . e(money(-$outstanding, $currency)) . '</div>' : '')
                . ($pending ? '<div class="text-muted-sm">' . e(money($pending, $currency)) . ' pending review</div>' : '') . '</td>'
                . '<td>' . status_badge($row['status']) . '<div class="text-muted-sm' . ($row['status'] === 'overdue' ? ' text-danger' : '') . '">' . e(format_date($row['liquidation_deadline'])) . '</div></td>';
        }
        $html .= '<td class="actions"><a class="btn btn-outline-primary btn-xs" href="' . e(url('advances/view.php?id=' . $row['id'])) . '">' . e($actionLabel) . '</a></td></tr>';
    }
    return $html . '</tbody></table></div>';
}
