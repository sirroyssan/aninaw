<?php
/**
 * Budgets and budget monitoring.
 *
 *   draft (budgets.manage) -> pending (submitted) -> approved (budgets.approve, not the creator)
 *                         <- returned to draft with a reason
 *   approved -> revised by budgets.approve with a reason (before/after kept in audit_logs)
 *   approved -> closed;  draft/pending -> cancelled
 *
 * Budget vs actual per category, for the budget's fund (and event, when set) within its period:
 *   actual   = paid expenses + verified liquidation expenses (posted to the ledger)
 *   pending  = submitted/approved expenses + liquidation expenses awaiting review (not posted)
 *   remaining = approved − actual;  available = remaining − pending
 * Viewing a budget never changes it.
 */
declare(strict_types=1);

require_once __DIR__ . '/funds.php';
require_once __DIR__ . '/events.php';
require_once __DIR__ . '/institutions.php';

const BUDGET_STATUS_LABELS = [
    'draft' => 'Draft', 'pending' => 'Awaiting approval', 'approved' => 'Approved', 'active' => 'Approved',
    'closed' => 'Closed', 'cancelled' => 'Cancelled',
];

/**
 * Budgets the current user may see: everything with funds.view_all; otherwise budgets
 * of funds they can see and of events they manage.
 */
function budget_visibility(string $alias = 'b'): array
{
    if (can('funds.view_all')) {
        return ['1 = 1', []];
    }
    [$fundVisibility, $params] = fund_visibility('vf');
    return [
        "(EXISTS (SELECT 1 FROM fund_accounts vf WHERE vf.institution_id = {$alias}.institution_id AND vf.id = {$alias}.fund_id AND {$fundVisibility})
          OR ({$alias}.event_id IS NOT NULL AND (EXISTS (SELECT 1 FROM events ve WHERE ve.institution_id = {$alias}.institution_id AND ve.id = {$alias}.event_id AND ve.created_by = ?)
              OR EXISTS (SELECT 1 FROM event_organizers vo WHERE vo.institution_id = {$alias}.institution_id AND vo.event_id = {$alias}.event_id AND vo.membership_id = ? AND vo.status = 'active'))))",
        array_merge($params, [(int) auth_user()['id'], current_membership_id()]),
    ];
}

function find_budget(int $budgetId): ?array
{
    if (!can_any(['budgets.view', 'budgets.manage', 'budgets.approve'])) {
        return null;
    }
    [$visibility, $params] = budget_visibility('b');
    $budget = db_one(
        "SELECT b.*, f.fund_name, f.fund_code, e.event_name, CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name, CONCAT(au.first_name, ' ', au.last_name) AS approved_by_name
         FROM budgets b
         JOIN fund_accounts f ON f.id = b.fund_id AND f.institution_id = b.institution_id
         LEFT JOIN events e ON e.id = b.event_id AND e.institution_id = b.institution_id
         JOIN users cu ON cu.id = b.created_by
         LEFT JOIN users au ON au.id = b.approved_by
         WHERE b.institution_id = ? AND b.id = ? AND {$visibility}",
        array_merge([current_institution_id(), $budgetId], $params)
    );
    if ($budget) {
        $budget['lines'] = budget_lines($budgetId);
        $budget['in_force'] = in_array($budget['status'], ['approved', 'active'], true);
    }
    return $budget;
}

function budget_lines(int $budgetId): array
{
    return db_all('SELECT * FROM budget_lines WHERE institution_id = ? AND budget_id = ? ORDER BY category', [current_institution_id(), $budgetId]);
}

