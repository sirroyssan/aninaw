<?php
/**
 * Institution-level financial summary for a period: income and expenses from posted
 * entries, cash position by account type, advances receivable, collections progress
 * and items still awaiting action (shown apart from posted figures).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/reports.php';
require_once APP_ROOT . '/includes/services/funds.php';
require_institution_context();
require_permission('reports.view');
require_permission(['funds.view_all', 'ledger.view']);

$institutionId = (int) current_institution_id();
$from = input_date($_GET, 'from') ?? date('Y-01-01');
$to = input_date($_GET, 'to') ?? date('Y-m-d');
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$currency = current_currency();
$code = static fn(string $key) => ledger_account($institutionId, $key)['code'];

/** Net movement of account codes in the period (credit-positive for income). */
$movement = static function (array $codes, bool $creditPositive, ?string $start, string $end) use ($institutionId): int {
    $marks = implode(',', array_fill(0, count($codes), '?'));
    $sql = "SELECT COALESCE(SUM(l.credit_amount - l.debit_amount), 0) FROM journal_lines l
            JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
            WHERE l.institution_id = ? AND l.account_code IN ({$marks}) AND j.journal_date <= ?" . ($start ? ' AND j.journal_date >= ?' : '');
    $value = to_cents(db_value($sql, array_merge([$institutionId], $codes, [$end], $start ? [$start] : [])));
    return $creditPositive ? $value : -$value;
};

$income = [
    'Collections and contributions' => $movement([$code('collections')], true, $from, $to),
    'Product sales'                 => $movement([$code('sales')], true, $from, $to),
    'Service revenue'               => $movement([$code('service_revenue')], true, $from, $to),
    'Donations and solicitations'   => $movement([$code('donations')], true, $from, $to),
    'Other income (cash overages)'  => $movement([$code('other_income')], true, $from, $to),
];
$expenses = [
    'Expenses'       => $movement([$code('expenses')], false, $from, $to),
    'Cash shortages' => $movement([$code('cash_shortage')], false, $from, $to),
];
$totalIncome = array_sum($income);
$totalExpenses = array_sum($expenses);

$cashByType = [];
foreach (db_all(
    "SELECT fa.account_type, COALESCE(SUM(l.debit_amount - l.credit_amount), 0) AS balance FROM journal_lines l
     JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
     JOIN financial_accounts fa ON fa.id = l.financial_account_id AND fa.institution_id = l.institution_id
     WHERE l.institution_id = ? AND j.journal_date <= ? GROUP BY fa.account_type",
    [$institutionId, $to]
) as $row) {
    $cashByType[$row['account_type']] = to_cents($row['balance']);
}
$cash = array_sum($cashByType);
$advances = -$movement([$code('advances')], true, null, $to);
$typeLabels = FINANCIAL_ACCOUNT_TYPES;

// Monthly income vs expenses (posted).
$monthly = db_all(
    "SELECT DATE_FORMAT(j.journal_date, '%Y-%m') AS month,
            SUM(CASE WHEN l.account_code IN (?, ?, ?, ?, ?) THEN l.credit_amount - l.debit_amount ELSE 0 END) AS income,
            SUM(CASE WHEN l.account_code IN (?, ?) THEN l.debit_amount - l.credit_amount ELSE 0 END) AS expense
     FROM journal_lines l JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
     WHERE l.institution_id = ? AND j.journal_date BETWEEN ? AND ? GROUP BY month ORDER BY month LIMIT 36",
    [$code('collections'), $code('sales'), $code('service_revenue'), $code('donations'), $code('other_income'), $code('expenses'), $code('cash_shortage'), $institutionId, $from, $to]
);

$collections = db_one(
    "SELECT COALESCE(SUM(o.amount_due - o.waived_amount), 0) AS expected,
            COALESCE(SUM((SELECT SUM(pa.allocated_amount) FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
                           WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id)), 0) AS collected
     FROM event_obligations o JOIN events e ON e.id = o.event_id AND e.institution_id = o.institution_id
     WHERE o.institution_id = ? AND o.status <> 'cancelled' AND e.status IN ('approved', 'collecting')",
    [$institutionId]
);

