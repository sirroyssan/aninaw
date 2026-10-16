<?php
/**
 * Account ledger: every posted line on one ledger account, with a running balance
 * on the account's normal side, fund and date filters, CSV and print.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/ledger.php';
require_institution_context();
require_permission(['ledger.view', 'ledger.post']);

$institutionId = (int) current_institution_id();
$code = get_str('code', 60);
$account = ledger_account_by_code($institutionId, $code);
if (!$account) {
    // Lines posted under a code that has since been renamed remain viewable.
    $name = db_value('SELECT account_name FROM journal_lines WHERE institution_id = ? AND account_code = ? LIMIT 1', [$institutionId, $code]);
    $account = require_found($name !== null ? ['code' => $code, 'name' => $name, 'class' => 'unclassified'] : null);
}
$debitNormal = ledger_debit_normal($account['class']);
$filters = ['fund' => get_int('fund'), 'from' => input_date($_GET, 'from'), 'to' => input_date($_GET, 'to')];

$where = ["l.institution_id = ?", 'l.account_code = ?'];
$params = [$institutionId, $code];
if ($filters['fund']) {
    $where[] = 'l.fund_id = ?';
    $params[] = $filters['fund'];
}
if ($filters['to']) {
    $where[] = 'j.journal_date <= ?';
    $params[] = $filters['to'];
}
$whereNoFrom = implode(' AND ', $where);
$paramsNoFrom = $params;
if ($filters['from']) {
    $where[] = 'j.journal_date >= ?';
    $params[] = $filters['from'];
}
$whereSql = implode(' AND ', $where);
$fromSql = "FROM journal_lines l
            JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
            JOIN fund_accounts f ON f.id = l.fund_id AND f.institution_id = l.institution_id
            LEFT JOIN financial_accounts fa ON fa.id = l.financial_account_id AND fa.institution_id = l.institution_id";
$select = 'SELECT l.debit_amount, l.credit_amount, l.description AS line_description, j.id AS entry_id, j.journal_number, j.journal_date, j.description, j.source_type, f.fund_name, fa.account_name AS financial_account';
$order = 'ORDER BY j.journal_date, j.id, l.id';
$sign = $debitNormal ? 1 : -1;
$net = static fn(array $row): int => $sign * (to_cents($row['debit_amount']) - to_cents($row['credit_amount']));

$opening = 0;
if ($filters['from']) {
    $opening = $sign * to_cents(db_value("SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0) {$fromSql} WHERE {$whereNoFrom} AND j.journal_date < ?", array_merge($paramsNoFrom, [$filters['from']])));
}

if (get_str('export', 10) === 'csv') {
    $running = $opening;
    $rows = db_all("{$select} {$fromSql} WHERE {$whereSql} {$order} LIMIT 100000", $params);
    send_csv('ledger_' . $code . '_' . date('Ymd') . '.csv', ['Date', 'Entry', 'Description', 'Fund', 'Cash account', 'Debit', 'Credit', 'Balance'],
        array_map(static function ($r) use (&$running, $net) {
            $running += $net($r);
            return [$r['journal_date'], $r['journal_number'], $r['line_description'] ?: $r['description'], $r['fund_name'], $r['financial_account'], $r['debit_amount'], $r['credit_amount'], cents_to_decimal($running)];
        }, $rows),
        [['Account ledger', $account['code'] . ' ' . $account['name']], ['Institution', auth_context()['label']], ['Period', ($filters['from'] ?: 'beginning') . ' to ' . ($filters['to'] ?: 'today')], ['Opening balance', cents_to_decimal($opening)], ['Generated', date('Y-m-d H:i')]]
    );
}

$totals = db_one("SELECT COALESCE(SUM(l.debit_amount), 0) AS debits, COALESCE(SUM(l.credit_amount), 0) AS credits {$fromSql} WHERE {$whereSql}", $params);
$pagination = paginate((int) db_value("SELECT COUNT(*) {$fromSql} WHERE {$whereSql}", $params), 40);
$broughtForward = $opening;
if ($pagination['offset'] > 0) {
    $broughtForward += $sign * to_cents(db_value("SELECT COALESCE(SUM(x.net), 0) FROM (SELECT l.debit_amount - l.credit_amount AS net {$fromSql} WHERE {$whereSql} {$order} LIMIT {$pagination['offset']}) x", $params));
}
$rows = db_all("{$select} {$fromSql} WHERE {$whereSql} {$order} LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params);
$closing = $opening + $sign * (to_cents($totals['debits']) - to_cents($totals['credits']));
$currency = current_currency();
$codes = [];
foreach (ledger_chart($institutionId) as $chartAccount) {
    $codes[$chartAccount['code']] = $chartAccount['code'] . ' · ' . $chartAccount['name'];
}

$pageTitle = $account['code'] . ' ' . $account['name'];
$activeNav = 'ledger';
$breadcrumb = [auth_context()['label'], 'General Ledger'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Account Ledger · <?= e($account['code'] . ' ' . $account['name']) ?></h2><p><?= e(auth_context()['label']) ?> · <?= e(($filters['from'] ? format_date($filters['from']) : 'Beginning') . ' – ' . ($filters['to'] ? format_date($filters['to']) : 'today')) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions('Posted lines on this account. The balance is shown on the account\'s normal side (' . ($debitNormal ? 'debit' : 'credit') . ').',
    button_link('ledger/trial_balance.php', 'Trial Balance', 'bi-arrow-left', 'btn-outline-secondary')
    . '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv', 'page' => null])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>

<div class="stat-grid">
    <?= stat_card('bi-skip-backward', 'Opening', money($opening, $currency), $filters['from'] ? 'Before ' . format_date($filters['from']) : 'From the beginning') ?>
    <?= stat_card('bi-arrow-down-left', 'Debits', m($totals['debits']), 'In the period') ?>
    <?= stat_card('bi-arrow-up-right', 'Credits', m($totals['credits']), 'In the period', 'info') ?>
    <?= stat_card('bi-skip-forward', 'Closing', money($closing, $currency), $filters['to'] ? 'As of ' . format_date($filters['to']) : 'Current', $closing < 0 ? 'warning' : 'success') ?>
</div>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="code">Account</label><select class="form-select" id="code" name="code"><?= options_html($codes, $code) ?></select></div>
        <div class="filter-field"><label for="fund">Fund</label><select class="form-select" id="fund" name="fund"><?= options_html(options_from_rows(db_all('SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? ORDER BY fund_name', [$institutionId]), 'id', 'fund_name'), $filters['fund'], 'All funds') ?></select></div>
        <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
        <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-journal-text', 'No posted lines on this account') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Date · Entry</th><th>Description</th><th>Fund</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
                <tbody>
                <tr class="table-light"><td colspan="5" class="text-muted-sm">Balance brought forward</td><td class="text-end fw-semibold"><?= e(money($broughtForward, $currency)) ?></td></tr>
                <?php $running = $broughtForward; foreach ($rows as $row): $running += $net($row); ?>
                    <tr>
                        <td><div><?= e(format_date($row['journal_date'])) ?></div><a class="text-muted-sm" href="<?= e(url('ledger/entry.php?id=' . $row['entry_id'])) ?>"><?= e($row['journal_number']) ?></a></td>
                        <td><div class="text-truncate" style="max-width:320px"><?= e($row['line_description'] ?: $row['description']) ?></div><div class="text-muted-sm"><?= e(journal_source_label($row['source_type']) . ($row['financial_account'] ? ' · ' . $row['financial_account'] : '')) ?></div></td>
                        <td class="text-muted-sm"><?= e($row['fund_name']) ?></td>
                        <td class="text-end"><?= to_cents($row['debit_amount']) ? e(m($row['debit_amount'])) : '' ?></td>
                        <td class="text-end"><?= to_cents($row['credit_amount']) ? e(m($row['credit_amount'])) : '' ?></td>
                        <td class="text-end fw-semibold"><?= e(money($running, $currency)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
