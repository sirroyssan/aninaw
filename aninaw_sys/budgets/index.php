<?php
/**
 * Budgets: approved amounts against posted and pending spending, with filters by
 * fund, event, department (through the event's groups), period and status.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/budgets.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission(['budgets.view', 'budgets.manage', 'budgets.approve']);

$institutionId = (int) current_institution_id();
$filters = [
    'fund'       => get_int('fund'),
    'event'      => get_int('event'),
    'department' => get_int('department'),
    'from'       => input_date($_GET, 'from'),
    'to'         => input_date($_GET, 'to'),
    'status'     => in_array(get_str('status', 20), ['draft', 'pending', 'approved', 'closed', 'cancelled'], true) ? get_str('status', 20) : '',
    'q'          => get_str('q', 100),
];
[$visibility, $params] = budget_visibility('b');
$where = ['b.institution_id = ?', $visibility];
$params = array_merge([$institutionId], $params);
foreach (['fund' => 'b.fund_id', 'event' => 'b.event_id'] as $key => $column) {
    if ($filters[$key]) {
        $where[] = "{$column} = ?";
        $params[] = $filters[$key];
    }
}
if ($filters['department']) {
    $where[] = "EXISTS (SELECT 1 FROM event_group_assignments ga JOIN `groups` g ON g.id = ga.group_id AND g.institution_id = ga.institution_id
                        WHERE ga.institution_id = b.institution_id AND ga.event_id = b.event_id AND g.department_id = ?)";
    $params[] = $filters['department'];
}
if ($filters['from']) {
    $where[] = 'b.end_date >= ?';
    $params[] = $filters['from'];
}
if ($filters['to']) {
    $where[] = 'b.start_date <= ?';
    $params[] = $filters['to'];
}
if ($filters['status'] === 'approved') {
    $where[] = "b.status IN ('approved', 'active')";
} elseif ($filters['status']) {
    $where[] = 'b.status = ?';
    $params[] = $filters['status'];
}
if ($filters['q'] !== '') {
    $where[] = '(b.budget_number LIKE ? OR b.budget_name LIKE ?)';
    $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
    array_push($params, $like, $like);
}
$whereSql = implode(' AND ', $where);
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM budgets b WHERE {$whereSql}", $params), 20);
$rows = db_all(
    "SELECT b.id FROM budgets b WHERE {$whereSql} ORDER BY FIELD(b.status, 'pending', 'draft', 'approved', 'active', 'closed', 'cancelled'), b.start_date DESC, b.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$budgets = [];
foreach ($rows as $row) {
    $budget = find_budget((int) $row['id']);
    if ($budget) {
        $budget['figures'] = budget_vs_actual($budget)['totals'];
        $budgets[] = $budget;
    }
}
$currency = current_currency();

$pageTitle = 'Budgets';
$activeNav = 'budgets';
$breadcrumb = [auth_context()['label'], 'Finance'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Budgets</h2><p><?= e(auth_context()['label']) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions('Approved budgets against posted spending. Pending expenses are shown separately and never counted as spent.',
    '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>'
    . (can('reports.view') ? button_link('reports/view.php?r=budget_vs_actual', 'Budget vs Actual Report', 'bi-bar-chart', 'btn-outline-primary') : '')
    . (can('budgets.manage') ? button_link('budgets/form.php', 'New Budget', 'bi-plus-lg') : '')) ?>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Number or name"></div>
        <div class="filter-field"><label for="fund">Fund</label><select class="form-select" id="fund" name="fund"><?= options_html(options_from_rows(db_all('SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? ORDER BY fund_name', [$institutionId]), 'id', 'fund_name'), $filters['fund'], 'All') ?></select></div>
        <div class="filter-field"><label for="event">Event</label><select class="form-select" id="event" name="event"><?= options_html(event_options(EVENT_STATUSES), $filters['event'], 'All') ?></select></div>
        <div class="filter-field"><label for="department">Department</label><select class="form-select" id="department" name="department"><?= options_html(department_options(false), $filters['department'], 'All') ?></select></div>
        <div class="filter-field"><label for="from">Period from</label><input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
        <div class="filter-field"><label for="to">Period to</label><input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['draft' => 'Draft', 'pending' => 'Awaiting approval', 'approved' => 'Approved', 'closed' => 'Closed', 'cancelled' => 'Cancelled'], $filters['status'], 'All') ?></select></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$budgets): ?>
        <div class="card-body"><?= empty_state('bi-clipboard-data', 'No budgets', can('budgets.manage') ? 'Create a budget for a fund or event.' : '') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Budget</th><th>Fund · Event</th><th>Period</th><th class="text-end">Approved</th><th class="text-end">Actual</th><th class="text-end">Pending</th><th class="text-end">Available</th><th>Status</th><th class="d-print-none"></th></tr></thead>
                <tbody>
                <?php foreach ($budgets as $budget): $f = $budget['figures']; ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($budget['budget_name']) ?></div><div class="text-muted-sm"><?= e($budget['budget_number']) ?></div></td>
                        <td><?= e($budget['fund_name']) ?><div class="text-muted-sm"><?= e($budget['event_name'] ?: 'Whole fund') ?></div></td>
                        <td class="text-muted-sm"><?= e(format_date($budget['start_date'])) ?> –<br><?= e(format_date($budget['end_date'])) ?></td>
                        <td class="text-end"><?= e(money($f['approved'], $currency)) ?></td>
                        <td class="text-end"><?= e(money($f['actual'], $currency)) ?><?= progress_bar($f['approved'] ? min(100, $f['actual'] * 100 / $f['approved']) : 0, $f['actual'] > $f['approved'] ? 'danger' : ($f['available'] < 0 ? 'warning' : '')) ?></td>
                        <td class="text-end text-muted-sm"><?= e(money($f['pending'], $currency)) ?></td>
                        <td class="text-end fw-semibold<?= $f['available'] < 0 ? ' text-danger' : '' ?>"><?= e(money($f['available'], $currency)) ?></td>
                        <td><?= status_badge($budget['status'] === 'pending' ? 'pending_approval' : $budget['status']) ?></td>
                        <td class="actions d-print-none"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('budgets/view.php?id=' . $budget['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