/** Validates header fields. Returns [fund_id, event_id|null, name, start, end]. */
function budget_header_input(array $input): array
{
    $name = input_str($input, 'budget_name', 180);
    if (mb_strlen($name) < 3) {
        fail_user('Enter a budget name.');
    }
    $start = input_date($input, 'start_date') ?? fail_user('Enter the start of the budget period.');
    $end = input_date($input, 'end_date') ?? fail_user('Enter the end of the budget period.');
    if ($end < $start) {
        fail_user('The budget period ends before it starts.');
    }
    $eventId = input_int($input, 'event_id');
    $fundId = input_int($input, 'fund_id') ?? fail_user('Choose the fund.');
    if ($eventId) {
        $event = find_event($eventId) ?? fail_user('Event not found.');
        if (!$event['can_manage'] && !can('funds.view_all')) {
            fail_user('Only the event organizers or finance officers can budget for this event.');
        }
        if (!db_value("SELECT 1 FROM event_funds WHERE institution_id = ? AND event_id = ? AND fund_id = ? AND status = 'active'", [current_institution_id(), $eventId, $fundId])) {
            fail_user('That fund is not linked to the event. Link it on the event setup tab first.');
        }
        find_fund($fundId, ['active', 'pending', 'draft']);
    } elseif (!find_visible_fund($fundId)) {
        fail_user('Fund not found or not accessible to you.');
    }
    return [$fundId, $eventId, $name, $start, $end];
}

/** Validates budget lines. Returns [[category, description, cents], ...] and the total. */
function budget_lines_input(array $input): array
{
    $categories = expense_categories();
    $lines = [];
    $seen = [];
    foreach ((array) ($input['lines'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $category = input_str($row, 'category', 100);
        $amountText = input_str($row, 'allocated_amount', 20);
        if ($category === '' && $amountText === '') {
            continue;
        }
        if (!in_array($category, $categories, true)) {
            fail_user('Choose a category for every budget line.');
        }
        if (isset($seen[$category])) {
            fail_user("{$category} appears twice. Use one line per category.");
        }
        $seen[$category] = true;
        $amount = parse_money($amountText) ?? fail_user("Enter a valid amount for {$category}.");
        $lines[] = [$category, input_str($row, 'description', 255) ?: null, $amount];
    }
    if (!$lines) {
        fail_user('Add at least one budget line.');
    }
    $total = array_sum(array_column($lines, 2));
    if ($total > MONEY_MAX_CENTS) {
        fail_user('The budget total is too large.');
    }
    return [$lines, $total];
}

function replace_budget_lines(int $institutionId, int $budgetId, array $lines): void
{
    db_query('DELETE FROM budget_lines WHERE institution_id = ? AND budget_id = ?', [$institutionId, $budgetId]);
    foreach ($lines as [$category, $description, $amount]) {
        db_query('INSERT INTO budget_lines (institution_id, budget_id, category, description, allocated_amount) VALUES (?, ?, ?, ?, ?)',
            [$institutionId, $budgetId, $category, $description, cents_to_decimal($amount)]);
    }
}

/** Comparable view of budget lines (stored rows or validated input), sorted by category. */
function lines_snapshot(array $lines): array
{
    $snapshot = array_map(static fn($line) => isset($line['category'])
        ? ['category' => $line['category'], 'description' => $line['description'], 'amount' => cents_to_decimal(to_cents($line['allocated_amount']))]
        : ['category' => $line[0], 'description' => $line[1], 'amount' => cents_to_decimal($line[2])], $lines);
    usort($snapshot, static fn($a, $b) => strcmp($a['category'], $b['category']));
    return $snapshot;
}

/** Creates or edits a draft budget (budgets.manage). */
function save_budget(?int $budgetId, array $input): int
{
    if (!can('budgets.manage')) {
        fail_user('You do not have permission to prepare budgets.');
    }
    $institutionId = (int) current_institution_id();
    [$fundId, $eventId, $name, $start, $end] = budget_header_input($input);
    [$lines, $total] = budget_lines_input($input);
    $userId = (int) auth_user()['id'];

    return db_transaction(function () use ($institutionId, $budgetId, $fundId, $eventId, $name, $start, $end, $lines, $total, $userId) {
        if ($budgetId) {
            $before = find_budget($budgetId) ?? fail_user('Budget not found.');
            db_query('SELECT id FROM budgets WHERE id = ? FOR UPDATE', [$budgetId]);
            if ($before['status'] !== 'draft') {
                fail_user('Only draft budgets can be edited. Approved budgets are changed through a revision.');
            }
            db_query('UPDATE budgets SET fund_id = ?, event_id = ?, budget_name = ?, total_amount = ?, start_date = ?, end_date = ? WHERE id = ?',
                [$fundId, $eventId, $name, cents_to_decimal($total), $start, $end, $budgetId]);
            replace_budget_lines($institutionId, $budgetId, $lines);
            audit_log('budget.updated', 'budget', $budgetId, $institutionId, $userId,
                ['total_amount' => cents_to_decimal($total), 'lines' => lines_snapshot($lines)],
                ['total_amount' => $before['total_amount'], 'lines' => lines_snapshot($before['lines'])]);
            return $budgetId;
        }
        db_query(
            "INSERT INTO budgets (institution_id, budget_number, fund_id, event_id, budget_name, total_amount, start_date, end_date, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)",
            [$institutionId, next_document_number($institutionId, 'budget', 'BUD'), $fundId, $eventId, $name, cents_to_decimal($total), $start, $end, $userId]
        );
        $budgetId = (int) db()->lastInsertId();
        replace_budget_lines($institutionId, $budgetId, $lines);
        audit_log('budget.created', 'budget', $budgetId, $institutionId, $userId, ['total_amount' => cents_to_decimal($total), 'lines' => lines_snapshot($lines)]);
        return $budgetId;
    });
}

function lock_budget(int $budgetId): array
{
    $budget = find_budget($budgetId) ?? fail_user('Budget not found.');
    db_query('SELECT id FROM budgets WHERE institution_id = ? AND id = ? FOR UPDATE', [current_institution_id(), $budgetId]);
    $budget['status'] = (string) db_value('SELECT status FROM budgets WHERE id = ?', [$budgetId]);
    return $budget;
}

function submit_budget(int $budgetId): void
{
    if (!can('budgets.manage')) {
        fail_user('You do not have permission to submit budgets.');
    }
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $budgetId) {
        $budget = lock_budget($budgetId);
        if ($budget['status'] !== 'draft') {
            fail_user('Only draft budgets can be submitted.');
        }
        db_query("UPDATE budgets SET status = 'pending' WHERE id = ?", [$budgetId]);
        audit_log('budget.submitted', 'budget', $budgetId, $institutionId, (int) auth_user()['id'], ['status' => 'pending'], ['status' => 'draft']);
        notify_permission($institutionId, 'budgets.approve', 'budget', 'Budget awaiting approval',
            $budget['budget_number'] . ' · ' . $budget['budget_name'] . ' (' . m($budget['total_amount']) . ') needs approval.');
    });
}

