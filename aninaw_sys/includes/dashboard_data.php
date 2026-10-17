<?php
/**
 * Dashboard data service.
 *
 * Every query is scoped by the institution and membership of the authenticated
 * context, never by request parameters. Totals use posted/confirmed records only,
 * and amounts are aggregated in subqueries so joins cannot double-count.
 */
declare(strict_types=1);

/**
 * Panels the current user may see, decided by permissions (a user with several
 * roles gets the panels of all of them).
 */
function dashboard_panels(): array
{
    $context = auth_context();
    if (!$context) {
        return [];
    }
    if ($context['type'] === 'platform') {
        return can('platform.institutions.view') ? ['platform'] : [];
    }

    $rules = [
        'overview'   => fn() => can('users.view'),
        'finance'    => fn() => can('funds.view_all'),
        'events'     => fn() => can('events.manage'),
        'cashier'    => fn() => can('turnovers.receive'),
        'collector'  => fn() => can('turnovers.submit'),
        'adviser'    => fn() => can('groups.monitor'),
        'ledger'     => fn() => can('ledger.view'),
        'activity'   => fn() => can('audit.view'),
        'member'     => fn() => can('self.obligations.view'),
        'advances'   => fn() => can('self.advances.view'),
    ];

    return array_keys(array_filter($rules, static fn(callable $rule): bool => $rule()));
}

/**
 * Loads one panel. A failing query affects only its own panel.
 *
 * @return array{error: bool, data: array}
 */
function dashboard_load(string $panel): array
{
    $loaders = [
        'platform'  => 'dashboard_platform',
        'overview'  => 'dashboard_overview',
        'finance'   => 'dashboard_finance',
        'events'    => 'dashboard_events',
        'cashier'   => 'dashboard_cashier',
        'collector' => 'dashboard_collector',
        'adviser'   => 'dashboard_adviser',
        'ledger'    => 'dashboard_ledger',
        'activity'  => 'dashboard_activity',
        'member'    => 'dashboard_member',
        'advances'  => 'dashboard_advances',
    ];

    try {
        return ['error' => false, 'data' => ($loaders[$panel])()];
    } catch (Throwable $exception) {
        log_error('Dashboard panel failed', ['panel' => $panel, 'message' => $exception->getMessage()]);
        return ['error' => true, 'data' => []];
    }
}

// ---------------------------------------------------------------------------
// Shared SQL fragments
// ---------------------------------------------------------------------------

/** Amount still owed on an obligation: due minus waived (cancelled obligations excluded by callers). */
const SQL_OBLIGATION_NET = '(o.amount_due - o.waived_amount)';

/** Posted payment allocations for obligation alias `o`. */
const SQL_OBLIGATION_PAID = "(SELECT COALESCE(SUM(pa.allocated_amount), 0)
    FROM payment_allocations pa
    JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
    WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id)";