$attention = [
    ['Expenses approved, not yet paid', "SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM expenses WHERE institution_id = ? AND status = 'approved'", 'reports/view.php?r=expense_report&status=approved'],
    ['Expenses awaiting approval', "SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM expenses WHERE institution_id = ? AND status = 'submitted'", 'reports/view.php?r=expense_report&status=submitted'],
    ['Turnovers not yet acknowledged', "SELECT COUNT(*), COALESCE(SUM(declared_amount), 0) FROM cash_turnovers WHERE institution_id = ? AND status IN ('submitted', 'received_pending_verification')", 'reports/view.php?r=turnover_report&status=submitted'],
    ['Open turnover discrepancies', "SELECT COUNT(*), COALESCE(SUM(ABS(difference_amount)), 0) FROM turnover_discrepancies WHERE institution_id = ? AND status IN ('open', 'under_review')", 'reports/view.php?r=discrepancy_report&status=open'],
    ['Deposits awaiting confirmation', "SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM deposits WHERE institution_id = ? AND status IN ('recorded', 'pending_confirmation')", 'reports/view.php?r=deposit_report&status=pending_confirmation'],
    ['Fund transfers awaiting posting', "SELECT COUNT(*), COALESCE(SUM(amount), 0) FROM fund_transfers WHERE institution_id = ? AND status IN ('submitted', 'approved')", 'reports/view.php?r=transfer_report&status=submitted'],
    ['Overdue cash advances', "SELECT COUNT(*), COALESCE(SUM(released_amount), 0) FROM cash_advances WHERE institution_id = ? AND status = 'overdue'", 'reports/view.php?r=liquidation_report&status=overdue'],
    ['Liquidations awaiting review', "SELECT COUNT(*), COALESCE(SUM(released_amount), 0) FROM cash_advances WHERE institution_id = ? AND status = 'submitted_for_review'", 'reports/view.php?r=liquidation_report&status=submitted_for_review'],
    ['Adjusting entries not yet posted', "SELECT COUNT(*), COALESCE(SUM((SELECT SUM(l.debit_amount) FROM journal_lines l WHERE l.institution_id = j.institution_id AND l.journal_entry_id = j.id)), 0) FROM journal_entries j WHERE j.institution_id = ? AND j.status = 'draft'", 'ledger/index.php?status=draft'],
];
foreach ($attention as &$item) {
    $result = db_query($item[1], [$institutionId])->fetch(PDO::FETCH_NUM);
    $item = ['label' => $item[0], 'count' => (int) $result[0], 'amount' => to_cents($result[1]), 'path' => $item[2]];
}
unset($item);

if (get_str('export', 10) === 'csv') {
    $lines = [];
    foreach ($income as $label => $value) {
        $lines[] = ['Income', $label, cents_to_decimal($value)];
    }
    foreach ($expenses as $label => $value) {
        $lines[] = ['Expenses', $label, cents_to_decimal($value)];
    }
    $lines[] = ['Result', 'Surplus / (deficit)', cents_to_decimal($totalIncome - $totalExpenses)];
    foreach ($cashByType as $type => $value) {
        $lines[] = ['Position as of ' . $to, 'Cash — ' . ($typeLabels[$type] ?? humanize($type)), cents_to_decimal($value)];
    }
    $lines[] = ['Position as of ' . $to, 'Cash advances receivable', cents_to_decimal($advances)];
    $lines[] = ['Position as of ' . $to, 'Total resources', cents_to_decimal($cash + $advances)];
    foreach ($attention as $item) {
        $lines[] = ['Awaiting action (not posted)', $item['label'] . ' (' . $item['count'] . ')', cents_to_decimal($item['amount'])];
    }
    audit_log('report.exported', 'report', null, $institutionId, (int) auth_user()['id'], ['report' => 'financial_summary', 'from' => $from, 'to' => $to]);
    send_csv('financial_summary_' . $from . '_' . $to . '.csv', ['Section', 'Item', 'Amount'], $lines,
        [['Institution financial summary', auth_context()['label']], ['Period', $from . ' to ' . $to], ['Basis', 'Posted journal entries; items awaiting action listed separately'], ['Generated', date('Y-m-d H:i') . ' by ' . auth_user()['username']]]);
}

$pageTitle = 'Institution Financial Summary';
$activeNav = 'reports';
$breadcrumb = [auth_context()['label'], 'Reports'];
$pageScripts = ['vendor/chartjs/chart.umd.js', 'js/charts.js'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Institution Financial Summary</h2><p><?= e(auth_context()['label']) ?> · Period <?= e(format_date($from) . ' – ' . format_date($to)) ?> · Posted entries · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(auth_user()['username']) ?></p></div>
<?= page_actions('Income and expenses for the period and the position at its end, from posted entries. Items still awaiting action are listed separately.',
    button_link('reports/index.php', 'All Reports', 'bi-arrow-left', 'btn-outline-secondary')
    . '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv'])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>
