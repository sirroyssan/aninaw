<?php
/**
 * General journal: every journal entry with its source document, filters,
 * line-level CSV export and print layout.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/ledger.php';
require_institution_context();
require_permission(['ledger.view', 'ledger.post']);

$institutionId = (int) current_institution_id();
$chart = ledger_chart($institutionId);
$codes = [];
foreach ($chart as $account) {
    $codes[$account['code']] = $account['code'] . ' · ' . $account['name'];
}
$sourceTypes = JOURNAL_SOURCE_LABELS;
$filters = [
    'from'   => input_date($_GET, 'from'),
    'to'     => input_date($_GET, 'to'),
    'fund'   => get_int('fund'),
    'code'   => isset($codes[get_str('code', 60)]) ? get_str('code', 60) : '',
    'source' => isset($sourceTypes[get_str('source', 40)]) ? get_str('source', 40) : '',
    'status' => in_array(get_str('status', 20), ['posted', 'draft', 'reversals', 'all'], true) ? get_str('status', 20) : 'posted',
    'q'      => get_str('q', 100),
];

$where = ['j.institution_id = ?'];
$params = [$institutionId];
if ($filters['status'] === 'posted') {
    $where[] = "j.status = 'posted'";
} elseif ($filters['status'] === 'draft') {
    $where[] = "j.status = 'draft'";
} elseif ($filters['status'] === 'reversals') {
    $where[] = "(j.reversal_of_id IS NOT NULL OR EXISTS (SELECT 1 FROM journal_entries r WHERE r.institution_id = j.institution_id AND r.reversal_of_id = j.id))";
}
if ($filters['from']) {
    $where[] = 'j.journal_date >= ?';
    $params[] = $filters['from'];
}
if ($filters['to']) {
    $where[] = 'j.journal_date <= ?';
    $params[] = $filters['to'];
}
if ($filters['source']) {
    $where[] = 'j.source_type = ?';
    $params[] = $filters['source'];
}
if ($filters['fund']) {
    $where[] = 'EXISTS (SELECT 1 FROM journal_lines fl WHERE fl.institution_id = j.institution_id AND fl.journal_entry_id = j.id AND fl.fund_id = ?)';
    $params[] = $filters['fund'];
}
if ($filters['code']) {
    $where[] = 'EXISTS (SELECT 1 FROM journal_lines cl WHERE cl.institution_id = j.institution_id AND cl.journal_entry_id = j.id AND cl.account_code = ?)';
    $params[] = $filters['code'];
}
if ($filters['q'] !== '') {
    $where[] = '(j.journal_number LIKE ? OR j.description LIKE ?)';
    $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
    array_push($params, $like, $like);
}
$whereSql = implode(' AND ', $where);

if (get_str('export', 10) === 'csv') {
    $rows = db_all(
        "SELECT j.journal_number, j.journal_date, j.status, j.source_type, j.source_id, j.description, f.fund_name, l.account_code, l.account_name,
                fa.account_name AS financial_account, l.debit_amount, l.credit_amount, l.description AS line_description
         FROM journal_entries j
         JOIN journal_lines l ON l.journal_entry_id = j.id AND l.institution_id = j.institution_id
         JOIN fund_accounts f ON f.id = l.fund_id AND f.institution_id = l.institution_id
         LEFT JOIN financial_accounts fa ON fa.id = l.financial_account_id AND fa.institution_id = l.institution_id
         WHERE {$whereSql} ORDER BY j.journal_date, j.id, l.id LIMIT 100000",
        $params
    );
    send_csv('journal_' . date('Ymd') . '.csv',
        ['Date', 'Entry', 'Status', 'Source', 'Source ID', 'Entry description', 'Fund', 'Account code', 'Account', 'Cash account', 'Debit', 'Credit', 'Line description'],
        array_map(static fn($r) => [$r['journal_date'], $r['journal_number'], $r['status'], journal_source_label($r['source_type']), $r['source_id'], $r['description'], $r['fund_name'],
            $r['account_code'], $r['account_name'], $r['financial_account'], $r['debit_amount'], $r['credit_amount'], $r['line_description']], $rows),
        [['General journal', auth_context()['label']], ['Period', ($filters['from'] ?: 'beginning') . ' to ' . ($filters['to'] ?: 'today')], ['Status', $filters['status']], ['Generated', date('Y-m-d H:i')]]
    );
}

$pagination = paginate((int) db_value("SELECT COUNT(*) FROM journal_entries j WHERE {$whereSql}", $params));
$rows = db_all(
    "SELECT j.*, CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name,
            (SELECT SUM(l.debit_amount) FROM journal_lines l WHERE l.institution_id = j.institution_id AND l.journal_entry_id = j.id) AS total,
            (SELECT GROUP_CONCAT(DISTINCT f.fund_name ORDER BY f.fund_name SEPARATOR ', ') FROM journal_lines l JOIN fund_accounts f ON f.id = l.fund_id AND f.institution_id = l.institution_id
              WHERE l.institution_id = j.institution_id AND l.journal_entry_id = j.id) AS funds,
            (SELECT r.journal_number FROM journal_entries r WHERE r.institution_id = j.institution_id AND r.reversal_of_id = j.id LIMIT 1) AS reversed_by
     FROM journal_entries j JOIN users cu ON cu.id = j.created_by
     WHERE {$whereSql} ORDER BY j.journal_date DESC, j.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$totals = db_one(
    "SELECT COALESCE(SUM(l.debit_amount), 0) AS debits, COALESCE(SUM(l.credit_amount), 0) AS credits
     FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id AND l.institution_id = j.institution_id WHERE {$whereSql}",
    $params
);
$drafts = (int) db_value("SELECT COUNT(*) FROM journal_entries WHERE institution_id = ? AND status = 'draft'", [$institutionId]);
$currency = current_currency();

$pageTitle = 'General Ledger';
$activeNav = 'ledger';
$breadcrumb = [auth_context()['label'], 'Finance'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>General Journal</h2><p><?= e(auth_context()['label']) ?> · <?= e(($filters['from'] ? format_date($filters['from']) : 'Beginning') . ' – ' . ($filters['to'] ? format_date($filters['to']) : 'today')) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions('The double-entry record behind every balance. Entries are created by the modules that own the transaction; corrections are reversals or adjusting entries, never edits.',
    button_link('ledger/trial_balance.php', 'Trial Balance', 'bi-list-columns-reverse', 'btn-outline-primary')
    . button_link('ledger/integrity.php', 'Integrity Checks', 'bi-shield-check', 'btn-outline-primary')
    . '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv', 'page' => null])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>'
    . (can('ledger.post') ? button_link('ledger/adjust.php', 'Adjusting Entry', 'bi-plus-lg') : '')) ?>

<?php if ($drafts && can('ledger.post') && $filters['status'] !== 'draft'): ?>
    <div class="notice notice-warning"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><?= e(number_format($drafts)) ?> prepared adjusting entr<?= $drafts === 1 ? 'y is' : 'ies are' ?> waiting to be reviewed and posted. <a href="<?= e(url('ledger/index.php?status=draft')) ?>">Review</a></div></div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('bi-journal-text', 'Entries', number_format($pagination['total']), 'Matching the filters') ?>
    <?= stat_card('bi-arrow-down-left', 'Debits', m($totals['debits']), 'Filtered entries') ?>
    <?= stat_card('bi-arrow-up-right', 'Credits', m($totals['credits']), 'Filtered entries', 'info') ?>
    <?= stat_card('bi-check2-circle', 'Difference', money(to_cents($totals['debits']) - to_cents($totals['credits']), $currency), to_cents($totals['debits']) === to_cents($totals['credits']) ? 'Debits equal credits' : 'Out of balance — run the integrity checks', to_cents($totals['debits']) === to_cents($totals['credits']) ? 'success' : 'danger') ?>
</div>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Entry no. or description"></div>
        <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
        <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
        <div class="filter-field"><label for="fund">Fund</label><select class="form-select" id="fund" name="fund"><?= options_html(options_from_rows(db_all('SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? ORDER BY fund_name', [$institutionId]), 'id', 'fund_name'), $filters['fund'], 'All') ?></select></div>
        <div class="filter-field"><label for="code">Account</label><select class="form-select" id="code" name="code"><?= options_html($codes, $filters['code'], 'All') ?></select></div>
        <div class="filter-field"><label for="source">Source</label><select class="form-select" id="source" name="source"><?= options_html($sourceTypes, $filters['source'], 'All') ?></select></div>
        <div class="filter-field"><label for="status">Show</label><select class="form-select" id="status" name="status"><?= options_html(['posted' => 'Posted', 'draft' => 'Prepared (not posted)', 'reversals' => 'Reversed and reversals', 'all' => 'All'], $filters['status']) ?></select></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-journal-text', 'No journal entries match') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Date · Entry</th><th>Description</th><th>Source</th><th>Fund</th><th class="text-end">Amount</th><th>Status</th><th class="d-print-none"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $link = journal_source_path($row['source_type'], (int) $row['source_id']); ?>
                    <tr>
                        <td><div><?= e(format_date($row['journal_date'])) ?></div><div class="text-muted-sm"><?= e($row['journal_number']) ?></div></td>
                        <td><div class="text-truncate" style="max-width:320px" title="<?= e($row['description']) ?>"><?= e($row['description']) ?></div><div class="text-muted-sm"><?= e($row['created_by_name']) ?></div></td>
                        <td class="text-muted-sm"><?= $link && $row['source_type'] !== 'adjustment' ? '<a href="' . e(url($link)) . '">' . e(journal_source_label($row['source_type'])) . '</a>' : e(journal_source_label($row['source_type'])) ?></td>
                        <td class="text-muted-sm"><?= e($row['funds']) ?></td>
                        <td class="text-end fw-semibold"><?= e(m($row['total'])) ?></td>
                        <td><?php if ($row['status'] === 'draft'): ?><span class="badge badge-soft-warning">Prepared</span>
                            <?php elseif ($row['reversed_by']): ?><span class="badge badge-soft-danger">Reversed</span><div class="text-muted-sm">by <?= e($row['reversed_by']) ?></div>
                            <?php elseif ($row['reversal_of_id']): ?><span class="badge badge-soft-info">Reversal</span>
                            <?php else: ?><?= status_badge('posted') ?><?php endif; ?></td>
                        <td class="actions d-print-none"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('ledger/entry.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
