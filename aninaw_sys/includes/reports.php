<?php
/**
 * Report registry and renderer.
 *
 * Tabular reports are declared once in report_definitions() and rendered by
 * render_report(): filters, institution scoping, pagination, totals, CSV export and
 * a print layout come from one place. Reports that need more than a table (fund
 * balances, event financials, the institution summary) and reports that already
 * exist as module pages (cash flow, journal, ledger) are listed in report_catalog().
 *
 * Every query is scoped to the current institution. Status filters default to the
 * posted/final state, and the active status is printed in the report header so
 * unposted rows are never mistaken for posted totals.
 */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/events.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_once APP_ROOT . '/includes/store_reports.php';

/** Adds a condition and its values when $active is true. */
function report_where(array &$where, array &$params, bool $active, string $sql, mixed ...$values): void
{
    if ($active) {
        $where[] = $sql;
        array_push($params, ...$values);
    }
}

function report_like(string $q): string
{
    return '%' . addcslashes($q, '%_\\') . '%';
}

/** SQL fragment: participant is (actively) in the filtered group / department. */
function report_participant_group_sql(string $participantColumn, array $f, array &$where, array &$params): void
{
    report_where($where, $params, (bool) $f['group'], "EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = ? AND gm.participant_id = {$participantColumn} AND gm.group_id = ? AND gm.status = 'active')", $f['institution_id'], $f['group']);
    report_where($where, $params, (bool) $f['department'], "EXISTS (SELECT 1 FROM group_memberships gm JOIN `groups` g ON g.id = gm.group_id AND g.institution_id = gm.institution_id WHERE gm.institution_id = ? AND gm.participant_id = {$participantColumn} AND g.department_id = ? AND gm.status = 'active')", $f['institution_id'], $f['department']);
}

/** SQL fragment: event has a participating group in the filtered department. */
function report_event_department_sql(string $eventColumn, array $f, array &$where, array &$params): void
{
    report_where($where, $params, (bool) $f['department'], "EXISTS (SELECT 1 FROM event_group_assignments ga JOIN `groups` g ON g.id = ga.group_id AND g.institution_id = ga.institution_id WHERE ga.institution_id = ? AND ga.event_id = {$eventColumn} AND g.department_id = ?)", $f['institution_id'], $f['department']);
}

const REPORT_PERSON = "CONCAT(%s.first_name, ' ', %s.last_name)";

function report_person(string $alias): string
{
    return sprintf(REPORT_PERSON, $alias, $alias);
}

/**
 * Tabular report definitions. Each 'build' returns [sql, params] for a SELECT
 * (no ORDER BY / LIMIT); 'order' refers to the selected column aliases.
 */