/** Approves, or returns to draft with a reason. The approver cannot be the preparer. */
function decide_budget(int $budgetId, bool $approve, string $reason): void
{
    if (!can('budgets.approve')) {
        fail_user('You do not have permission to approve budgets.');
    }
    $institutionId = (int) current_institution_id();
    $userId = (int) auth_user()['id'];
    db_transaction(function () use ($institutionId, $budgetId, $approve, $reason, $userId) {
        $budget = lock_budget($budgetId);
        if ($budget['status'] !== 'pending') {
            fail_user('This budget is not awaiting approval.');
        }
        if ((int) $budget['created_by'] === $userId) {
            fail_user('You prepared this budget, so another approver must decide on it.');
        }
        if ($approve) {
            db_query("UPDATE budgets SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?", [$userId, $budgetId]);
            audit_log('budget.approved', 'budget', $budgetId, $institutionId, $userId, ['status' => 'approved', 'total_amount' => $budget['total_amount']], ['status' => 'pending'], $reason ?: null);
            notify_user($institutionId, (int) $budget['created_by'], 'budget', 'Budget approved', $budget['budget_number'] . ' was approved.');
            return;
        }
        if (mb_strlen($reason) < 5) {
            fail_user('Give a reason so the preparer can correct the budget.');
        }
        db_query("UPDATE budgets SET status = 'draft' WHERE id = ?", [$budgetId]);
        audit_log('budget.returned', 'budget', $budgetId, $institutionId, $userId, ['status' => 'draft'], ['status' => 'pending'], $reason);
        notify_user($institutionId, (int) $budget['created_by'], 'budget', 'Budget returned', $budget['budget_number'] . ' was returned: ' . $reason);
    });
}

