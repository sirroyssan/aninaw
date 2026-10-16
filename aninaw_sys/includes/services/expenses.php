<?php
/**
 * Direct expenses: record -> submit -> approve/reject -> pay (post) -> reverse.
 *
 * - Approval by someone other than the person who recorded the expense.
 * - Only paid expenses are posted (Dr Expenses / Cr Cash). Draft, submitted, approved
 *   and rejected expenses never affect posted totals.
 * - Posted expenses are corrected by reversal with a reason, never edited or deleted.
 */
declare(strict_types=1);

require_once __DIR__ . '/funds.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/institutions.php';

const EXPENSE_METHODS = ['cash' => 'Cash', 'gcash' => 'GCash / e-wallet', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'];

function find_expense(int $expenseId): ?array
{
    return db_one(
        "SELECT x.*, f.fund_name, f.fund_code, e.event_name, fa.account_name, fa.account_type,
                CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name, CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name,
                CONCAT(pu.first_name, ' ', pu.last_name) AS paid_by_name
         FROM expenses x
         JOIN fund_accounts f ON f.id = x.fund_id AND f.institution_id = x.institution_id
         LEFT JOIN events e ON e.id = x.event_id AND e.institution_id = x.institution_id
         LEFT JOIN financial_accounts fa ON fa.id = x.financial_account_id AND fa.institution_id = x.institution_id
         JOIN users cu ON cu.id = x.created_by
         LEFT JOIN users au ON au.id = x.approved_by
         LEFT JOIN users pu ON pu.id = x.paid_by
         WHERE x.institution_id = ? AND x.id = ?",
        [current_institution_id(), $expenseId]
    );
}

/** Validated expense fields from input. */
function expense_input(array $input): array
{
    $amount = parse_money(input_str($input, 'amount', 20)) ?? fail_user('Enter the expense amount.');
    $data = [
        'fund_id'      => input_int($input, 'fund_id') ?? 0,
        'event_id'     => input_int($input, 'event_id'),
        'expense_date' => input_date($input, 'expense_date') ?? fail_user('Enter the transaction date.'),
        'payee'        => input_str($input, 'payee', 180),
        'category'     => input_str($input, 'category', 100),
        'description'  => input_str($input, 'description', 3000),
        'amount'       => cents_to_decimal($amount),
        'payment_method' => input_enum($input, 'payment_method', array_keys(EXPENSE_METHODS), 'cash'),
        'financial_account_id' => input_int($input, 'financial_account_id'),
    ];
    $invoice = input_str($input, 'invoice_reference', 100);
    if ($data['payee'] === '' || mb_strlen($data['description']) < 5) {
        fail_user('Enter the payee or supplier and the purpose of the expense.');
    }
    if (!in_array($data['category'], expense_categories(), true)) {
        fail_user('Choose one of the institution\'s expense categories.');
    }
    if ($data['expense_date'] > date('Y-m-d')) {
        fail_user('The transaction date cannot be in the future.');
    }
    find_fund($data['fund_id']);
    if ($data['event_id']) {
        $event = find_event($data['event_id']) ?? fail_user('Event not found.');
        $eventFunds = array_map(static fn($row) => (int) $row['fund_id'], event_funds($data['event_id']));
        if (!in_array($data['fund_id'], $eventFunds, true)) {
            fail_user('Expenses of ' . $event['event_name'] . ' must be charged to one of its funds.');
        }
    }
    if ($data['financial_account_id']) {
        find_financial_account($data['financial_account_id']);
    }
    // The invoice/receipt number is kept as the first line of the description (no separate column in the schema).
    $description = preg_replace('/^Invoice\/receipt no\.: .*\R?/u', '', $data['description']);
    $data['description'] = ($invoice !== '' ? 'Invoice/receipt no.: ' . $invoice . "\n" : '') . $description;
    return $data;
}

/** Creates (draft) or updates a draft/rejected expense. */
function save_expense(?int $expenseId, array $input): int
{
    if (!can('expenses.record')) {
        fail_user('You do not have permission to record expenses.');
    }
    $institutionId = (int) current_institution_id();
    $data = expense_input($input);

    return db_transaction(function () use ($institutionId, $expenseId, $data) {
        if ($expenseId) {
            $before = db_one('SELECT * FROM expenses WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $expenseId]) ?? fail_user('Expense not found.');
            if (!in_array($before['status'], ['draft', 'rejected'], true)) {
                fail_user('Only draft or rejected expenses can be edited. Submitted and posted expenses are locked.');
            }
            db_query(
                "UPDATE expenses SET fund_id = ?, event_id = ?, financial_account_id = ?, expense_date = ?, payee = ?, description = ?, category = ?, amount = ?, payment_method = ?, status = 'draft' WHERE id = ?",
                [$data['fund_id'], $data['event_id'], $data['financial_account_id'], $data['expense_date'], $data['payee'], $data['description'], $data['category'], $data['amount'], $data['payment_method'], $expenseId]
            );
            audit_log('expense.updated', 'expense', $expenseId, $institutionId, (int) auth_user()['id'], $data, array_intersect_key($before, $data));
        } else {
            $number = next_document_number($institutionId, 'expense', 'EXP');
            db_query(
                "INSERT INTO expenses (institution_id, expense_number, fund_id, event_id, financial_account_id, expense_date, payee, description, category, amount, payment_method, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)",
                [$institutionId, $number, $data['fund_id'], $data['event_id'], $data['financial_account_id'], $data['expense_date'], $data['payee'], $data['description'], $data['category'], $data['amount'], $data['payment_method'], auth_user()['id']]
            );
            $expenseId = (int) db()->lastInsertId();
            audit_log('expense.created', 'expense', $expenseId, $institutionId, (int) auth_user()['id'], $data + ['expense_number' => $number]);
        }
        if (has_upload('receipt')) {
            $attachmentId = store_attachment('receipt', 'expense', $expenseId, true);
            db_query('UPDATE expenses SET receipt_attachment_id = ? WHERE id = ?', [$attachmentId, $expenseId]);
        }
        if (has_upload('attachment')) {
            store_attachment('attachment', 'expense', $expenseId);
        }
        return $expenseId;
    });
}

/** Draft -> submitted for approval. A receipt or invoice must be attached. */
function submit_expense(int $expenseId): void
{
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $expenseId) {
        $expense = db_one('SELECT * FROM expenses WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $expenseId]) ?? fail_user('Expense not found.');
        if (!can('expenses.record')) {
            fail_user('You do not have permission to submit expenses.');
        }
        if ($expense['status'] !== 'draft') {
            fail_user('Only draft expenses can be submitted.');
        }
        if (!$expense['receipt_attachment_id']) {
            fail_user('Attach the official receipt or invoice before submitting.');
        }
        db_query("UPDATE expenses SET status = 'submitted' WHERE id = ?", [$expenseId]);
        audit_log('expense.submitted', 'expense', $expenseId, $institutionId, (int) auth_user()['id'], ['status' => 'submitted'], ['status' => 'draft']);
        notify_permission($institutionId, 'expenses.approve', 'expense', 'Expense awaiting approval',
            $expense['expense_number'] . ': ' . money(to_cents($expense['amount']), current_currency()) . ' to ' . $expense['payee'] . '.');
    });
}

/** Approver decision. The approver cannot be the person who recorded the expense. */
function decide_expense(int $expenseId, bool $approve, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (!can('expenses.approve')) {
        fail_user('You do not have permission to approve expenses.');
    }
    db_transaction(function () use ($institutionId, $expenseId, $approve, $reason) {
        $expense = db_one('SELECT * FROM expenses WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $expenseId]) ?? fail_user('Expense not found.');
        if ($expense['status'] !== 'submitted') {
            fail_user('This expense is ' . humanize($expense['status']) . ', not waiting for approval.');
        }
        if ((int) $expense['created_by'] === (int) auth_user()['id']) {
            fail_user('You cannot approve an expense you recorded. Another approver must review it.');
        }
        if (!$approve && mb_strlen($reason) < 5) {
            fail_user('Explain why the expense is rejected.');
        }
        $status = $approve ? 'approved' : 'rejected';
        db_query('UPDATE expenses SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?', [$status, auth_user()['id'], $expenseId]);
        audit_log('expense.' . $status, 'expense', $expenseId, $institutionId, (int) auth_user()['id'], ['status' => $status], ['status' => 'submitted'], $reason ?: null);
        $creatorMembership = db_value('SELECT id FROM institution_memberships WHERE institution_id = ? AND user_id = ?', [$institutionId, $expense['created_by']]);
        if ($creatorMembership) {
            notify_membership($institutionId, (int) $creatorMembership, 'expense', 'Expense ' . $status,
                $expense['expense_number'] . ' was ' . $status . ($approve ? '.' : ': ' . $reason));
        }
    });
}

/** Disbursement of an approved expense: posts Dr Expenses / Cr Cash from an authorised account. */
function pay_expense(int $expenseId, array $input): void
{
    $institutionId = (int) current_institution_id();
    if (!can('expenses.record')) {
        fail_user('You do not have permission to disburse expenses.');
    }
    db_transaction(function () use ($institutionId, $expenseId, $input) {
        $expense = db_one('SELECT * FROM expenses WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $expenseId]) ?? fail_user('Expense not found.');
        if ($expense['status'] !== 'approved') {
            fail_user('Only approved expenses can be paid.');
        }
        $accountId = input_int($input, 'financial_account_id') ?? (int) $expense['financial_account_id'];
        $account = find_financial_account($accountId);
        require_usable_account($account);
        $paidOn = input_date($input, 'paid_date') ?? date('Y-m-d');
        require_fund_account_mapping((int) $expense['fund_id'], $accountId, $paidOn);
        $amount = to_cents($expense['amount']);
        require_available_cash($institutionId, (int) $expense['fund_id'], $accountId, $amount);
        find_fund((int) $expense['fund_id']);

        post_journal([
            'institution_id' => $institutionId,
            'date' => $paidOn,
            'description' => 'Expense ' . $expense['expense_number'] . ' paid to ' . $expense['payee'] . ' (' . $expense['category'] . ')',
            'source_type' => 'expense',
            'source_id' => $expenseId,
            'cash_flow_type' => 'direct_expense',
            'lines' => [
                ['fund_id' => (int) $expense['fund_id'], 'account' => 'expenses', 'debit' => $amount, 'description' => $expense['category']],
                ['fund_id' => (int) $expense['fund_id'], 'account' => cash_ledger_key($account['account_type']), 'financial_account_id' => $accountId, 'credit' => $amount],
            ],
        ]);
        db_query("UPDATE expenses SET status = 'paid', financial_account_id = ?, paid_by = ?, paid_at = NOW() WHERE id = ?", [$accountId, auth_user()['id'], $expenseId]);
        audit_log('expense.paid', 'expense', $expenseId, $institutionId, (int) auth_user()['id'], ['status' => 'paid', 'financial_account_id' => $accountId, 'paid_date' => $paidOn], ['status' => 'approved']);
    });
}

/** Reverses a paid (posted) expense with a reason. The original stays visible. */
function reverse_expense(int $expenseId, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (!can('expenses.approve')) {
        fail_user('You do not have permission to reverse posted expenses.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('A reason is required to reverse an expense.');
    }
    db_transaction(function () use ($institutionId, $expenseId, $reason) {
        $expense = db_one('SELECT * FROM expenses WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $expenseId]) ?? fail_user('Expense not found.');
        if ($expense['status'] !== 'paid') {
            fail_user('Only paid (posted) expenses can be reversed.');
        }
        reverse_journal($institutionId, 'expense', $expenseId, $reason);
        db_query("UPDATE expenses SET status = 'reversed' WHERE id = ?", [$expenseId]);
        audit_log('expense.reversed', 'expense', $expenseId, $institutionId, (int) auth_user()['id'], ['status' => 'reversed'], ['status' => 'paid'], $reason);
    });
}

/** Deletes a draft that was never submitted (nothing financial has happened). */
function delete_draft_expense(int $expenseId): void
{
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $expenseId) {
        $expense = db_one('SELECT * FROM expenses WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $expenseId]) ?? fail_user('Expense not found.');
        if ($expense['status'] !== 'draft' || db_value("SELECT 1 FROM audit_logs WHERE institution_id = ? AND entity_type = 'expense' AND entity_id = ? AND action = 'expense.submitted'", [$institutionId, $expenseId])) {
            fail_user('Only drafts that were never submitted can be deleted. Others stay on record.');
        }
        if ((int) $expense['created_by'] !== (int) auth_user()['id'] && !can('expenses.approve')) {
            fail_user('Only the person who recorded this draft can delete it.');
        }
        db_query('UPDATE expenses SET receipt_attachment_id = NULL WHERE id = ?', [$expenseId]);
        // Its documents go too, so the same receipt can support a corrected expense later.
        foreach (db_all("SELECT id, storage_key FROM file_attachments WHERE institution_id = ? AND entity_type = 'expense' AND entity_id = ?", [$institutionId, $expenseId]) as $file) {
            db_query('DELETE FROM file_attachments WHERE id = ?', [$file['id']]);
            @unlink(APP_ROOT . '/' . $file['storage_key']);
        }
        db_query('DELETE FROM expenses WHERE id = ?', [$expenseId]);
        audit_log('expense.draft_deleted', 'expense', $expenseId, $institutionId, (int) auth_user()['id'], null, ['expense_number' => $expense['expense_number'], 'amount' => $expense['amount'], 'payee' => $expense['payee']]);
    });
}
