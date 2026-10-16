<?php
/**
 * Trial balance from posted journal lines, by account, optionally for one fund
 * and as of a date. Total debit balances must equal total credit balances.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/ledger.php';
require_institution_context();
require_permission(['ledger.view', 'ledger.post']);

$institutionId = (int) current_institution_id();
$fundId = get_int('fund');
$asOf = input_date($_GET, 'as_of') ?? date('Y-m-d');
$fund = $fundId ? require_found(db_one('SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? AND id = ?', [$institutionId, $fundId])) : null;
$rows = trial_balance($fundId, $asOf);
$debitTotal = array_sum(array_column($rows, 'debit_balance'));
$creditTotal = array_sum(array_column($rows, 'credit_balance'));
$currency = current_currency();
$classLabels = ['asset' => 'Assets', 'equity' => 'Fund balance', 'income' => 'Income', 'expense' => 'Expenses', 'unclassified' => 'Not in the chart of accounts'];

if (get_str('export', 10) === 'csv') {
    send_csv('trial_balance_' . $asOf . '.csv', ['Account code', 'Account', 'Class', 'Total debits', 'Total credits', 'Debit balance', 'Credit balance'],
        array_map(static fn($r) => [$r['account_code'], $r['account_name'], $r['class'], $r['debits'], $r['credits'], cents_to_decimal($r['debit_balance']), cents_to_decimal($r['credit_balance'])], $rows),
        [['Trial balance', auth_context()['label']], ['Fund', $fund['fund_name'] ?? 'All funds'], ['As of', $asOf], ['Generated', date('Y-m-d H:i')]]
    );
}

$pageTitle = 'Trial Balance';
$activeNav = 'ledger';
$breadcrumb = [auth_context()['label'], 'General Ledger'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Trial Balance</h2><p><?= e(auth_context()['label']) ?> · <?= e($fund['fund_name'] ?? 'All funds') ?> · As of <?= e(format_date($asOf)) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions('Balances of every ledger account from posted entries only. Prepared (unposted) entries are excluded.',
    button_link('ledger/index.php', 'Journal', 'bi-arrow-left', 'btn-outline-secondary')
    . '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv'])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>

<div class="stat-grid">
    <?= stat_card('bi-arrow-down-left', 'Debit balances', money($debitTotal, $currency), 'Assets and expenses') ?>
    <?= stat_card('bi-arrow-up-right', 'Credit balances', money($creditTotal, $currency), 'Fund balance and income', 'info') ?>
    <?= stat_card($debitTotal === $creditTotal ? 'bi-check2-circle' : 'bi-exclamation-triangle', $debitTotal === $creditTotal ? 'In balance' : 'Out of balance', money(abs($debitTotal - $creditTotal), $currency), 'Difference', $debitTotal === $creditTotal ? 'success' : 'danger') ?>
</div>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="fund">Fund</label><select class="form-select" id="fund" name="fund"><?= options_html(options_from_rows(db_all('SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? ORDER BY fund_name', [$institutionId]), 'id', 'fund_name'), $fundId, 'All funds') ?></select></div>
        <div class="filter-field"><label for="as_of">As of</label><input class="form-control" type="date" id="as_of" name="as_of" value="<?= e($asOf) ?>"></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-list-columns-reverse', 'No posted entries yet', 'Balances appear once transactions are posted.') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Code</th><th>Account</th><th class="text-end">Total debits</th><th class="text-end">Total credits</th><th class="text-end">Debit balance</th><th class="text-end">Credit balance</th></tr></thead>
                <tbody>
                <?php $class = null; foreach ($rows as $row): ?>
                    <?php if ($row['class'] !== $class): $class = $row['class']; ?><tr class="table-light"><td colspan="6" class="fw-semibold text-muted-sm"><?= e($classLabels[$class] ?? humanize($class)) ?></td></tr><?php endif; ?>
                    <tr>
                        <td><a href="<?= e(url('ledger/account.php?' . http_build_query(array_filter(['code' => $row['account_code'], 'fund' => $fundId, 'to' => $asOf])))) ?>"><?= e($row['account_code']) ?></a></td>
                        <td><?= e($row['account_name']) ?></td>
                        <td class="text-end text-muted-sm"><?= e(m($row['debits'])) ?></td>
                        <td class="text-end text-muted-sm"><?= e(m($row['credits'])) ?></td>
                        <td class="text-end fw-semibold"><?= $row['debit_balance'] ? e(money($row['debit_balance'], $currency)) : '' ?></td>
                        <td class="text-end fw-semibold"><?= $row['credit_balance'] ? e(money($row['credit_balance'], $currency)) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold"><td colspan="4" class="text-end">Totals</td><td class="text-end"><?= e(money($debitTotal, $currency)) ?></td><td class="text-end"><?= e(money($creditTotal, $currency)) ?></td></tr></tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