/** Revises the lines of an approved budget (budgets.approve, reason required). */
function revise_budget(int $budgetId, array $input): void
{
    if (!can('budgets.approve')) {
        fail_user('Only budget approvers can authorise a revision.');
    }
    $reason = input_str($input, 'reason', 1000);
    if (mb_strlen($reason) < 10) {
        fail_user('Explain the reason for the revision (at least 10 characters).');
    }
    [$lines, $total] = budget_lines_input($input);
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $budgetId, $lines, $total, $reason) {
        $budget = lock_budget($budgetId);
        if (!in_array($budget['status'], ['approved', 'active'], true)) {
            fail_user('Only approved budgets can be revised.');
        }
        $before = lines_snapshot($budget['lines']);
        if ($before === lines_snapshot($lines)) {
            fail_user('Nothing changed in the budget lines.');
        }
        db_query('UPDATE budgets SET total_amount = ? WHERE id = ?', [cents_to_decimal($total), $budgetId]);
        replace_budget_lines($institutionId, $budgetId, $lines);
        audit_log('budget.revised', 'budget', $budgetId, $institutionId, (int) auth_user()['id'],
            ['total_amount' => cents_to_decimal($total), 'lines' => lines_snapshot($lines)],
            ['total_amount' => $budget['total_amount'], 'lines' => $before], $reason);
        notify_user($institutionId, (int) $budget['created_by'], 'budget', 'Budget revised', $budget['budget_number'] . ' was revised: ' . $reason);
    });
}

/** Closes an approved budget, or cancels a draft/pending one. */
function set_budget_status(int $budgetId, string $status, string $reason): void
{
    if (!can_any(['budgets.manage', 'budgets.approve'])) {
        fail_user('You do not have permission to change budgets.');
    }
    $allowed = ['closed' => ['approved', 'active'], 'cancelled' => ['draft', 'pending']];
    if (!isset($allowed[$status])) {
        fail_user('Unknown status.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('Give a reason.');
    }
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $budgetId, $status, $reason, $allowed) {
        $budget = lock_budget($budgetId);
        if (!in_array($budget['status'], $allowed[$status], true)) {
            fail_user($status === 'closed' ? 'Only approved budgets can be closed.' : 'Only draft or pending budgets can be cancelled.');
        }
        db_query('UPDATE budgets SET status = ? WHERE id = ?', [$status, $budgetId]);
        audit_log('budget.' . $status, 'budget', $budgetId, $institutionId, (int) auth_user()['id'], ['status' => $status], ['status' => $budget['status']], $reason);
    });
}

/**
 * Budget vs actual per category (centavos). Categories with spending but no budget
 * line appear as unbudgeted rows (approved 0).
 *
 * @return array{rows: array, totals: array}
 */
