<?php
/**
 * Expense register. Posted (paid) totals are kept separate from pending and rejected amounts.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/expenses.php';
require_institution_context();
require_permission(['expenses.view', 'expenses.record', 'expenses.approve']);

$institutionId = (int) current_institution_id();
$filters = [
    'status' => get_str('status', 20),
    'fund' => get_int('fund'),
    'event' => get_int('event'),
    'category' => get_str('category', 100),
    'from' => input_date($_GET, 'from'),
    'to' => input_date($_GET, 'to'),
    'q' => get_str('q', 100),
];
$where = ['x.institution_id = ?'];
$params = [$institutionId];
if (in_array($filters['status'], ['draft', 'submitted', 'approved', 'paid', 'rejected', 'reversed'], true)) {
    $where[] = 'x.status = ?';
    $params[] = $filters['status'];
} elseif ($filters['status'] === 'awaiting') {
    $where[] = "x.status = 'submitted'";
}
foreach (['fund' => 'x.fund_id', 'event' => 'x.event_id'] as $key => $column) {
    if ($filters[$key]) {
        $where[] = "{$column} = ?";
        $params[] = $filters[$key];
    }
}
if ($filters['category'] !== '') {
    $where[] = 'x.category = ?';
    $params[] = $filters['category'];
}
if ($filters['from']) {
    $where[] = 'x.expense_date >= ?';
    $params[] = $filters['from'];
}
if ($filters['to']) {
    $where[] = 'x.expense_date <= ?';
    $params[] = $filters['to'];
}
if ($filters['q'] !== '') {
    $where[] = '(x.expense_number LIKE ? OR x.payee LIKE ? OR x.description LIKE ?)';
    array_push($params, "%{$filters['q']}%", "%{$filters['q']}%", "%{$filters['q']}%");
}
$whereSql = implode(' AND ', $where);
$totals = db_one(
    "SELECT COALESCE(SUM(CASE WHEN status = 'paid' THEN amount END), 0) AS posted,
            COALESCE(SUM(CASE WHEN status IN ('submitted', 'approved') THEN amount END), 0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'draft' THEN amount END), 0) AS drafts,
            SUM(status = 'submitted') AS awaiting
     FROM expenses x WHERE {$whereSql}",
    $params
);
[$orderBy, $sortKey, $sortDir] = sort_clause(['date' => 'x.expense_date', 'amount' => 'x.amount', 'payee' => 'x.payee', 'status' => 'x.status'], 'date', 'desc');
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM expenses x WHERE {$whereSql}", $params));
$rows = db_all(
    "SELECT x.*, f.fund_name, e.event_name, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
     FROM expenses x JOIN fund_accounts f ON f.id = x.fund_id LEFT JOIN events e ON e.id = x.event_id JOIN users u ON u.id = x.created_by
     WHERE {$whereSql} ORDER BY {$orderBy}, x.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Expenses';
$activeNav = 'expenses';
$breadcrumb = [auth_context()['label'], 'Finance'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Direct expenses. Only paid expenses are posted; pending and rejected ones never count toward spending.',
    can('expenses.record') ? button_link('expenses/record_expense.php', 'Record Expense', 'bi-receipt') : '') ?>
<div class="stat-grid">
    <?= stat_card('bi-check2-circle', 'Posted (paid)', m($totals['posted']), 'Counts as spending') ?>
    <?= stat_card('bi-hourglass-split', 'Pending', m($totals['pending']), 'Submitted or approved, not paid', 'warning') ?>
    <?= stat_card('bi-pencil-square', 'Drafts', m($totals['drafts']), 'Not submitted', 'info') ?>
    <?= stat_card('bi-person-check', 'Awaiting Approval', number_format((int) $totals['awaiting']), 'Submitted expenses', (int) $totals['awaiting'] ? 'warning' : 'success') ?>
</div>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Number, payee or purpose"></div>
        <div class="filter-field"><label for="fund">Fund</label><select class="form-select" id="fund" name="fund"><?= options_html(options_from_rows(db_all('SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? ORDER BY fund_name', [$institutionId]), 'id', 'fund_name'), $filters['fund'], 'All') ?></select></div>
        <div class="filter-field"><label for="event">Event</label><select class="form-select" id="event" name="event"><?= options_html(event_options(EVENT_STATUSES), $filters['event'], 'All') ?></select></div>
        <div class="filter-field"><label for="category">Category</label><select class="form-select" id="category" name="category"><?= options_html(array_combine(expense_categories(), expense_categories()), $filters['category'], 'All') ?></select></div>
        <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
        <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['awaiting' => 'Awaiting approval', 'draft' => 'Draft', 'submitted' => 'Submitted', 'approved' => 'Approved', 'paid' => 'Paid (posted)', 'rejected' => 'Rejected', 'reversed' => 'Reversed'], $filters['status'], 'All') ?></select></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-receipt', 'No expenses match') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr>
                    <th><?= sort_link('Date · No.', 'date', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Payee', 'payee', $sortKey, $sortDir) ?></th>
                    <th>Fund · Event</th><th>Category</th>
                    <th class="text-end"><?= sort_link('Amount', 'amount', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Status', 'status', $sortKey, $sortDir) ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><div><?= e(format_date($row['expense_date'])) ?></div><div class="text-muted-sm"><?= e($row['expense_number']) ?></div></td>
                        <td><div class="fw-semibold"><?= e($row['payee']) ?></div><div class="text-muted-sm text-truncate" style="max-width:240px"><?= e(preg_replace('/^Invoice\/receipt no\.: .*\R?/u', '', $row['description'])) ?></div></td>
                        <td><?= e($row['fund_name']) ?><div class="text-muted-sm"><?= e($row['event_name'] ?: '—') ?></div></td>
                        <td><?= e($row['category']) ?></td>
                        <td class="text-end fw-semibold<?= in_array($row['status'], ['rejected', 'reversed'], true) ? ' text-decoration-line-through text-muted' : '' ?>"><?= e(m($row['amount'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('expenses/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