<div class="card panel mb-3">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
</div>
<div class="stat-grid">
    <?= stat_card('bi-box-arrow-in-down', 'Income', money($totalIncome, $currency), 'Posted in the period') ?>
    <?= stat_card('bi-box-arrow-up', 'Expenses', money($totalExpenses, $currency), 'Posted in the period', 'info') ?>
    <?= stat_card('bi-plus-slash-minus', $totalIncome >= $totalExpenses ? 'Surplus' : 'Deficit', money($totalIncome - $totalExpenses, $currency), 'Income − expenses', $totalIncome >= $totalExpenses ? 'success' : 'danger') ?>
    <?= stat_card('bi-wallet', 'Total resources', money($cash + $advances, $currency), 'Cash + advances as of ' . format_date($to)) ?>
</div>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Income and expenses by month</h3></div>
            <div class="card-body">
                <?= $monthly ? chart_canvas(['type' => 'bar', 'labels' => array_map(static fn($r) => date('M Y', strtotime($r['month'] . '-01')), $monthly), 'datasets' => [
                    ['label' => 'Income', 'data' => array_map(static fn($r) => chart_amount(to_cents($r['income'])), $monthly)],
                    ['label' => 'Expenses', 'data' => array_map(static fn($r) => chart_amount(to_cents($r['expense'])), $monthly)],
                ]], 'Monthly income and expenses') : empty_state('bi-bar-chart', 'No posted income or expenses in this period') ?>
            </div>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>Statement of income and expenses</h3></div>
            <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                <tbody>
                    <tr class="table-light"><td colspan="2" class="fw-semibold">Income</td></tr>
                    <?php foreach ($income as $label => $value): ?><tr><td class="ps-4"><?= e($label) ?></td><td class="text-end"><?= e(money($value, $currency)) ?></td></tr><?php endforeach; ?>
                    <tr class="fw-semibold"><td>Total income</td><td class="text-end"><?= e(money($totalIncome, $currency)) ?></td></tr>
                    <tr class="table-light"><td colspan="2" class="fw-semibold">Expenses</td></tr>
                    <?php foreach ($expenses as $label => $value): ?><tr><td class="ps-4"><?= e($label) ?></td><td class="text-end"><?= e(money($value, $currency)) ?></td></tr><?php endforeach; ?>
                    <tr class="fw-semibold"><td>Total expenses</td><td class="text-end"><?= e(money($totalExpenses, $currency)) ?></td></tr>
                    <tr class="fw-bold"><td><?= $totalIncome >= $totalExpenses ? 'Surplus' : 'Deficit' ?> for the period</td><td class="text-end"><?= e(money($totalIncome - $totalExpenses, $currency)) ?></td></tr>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Position as of <?= e(format_date($to)) ?></h3></div>
            <div class="table-responsive"><table class="table table-clean align-middle mb-0"><tbody>
                <?php foreach ($cashByType as $type => $value): ?><tr><td><?= e($typeLabels[$type] ?? humanize($type)) ?></td><td class="text-end"><?= e(money($value, $currency)) ?></td></tr><?php endforeach; ?>
                <tr><td>Cash advances receivable</td><td class="text-end"><?= e(money($advances, $currency)) ?></td></tr>
                <tr class="fw-semibold"><td>Total resources</td><td class="text-end"><?= e(money($cash + $advances, $currency)) ?></td></tr>
            </tbody></table></div>
            <div class="card-body text-muted-sm">Per fund: <a href="<?= e(url('reports/fund_statement.php')) ?>">Fund Balance Report</a>.</div>
        </div>
        <div class="card panel mb-3">
            <div class="card-header"><h3>Active collections</h3></div>
            <div class="card-body">
                <?php $expected = to_cents($collections['expected']); $collected = to_cents($collections['collected']); ?>
                <div class="d-flex justify-content-between"><span><?= e(money($collected, $currency)) ?> collected</span><span class="text-muted-sm">of <?= e(money($expected, $currency)) ?></span></div>
                <?= progress_bar($expected ? $collected * 100 / $expected : 0) ?>
                <div class="text-muted-sm mt-1">Events that are approved or collecting. <a href="<?= e(url('reports/collection_summary.php')) ?>">Collection summary</a></div>
            </div>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>Awaiting action</h3><span class="badge badge-soft-warning">Not posted</span></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($attention as $item): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= $item['count'] ? '<a href="' . e(url($item['path'])) . '">' . e($item['label']) . '</a>' : e($item['label']) ?></span>
                        <span class="text-end"><span class="fw-semibold"><?= e(number_format($item['count'])) ?></span><?php if ($item['count']): ?><div class="text-muted-sm"><?= e(money($item['amount'], $currency)) ?></div><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