function budget_vs_actual(array $budget): array
{
    $institutionId = (int) current_institution_id();
    $eventSql = $budget['event_id'] ? ' AND x.event_id = ?' : '';
    $advanceEventSql = $budget['event_id'] ? ' AND a.event_id = ?' : '';
    $params = array_filter([$institutionId, (int) $budget['fund_id'], $budget['start_date'], $budget['end_date'], $budget['event_id'] ? (int) $budget['event_id'] : null], static fn($v) => $v !== null);
    $spending = db_all(
        "SELECT category, SUM(actual) AS actual, SUM(pending) AS pending FROM (
            SELECT x.category,
                   CASE WHEN x.status = 'paid' THEN x.amount ELSE 0 END AS actual,
                   CASE WHEN x.status IN ('submitted', 'approved') THEN x.amount ELSE 0 END AS pending
            FROM expenses x
            WHERE x.institution_id = ? AND x.fund_id = ? AND x.expense_date BETWEEN ? AND ? AND x.status IN ('paid', 'submitted', 'approved'){$eventSql}
            UNION ALL
            SELECT ae.category,
                   CASE WHEN ae.verification_status = 'verified' THEN ae.amount ELSE 0 END,
                   CASE WHEN ae.verification_status IN ('pending', 'needs_correction') THEN ae.amount ELSE 0 END
            FROM cash_advance_expenses ae JOIN cash_advances a ON a.id = ae.cash_advance_id AND a.institution_id = ae.institution_id
            WHERE ae.institution_id = ? AND a.fund_id = ? AND ae.expense_date BETWEEN ? AND ? AND a.status <> 'cancelled'
              AND ae.verification_status IN ('verified', 'pending', 'needs_correction'){$advanceEventSql}
         ) s GROUP BY category",
        array_merge(array_values($params), array_values($params))
    );
    $byCategory = [];
    foreach ($spending as $row) {
        $byCategory[$row['category']] = ['actual' => to_cents($row['actual']), 'pending' => to_cents($row['pending'])];
    }
    $rows = [];
    foreach ($budget['lines'] as $line) {
        $rows[$line['category']] = ['category' => $line['category'], 'description' => $line['description'], 'budgeted' => true, 'approved' => to_cents($line['allocated_amount'])]
            + ($byCategory[$line['category']] ?? ['actual' => 0, 'pending' => 0]);
    }
    foreach ($byCategory as $category => $figures) {
        if (!isset($rows[$category])) {
            $rows[$category] = ['category' => $category, 'description' => null, 'budgeted' => false, 'approved' => 0] + $figures;
        }
    }
    $totals = ['approved' => 0, 'actual' => 0, 'pending' => 0];
    foreach ($rows as &$row) {
        $row['remaining'] = $row['approved'] - $row['actual'];
        $row['available'] = $row['remaining'] - $row['pending'];
        $row['over'] = $row['actual'] > $row['approved'];
        $row['at_risk'] = !$row['over'] && $row['available'] < 0;
        $row['used_percent'] = $row['approved'] > 0 ? (int) floor($row['actual'] * 100 / $row['approved']) : ($row['actual'] > 0 ? 100 : 0);
        foreach ($totals as $key => $value) {
            $totals[$key] += $row[$key];
        }
    }
    unset($row);
    $totals['remaining'] = $totals['approved'] - $totals['actual'];
    $totals['available'] = $totals['remaining'] - $totals['pending'];
    return ['rows' => array_values($rows), 'totals' => $totals];
}

/** Funds the user can budget for: visible funds plus funds linked to events they manage. */
function budget_fund_options(): array
{
    [$visibility, $params] = fund_visibility('f');
    return options_from_rows(db_all(
        "SELECT f.id, f.fund_name FROM fund_accounts f
         WHERE f.institution_id = ? AND f.status IN ('draft', 'pending', 'active')
           AND ({$visibility} OR EXISTS (
                SELECT 1 FROM event_funds ef JOIN events e ON e.id = ef.event_id AND e.institution_id = ef.institution_id
                WHERE ef.institution_id = f.institution_id AND ef.fund_id = f.id AND ef.status = 'active'
                  AND (e.created_by = ? OR EXISTS (SELECT 1 FROM event_organizers o WHERE o.institution_id = e.institution_id AND o.event_id = e.id AND o.membership_id = ? AND o.status = 'active'))))
         ORDER BY f.fund_name",
        array_merge([current_institution_id()], $params, [(int) auth_user()['id'], current_membership_id()])
    ), 'id', 'fund_name');
}