/** Expected and collected totals for one event, optionally limited to one group's active members. */
function event_group_progress(int $institutionId, int $eventId, ?int $groupId = null): array
{
    $groupFilter = '';
    $params = [$institutionId, $eventId];
    if ($groupId !== null) {
        $groupFilter = "AND EXISTS (SELECT 1 FROM group_memberships gm
                         WHERE gm.institution_id = o.institution_id AND gm.group_id = ?
                           AND gm.participant_id = o.participant_id AND gm.status = 'active')";
        $params[] = $groupId;
    }

    $row = db_one(
        'SELECT COUNT(*) AS obligations,
                COALESCE(SUM(' . SQL_OBLIGATION_NET . '), 0) AS expected,
                COALESCE(SUM(' . SQL_OBLIGATION_PAID . "), 0) AS collected
         FROM event_obligations o
         WHERE o.institution_id = ? AND o.event_id = ? AND o.status <> 'cancelled' {$groupFilter}",
        $params
    );

    $expected = (float) $row['expected'];
    $collected = (float) $row['collected'];
    return [
        'obligations' => (int) $row['obligations'],
        'expected'    => $expected,
        'collected'   => $collected,
        'remaining'   => max(0.0, $expected - $collected),
        'percent'     => percent($collected, $expected),
    ];
}

// ---------------------------------------------------------------------------
// Platform Super Administrator
// ---------------------------------------------------------------------------

function dashboard_platform(): array
{
    $institutionStatus = db_query('SELECT status, COUNT(*) FROM institutions GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $userStatus = db_query('SELECT status, COUNT(*) FROM users GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'institutions_total'  => array_sum($institutionStatus),
        'institution_status'  => $institutionStatus,
        'users_total'         => array_sum($userStatus),
        'user_status'         => $userStatus,
        'recent_institutions' => db_all(
            "SELECT i.institution_code, i.institution_name, i.institution_type, i.status, i.created_at,
                    (SELECT COUNT(*) FROM institution_memberships m
                      WHERE m.institution_id = i.id AND m.membership_status = 'active') AS active_members
             FROM institutions i
             ORDER BY i.created_at DESC, i.id DESC
             LIMIT 8"
        ),
        // Platform-level events only; institution activity stays private to the institution.
        'recent_activity' => db_all(
            "SELECT a.action, a.entity_type, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.institution_id IS NULL AND a.action NOT IN ('auth.login_failed', 'auth.login_throttled')
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 10"
        ),
    ];
}

// ---------------------------------------------------------------------------
// Institution Administrator
// ---------------------------------------------------------------------------

function dashboard_overview(): array
{
    $institutionId = current_institution_id();

    $institution = db_one(
        'SELECT institution_code, institution_name, institution_type, email, phone, timezone, currency_code, status, created_at
         FROM institutions WHERE id = ?',
        [$institutionId]
    );
    $memberships = db_query(
        'SELECT membership_status, COUNT(*) FROM institution_memberships WHERE institution_id = ? GROUP BY membership_status',
        [$institutionId]
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'institution'   => $institution,
        'memberships'   => $memberships,
        'members_total' => array_sum($memberships),
        'roles' => db_all(
            "SELECT r.role_name, COUNT(*) AS members
             FROM membership_roles mr
             JOIN institution_memberships m ON m.id = mr.membership_id AND m.institution_id = ? AND m.membership_status = 'active'
             JOIN roles r ON r.id = mr.role_id
             GROUP BY r.id, r.role_name
             ORDER BY members DESC, r.role_name",
            [$institutionId]
        ),
        'active_events' => (int) db_value(
            "SELECT COUNT(*) FROM events WHERE institution_id = ? AND status IN ('approved', 'collecting')",
            [$institutionId]
        ),
        'active_funds' => (int) db_value(
            "SELECT COUNT(*) FROM fund_accounts WHERE institution_id = ? AND status = 'active'",
            [$institutionId]
        ),
        'active_groups' => (int) db_value(
            "SELECT COUNT(*) FROM `groups` WHERE institution_id = ? AND status = 'active'",
            [$institutionId]
        ),
        'active_participants' => (int) db_value(
            "SELECT COUNT(*) FROM participants WHERE institution_id = ? AND status = 'active'",
            [$institutionId]
        ),
    ];
}

// ---------------------------------------------------------------------------
// Treasurer / Finance Officer (also read-only for Auditor and Institution Admin)
// ---------------------------------------------------------------------------

function dashboard_finance(): array
{
    $institutionId = current_institution_id();

    $collections = db_one(
        "SELECT COUNT(*) AS payments, COALESCE(SUM(amount), 0) AS total
         FROM payments WHERE institution_id = ? AND status = 'posted'",
        [$institutionId]
    );
    $expenses = db_one(
        "SELECT COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END), 0) AS paid_total,
                SUM(status = 'paid') AS paid_count,
                SUM(status = 'submitted') AS awaiting_approval
         FROM expenses WHERE institution_id = ?",
        [$institutionId]
    );

    // Outstanding = released − verified liquidation expenses − confirmed returns.
    $advances = db_one(
        "SELECT COUNT(*) AS open_advances,
                COALESCE(SUM(GREATEST(a.released_amount - COALESCE(x.verified, 0) - COALESCE(r.returned, 0), 0)), 0) AS outstanding,
                SUM(a.status = 'overdue' OR (a.liquidation_deadline IS NOT NULL AND a.liquidation_deadline < CURDATE())) AS overdue
         FROM cash_advances a
         LEFT JOIN (SELECT cash_advance_id, SUM(amount) AS verified FROM cash_advance_expenses
                    WHERE institution_id = ? AND verification_status = 'verified' GROUP BY cash_advance_id) x
                ON x.cash_advance_id = a.id
         LEFT JOIN (SELECT cash_advance_id, SUM(returned_amount) AS returned FROM cash_advance_returns
                    WHERE institution_id = ? AND status = 'confirmed' GROUP BY cash_advance_id) r
                ON r.cash_advance_id = a.id
         WHERE a.institution_id = ?
           AND a.status IN ('released', 'partially_liquidated', 'submitted_for_review', 'returned_for_correction', 'overdue')",
        [$institutionId, $institutionId, $institutionId]
    );

    // Fund positions from posted journal entries (same definitions as the Fund Balance Report).
    require_once APP_ROOT . '/includes/services/funds.php';
    $funds = [];
    foreach (db_all("SELECT id, fund_code, fund_name, status FROM fund_accounts WHERE institution_id = ? AND status IN ('active', 'suspended') ORDER BY fund_name", [$institutionId]) as $fund) {
        $funds[] = $fund + fund_summary((int) $fund['id']);
    }

    // Budget utilisation of budgets in force (actual = paid + verified; pending shown apart).
    require_once APP_ROOT . '/includes/services/budgets.php';
    $budgets = [];
    foreach (db_all("SELECT id FROM budgets WHERE institution_id = ? AND status IN ('approved', 'active') ORDER BY end_date LIMIT 5", [$institutionId]) as $row) {
        $budget = find_budget((int) $row['id']);
        if ($budget) {
            $budgets[] = ['id' => $budget['id'], 'name' => $budget['budget_name'], 'fund' => $budget['fund_name']] + budget_vs_actual($budget)['totals'];
        }
    }

    $pending = [
        'turnovers_to_verify' => (int) db_value(
            "SELECT COUNT(*) FROM cash_turnovers WHERE institution_id = ? AND status IN ('submitted', 'received_pending_verification')",
            [$institutionId]
        ),
        'open_discrepancies' => (int) db_value(
            "SELECT COUNT(*) FROM turnover_discrepancies WHERE institution_id = ? AND status IN ('open', 'under_review')",
            [$institutionId]
        ),
        'deposits_unconfirmed' => (int) db_value(
            "SELECT COUNT(*) FROM deposits WHERE institution_id = ? AND status IN ('recorded', 'pending_confirmation')",
            [$institutionId]
        ),
        'liquidations_to_review' => (int) db_value(
            "SELECT COUNT(*) FROM cash_advances WHERE institution_id = ? AND status = 'submitted_for_review'",
            [$institutionId]
        ),
    ];
    $approvals = [
        'expenses_to_approve' => (int) db_value("SELECT COUNT(*) FROM expenses WHERE institution_id = ? AND status = 'submitted'", [$institutionId]),
        'expenses_to_pay'     => (int) db_value("SELECT COUNT(*) FROM expenses WHERE institution_id = ? AND status = 'approved'", [$institutionId]),
        'advances_to_approve' => (int) db_value("SELECT COUNT(*) FROM cash_advances WHERE institution_id = ? AND status = 'pending_approval'", [$institutionId]),
        'advances_to_release' => (int) db_value("SELECT COUNT(*) FROM cash_advances WHERE institution_id = ? AND status = 'approved'", [$institutionId]),
        'transfers_to_decide' => (int) db_value("SELECT COUNT(*) FROM fund_transfers WHERE institution_id = ? AND status IN ('submitted', 'approved')", [$institutionId]),
        'budgets_to_approve'  => (int) db_value("SELECT COUNT(*) FROM budgets WHERE institution_id = ? AND status = 'pending'", [$institutionId]),
        'entries_to_post'     => (int) db_value("SELECT COUNT(*) FROM journal_entries WHERE institution_id = ? AND status = 'draft'", [$institutionId]),
    ];

    return [
        'collections_total'  => (float) $collections['total'],
        'collections_count'  => (int) $collections['payments'],
        'expenses_paid'      => (float) $expenses['paid_total'],
        'expenses_count'     => (int) $expenses['paid_count'],
        'expenses_awaiting'  => (int) $expenses['awaiting_approval'],
        'advances_outstanding' => (float) $advances['outstanding'],
        'advances_open'      => (int) $advances['open_advances'],
        'advances_overdue'   => (int) $advances['overdue'],
        'funds'              => $funds,
        'budgets'            => $budgets,
        'pending'            => $pending,
        'approvals'          => $approvals,
        'recent_turnovers'   => db_all(
            "SELECT t.turnover_number, t.declared_amount, t.received_amount, t.status, t.submitted_at,
                    g.group_name, e.event_name
             FROM cash_turnovers t
             JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
             JOIN events e ON e.id = t.event_id AND e.institution_id = t.institution_id
             WHERE t.institution_id = ? AND t.status <> 'draft'
             ORDER BY COALESCE(t.submitted_at, t.received_at) DESC, t.id DESC
             LIMIT 5",
            [$institutionId]
        ),
        'recent_transactions' => db_all(
            "SELECT c.transaction_number, c.transaction_date, c.transaction_type, c.direction, c.amount,
                    f.fund_name, fa.account_name
             FROM cash_flow_transactions c
             JOIN fund_accounts f ON f.id = c.fund_id AND f.institution_id = c.institution_id
             JOIN financial_accounts fa ON fa.id = c.financial_account_id AND fa.institution_id = c.institution_id
             WHERE c.institution_id = ? AND c.status = 'posted'
             ORDER BY c.transaction_date DESC, c.id DESC
             LIMIT 8",
            [$institutionId]
        ),
    ];
}

// ---------------------------------------------------------------------------
// Event Organizer
// ---------------------------------------------------------------------------

function dashboard_events(): array
{
    $institutionId = current_institution_id();
    $userId = (int) auth_user()['id'];

    // The schema records event ownership through events.created_by.
    $events = db_all(
        "SELECT e.id, e.event_code, e.event_name, e.status, e.start_date, e.end_date, e.collection_deadline
         FROM events e
         WHERE e.institution_id = ? AND e.created_by = ? AND e.status NOT IN ('closed', 'cancelled')
         ORDER BY COALESCE(e.start_date, e.created_at) DESC
         LIMIT 10",
        [$institutionId, $userId]
    );

    $showExpenses = can('expenses.view');
    $tasks = [];
    foreach ($events as &$event) {
        $event += event_group_progress($institutionId, (int) $event['id']);
        $event['expenses_paid'] = $showExpenses ? (float) db_value(
            "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE institution_id = ? AND event_id = ? AND status = 'paid'",
            [$institutionId, $event['id']]
        ) : null;

        if (in_array($event['status'], ['draft', 'pending'], true)) {
            $tasks[] = ['icon' => 'bi-hourglass-split', 'text' => $event['event_name'] . ' is ' . humanize($event['status']) . ' and not yet approved for collection.'];
        }
        if ($event['obligations'] === 0 && in_array($event['status'], ['approved', 'collecting'], true)) {
            $tasks[] = ['icon' => 'bi-list-check', 'text' => $event['event_name'] . ' has no collection obligations generated yet.'];
        }
        if ($event['collection_deadline'] && $event['collection_deadline'] < date('Y-m-d') && $event['remaining'] > 0) {
            $tasks[] = ['icon' => 'bi-exclamation-triangle', 'text' => $event['event_name'] . ' passed its collection deadline with ' . format_money($event['remaining'], auth_context()['currency_code']) . ' uncollected.'];
        }
    }
    unset($event);

    return ['events' => $events, 'tasks' => $tasks, 'show_expenses' => $showExpenses];
}

// ---------------------------------------------------------------------------
// Faculty Cashier / Collection Officer
// ---------------------------------------------------------------------------

function dashboard_cashier(): array
{
    $institutionId = current_institution_id();
    $membershipId = current_membership_id();

    $collected = db_one(
        "SELECT COUNT(*) AS payments, COALESCE(SUM(amount), 0) AS total
         FROM payments WHERE institution_id = ? AND received_by_membership_id = ? AND status = 'posted'",
        [$institutionId, $membershipId]
    );

    return [
        'collected_total' => (float) $collected['total'],
        'collected_count' => (int) $collected['payments'],
        'assigned_groups' => (int) db_value(
            "SELECT COUNT(*) FROM event_group_assignments WHERE institution_id = ? AND cashier_membership_id = ? AND status = 'active'",
            [$institutionId, $membershipId]
        ),
        'pending_turnovers' => db_all(
            "SELECT t.turnover_number, t.declared_amount, t.received_amount, t.status, t.submitted_at,
                    g.group_name, e.event_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS submitted_by
             FROM cash_turnovers t
             JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
             JOIN events e ON e.id = t.event_id AND e.institution_id = t.institution_id
             JOIN institution_memberships m ON m.id = t.submitted_by_membership_id AND m.institution_id = t.institution_id
             JOIN users u ON u.id = m.user_id
             WHERE t.institution_id = ? AND t.received_by_membership_id = ?
               AND t.status IN ('submitted', 'received_pending_verification', 'discrepancy')
             ORDER BY t.submitted_at ASC, t.id ASC
             LIMIT 10",
            [$institutionId, $membershipId]
        ),
        'recent_receipts' => db_all(
            "SELECT r.receipt_number, r.issued_at, r.receipt_status, p.amount, p.payment_method,
                    CONCAT(pt.first_name, ' ', pt.last_name) AS payer
             FROM receipts r
             JOIN payments p ON p.id = r.payment_id AND p.institution_id = r.institution_id
             JOIN participants pt ON pt.id = p.payer_participant_id AND pt.institution_id = p.institution_id
             WHERE r.institution_id = ? AND r.issued_by_membership_id = ?
             ORDER BY r.issued_at DESC, r.id DESC
             LIMIT 6",
            [$institutionId, $membershipId]
        ),
        'open_discrepancies' => db_all(
            "SELECT t.turnover_number, d.expected_amount, d.actual_amount, d.difference_amount, d.status, d.reported_at
             FROM turnover_discrepancies d
             JOIN cash_turnovers t ON t.id = d.turnover_id AND t.institution_id = d.institution_id
             WHERE d.institution_id = ? AND t.received_by_membership_id = ? AND d.status IN ('open', 'under_review')
             ORDER BY d.reported_at DESC
             LIMIT 6",
            [$institutionId, $membershipId]
        ),
    ];
}

// ---------------------------------------------------------------------------
// Class President / Collector
// ---------------------------------------------------------------------------

function dashboard_collector(): array
{
    $institutionId = current_institution_id();
    $membershipId = current_membership_id();

    $officerGroups = db_all(
        "SELECT g.group_code, g.group_name, g.group_type, g.academic_year, g.term, o.officer_type
         FROM group_officers o
         JOIN `groups` g ON g.id = o.group_id AND g.institution_id = o.institution_id
         WHERE o.institution_id = ? AND o.membership_id = ? AND o.status = 'active'
           AND o.officer_type IN ('president', 'collector')
           AND o.valid_from <= CURDATE() AND (o.valid_until IS NULL OR o.valid_until >= CURDATE())
         ORDER BY g.group_name",
        [$institutionId, $membershipId]
    );

    $assignments = db_all(
        "SELECT a.event_id, a.group_id, e.event_name, e.status AS event_status, e.collection_deadline, g.group_name,
                CONCAT(u.first_name, ' ', u.last_name) AS cashier
         FROM event_group_assignments a
         JOIN events e ON e.id = a.event_id AND e.institution_id = a.institution_id
         JOIN `groups` g ON g.id = a.group_id AND g.institution_id = a.institution_id
         JOIN institution_memberships cm ON cm.id = a.cashier_membership_id AND cm.institution_id = a.institution_id
         JOIN users u ON u.id = cm.user_id
         WHERE a.institution_id = ? AND a.collector_membership_id = ? AND a.status = 'active'
           AND e.status NOT IN ('closed', 'cancelled')
         ORDER BY e.collection_deadline IS NULL, e.collection_deadline, e.event_name",
        [$institutionId, $membershipId]
    );
    foreach ($assignments as &$assignment) {
        $assignment += event_group_progress($institutionId, (int) $assignment['event_id'], (int) $assignment['group_id']);
        $assignment['turned_over'] = (float) db_value(
            "SELECT COALESCE(SUM(COALESCE(received_amount, declared_amount)), 0) FROM cash_turnovers
             WHERE institution_id = ? AND event_id = ? AND group_id = ? AND status IN ('received_pending_verification', 'verified')",
            [$institutionId, $assignment['event_id'], $assignment['group_id']]
        );
    }
    unset($assignment);

    return [
        'officer_groups' => $officerGroups,
        'assignments'    => $assignments,
        'turnovers'      => db_all(
            "SELECT t.turnover_number, t.declared_amount, t.received_amount, t.status, t.submitted_at, t.verified_at,
                    g.group_name, e.event_name
             FROM cash_turnovers t
             JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
             JOIN events e ON e.id = t.event_id AND e.institution_id = t.institution_id
             WHERE t.institution_id = ? AND t.submitted_by_membership_id = ?
             ORDER BY t.id DESC
             LIMIT 8",
            [$institutionId, $membershipId]
        ),
    ];
}

// ---------------------------------------------------------------------------
// Adviser
// ---------------------------------------------------------------------------

function dashboard_adviser(): array
{
    $institutionId = current_institution_id();
    $membershipId = current_membership_id();

    $groups = db_all(
        "SELECT g.id, g.group_code, g.group_name, g.academic_year, g.term,
                (SELECT COUNT(*) FROM group_memberships gm
                  WHERE gm.institution_id = g.institution_id AND gm.group_id = g.id AND gm.status = 'active') AS members
         FROM group_officers o
         JOIN `groups` g ON g.id = o.group_id AND g.institution_id = o.institution_id
         WHERE o.institution_id = ? AND o.membership_id = ? AND o.status = 'active' AND o.officer_type = 'adviser'
           AND o.valid_from <= CURDATE() AND (o.valid_until IS NULL OR o.valid_until >= CURDATE())
         ORDER BY g.group_name",
        [$institutionId, $membershipId]
    );

    $progress = [];
    $groupIds = array_map(static fn(array $group): int => (int) $group['id'], $groups);
    foreach ($groupIds as $groupId) {
        $rows = db_all(
            "SELECT a.event_id, e.event_name, e.status AS event_status
             FROM event_group_assignments a
             JOIN events e ON e.id = a.event_id AND e.institution_id = a.institution_id
             WHERE a.institution_id = ? AND a.group_id = ? AND a.status = 'active' AND e.status NOT IN ('closed', 'cancelled')
             ORDER BY e.event_name",
            [$institutionId, $groupId]
        );
        foreach ($rows as $row) {
            $row['group_id'] = $groupId;
            $row += event_group_progress($institutionId, (int) $row['event_id'], $groupId);
            $row['turnover_status'] = db_query(
                'SELECT status, COUNT(*) FROM cash_turnovers WHERE institution_id = ? AND event_id = ? AND group_id = ? GROUP BY status',
                [$institutionId, $row['event_id'], $groupId]
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $progress[] = $row;
        }
    }

    $discrepancies = [];
    if ($groupIds) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $discrepancies = db_all(
            "SELECT t.turnover_number, g.group_name, d.difference_amount, d.status, d.reported_at
             FROM turnover_discrepancies d
             JOIN cash_turnovers t ON t.id = d.turnover_id AND t.institution_id = d.institution_id
             JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
             WHERE d.institution_id = ? AND t.group_id IN ({$placeholders}) AND d.status IN ('open', 'under_review')
             ORDER BY d.reported_at DESC
             LIMIT 6",
            array_merge([$institutionId], $groupIds)
        );
    }

    $groupNames = array_column($groups, 'group_name', 'id');
    foreach ($progress as &$row) {
        $row['group_name'] = $groupNames[$row['group_id']] ?? '';
    }
    unset($row);

    return ['groups' => $groups, 'progress' => $progress, 'discrepancies' => $discrepancies];
}

// ---------------------------------------------------------------------------
// Ledger and reconciliation (Auditor, Treasurer)
// ---------------------------------------------------------------------------

function dashboard_ledger(): array
{
    $institutionId = current_institution_id();

    return [
        'journal_entries' => db_all(
            "SELECT j.journal_number, j.journal_date, j.description, j.source_type, j.posted_at,
                    COALESCE(l.debits, 0) AS debits, COALESCE(l.credits, 0) AS credits
             FROM journal_entries j
             LEFT JOIN (SELECT journal_entry_id, SUM(debit_amount) AS debits, SUM(credit_amount) AS credits
                        FROM journal_lines WHERE institution_id = ? GROUP BY journal_entry_id) l
                    ON l.journal_entry_id = j.id
             WHERE j.institution_id = ? AND j.status = 'posted'
             ORDER BY j.posted_at DESC, j.id DESC
             LIMIT 6",
            [$institutionId, $institutionId]
        ),
        'turnover_status' => db_query(
            "SELECT status, COUNT(*) FROM cash_turnovers WHERE institution_id = ? AND status <> 'draft' GROUP BY status",
            [$institutionId]
        )->fetchAll(PDO::FETCH_KEY_PAIR),
        'deposit_status' => db_query(
            'SELECT status, COUNT(*) FROM deposits WHERE institution_id = ? GROUP BY status',
            [$institutionId]
        )->fetchAll(PDO::FETCH_KEY_PAIR),
        'discrepancies' => db_all(
            "SELECT t.turnover_number, g.group_name, d.expected_amount, d.actual_amount, d.difference_amount, d.status, d.reported_at
             FROM turnover_discrepancies d
             JOIN cash_turnovers t ON t.id = d.turnover_id AND t.institution_id = d.institution_id
             JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
             WHERE d.institution_id = ? AND d.status IN ('open', 'under_review')
             ORDER BY d.reported_at DESC
             LIMIT 8",
            [$institutionId]
        ),
    ];
}

function dashboard_activity(): array
{
    return [
        'entries' => db_all(
            "SELECT a.action, a.entity_type, a.entity_id, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.institution_id = ?
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT 10",
            [current_institution_id()]
        ),
    ];
}

// ---------------------------------------------------------------------------
// Student / Member (own records only)
// ---------------------------------------------------------------------------

function dashboard_member(): array
{
    $institutionId = current_institution_id();

    $participant = db_one(
        "SELECT id, participant_number, first_name, last_name, participant_type
         FROM participants WHERE institution_id = ? AND user_id = ? AND status = 'active'",
        [$institutionId, (int) auth_user()['id']]
    );
    if (!$participant) {
        return ['participant' => null];
    }

    $obligations = db_all(
        'SELECT o.id, e.event_name, cr.rule_name, cr.inclusions, o.amount_due, o.waived_amount, o.due_date, o.status,
                ' . SQL_OBLIGATION_PAID . " AS paid
         FROM event_obligations o
         JOIN events e ON e.id = o.event_id AND e.institution_id = o.institution_id
         JOIN collection_rules cr ON cr.id = o.collection_rule_id AND cr.institution_id = o.institution_id
         WHERE o.institution_id = ? AND o.participant_id = ? AND o.status <> 'cancelled'
         ORDER BY o.due_date IS NULL, o.due_date, e.event_name",
        [$institutionId, $participant['id']]
    );

    $totalDue = 0.0;
    $totalPaid = 0.0;
    foreach ($obligations as &$obligation) {
        $net = (float) $obligation['amount_due'] - (float) $obligation['waived_amount'];
        $obligation['balance'] = max(0.0, $net - (float) $obligation['paid']);
        $totalDue += $net;
        $totalPaid += (float) $obligation['paid'];
    }
    unset($obligation);

    return [
        'participant' => $participant,
        'obligations' => $obligations,
        'total_due'   => $totalDue,
        'total_paid'  => $totalPaid,
        'balance'     => max(0.0, $totalDue - $totalPaid),
        'payments'    => db_all(
            "SELECT p.payment_number, p.payment_date, p.payment_method, p.amount, p.status, r.receipt_number
             FROM payments p
             LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id AND r.receipt_status = 'issued'
             WHERE p.institution_id = ? AND p.payer_participant_id = ? AND p.status IN ('posted', 'refunded')
             ORDER BY p.payment_date DESC, p.id DESC
             LIMIT 10",
            [$institutionId, $participant['id']]
        ),
    ];
}

// ---------------------------------------------------------------------------
// Cash Advance Recipient (own advances only)
// ---------------------------------------------------------------------------

function dashboard_advances(): array
{
    $institutionId = current_institution_id();

    $advances = db_all(
        "SELECT a.id, a.advance_number, a.purpose, a.advance_type, a.status, a.released_amount, a.approved_amount,
                a.requested_amount, a.liquidation_deadline, a.released_at,
                COALESCE(x.submitted, 0) AS submitted_expenses,
                COALESCE(x.verified, 0) AS verified_expenses,
                COALESCE(x.missing_receipts, 0) AS missing_receipts,
                COALESCE(x.needs_correction, 0) AS needs_correction,
                COALESCE(r.returned, 0) AS returned,
                COALESCE(r.pending_returns, 0) AS pending_returns
         FROM cash_advances a
         LEFT JOIN (SELECT cash_advance_id,
                           SUM(CASE WHEN verification_status <> 'rejected' THEN amount ELSE 0 END) AS submitted,
                           SUM(CASE WHEN verification_status = 'verified' THEN amount ELSE 0 END) AS verified,
                           SUM(receipt_attachment_id IS NULL AND verification_status <> 'rejected') AS missing_receipts,
                           SUM(verification_status = 'needs_correction') AS needs_correction
                    FROM cash_advance_expenses WHERE institution_id = ? GROUP BY cash_advance_id) x
                ON x.cash_advance_id = a.id
         LEFT JOIN (SELECT cash_advance_id,
                           SUM(CASE WHEN status = 'confirmed' THEN returned_amount ELSE 0 END) AS returned,
                           SUM(status = 'pending_verification') AS pending_returns
                    FROM cash_advance_returns WHERE institution_id = ? GROUP BY cash_advance_id) r
                ON r.cash_advance_id = a.id
         WHERE a.institution_id = ? AND a.recipient_membership_id = ? AND a.status NOT IN ('cancelled')
         ORDER BY a.status = 'liquidated', a.liquidation_deadline IS NULL, a.liquidation_deadline, a.id DESC
         LIMIT 10",
        [$institutionId, $institutionId, $institutionId, current_membership_id()]
    );

    $outstanding = 0.0;
    foreach ($advances as &$advance) {
        $advance['outstanding'] = max(0.0, (float) $advance['released_amount'] - (float) $advance['verified_expenses'] - (float) $advance['returned']);
        if ($advance['status'] !== 'liquidated') {
            $outstanding += $advance['outstanding'];
        }
    }
    unset($advance);

    return ['advances' => $advances, 'outstanding' => $outstanding];
}