function report_definitions(): array
{
    return [
        'collection_summary' => [
            'title' => 'Collection Summary', 'icon' => 'bi-cash-coin', 'permissions' => ['reports.view'],
            'description' => 'Expected, collected (posted payments), waived and outstanding amounts per event and collection rule.',
            'filters' => ['event', 'group', 'department'],
            'build' => static function (array $f): array {
                $where = ['o.institution_id = ?', "o.status <> 'cancelled'"];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['event'], 'o.event_id = ?', $f['event']);
                report_participant_group_sql('o.participant_id', $f, $where, $params);
                return ["SELECT e.id AS event_id, e.event_name, r.rule_name, COUNT(o.id) AS obligations, SUM(o.status = 'paid') AS fully_paid,
                               SUM(o.amount_due) AS expected, SUM(o.waived_amount) AS waived, SUM(COALESCE(pd.paid, 0)) AS collected,
                               SUM(GREATEST(o.amount_due - o.waived_amount - COALESCE(pd.paid, 0), 0)) AS outstanding
                        FROM event_obligations o
                        JOIN events e ON e.id = o.event_id AND e.institution_id = o.institution_id
                        JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
                        LEFT JOIN (SELECT pa.obligation_id, SUM(pa.allocated_amount) AS paid FROM payment_allocations pa
                                   JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
                                   WHERE pa.institution_id = ? GROUP BY pa.obligation_id) pd ON pd.obligation_id = o.id
                        WHERE " . implode(' AND ', $where) . ' GROUP BY e.id, e.event_name, r.id, r.rule_name', array_merge([$f['institution_id']], $params)];
            },
            'order' => 'event_name, rule_name',
            'columns' => ['event_name' => ['Event'], 'rule_name' => ['Collection rule'], 'obligations' => ['People', 'int', true], 'fully_paid' => ['Fully paid', 'int', true],
                'expected' => ['Expected', 'money', true], 'collected' => ['Collected', 'money', true], 'waived' => ['Waived', 'money', true], 'outstanding' => ['Outstanding', 'money', true]],
            'link' => ['event_name', 'events/view.php?id=', 'event_id'],
            'note' => 'Collected counts posted payments only; reversed payments are excluded.',
        ],
        'collection_status' => [
            'title' => 'Individual Collection Status', 'icon' => 'bi-person-check', 'permissions' => ['reports.view'],
            'description' => 'Each participant\'s obligations: amount due, waived, paid and balance.',
            'filters' => ['event', 'group', 'department', 'status', 'q'],
            'statuses' => ['unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'waived' => 'Waived', 'cancelled' => 'Cancelled'],
            'build' => static function (array $f): array {
                $where = ['o.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['event'], 'o.event_id = ?', $f['event']);
                report_where($where, $params, (bool) $f['status'], 'o.status = ?', $f['status']);
                report_where($where, $params, $f['q'] !== '', '(pt.participant_number LIKE ? OR pt.first_name LIKE ? OR pt.last_name LIKE ?)', report_like($f['q']), report_like($f['q']), report_like($f['q']));
                report_participant_group_sql('o.participant_id', $f, $where, $params);
                return ["SELECT pt.id AS participant_id, pt.participant_number, " . report_person('pt') . " AS participant,
                               (SELECT GROUP_CONCAT(g.group_name SEPARATOR ', ') FROM group_memberships gm JOIN `groups` g ON g.id = gm.group_id AND g.institution_id = gm.institution_id
                                 WHERE gm.institution_id = o.institution_id AND gm.participant_id = pt.id AND gm.status = 'active') AS group_names,
                               e.event_name, r.rule_name, o.amount_due, o.waived_amount,
                               (SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
                                 WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id) AS paid,
                               o.due_date, o.status
                        FROM event_obligations o
                        JOIN participants pt ON pt.id = o.participant_id AND pt.institution_id = o.institution_id
                        JOIN events e ON e.id = o.event_id AND e.institution_id = o.institution_id
                        JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'participant, event_name, rule_name',
            'columns' => ['participant_number' => ['No.'], 'participant' => ['Person'], 'group_names' => ['Group'], 'event_name' => ['Event'], 'rule_name' => ['Rule'],
                'amount_due' => ['Due', 'money', true], 'waived_amount' => ['Waived', 'money', true], 'paid' => ['Paid', 'money', true], 'balance' => ['Balance', 'money', true, static fn($r) => cents_to_decimal(max(0, to_cents($r['amount_due']) - to_cents($r['waived_amount']) - to_cents($r['paid'])))],
                'due_date' => ['Due date', 'date'], 'status' => ['Status', 'status']],
            'link' => ['participant', 'participants/view.php?id=', 'participant_id'],
        ],
        'payment_history' => [
            'title' => 'Payment History', 'icon' => 'bi-clock-history', 'permissions' => ['reports.view'],
            'description' => 'Payments received with receipt, payer, method and status.',
            'filters' => ['from', 'to', 'event', 'group', 'department', 'account', 'status', 'q'],
            'statuses' => ['posted' => 'Posted', 'reversed' => 'Reversed', 'pending' => 'Pending', 'refunded' => 'Refunded'], 'default_status' => 'posted',
            'build' => static function (array $f): array {
                $where = ['p.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['status'], 'p.status = ?', $f['status']);
                report_where($where, $params, (bool) $f['from'], 'p.payment_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'p.payment_date <= ?', $f['to'] . ' 23:59:59');
                report_where($where, $params, (bool) $f['account'], 'p.financial_account_id = ?', $f['account']);
                report_where($where, $params, (bool) $f['event'], 'EXISTS (SELECT 1 FROM payment_allocations pa JOIN event_obligations o ON o.id = pa.obligation_id AND o.institution_id = pa.institution_id WHERE pa.institution_id = p.institution_id AND pa.payment_id = p.id AND o.event_id = ?)', $f['event']);
                report_where($where, $params, $f['q'] !== '', '(p.payment_number LIKE ? OR rc.receipt_number LIKE ? OR pt.last_name LIKE ? OR pt.first_name LIKE ? OR pt.participant_number LIKE ?)', ...array_fill(0, 5, report_like($f['q'])));
                report_participant_group_sql('p.payer_participant_id', $f, $where, $params);
                return ["SELECT p.id, p.payment_date, p.payment_number, rc.receipt_number, " . report_person('pt') . " AS payer, p.payment_method, fa.account_name, " . report_person('ru') . " AS received_by, p.amount, p.status
                        FROM payments p
                        JOIN participants pt ON pt.id = p.payer_participant_id AND pt.institution_id = p.institution_id
                        LEFT JOIN receipts rc ON rc.payment_id = p.id AND rc.institution_id = p.institution_id
                        LEFT JOIN financial_accounts fa ON fa.id = p.financial_account_id AND fa.institution_id = p.institution_id
                        JOIN institution_memberships rm ON rm.id = p.received_by_membership_id JOIN users ru ON ru.id = rm.user_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'payment_date DESC, id DESC', 'date_label' => 'Payment date',
            'columns' => ['payment_date' => ['Date', 'datetime'], 'payment_number' => ['Payment'], 'receipt_number' => ['Receipt'], 'payer' => ['Payer'], 'payment_method' => ['Method', 'label'],
                'account_name' => ['Received into'], 'received_by' => ['Received by'], 'amount' => ['Amount', 'money', true], 'status' => ['Status', 'status']],
            'link' => ['payment_number', 'collections/payment.php?id=', 'id'],
        ],
        'receipt_register' => [
            'title' => 'Receipt Register', 'icon' => 'bi-receipt-cutoff', 'permissions' => ['reports.view'],
            'description' => 'Every official receipt issued or voided, with reprint counts.',
            'filters' => ['from', 'to', 'status', 'q'],
            'statuses' => ['issued' => 'Issued', 'voided' => 'Voided'],
            'build' => static function (array $f): array {
                $where = ['rc.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['status'], 'rc.receipt_status = ?', $f['status']);
                report_where($where, $params, (bool) $f['from'], 'rc.issued_at >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'rc.issued_at <= ?', $f['to'] . ' 23:59:59');
                report_where($where, $params, $f['q'] !== '', '(rc.receipt_number LIKE ? OR pt.last_name LIKE ? OR pt.first_name LIKE ?)', ...array_fill(0, 3, report_like($f['q'])));
                return ["SELECT p.id, rc.receipt_number, rc.issued_at, " . report_person('pt') . " AS payer, p.payment_number, p.amount, rc.receipt_status, p.status AS payment_status, " . report_person('iu') . " AS issued_by,
                               (SELECT COUNT(*) FROM audit_logs a WHERE a.institution_id = rc.institution_id AND a.entity_type = 'receipt' AND a.entity_id = rc.id AND a.action = 'receipt.reprinted') AS reprints
                        FROM receipts rc
                        JOIN payments p ON p.id = rc.payment_id AND p.institution_id = rc.institution_id
                        JOIN participants pt ON pt.id = p.payer_participant_id AND pt.institution_id = p.institution_id
                        JOIN institution_memberships im ON im.id = rc.issued_by_membership_id JOIN users iu ON iu.id = im.user_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'issued_at DESC, receipt_number DESC', 'date_label' => 'Issue date',
            'columns' => ['receipt_number' => ['Receipt'], 'issued_at' => ['Issued', 'datetime'], 'payer' => ['Payer'], 'payment_number' => ['Payment'], 'amount' => ['Amount', 'money', true],
                'receipt_status' => ['Receipt', 'status'], 'payment_status' => ['Payment', 'status'], 'issued_by' => ['Issued by'], 'reprints' => ['Reprints', 'int', true]],
            'link' => ['receipt_number', 'collections/payment.php?id=', 'id'],
            'note' => 'Amounts include voided receipts when no status filter is applied; filter by "Issued" for valid receipts only.',
        ],
        'turnover_report' => [
            'title' => 'Cash Turnover Report', 'icon' => 'bi-arrow-left-right', 'permissions' => ['reports.view'],
            'description' => 'Collections handed over by collectors: declared vs counted amounts and status.',
            'filters' => ['from', 'to', 'fund', 'event', 'group', 'department', 'status'],
            'statuses' => ['submitted' => 'Submitted', 'received_pending_verification' => 'Counted, awaiting acknowledgment', 'verified' => 'Verified', 'discrepancy' => 'Discrepancy', 'cancelled' => 'Cancelled', 'draft' => 'Draft'],
            'build' => static function (array $f): array {
                $where = ['t.institution_id = ?'];
                $params = [$f['institution_id']];
                foreach (['status' => 't.status', 'fund' => 't.fund_id', 'event' => 't.event_id', 'group' => 't.group_id'] as $key => $column) {
                    report_where($where, $params, (bool) $f[$key], "{$column} = ?", $f[$key]);
                }
                report_where($where, $params, (bool) $f['department'], 'g.department_id = ?', $f['department']);
                report_where($where, $params, (bool) $f['from'], 't.submitted_at >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 't.submitted_at <= ?', $f['to'] . ' 23:59:59');
                return ["SELECT t.id, t.turnover_number, t.submitted_at, e.event_name, g.group_name, " . report_person('su') . " AS submitted_by, " . report_person('ru') . " AS received_by,
                               t.declared_amount, t.received_amount, (COALESCE(t.received_amount, 0) - t.declared_amount) AS difference, t.status
                        FROM cash_turnovers t
                        JOIN events e ON e.id = t.event_id AND e.institution_id = t.institution_id
                        JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
                        JOIN institution_memberships sm ON sm.id = t.submitted_by_membership_id JOIN users su ON su.id = sm.user_id
                        JOIN institution_memberships rm ON rm.id = t.received_by_membership_id JOIN users ru ON ru.id = rm.user_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'submitted_at DESC, id DESC', 'date_label' => 'Submitted',
            'columns' => ['turnover_number' => ['Turnover'], 'submitted_at' => ['Submitted', 'datetime'], 'event_name' => ['Event'], 'group_name' => ['Group'], 'submitted_by' => ['Collector'], 'received_by' => ['Received by'],
                'declared_amount' => ['Declared', 'money', true], 'received_amount' => ['Counted', 'money', true], 'difference' => ['Difference', 'money', true], 'status' => ['Status', 'status']],
            'link' => ['turnover_number', 'turnovers/view.php?id=', 'id'],
        ],
        'cash_custody' => [
            'title' => 'Cash Custody Report', 'icon' => 'bi-safe', 'permissions' => ['reports.view'],
            'description' => 'Who is holding how much of each fund, by financial account, from posted entries.',
            'filters' => ['to', 'fund', 'account'],
            'build' => static function (array $f): array {
                $where = ['l.institution_id = ?', 'l.financial_account_id IS NOT NULL'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['fund'], 'l.fund_id = ?', $f['fund']);
                report_where($where, $params, (bool) $f['account'], 'l.financial_account_id = ?', $f['account']);
                report_where($where, $params, (bool) $f['to'], 'j.journal_date <= ?', $f['to']);
                return ["SELECT fa.id, fa.account_name, fa.account_type, " . report_person('cu') . " AS custodian, f.fund_name, SUM(l.debit_amount - l.credit_amount) AS balance,
                               (SELECT COALESCE(SUM(CASE WHEN c.direction = 'inflow' THEN c.amount ELSE -c.amount END), 0) FROM cash_flow_transactions c
                                 WHERE c.institution_id = l.institution_id AND c.financial_account_id = fa.id AND c.fund_id = f.id AND c.status = 'pending') AS pending
                        FROM journal_lines l
                        JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
                        JOIN financial_accounts fa ON fa.id = l.financial_account_id AND fa.institution_id = l.institution_id
                        JOIN fund_accounts f ON f.id = l.fund_id AND f.institution_id = l.institution_id
                        LEFT JOIN institution_memberships cm ON cm.id = fa.custodian_membership_id LEFT JOIN users cu ON cu.id = cm.user_id
                        WHERE " . implode(' AND ', $where) . ' GROUP BY fa.id, fa.account_name, fa.account_type, cu.id, cu.first_name, cu.last_name, f.id, f.fund_name, l.institution_id HAVING balance <> 0 OR pending <> 0', $params];
            },
            'order' => 'custodian, account_name, fund_name', 'date_label' => 'As of',
            'columns' => ['account_name' => ['Account'], 'account_type' => ['Type', 'label'], 'custodian' => ['Custodian'], 'fund_name' => ['Fund'], 'balance' => ['Held (posted)', 'money', true], 'pending' => ['Pending movements', 'money', true]],
            'link' => ['account_name', 'funds/account.php?id=', 'id'],
            'note' => 'Held = posted ledger balance. Pending movements (unconfirmed deposits, turnovers being counted) are shown separately and not included.',
        ],
        'discrepancy_report' => [
            'title' => 'Turnover Discrepancy Report', 'icon' => 'bi-exclamation-diamond', 'permissions' => ['reports.view'],
            'description' => 'Shortages and overages found when turnovers were counted, and how they were resolved.',
            'filters' => ['from', 'to', 'event', 'group', 'status'],
            'statuses' => ['open' => 'Open', 'under_review' => 'Under review', 'resolved' => 'Resolved', 'accepted_exception' => 'Written off'],
            'build' => static function (array $f): array {
                $where = ['d.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['status'], 'd.status = ?', $f['status']);
                report_where($where, $params, (bool) $f['event'], 't.event_id = ?', $f['event']);
                report_where($where, $params, (bool) $f['group'], 't.group_id = ?', $f['group']);
                report_where($where, $params, (bool) $f['from'], 'd.reported_at >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'd.reported_at <= ?', $f['to'] . ' 23:59:59');
                return ["SELECT t.id, d.reported_at, t.turnover_number, e.event_name, g.group_name, " . report_person('su') . " AS collector,
                               d.expected_amount, d.actual_amount, d.difference_amount, d.status, d.reason, d.resolution_notes, d.resolved_at
                        FROM turnover_discrepancies d
                        JOIN cash_turnovers t ON t.id = d.turnover_id AND t.institution_id = d.institution_id
                        JOIN events e ON e.id = t.event_id AND e.institution_id = t.institution_id
                        JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
                        JOIN institution_memberships sm ON sm.id = t.submitted_by_membership_id JOIN users su ON su.id = sm.user_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'reported_at DESC', 'date_label' => 'Reported',
            'columns' => ['reported_at' => ['Reported', 'datetime'], 'turnover_number' => ['Turnover'], 'event_name' => ['Event'], 'group_name' => ['Group'], 'collector' => ['Collector'],
                'expected_amount' => ['Expected', 'money', true], 'actual_amount' => ['Counted', 'money', true], 'difference_amount' => ['Difference', 'money', true], 'status' => ['Status', 'status'],
                'reason' => ['Reason'], 'resolution_notes' => ['Resolution'], 'resolved_at' => ['Resolved', 'datetime']],
            'link' => ['turnover_number', 'turnovers/view.php?id=', 'id'],
        ],
        'deposit_report' => [
            'title' => 'Deposit Report', 'icon' => 'bi-bank', 'permissions' => ['reports.view'],
            'description' => 'Cash moved from custody into bank or e-wallet accounts, with confirmation status.',
            'filters' => ['from', 'to', 'fund', 'account', 'status'],
            'statuses' => ['confirmed' => 'Confirmed', 'recorded' => 'Recorded', 'pending_confirmation' => 'Awaiting confirmation', 'reversed' => 'Reversed / cancelled'], 'default_status' => 'confirmed',
            'build' => static function (array $f): array {
                $where = ['d.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['status'], 'd.status = ?', $f['status']);
                report_where($where, $params, (bool) $f['account'], '(d.source_account_id = ? OR d.destination_account_id = ?)', $f['account'], $f['account']);
                report_where($where, $params, (bool) $f['fund'], "EXISTS (SELECT 1 FROM cash_flow_transactions c WHERE c.institution_id = d.institution_id AND c.source_type = 'deposit' AND c.source_id = d.id AND c.fund_id = ?)", $f['fund']);
                report_where($where, $params, (bool) $f['from'], 'd.deposit_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'd.deposit_date <= ?', $f['to']);
                return ["SELECT d.id, d.deposit_date, d.deposit_number, sa.account_name AS from_account, da.account_name AS to_account,
                               (SELECT GROUP_CONCAT(DISTINCT f.fund_name SEPARATOR ', ') FROM cash_flow_transactions c JOIN fund_accounts f ON f.id = c.fund_id AND f.institution_id = c.institution_id
                                 WHERE c.institution_id = d.institution_id AND c.source_type = 'deposit' AND c.source_id = d.id) AS funds,
                               d.amount, d.reference_number, d.status, " . report_person('ru') . " AS recorded_by, " . report_person('cu') . " AS confirmed_by
                        FROM deposits d
                        JOIN financial_accounts sa ON sa.id = d.source_account_id AND sa.institution_id = d.institution_id
                        JOIN financial_accounts da ON da.id = d.destination_account_id AND da.institution_id = d.institution_id
                        JOIN users ru ON ru.id = d.recorded_by LEFT JOIN users cu ON cu.id = d.confirmed_by
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'deposit_date DESC, id DESC', 'date_label' => 'Deposit date',
            'columns' => ['deposit_date' => ['Date', 'date'], 'deposit_number' => ['Deposit'], 'from_account' => ['From'], 'to_account' => ['To'], 'funds' => ['Fund'], 'amount' => ['Amount', 'money', true],
                'reference_number' => ['Reference'], 'status' => ['Status', 'status'], 'recorded_by' => ['Recorded by'], 'confirmed_by' => ['Confirmed by']],
            'link' => ['deposit_number', 'deposits/view.php?id=', 'id'],
        ],
        'transfer_report' => [
            'title' => 'Fund Transfer Report', 'icon' => 'bi-shuffle', 'permissions' => ['reports.view'],
            'description' => 'Approved and posted transfers between funds, with requester and approver.',
            'filters' => ['from', 'to', 'fund', 'status'],
            'statuses' => ['posted' => 'Posted', 'submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected', 'reversed' => 'Reversed'], 'default_status' => 'posted',
            'build' => static function (array $f): array {
                $where = ['t.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, (bool) $f['status'], 't.status = ?', $f['status']);
                report_where($where, $params, (bool) $f['fund'], '(t.from_fund_id = ? OR t.to_fund_id = ?)', $f['fund'], $f['fund']);
                report_where($where, $params, (bool) $f['from'], 't.transfer_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 't.transfer_date <= ?', $f['to']);
                return ["SELECT t.id, t.transfer_date, t.transfer_number, ff.fund_name AS from_fund, tf.fund_name AS to_fund, t.amount, t.purpose, t.status,
                               " . report_person('qu') . " AS requested_by, " . report_person('au') . " AS approved_by, t.posted_at
                        FROM fund_transfers t
                        JOIN fund_accounts ff ON ff.id = t.from_fund_id AND ff.institution_id = t.institution_id
                        JOIN fund_accounts tf ON tf.id = t.to_fund_id AND tf.institution_id = t.institution_id
                        JOIN users qu ON qu.id = t.requested_by LEFT JOIN users au ON au.id = t.approved_by
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'transfer_date DESC, id DESC', 'date_label' => 'Transfer date',
            'columns' => ['transfer_date' => ['Date', 'date'], 'transfer_number' => ['Transfer'], 'from_fund' => ['From fund'], 'to_fund' => ['To fund'], 'amount' => ['Amount', 'money', true],
                'purpose' => ['Purpose'], 'status' => ['Status', 'status'], 'requested_by' => ['Requested by'], 'approved_by' => ['Approved by'], 'posted_at' => ['Posted', 'datetime']],
            'link' => ['transfer_number', 'transfers/view.php?id=', 'id'],
        ],
        'expense_report' => [
            'title' => 'Expense Report', 'icon' => 'bi-receipt', 'permissions' => ['reports.view'],
            'description' => 'Direct expenses by fund, event and category with approval and payment status.',
            'filters' => ['from', 'to', 'fund', 'event', 'department', 'account', 'status', 'q'],
            'statuses' => ['paid' => 'Paid (posted)', 'approved' => 'Approved, not paid', 'submitted' => 'Submitted', 'draft' => 'Draft', 'rejected' => 'Rejected', 'reversed' => 'Reversed'], 'default_status' => 'paid',
            'build' => static function (array $f): array {
                $where = ['x.institution_id = ?'];
                $params = [$f['institution_id']];
                foreach (['status' => 'x.status', 'fund' => 'x.fund_id', 'event' => 'x.event_id', 'account' => 'x.financial_account_id'] as $key => $column) {
                    report_where($where, $params, (bool) $f[$key], "{$column} = ?", $f[$key]);
                }
                report_where($where, $params, (bool) $f['from'], 'x.expense_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'x.expense_date <= ?', $f['to']);
                report_where($where, $params, $f['q'] !== '', '(x.expense_number LIKE ? OR x.payee LIKE ? OR x.category LIKE ?)', ...array_fill(0, 3, report_like($f['q'])));
                report_event_department_sql('x.event_id', $f, $where, $params);
                return ["SELECT x.id, x.expense_date, x.expense_number, x.payee, x.category, f.fund_name, e.event_name, x.amount, x.payment_method, x.status, " . report_person('cu') . " AS recorded_by, x.paid_at
                        FROM expenses x
                        JOIN fund_accounts f ON f.id = x.fund_id AND f.institution_id = x.institution_id
                        LEFT JOIN events e ON e.id = x.event_id AND e.institution_id = x.institution_id
                        JOIN users cu ON cu.id = x.created_by
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'expense_date DESC, id DESC', 'date_label' => 'Expense date',
            'columns' => ['expense_date' => ['Date', 'date'], 'expense_number' => ['Expense'], 'payee' => ['Payee'], 'category' => ['Category'], 'fund_name' => ['Fund'], 'event_name' => ['Event'],
                'amount' => ['Amount', 'money', true], 'payment_method' => ['Method', 'label'], 'status' => ['Status', 'status'], 'recorded_by' => ['Recorded by'], 'paid_at' => ['Paid', 'datetime']],
            'link' => ['expense_number', 'expenses/view.php?id=', 'id'],
        ],
        'advance_report' => [
            'title' => 'Cash Advance Report', 'icon' => 'bi-wallet2', 'permissions' => ['reports.view'],
            'description' => 'Advance requests, approvals and releases by recipient, fund and event.',
            'filters' => ['from', 'to', 'fund', 'event', 'status', 'q'],
            'statuses' => ['pending_approval' => 'Awaiting approval', 'approved' => 'Approved', 'released' => 'Released', 'partially_liquidated' => 'Partially liquidated', 'submitted_for_review' => 'Under review',
                'returned_for_correction' => 'Returned for correction', 'overdue' => 'Overdue', 'liquidated' => 'Liquidated', 'cancelled' => 'Cancelled / rejected'],
            'build' => static function (array $f): array {
                $where = ['a.institution_id = ?'];
                $params = [$f['institution_id']];
                foreach (['status' => 'a.status', 'fund' => 'a.fund_id', 'event' => 'a.event_id'] as $key => $column) {
                    report_where($where, $params, (bool) $f[$key], "{$column} = ?", $f[$key]);
                }
                report_where($where, $params, (bool) $f['from'], 'a.request_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'a.request_date <= ?', $f['to']);
                report_where($where, $params, $f['q'] !== '', '(a.advance_number LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ? OR a.purpose LIKE ?)', ...array_fill(0, 4, report_like($f['q'])));
                return ["SELECT a.id, a.request_date, a.advance_number, " . report_person('u') . " AS recipient, f.fund_name, e.event_name, a.purpose, a.requested_amount, a.approved_amount, a.released_amount, a.released_at, a.liquidation_deadline, a.status
                        FROM cash_advances a
                        JOIN fund_accounts f ON f.id = a.fund_id AND f.institution_id = a.institution_id
                        LEFT JOIN events e ON e.id = a.event_id AND e.institution_id = a.institution_id
                        JOIN institution_memberships m ON m.id = a.recipient_membership_id JOIN users u ON u.id = m.user_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'request_date DESC, id DESC', 'date_label' => 'Request date',
            'columns' => ['request_date' => ['Requested', 'date'], 'advance_number' => ['Advance'], 'recipient' => ['Recipient'], 'fund_name' => ['Fund'], 'event_name' => ['Event'], 'purpose' => ['Purpose'],
                'requested_amount' => ['Requested', 'money', true], 'approved_amount' => ['Approved', 'money', true], 'released_amount' => ['Released', 'money', true], 'liquidation_deadline' => ['Deadline', 'date'], 'status' => ['Status', 'status']],
            'link' => ['advance_number', 'advances/view.php?id=', 'id'],
        ],
        'liquidation_report' => [
            'title' => 'Cash Advance Liquidation Report', 'icon' => 'bi-journal-check', 'permissions' => ['reports.view'],
            'description' => 'Released advances: verified receipts, confirmed returns, items pending review and the unliquidated balance.',
            'filters' => ['from', 'to', 'fund', 'event', 'status'],
            'statuses' => ['open' => 'Not yet liquidated', 'overdue' => 'Overdue', 'submitted_for_review' => 'Under review', 'liquidated' => 'Liquidated'],
            'build' => static function (array $f): array {
                $where = ['a.institution_id = ?', "a.status IN ('released', 'partially_liquidated', 'submitted_for_review', 'returned_for_correction', 'overdue', 'liquidated')"];
                $params = [$f['institution_id']];
                report_where($where, $params, $f['status'] === 'open', "a.status <> 'liquidated'");
                report_where($where, $params, in_array($f['status'], ['overdue', 'submitted_for_review', 'liquidated'], true), 'a.status = ?', $f['status']);
                report_where($where, $params, (bool) $f['fund'], 'a.fund_id = ?', $f['fund']);
                report_where($where, $params, (bool) $f['event'], 'a.event_id = ?', $f['event']);
                report_where($where, $params, (bool) $f['from'], 'a.released_at >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'a.released_at <= ?', $f['to'] . ' 23:59:59');
                $sum = static fn(string $table, string $column, string $condition) => "(SELECT COALESCE(SUM(x.{$column}), 0) FROM {$table} x WHERE x.institution_id = a.institution_id AND x.cash_advance_id = a.id AND {$condition})";
                return ["SELECT a.id, a.advance_number, " . report_person('u') . " AS recipient, f.fund_name, a.released_amount,
                               {$sum('cash_advance_expenses', 'amount', "x.verification_status = 'verified'")} AS verified,
                               {$sum('cash_advance_returns', 'returned_amount', "x.status = 'confirmed'")} AS returned,
                               ({$sum('cash_advance_expenses', 'amount', "x.verification_status IN ('pending', 'needs_correction')")} + {$sum('cash_advance_returns', 'returned_amount', "x.status = 'pending_verification'")}) AS pending_review,
                               {$sum('cash_advance_expenses', 'amount', "x.verification_status = 'rejected'")} AS rejected,
                               a.liquidation_deadline, GREATEST(DATEDIFF(CURDATE(), a.liquidation_deadline), 0) AS days_late, a.status
                        FROM cash_advances a
                        JOIN fund_accounts f ON f.id = a.fund_id AND f.institution_id = a.institution_id
                        JOIN institution_memberships m ON m.id = a.recipient_membership_id JOIN users u ON u.id = m.user_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => "status = 'liquidated', liquidation_deadline, id", 'date_label' => 'Released',
            'columns' => ['advance_number' => ['Advance'], 'recipient' => ['Recipient'], 'fund_name' => ['Fund'], 'released_amount' => ['Released', 'money', true], 'verified' => ['Verified receipts', 'money', true],
                'returned' => ['Returned', 'money', true], 'pending_review' => ['Pending review', 'money', true], 'rejected' => ['Rejected receipts', 'money', true],
                'unliquidated' => ['Unliquidated', 'money', true, static fn($r) => cents_to_decimal(max(0, to_cents($r['released_amount']) - to_cents($r['verified']) - to_cents($r['returned'])))],
                'liquidation_deadline' => ['Deadline', 'date'], 'days_late' => ['Days late', 'late'], 'status' => ['Status', 'status']],
            'link' => ['advance_number', 'advances/view.php?id=', 'id'],
            'note' => 'Unliquidated = released − verified receipts − confirmed returns. Items pending review do not reduce it.',
        ],
        'budget_vs_actual' => [
            'title' => 'Budget versus Actual', 'icon' => 'bi-clipboard-data', 'permissions' => ['reports.view'],
            'description' => 'Approved budget lines against posted (paid or verified) and pending spending.',
            'filters' => ['from', 'to', 'fund', 'event', 'department', 'status'],
            'statuses' => ['approved' => 'Approved (in force)', 'closed' => 'Closed', 'pending' => 'Awaiting approval', 'draft' => 'Draft'], 'default_status' => 'approved',
            'build' => static function (array $f): array {
                $where = ['b.institution_id = ?'];
                $params = [$f['institution_id']];
                report_where($where, $params, $f['status'] === 'approved', "b.status IN ('approved', 'active')");
                report_where($where, $params, in_array($f['status'], ['closed', 'pending', 'draft'], true), 'b.status = ?', $f['status']);
                report_where($where, $params, !$f['status'], "b.status <> 'cancelled'");
                report_where($where, $params, (bool) $f['fund'], 'b.fund_id = ?', $f['fund']);
                report_where($where, $params, (bool) $f['event'], 'b.event_id = ?', $f['event']);
                report_where($where, $params, (bool) $f['from'], 'b.end_date >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'b.start_date <= ?', $f['to']);
                report_event_department_sql('b.event_id', $f, $where, $params);
                $scope = "x.institution_id = b.institution_id AND x.fund_id = b.fund_id AND x.category = bl.category AND x.expense_date BETWEEN b.start_date AND b.end_date AND (b.event_id IS NULL OR x.event_id = b.event_id)";
                $advanceScope = "ae.institution_id = b.institution_id AND a.fund_id = b.fund_id AND ae.category = bl.category AND ae.expense_date BETWEEN b.start_date AND b.end_date AND (b.event_id IS NULL OR a.event_id = b.event_id) AND a.status <> 'cancelled'";
                $advanceFrom = 'cash_advance_expenses ae JOIN cash_advances a ON a.id = ae.cash_advance_id AND a.institution_id = ae.institution_id';
                return ["SELECT b.id, b.budget_number, b.budget_name, f.fund_name, e.event_name, bl.category, bl.allocated_amount AS approved,
                               ((SELECT COALESCE(SUM(x.amount), 0) FROM expenses x WHERE {$scope} AND x.status = 'paid')
                                + (SELECT COALESCE(SUM(ae.amount), 0) FROM {$advanceFrom} WHERE {$advanceScope} AND ae.verification_status = 'verified')) AS actual,
                               ((SELECT COALESCE(SUM(x.amount), 0) FROM expenses x WHERE {$scope} AND x.status IN ('submitted', 'approved'))
                                + (SELECT COALESCE(SUM(ae.amount), 0) FROM {$advanceFrom} WHERE {$advanceScope} AND ae.verification_status IN ('pending', 'needs_correction'))) AS pending,
                               b.status
                        FROM budget_lines bl
                        JOIN budgets b ON b.id = bl.budget_id AND b.institution_id = bl.institution_id
                        JOIN fund_accounts f ON f.id = b.fund_id AND f.institution_id = b.institution_id
                        LEFT JOIN events e ON e.id = b.event_id AND e.institution_id = b.institution_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'budget_number, category',
            'columns' => ['budget_name' => ['Budget'], 'fund_name' => ['Fund'], 'event_name' => ['Event'], 'category' => ['Category'], 'approved' => ['Approved', 'money', true], 'actual' => ['Actual (posted)', 'money', true],
                'pending' => ['Pending', 'money', true], 'remaining' => ['Remaining', 'money', true, static fn($r) => cents_to_decimal(to_cents($r['approved']) - to_cents($r['actual']))],
                'available' => ['Available', 'money', true, static fn($r) => cents_to_decimal(to_cents($r['approved']) - to_cents($r['actual']) - to_cents($r['pending']))],
                'used' => ['Used', 'usage', false, static fn($r) => to_cents($r['approved']) > 0 ? (string) floor(to_cents($r['actual']) * 100 / to_cents($r['approved'])) : (to_cents($r['actual']) ? '100' : '0')], 'status' => ['Status', 'status']],
            'link' => ['budget_name', 'budgets/view.php?id=', 'id'],
            'note' => 'Actual = paid expenses and verified liquidation receipts in the budget\'s fund, event, period and category. Pending items are not posted. Spending in categories without a budget line appears on each budget\'s page.',
        ],
        'audit_trail' => [
            'title' => 'Audit Trail', 'icon' => 'bi-clipboard-check', 'permissions' => ['audit.view', 'platform.audit.view'], 'context' => 'any',
            'description' => 'Who did what, when, with the reason and before/after values.',
            'filters' => ['from', 'to', 'q'],
            'build' => static function (array $f): array {
                $where = [];
                $params = [];
                if ($f['institution_id']) {
                    $where[] = 'a.institution_id = ?';
                    $params[] = $f['institution_id'];
                } else {
                    $where[] = '1 = 1'; // platform context: platform.audit.view sees every institution
                }
                report_where($where, $params, (bool) $f['from'], 'a.created_at >= ?', $f['from']);
                report_where($where, $params, (bool) $f['to'], 'a.created_at <= ?', $f['to'] . ' 23:59:59');
                report_where($where, $params, $f['q'] !== '', '(a.action LIKE ? OR a.entity_type LIKE ? OR u.username LIKE ? OR u.last_name LIKE ? OR a.reason LIKE ?)', ...array_fill(0, 5, report_like($f['q'])));
                return ["SELECT a.id, a.created_at, COALESCE(" . report_person('u') . ", 'System') AS actor, i.institution_code, a.action, a.entity_type, a.entity_id, a.reason, a.request_id,
                               a.before_data, a.after_data
                        FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id LEFT JOIN institutions i ON i.id = a.institution_id
                        WHERE " . implode(' AND ', $where), $params];
            },
            'order' => 'created_at DESC, id DESC', 'date_label' => 'Logged',
            'columns' => ['created_at' => ['When', 'datetime'], 'actor' => ['User'], 'institution_code' => ['Institution', 'text', false, null, 'platform'], 'action' => ['Action'],
                'record' => ['Record', 'text', false, static fn($r) => $r['entity_type'] . ($r['entity_id'] ? ' #' . $r['entity_id'] : '')], 'reason' => ['Reason'],
                'changes' => ['Changes', 'changes', false, static fn($r) => audit_changes_text($r['before_data'], $r['after_data'])], 'request_id' => ['Request ID']],
            'note' => 'Audit records are read-only in the application. Network details (IP, browser) are kept in the log but not exported.',
        ],
    ] + store_report_definitions();
}

/** Compact "field: old → new" text from audit before/after JSON (client details omitted). */
function audit_changes_text(?string $beforeJson, ?string $afterJson): string
{
    $before = json_decode((string) $beforeJson, true) ?: [];
    $after = json_decode((string) $afterJson, true) ?: [];
    unset($after['ip'], $after['user_agent'], $before['ip'], $before['user_agent']);
    $parts = [];
    foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
        $old = $before[$key] ?? null;
        $new = $after[$key] ?? null;
        $format = static fn($v) => is_scalar($v) || $v === null ? (string) ($v ?? '—') : mb_strimwidth((string) json_encode($v, JSON_UNESCAPED_UNICODE), 0, 60, '…');
        $parts[] = $key . ': ' . (array_key_exists($key, $before) ? $format($old) . ' → ' : '') . $format($new);
    }
    return mb_strimwidth(implode('; ', $parts), 0, 300, '…');
}

/** Hub entries: tabular reports plus dedicated report pages. [key => [title, description, icon, path, permissions]] */
function report_catalog(): array
{
    $tabular = [];
    foreach (report_definitions() as $key => $definition) {
        $tabular[$key] = [$definition['title'], $definition['description'], $definition['icon'], 'reports/view.php?r=' . $key, $definition['permissions']];
    }
    $catalog = [
        'Funds and position' => [
            'fund_balances'      => ['Fund Balance Report', 'Every fund\'s collections, spending, cash held and advances outstanding.', 'bi-wallet', 'reports/fund_statement.php', ['reports.view']],
            'financial_summary'  => ['Institution Financial Summary', 'Income, expenses, cash position and items awaiting action for a period.', 'bi-building', 'reports/financial_summary.php', ['reports.view']],
            'cash_custody'       => $tabular['cash_custody'],
            'cash_flow'          => ['Cash Flow Report', 'Every inflow and outflow by fund, account, custodian and type.', 'bi-activity', 'cash_flow/index.php', ['cashflow.view']],
        ],
        'Collections' => [
            'collection_summary' => $tabular['collection_summary'],
            'collection_status'  => $tabular['collection_status'],
            'event_financial'    => ['Event Financial Report', 'Collections, expenses, advances and budget for one event.', 'bi-calendar-event', 'reports/event_financial.php', ['reports.view']],
            'payment_history'    => $tabular['payment_history'],
            'receipt_register'   => $tabular['receipt_register'],
        ],
        'Custody and movements' => [
            'turnover_report'    => $tabular['turnover_report'],
            'discrepancy_report' => $tabular['discrepancy_report'],
            'deposit_report'     => $tabular['deposit_report'],
            'transfer_report'    => $tabular['transfer_report'],
        ],
        'Spending' => [
            'expense_report'     => $tabular['expense_report'],
            'advance_report'     => $tabular['advance_report'],
            'liquidation_report' => $tabular['liquidation_report'],
            'budget_vs_actual'   => $tabular['budget_vs_actual'],
        ],
        'Store' => [
            'store_inventory'         => $tabular['store_inventory'],
            'store_movements'         => $tabular['store_movements'],
            'store_shirt_sizes'       => $tabular['store_shirt_sizes'],
            'store_deliveries'        => $tabular['store_deliveries'],
            'store_customer_orders'   => $tabular['store_customer_orders'],
            'store_printing'          => $tabular['store_printing'],
            'store_sales_collections' => $tabular['store_sales_collections'],
            'store_unpaid'            => $tabular['store_unpaid'],
            'store_unclaimed'         => $tabular['store_unclaimed'],
            'store_profit'            => $tabular['store_profit'],
        ],
        'Accounting and audit' => [
            'journal'            => ['Journal Entry Report', 'All journal entries with source documents, reversals and adjustments.', 'bi-journal-text', 'ledger/index.php', ['ledger.view', 'ledger.post']],
            'ledger'             => ['Ledger Report', 'Trial balance and account ledgers with running balances.', 'bi-list-columns-reverse', 'ledger/trial_balance.php', ['ledger.view', 'ledger.post']],
            'audit_trail'        => [$tabular['audit_trail'][0], $tabular['audit_trail'][1], $tabular['audit_trail'][2], 'reports/audit_trail.php', ['audit.view']],
        ],
    ];
    return $catalog;
}

/** Reads the filter values a report supports from the query string. */
function report_filter_values(array $definition): array
{
    $statuses = $definition['statuses'] ?? [];
    $status = get_str('status', 40);
    if (!isset($_GET['status'])) {
        $status = $definition['default_status'] ?? '';
    }
    return [
        'institution_id' => current_institution_id(),
        'from'       => input_date($_GET, 'from'),
        'to'         => input_date($_GET, 'to'),
        'fund'       => get_int('fund'),
        'event'      => get_int('event'),
        'group'      => get_int('group'),
        'department' => get_int('department'),
        'account'    => get_int('account'),
        'product'    => get_int('product'),
        'category'   => get_int('category'),
        'variant'    => get_int('variant'),
        'status'     => isset($statuses[$status]) ? $status : '',
        'q'          => get_str('q', 100),
    ];
}

function report_filter_fields(array $definition, array $f): string
{
    $institutionId = (int) current_institution_id();
    $html = '';
    $field = static fn(string $id, string $label, string $control) => '<div class="filter-field"><label for="' . $id . '">' . e($label) . '</label>' . $control . '</div>';
    foreach ($definition['filters'] as $filter) {
        $html .= match ($filter) {
            'q'          => $field('q', 'Search', '<input class="form-control" type="search" id="q" name="q" value="' . e($f['q']) . '">'),
            'from'       => $field('from', ($definition['date_label'] ?? 'Date') . ' from', '<input class="form-control" type="date" id="from" name="from" value="' . e($f['from']) . '">'),
            'to'         => $field('to', in_array('from', $definition['filters'], true) ? 'To' : ($definition['date_label'] ?? 'As of'), '<input class="form-control" type="date" id="to" name="to" value="' . e($f['to']) . '">'),
            'fund'       => $field('fund', 'Fund', '<select class="form-select" id="fund" name="fund">' . options_html(options_from_rows(db_all('SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? ORDER BY fund_name', [$institutionId]), 'id', 'fund_name'), $f['fund'], 'All') . '</select>'),
            'event'      => $field('event', 'Event', '<select class="form-select" id="event" name="event">' . options_html(event_options(EVENT_STATUSES), $f['event'], 'All') . '</select>'),
            'group'      => $field('group', 'Group', '<select class="form-select" id="group" name="group">' . options_html(group_options(false), $f['group'], 'All') . '</select>'),
            'department' => $field('department', 'Department', '<select class="form-select" id="department" name="department">' . options_html(department_options(false), $f['department'], 'All') . '</select>'),
            'account'    => $field('account', 'Account', '<select class="form-select" id="account" name="account">' . options_html(options_from_rows(db_all('SELECT id, account_name FROM financial_accounts WHERE institution_id = ? ORDER BY account_name', [$institutionId]), 'id', 'account_name'), $f['account'], 'All') . '</select>'),
            'product'    => $field('product', 'Product', '<select class="form-select" id="product" name="product" data-searchable>' . options_html(options_from_rows(db_all('SELECT id, product_name FROM products WHERE institution_id = ? ORDER BY product_name', [$institutionId]), 'id', 'product_name'), $f['product'], 'All') . '</select>'),
            'category'   => $field('category', 'Category', '<select class="form-select" id="category" name="category">' . options_html(options_from_rows(db_all('SELECT id, category_name FROM product_categories WHERE institution_id = ? ORDER BY category_name', [$institutionId]), 'id', 'category_name'), $f['category'], 'All') . '</select>'),
            'variant'    => $f['variant'] ? '<input type="hidden" name="variant" value="' . (int) $f['variant'] . '">' : '',
            'status'     => $field('status', 'Status', '<select class="form-select" id="status" name="status">' . options_html($definition['statuses'], $f['status'], 'All') . '</select>'),
            default      => '',
        };
    }
    return $html;
}

/** Human summary of the active filters for the report header and CSV preamble. */
function report_filter_summary(array $definition, array $f): array
{
    $institutionId = (int) current_institution_id();
    $lookups = [
        'fund' => 'SELECT fund_name FROM fund_accounts WHERE institution_id = ? AND id = ?',
        'event' => 'SELECT event_name FROM events WHERE institution_id = ? AND id = ?',
        'group' => 'SELECT group_name FROM `groups` WHERE institution_id = ? AND id = ?',
        'department' => 'SELECT department_name FROM departments WHERE institution_id = ? AND id = ?',
        'account' => 'SELECT account_name FROM financial_accounts WHERE institution_id = ? AND id = ?',
        'product' => 'SELECT product_name FROM products WHERE institution_id = ? AND id = ?',
        'category' => 'SELECT category_name FROM product_categories WHERE institution_id = ? AND id = ?',
        'variant' => "SELECT CONCAT(p.product_name, ' · ', v.variant_name) FROM product_variants v JOIN products p ON p.id = v.product_id AND p.institution_id = v.institution_id WHERE v.institution_id = ? AND v.id = ?",
    ];
    $summary = [];
    if (in_array('from', $definition['filters'], true) || in_array('to', $definition['filters'], true)) {
        $summary['Period'] = in_array('from', $definition['filters'], true)
            ? ($f['from'] ? format_date($f['from']) : 'Beginning') . ' – ' . ($f['to'] ? format_date($f['to']) : 'today')
            : 'As of ' . format_date($f['to'] ?: date('Y-m-d'));
    }
    foreach ($lookups as $key => $sql) {
        if (in_array($key, $definition['filters'], true)) {
            if ($key === 'variant' && !$f[$key]) {
                continue;
            }
            $summary[ucfirst($key)] = $f[$key] ? (string) db_value($sql, [$institutionId, $f[$key]]) : 'All';
        }
    }
    if (isset($definition['statuses'])) {
        $summary['Status'] = $f['status'] ? $definition['statuses'][$f['status']] : 'All statuses';
    }
    if ($f['q'] !== '') {
        $summary['Search'] = $f['q'];
    }
    return $summary;
}

/** Display value of a cell (HTML). */
function report_cell(array $column, mixed $value): string
{
    $type = $column[1] ?? 'text';
    return match ($type) {
        'money'    => $value === null ? '' : e(m($value)),
        'int'      => e(number_format((int) $value)),
        'date'     => e($value ? format_date($value) : '—'),
        'datetime' => e($value ? format_datetime($value) : '—'),
        'status'   => status_badge($value),
        'label'    => e(humanize((string) $value)),
        'late'     => (int) $value > 0 ? '<span class="text-danger">' . e((string) $value) . '</span>' : '',
        'usage'    => progress_bar(min(100, (int) $value), (int) $value > 100 ? 'danger' : ((int) $value >= 90 ? 'warning' : '')) . '<div class="text-muted-sm">' . e($value . '%') . '</div>',
        'changes'  => '<span class="text-muted-sm">' . e((string) $value) . '</span>',
        default    => e((string) ($value ?? '')),
    };
}

/** Plain value for CSV. */
function report_csv_value(array $column, mixed $value): string
{
    return match ($column[1] ?? 'text') {
        'money'  => $value === null ? '' : cents_to_decimal(to_cents($value)),
        'label', 'status' => humanize((string) $value),
        default  => (string) ($value ?? ''),
    };
}

/** Renders (or exports) a tabular report. Ends the request. */
function render_report(string $key): never
{
    $definitions = report_definitions();
    $definition = $definitions[$key] ?? null;
    if (!$definition) {
        render_error_page(404, 'Report Not Found', 'That report does not exist.');
    }
    if (($definition['context'] ?? 'institution') === 'institution') {
        require_institution_context();
    }
    require_permission($definition['permissions']);
    if (current_institution_id() === null && !can('platform.audit.view')) {
        require_institution_context();
    }

    $f = report_filter_values($definition);
    [$sql, $params] = $definition['build']($f);
    $columns = $definition['columns'];
    if (current_institution_id() !== null) {
        $columns = array_filter($columns, static fn($c) => ($c[4] ?? null) !== 'platform');
    }
    $order = $definition['order'];
    $summary = report_filter_summary($definition, $f);
    $contextLabel = auth_context()['label'] ?? 'Platform';

    $computed = array_filter($columns, static fn($c) => isset($c[3]) && is_callable($c[3]));
    $withComputed = static function (array $row) use ($computed): array {
        foreach ($computed as $alias => $column) {
            $row[$alias] = $column[3]($row);
        }
        return $row;
    };

    if (get_str('export', 10) === 'csv') {
        $rows = array_map($withComputed, db_all("SELECT * FROM ({$sql}) r ORDER BY {$order} LIMIT 100000", $params));
        audit_log('report.exported', 'report', null, current_institution_id(), (int) auth_user()['id'], ['report' => $key, 'filters' => $summary, 'rows' => count($rows)]);
        $preamble = [[$definition['title'], $contextLabel]];
        foreach ($summary as $label => $value) {
            $preamble[] = [$label, $value];
        }
        $preamble[] = ['Generated', date('Y-m-d H:i') . ' by ' . auth_user()['username']];
        send_csv($key . '_' . date('Ymd') . '.csv', array_map(static fn($c) => $c[0], array_values($columns)),
            array_map(static fn($row) => array_map(static fn($alias) => report_csv_value($columns[$alias], $row[$alias] ?? null), array_keys($columns)), $rows),
            $preamble);
    }

    $pagination = paginate((int) db_value("SELECT COUNT(*) FROM ({$sql}) r", $params));
    $rows = array_map($withComputed, db_all("SELECT * FROM ({$sql}) r ORDER BY {$order} LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params));

    // Totals over the whole filtered result (not just this page).
    $totals = [];
    $sqlTotals = [];
    foreach ($columns as $alias => $column) {
        if (($column[2] ?? false) && !isset($column[3])) {
            $sqlTotals[] = "COALESCE(SUM(r.`{$alias}`), 0) AS `{$alias}`";
        }
    }
    if ($sqlTotals) {
        $totals = db_one('SELECT ' . implode(', ', $sqlTotals) . " FROM ({$sql}) r", $params) ?? [];
    }
    $computedTotals = array_filter($computed, static fn($c) => ($c[2] ?? false) && ($c[1] ?? '') === 'money');
    if ($computedTotals && $pagination['total'] > 0) {
        $all = db_all("SELECT * FROM ({$sql}) r LIMIT 100000", $params);
        foreach ($computedTotals as $alias => $column) {
            $totals[$alias] = cents_to_decimal(array_sum(array_map(static fn($row) => to_cents($column[3]($row)), $all)));
        }
    }
    [$linkColumn, $linkPath, $linkId] = $definition['link'] ?? [null, null, null];

    $pageTitle = $definition['title'];
    $activeNav = $definition['nav'] ?? ($key === 'audit_trail' ? 'audit' : 'reports');
    $breadcrumb = [$contextLabel, 'Reports'];
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="report-header"><h2><?= e($definition['title']) ?></h2><p><?= e($contextLabel) ?> · <?= e(implode(' · ', array_map(static fn($label, $value) => $label . ': ' . $value, array_keys($summary), $summary))) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(auth_user()['username']) ?></p></div>
    <?= page_actions($definition['description'],
        (isset($definition['hub']) && !can('reports.view') ? button_link($definition['hub'], 'Store Reports', 'bi-arrow-left', 'btn-outline-secondary')
            : (can('reports.view') ? button_link('reports/index.php', 'All Reports', 'bi-arrow-left', 'btn-outline-secondary') : ''))
        . '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv', 'page' => null])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
        . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>
    <?php if (!empty($definition['note'])): ?>
        <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div><?= e($definition['note']) ?></div></div>
    <?php endif; ?>
    <div class="card panel">
        <form class="filter-bar" method="get">
            <?php if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'view.php'): ?><input type="hidden" name="r" value="<?= e($key) ?>"><?php endif; ?>
            <?= report_filter_fields($definition, $f) ?>
            <button class="btn btn-primary" type="submit">Apply</button>
        </form>
        <?php if (!$rows): ?>
            <div class="card-body"><?= empty_state($definition['icon'], 'No records match the filters') ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-clean table-report align-middle mb-0">
                    <thead><tr><?php foreach ($columns as $column): ?><th<?= in_array($column[1] ?? '', ['money', 'int'], true) ? ' class="text-end"' : '' ?>><?= e($column[0]) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $alias => $column): $cell = report_cell($column, $row[$alias] ?? null); ?>
                                <td<?= in_array($column[1] ?? '', ['money', 'int'], true) ? ' class="text-end"' : '' ?>><?= $alias === $linkColumn && !empty($row[$linkId]) ? '<a href="' . e(url($linkPath . $row[$linkId])) . '">' . $cell . '</a>' : $cell ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($totals): ?>
                        <tfoot><tr class="fw-semibold">
                            <?php $first = true; foreach ($columns as $alias => $column): ?>
                                <td<?= in_array($column[1] ?? '', ['money', 'int'], true) ? ' class="text-end"' : '' ?>><?php if (array_key_exists($alias, $totals)): ?><?= report_cell($column, $totals[$alias]) ?><?php elseif ($first): ?>Total (<?= e(number_format($pagination['total'])) ?> rows)<?php endif; ?></td>
                            <?php $first = false; endforeach; ?>
                        </tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
        <?php endif; ?>
        <?= pagination_links($pagination) ?>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

/** Chart canvas for assets/js/charts.js. $config: type, labels, datasets[label, data]. */
function chart_canvas(array $config, string $label): string
{
    $config['prefix'] = current_currency() === 'PHP' ? '₱' : '';
    return '<div class="chart-box"><canvas data-chart="' . e((string) json_encode($config, JSON_UNESCAPED_UNICODE)) . '" role="img" aria-label="' . e($label) . '"></canvas></div>';
}

/** Centavos as a plain number for charts. */
function chart_amount(int $cents): float
{
    return round($cents / 100, 2);
}
