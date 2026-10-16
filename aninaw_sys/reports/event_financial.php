<?php
/**
 * Event financial report: collections, spending by category (posted vs pending),
 * cash advances and budgets for one event, with the net position.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/reports.php';
require_once APP_ROOT . '/includes/services/budgets.php';
require_institution_context();
require_permission('reports.view');

$institutionId = (int) current_institution_id();
$eventId = get_int('event');
$event = $eventId ? require_found(find_event($eventId)) : null;
$currency = current_currency();

if ($event) {
    $collections = event_collection_stats($eventId);
    $waived = to_cents(db_value("SELECT COALESCE(SUM(waived_amount), 0) FROM event_obligations WHERE institution_id = ? AND event_id = ? AND status <> 'cancelled'", [$institutionId, $eventId]));
    $funds = db_all("SELECT f.id, f.fund_name, ef.allocation_purpose FROM event_funds ef JOIN fund_accounts f ON f.id = ef.fund_id AND f.institution_id = ef.institution_id WHERE ef.institution_id = ? AND ef.event_id = ? AND ef.status = 'active' ORDER BY f.fund_name", [$institutionId, $eventId]);
    $spending = db_all(
        "SELECT category, SUM(posted) AS posted, SUM(pending) AS pending FROM (
            SELECT category, CASE WHEN status = 'paid' THEN amount ELSE 0 END AS posted, CASE WHEN status IN ('submitted', 'approved') THEN amount ELSE 0 END AS pending
            FROM expenses WHERE institution_id = ? AND event_id = ? AND status IN ('paid', 'submitted', 'approved')
            UNION ALL
            SELECT ae.category, CASE WHEN ae.verification_status = 'verified' THEN ae.amount ELSE 0 END, CASE WHEN ae.verification_status IN ('pending', 'needs_correction') THEN ae.amount ELSE 0 END
            FROM cash_advance_expenses ae JOIN cash_advances a ON a.id = ae.cash_advance_id AND a.institution_id = ae.institution_id
            WHERE ae.institution_id = ? AND a.event_id = ? AND a.status <> 'cancelled' AND ae.verification_status IN ('verified', 'pending', 'needs_correction')
         ) s GROUP BY category ORDER BY posted DESC",
        [$institutionId, $eventId, $institutionId, $eventId]
    );
    $spent = array_sum(array_map(static fn($r) => to_cents($r['posted']), $spending));
    $pendingSpend = array_sum(array_map(static fn($r) => to_cents($r['pending']), $spending));
    $advances = db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(a.released_amount), 0) AS released,
                COALESCE(SUM((SELECT SUM(x.amount) FROM cash_advance_expenses x WHERE x.institution_id = a.institution_id AND x.cash_advance_id = a.id AND x.verification_status = 'verified')), 0) AS verified,
                COALESCE(SUM((SELECT SUM(r.returned_amount) FROM cash_advance_returns r WHERE r.institution_id = a.institution_id AND r.cash_advance_id = a.id AND r.status = 'confirmed')), 0) AS returned
         FROM cash_advances a WHERE a.institution_id = ? AND a.event_id = ? AND a.status NOT IN ('draft', 'pending_approval', 'approved', 'cancelled')",
        [$institutionId, $eventId]
    );
    $advanceOutstanding = to_cents($advances['released']) - to_cents($advances['verified']) - to_cents($advances['returned']);
    $budgets = [];
    foreach (db_all("SELECT id FROM budgets WHERE institution_id = ? AND event_id = ? AND status <> 'cancelled' ORDER BY start_date", [$institutionId, $eventId]) as $row) {
        $budget = find_budget((int) $row['id']);
        if ($budget) {
            $budgets[] = $budget + ['figures' => budget_vs_actual($budget)['totals']];
        }
    }
    $net = $collections['received'] - $spent;

    if (get_str('export', 10) === 'csv') {
        $lines = [
            ['Collections', 'Expected (net of waivers)', cents_to_decimal($collections['expected'])],
            ['Collections', 'Collected (posted)', cents_to_decimal($collections['received'])],
            ['Collections', 'Waived', cents_to_decimal($waived)],
            ['Collections', 'Outstanding', cents_to_decimal($collections['remaining'])],
        ];
        foreach ($spending as $row) {
            $lines[] = ['Spending (posted)', $row['category'], cents_to_decimal(to_cents($row['posted']))];
            $lines[] = ['Spending (pending, not posted)', $row['category'], cents_to_decimal(to_cents($row['pending']))];
        }
        array_push($lines,
            ['Cash advances', 'Released', cents_to_decimal(to_cents($advances['released']))],
            ['Cash advances', 'Outstanding', cents_to_decimal(max(0, $advanceOutstanding))],
            ['Net', 'Collected − posted spending', cents_to_decimal($net)]);
        foreach ($budgets as $budget) {
            $lines[] = ['Budget ' . $budget['budget_number'], 'Approved / actual / available', cents_to_decimal($budget['figures']['approved']) . ' / ' . cents_to_decimal($budget['figures']['actual']) . ' / ' . cents_to_decimal($budget['figures']['available'])];
        }
        audit_log('report.exported', 'report', null, $institutionId, (int) auth_user()['id'], ['report' => 'event_financial', 'event_id' => $eventId]);
        send_csv('event_' . $event['event_code'] . '_' . date('Ymd') . '.csv', ['Section', 'Item', 'Amount'], $lines,
            [['Event financial report', auth_context()['label']], ['Event', $event['event_name'] . ' (' . $event['event_code'] . ')'], ['Generated', date('Y-m-d H:i') . ' by ' . auth_user()['username']]]);
    }
}

$pageTitle = 'Event Financial Report';
$activeNav = 'reports';
$breadcrumb = [auth_context()['label'], 'Reports'];
$pageScripts = ['vendor/chartjs/chart.umd.js', 'js/charts.js'];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($event): ?><div class="report-header"><h2>Event Financial Report · <?= e($event['event_name']) ?></h2><p><?= e(auth_context()['label']) ?> · <?= e($event['event_code']) ?> · <?= e(humanize($event['status'])) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(auth_user()['username']) ?></p></div><?php endif; ?>
<?= page_actions('Everything collected and spent for one event. Posted figures and pending items are shown separately.',
    button_link('reports/index.php', 'All Reports', 'bi-arrow-left', 'btn-outline-secondary')
    . ($event ? '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv'])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
        . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>' : '')) ?>
<div class="card panel mb-3">
    <form class="filter-bar" method="get">
        <div class="filter-field" style="min-width:320px"><label for="event">Event</label><select class="form-select" id="event" name="event" data-autosubmit><?= options_html(event_options(EVENT_STATUSES), $eventId, 'Choose an event…') ?></select></div>
    </form>
</div>
<?php if (!$event): ?>
    <div class="card panel"><div class="card-body"><?= empty_state('bi-calendar-event', 'Choose an event', 'The report covers its collections, expenses, advances and budget.') ?></div></div>
<?php else: ?>
    <div class="stat-grid">
        <?= stat_card('bi-cash-coin', 'Collected', money($collections['received'], $currency), $collections['percent'] . '% of ' . money($collections['expected'], $currency) . ' expected') ?>
        <?= stat_card('bi-receipt', 'Spent (posted)', money($spent, $currency), money($pendingSpend, $currency) . ' pending, not posted', 'info') ?>
        <?= stat_card('bi-wallet2', 'Advances outstanding', money(max(0, $advanceOutstanding), $currency), number_format((int) $advances['n']) . ' released advance(s)', $advanceOutstanding > 0 ? 'warning' : 'success') ?>
        <?= stat_card('bi-plus-slash-minus', 'Net', money($net, $currency), 'Collected − posted spending', $net < 0 ? 'danger' : 'success') ?>
    </div>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card panel h-100">
                <div class="card-header"><h3>Collections</h3></div>
                <div class="card-body">
                    <dl class="detail-grid">
                        <div><dt>Expected (after waivers)</dt><dd><?= e(money($collections['expected'], $currency)) ?></dd></div>
                        <div><dt>Collected (posted)</dt><dd><?= e(money($collections['received'], $currency)) ?></dd></div>
                        <div><dt>Waived</dt><dd><?= e(money($waived, $currency)) ?></dd></div>
                        <div><dt>Outstanding</dt><dd><?= e(money($collections['remaining'], $currency)) ?></dd></div>
                        <div><dt>Participants</dt><dd><?= e($collections['paid_count'] . ' paid · ' . $collections['partial_count'] . ' partial · ' . $collections['unpaid_count'] . ' unpaid') ?></dd></div>
                        <div><dt>With collectors, not turned over</dt><dd><?= e(money($collections['pending_turnover'], $currency)) ?></dd></div>
                    </dl>
                    <p class="text-muted-sm mt-3 mb-0">Funds: <?= $funds ? e(implode(', ', array_column($funds, 'fund_name'))) : 'none linked' ?> · <a class="d-print-none" href="<?= e(url('reports/view.php?r=collection_status&event=' . $eventId)) ?>">Participant status</a></p>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card panel h-100">
                <div class="card-header"><h3>Posted spending by category</h3></div>
                <div class="card-body">
                    <?php $postedRows = array_values(array_filter($spending, static fn($r) => to_cents($r['posted']) > 0)); ?>
                    <?= $postedRows ? chart_canvas(['type' => 'doughnut', 'labels' => array_column($postedRows, 'category'), 'datasets' => [['label' => 'Posted', 'data' => array_map(static fn($r) => chart_amount(to_cents($r['posted'])), $postedRows)]]], 'Posted spending by category') : empty_state('bi-pie-chart', 'No posted spending yet') ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card panel h-100">
                <div class="card-header"><h3>Spending</h3></div>
                <?php if (!$spending): ?><div class="card-body"><?= empty_state('bi-receipt', 'No expenses recorded for this event') ?></div><?php else: ?>
                <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Category</th><th class="text-end">Posted</th><th class="text-end">Pending</th></tr></thead>
                    <tbody><?php foreach ($spending as $row): ?><tr><td><?= e($row['category']) ?></td><td class="text-end"><?= e(m($row['posted'])) ?></td><td class="text-end text-muted-sm"><?= e(m($row['pending'])) ?></td></tr><?php endforeach; ?></tbody>
                    <tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end"><?= e(money($spent, $currency)) ?></td><td class="text-end"><?= e(money($pendingSpend, $currency)) ?></td></tr></tfoot>
                </table></div>
                <div class="card-body text-muted-sm">Posted = paid expenses and verified liquidation receipts. Pending = submitted/approved expenses and receipts awaiting review.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card panel h-100">
                <div class="card-header"><h3>Cash advances and budget</h3></div>
                <div class="card-body">
                    <dl class="detail-grid mb-3">
                        <div><dt>Released</dt><dd><?= e(m($advances['released'])) ?></dd></div>
                        <div><dt>Liquidated (verified + returned)</dt><dd><?= e(money(to_cents($advances['verified']) + to_cents($advances['returned']), $currency)) ?></dd></div>
                        <div><dt>Outstanding</dt><dd><?= e(money(max(0, $advanceOutstanding), $currency)) ?></dd></div>
                    </dl>
                    <?php if (!$budgets): ?>
                        <p class="text-muted-sm mb-0">No budget has been prepared for this event.</p>
                    <?php else: ?>
                        <?php foreach ($budgets as $budget): $b = $budget['figures']; ?>
                            <div class="mb-2"><a href="<?= e(url('budgets/view.php?id=' . $budget['id'])) ?>"><?= e($budget['budget_name']) ?></a> <?= status_badge($budget['status'] === 'pending' ? 'pending_approval' : $budget['status']) ?>
                                <?= progress_bar($b['approved'] ? min(100, $b['actual'] * 100 / $b['approved']) : 0, $b['actual'] > $b['approved'] ? 'danger' : '') ?>
                                <div class="text-muted-sm"><?= e(money($b['actual'], $currency) . ' of ' . money($b['approved'], $currency) . ' used · ' . money($b['available'], $currency) . ' available after pending') ?></div></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
