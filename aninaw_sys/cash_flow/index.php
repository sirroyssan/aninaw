<?php
/**
 * Cash flow register: every movement of money by fund and financial account, with
 * classifications, filters, running balance (posted rows) and CSV export.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/funds.php';
require_once APP_ROOT . '/includes/services/events.php';
require_once APP_ROOT . '/includes/services/ledger.php';
require_institution_context();
require_permission('cashflow.view');

const CASH_FLOW_TYPES = [
    'collection' => 'Collection', 'direct_expense' => 'Expense', 'advance_release' => 'Cash advance release', 'advance_return' => 'Cash advance return',
    'deposit' => 'Deposit', 'withdrawal' => 'Withdrawal', 'transfer' => 'Transfer / custody move', 'refund' => 'Refund', 'adjustment' => 'Adjustment', 'opening_balance' => 'Opening balance',
];

$institutionId = (int) current_institution_id();
$filters = [
    'from'      => input_date($_GET, 'from'),
    'to'        => input_date($_GET, 'to'),
    'fund'      => get_int('fund'),
    'account'   => get_int('account'),
    'event'     => get_int('event'),
    'group'     => get_int('group'),
    'type'      => isset(CASH_FLOW_TYPES[get_str('type', 30)]) ? get_str('type', 30) : '',
    'status'    => in_array(get_str('status', 20), ['posted', 'pending', 'reversed', 'all'], true) ? get_str('status', 20) : 'posted',
    'custodian' => get_int('custodian'),
];

$where = ['c.institution_id = ?'];
$params = [$institutionId];
if ($filters['status'] !== 'all') {
    $where[] = 'c.status = ?';
    $params[] = $filters['status'];
}
foreach (['fund' => 'c.fund_id', 'account' => 'c.financial_account_id', 'custodian' => 'fa.custodian_membership_id'] as $key => $column) {
    if ($filters[$key]) {
        $where[] = "{$column} = ?";
        $params[] = $filters[$key];
    }
}
if ($filters['type']) {
    $where[] = 'c.transaction_type = ?';
    $params[] = $filters['type'];
}
if ($filters['to']) {
    $where[] = 'c.transaction_date <= ?';
    $params[] = $filters['to'] . ' 23:59:59';
}
if ($filters['event']) {
    // Linked to the event through the source record.
    $where[] = "(
        (c.source_type IN ('payment', 'payment_reversal') AND EXISTS (SELECT 1 FROM payment_allocations pa JOIN event_obligations o ON o.id = pa.obligation_id AND o.institution_id = pa.institution_id WHERE pa.institution_id = c.institution_id AND pa.payment_id = c.source_id AND o.event_id = ?))
     OR (c.source_type IN ('turnover', 'turnover_reversal') AND EXISTS (SELECT 1 FROM cash_turnovers t WHERE t.institution_id = c.institution_id AND t.id = c.source_id AND t.event_id = ?))
     OR (c.source_type IN ('expense', 'expense_reversal') AND EXISTS (SELECT 1 FROM expenses x WHERE x.institution_id = c.institution_id AND x.id = c.source_id AND x.event_id = ?))
     OR (c.source_type IN ('advance_release', 'advance_release_reversal') AND EXISTS (SELECT 1 FROM cash_advances a WHERE a.institution_id = c.institution_id AND a.id = c.source_id AND a.event_id = ?))
     OR (c.source_type IN ('advance_return', 'advance_return_reversal') AND EXISTS (SELECT 1 FROM cash_advance_returns r JOIN cash_advances a ON a.id = r.cash_advance_id WHERE r.institution_id = c.institution_id AND r.id = c.source_id AND a.event_id = ?))
    )";
    array_push($params, ...array_fill(0, 5, $filters['event']));
}
if ($filters['group']) {
    $where[] = "(
        (c.source_type IN ('payment', 'payment_reversal') AND EXISTS (SELECT 1 FROM payments p JOIN group_memberships gm ON gm.institution_id = p.institution_id AND gm.participant_id = p.payer_participant_id AND gm.group_id = ? WHERE p.institution_id = c.institution_id AND p.id = c.source_id))
     OR (c.source_type IN ('turnover', 'turnover_reversal') AND EXISTS (SELECT 1 FROM cash_turnovers t WHERE t.institution_id = c.institution_id AND t.id = c.source_id AND t.group_id = ?))
    )";
    array_push($params, $filters['group'], $filters['group']);
}
$whereNoFrom = implode(' AND ', $where);
if ($filters['from']) {
    $where[] = 'c.transaction_date >= ?';
    $params[] = $filters['from'];
}
$whereSql = implode(' AND ', $where);
$fromSql = "FROM cash_flow_transactions c
            JOIN fund_accounts f ON f.id = c.fund_id AND f.institution_id = c.institution_id
            JOIN financial_accounts fa ON fa.id = c.financial_account_id AND fa.institution_id = c.institution_id
            LEFT JOIN institution_memberships m ON m.id = fa.custodian_membership_id LEFT JOIN users cu ON cu.id = m.user_id
            JOIN users ru ON ru.id = c.recorded_by";
$select = "SELECT c.id, c.transaction_number, c.transaction_date, c.transaction_type, c.direction, c.amount, c.status, c.source_type, c.source_id,
                  f.fund_name, fa.account_name, CONCAT(cu.first_name, ' ', cu.last_name) AS custodian_name, CONCAT(ru.first_name, ' ', ru.last_name) AS recorded_by_name";
$order = 'ORDER BY c.transaction_date, c.id';

$totals = db_one("SELECT COALESCE(SUM(CASE WHEN c.direction = 'inflow' THEN c.amount END), 0) AS inflows, COALESCE(SUM(CASE WHEN c.direction = 'outflow' THEN c.amount END), 0) AS outflows {$fromSql} WHERE {$whereSql}", $params);
$showRunning = $filters['status'] === 'posted';

if (get_str('export', 10) === 'csv') {
    $rows = db_all("{$select} {$fromSql} WHERE {$whereSql} {$order} LIMIT 50000", $params);
    send_csv('cash_flow_' . date('Ymd') . '.csv',
        ['Date', 'Number', 'Fund', 'Account', 'Custodian', 'Type', 'Source', 'Direction', 'Amount', 'Status', 'Recorded by'],
        array_map(static fn($r) => [substr($r['transaction_date'], 0, 10), $r['transaction_number'], $r['fund_name'], $r['account_name'], $r['custodian_name'], CASH_FLOW_TYPES[$r['transaction_type']] ?? $r['transaction_type'],
            humanize($r['source_type']) . ' #' . $r['source_id'], $r['direction'], ($r['direction'] === 'outflow' ? '-' : '') . cents_to_decimal(to_cents($r['amount'])), $r['status'], $r['recorded_by_name']], $rows),
        [['Cash flow register', auth_context()['label']], ['Period', ($filters['from'] ?: 'beginning') . ' to ' . ($filters['to'] ?: 'today')], ['Status', $filters['status']], ['Generated', date('Y-m-d H:i')]]
    );
}

$pagination = paginate((int) db_value("SELECT COUNT(*) {$fromSql} WHERE {$whereSql}", $params));
$opening = 0;
if ($showRunning) {
    $paramsNoFrom = array_slice($params, 0, count($params) - ($filters['from'] ? 1 : 0));
    if ($filters['from']) {
        $opening = to_cents(db_value("SELECT COALESCE(SUM(CASE WHEN c.direction = 'inflow' THEN c.amount ELSE -c.amount END), 0) {$fromSql} WHERE {$whereNoFrom} AND c.transaction_date < ?", array_merge($paramsNoFrom, [$filters['from']])));
    }
    if ($pagination['offset'] > 0) {
        $opening += to_cents(db_value("SELECT COALESCE(SUM(x.net), 0) FROM (SELECT CASE WHEN c.direction = 'inflow' THEN c.amount ELSE -c.amount END AS net {$fromSql} WHERE {$whereSql} {$order} LIMIT {$pagination['offset']}) x", $params));
    }
}
$rows = db_all("{$select} {$fromSql} WHERE {$whereSql} {$order} LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params);
$currency = current_currency();
$custodians = options_from_rows(db_all(
    "SELECT DISTINCT m.id, u.first_name, u.last_name FROM financial_accounts fa JOIN institution_memberships m ON m.id = fa.custodian_membership_id JOIN users u ON u.id = m.user_id WHERE fa.institution_id = ? ORDER BY u.last_name",
    [$institutionId]
), 'id', static fn($r) => person_name($r));

$pageTitle = 'Cash Flow';
$activeNav = 'cash-flow';
$breadcrumb = [auth_context()['label'], 'Finance'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Cash Flow Register</h2><p><?= e(auth_context()['label']) ?> · <?= e(($filters['from'] ? format_date($filters['from']) : 'Beginning') . ' – ' . ($filters['to'] ? format_date($filters['to']) : 'today')) ?> · <?= e(humanize($filters['status'])) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions('Every movement of money. Deposits, transfers and turnovers appear as matching outflow/inflow pairs and do not change fund totals.',
    '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv', 'page' => null])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>

<div class="stat-grid">
    <?= stat_card('bi-box-arrow-in-down', 'Inflows', m($totals['inflows']), 'Filtered rows') ?>
    <?= stat_card('bi-box-arrow-up', 'Outflows', m($totals['outflows']), 'Filtered rows', 'info') ?>
    <?= stat_card('bi-plus-slash-minus', 'Net', money(to_cents($totals['inflows']) - to_cents($totals['outflows']), $currency), $filters['status'] === 'posted' ? 'Posted only' : humanize($filters['status']) . ' rows — not balances', $filters['status'] === 'posted' ? 'success' : 'warning') ?>
</div>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
        <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
        <div class="filter-field"><label for="fund">Fund</label><select class="form-select" id="fund" name="fund"><?= options_html(options_from_rows(db_all('SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? ORDER BY fund_name', [$institutionId]), 'id', 'fund_name'), $filters['fund'], 'All') ?></select></div>
        <div class="filter-field"><label for="account">Account</label><select class="form-select" id="account" name="account"><?= options_html(options_from_rows(db_all('SELECT id, account_name FROM financial_accounts WHERE institution_id = ? ORDER BY account_name', [$institutionId]), 'id', 'account_name'), $filters['account'], 'All') ?></select></div>
        <div class="filter-field"><label for="custodian">Custodian</label><select class="form-select" id="custodian" name="custodian"><?= options_html($custodians, $filters['custodian'], 'All') ?></select></div>
        <div class="filter-field"><label for="event">Event</label><select class="form-select" id="event" name="event"><?= options_html(event_options(EVENT_STATUSES), $filters['event'], 'All') ?></select></div>
        <div class="filter-field"><label for="group">Group</label><select class="form-select" id="group" name="group"><?= options_html(group_options(false), $filters['group'], 'All') ?></select></div>
        <div class="filter-field"><label for="type">Type</label><select class="form-select" id="type" name="type"><?= options_html(CASH_FLOW_TYPES, $filters['type'], 'All') ?></select></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['posted' => 'Posted', 'pending' => 'Pending', 'reversed' => 'Withdrawn', 'all' => 'All'], $filters['status']) ?></select></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-activity', 'No movements match') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Date · No.</th><th>Fund</th><th>Account · Custodian</th><th>Type · Source</th><th class="text-end">In</th><th class="text-end">Out</th><?php if ($showRunning): ?><th class="text-end">Running</th><?php endif; ?><th>Status</th></tr></thead>
                <tbody>
                <?php if ($showRunning): ?><tr class="table-light"><td colspan="6" class="text-muted-sm">Balance brought forward<?= $filters['fund'] || $filters['account'] ? '' : ' (all funds and accounts)' ?></td><td class="text-end fw-semibold"><?= e(money($opening, $currency)) ?></td><td></td></tr><?php endif; ?>
                <?php $running = $opening; foreach ($rows as $row): $cents = to_cents($row['amount']); $running += $row['direction'] === 'inflow' ? $cents : -$cents;
                    $link = journal_source_path($row['source_type'], (int) $row['source_id']); ?>
                    <tr>
                        <td><div><?= e(format_date($row['transaction_date'])) ?></div><div class="text-muted-sm"><?= e($row['transaction_number']) ?></div></td>
                        <td><?= e($row['fund_name']) ?></td>
                        <td><?= e($row['account_name']) ?><div class="text-muted-sm"><?= e($row['custodian_name'] ?: '—') ?></div></td>
                        <td><?= e(CASH_FLOW_TYPES[$row['transaction_type']] ?? $row['transaction_type']) ?><div class="text-muted-sm"><?= $link ? '<a href="' . e(url($link)) . '">' . e(humanize($row['source_type'])) . ' #' . e((string) $row['source_id']) . '</a>' : e(humanize($row['source_type']) . ' #' . $row['source_id']) ?></div></td>
                        <td class="text-end text-success"><?= $row['direction'] === 'inflow' ? e(money($cents, $currency)) : '' ?></td>
                        <td class="text-end text-danger"><?= $row['direction'] === 'outflow' ? e(money($cents, $currency)) : '' ?></td>
                        <?php if ($showRunning): ?><td class="text-end fw-semibold"><?= e(money($running, $currency)) ?></td><?php endif; ?>
                        <td><?= status_badge($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
