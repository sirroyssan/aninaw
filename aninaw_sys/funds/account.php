<?php
/**
 * One financial account: balance per fund, authorised funds and its cash movements.
 * Custodians can open their own accounts; finance staff can open all of them.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/funds.php';
require_institution_context();

$institutionId = (int) current_institution_id();
$accountId = require_get_id();
$account = require_found(db_one(
    "SELECT fa.*, CONCAT(u.first_name, ' ', u.last_name) AS custodian_name FROM financial_accounts fa
     LEFT JOIN institution_memberships m ON m.id = fa.custodian_membership_id AND m.institution_id = fa.institution_id
     LEFT JOIN users u ON u.id = m.user_id WHERE fa.institution_id = ? AND fa.id = ?",
    [$institutionId, $accountId]
));
$isCustodian = (int) $account['custodian_membership_id'] === current_membership_id();
if (!$isCustodian) {
    require_permission(['funds.view_all', 'funds.manage', 'reconciliation.manage']);
}

if (is_post()) {
    require_permission('funds.manage');
    handle_post(function () use ($accountId) {
        set_financial_account_status($accountId, post_str('status', 20), post_str('reason', 1000));
        flash('success', 'Account status updated');
        return 'funds/account.php?id=' . $accountId;
    }, false);
}

$byFund = db_all(
    "SELECT f.id, f.fund_name, f.fund_code, COALESCE(SUM(l.debit_amount - l.credit_amount), 0) AS balance
     FROM journal_lines l
     JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
     JOIN fund_accounts f ON f.id = l.fund_id AND f.institution_id = l.institution_id
     WHERE l.institution_id = ? AND l.financial_account_id = ?
     GROUP BY f.id, f.fund_name, f.fund_code ORDER BY f.fund_name",
    [$institutionId, $accountId]
);
$mappings = db_all(
    "SELECT fm.valid_from, fm.valid_until, f.id AS fund_id, f.fund_name FROM fund_account_mappings fm
     JOIN fund_accounts f ON f.id = fm.fund_id AND f.institution_id = fm.institution_id
     WHERE fm.institution_id = ? AND fm.financial_account_id = ? ORDER BY f.fund_name",
    [$institutionId, $accountId]
);
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM cash_flow_transactions WHERE institution_id = ? AND financial_account_id = ?", [$institutionId, $accountId]), 25);
$movements = db_all(
    "SELECT c.transaction_number, c.transaction_date, c.transaction_type, c.direction, c.amount, c.status, c.source_type, f.fund_name
     FROM cash_flow_transactions c JOIN fund_accounts f ON f.id = c.fund_id AND f.institution_id = c.institution_id
     WHERE c.institution_id = ? AND c.financial_account_id = ?
     ORDER BY c.transaction_date DESC, c.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    [$institutionId, $accountId]
);
$total = array_sum(array_map(static fn($row) => to_cents($row['balance']), $byFund));

$pageTitle = $account['account_name'];
$activeNav = 'accounts';
$breadcrumb = [auth_context()['label'], 'Financial Accounts'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions((FINANCIAL_ACCOUNT_TYPES[$account['account_type']] ?? $account['account_type']) . ($account['account_reference'] ? ' · ' . $account['account_reference'] : '') . ' · Custodian: ' . ($account['custodian_name'] ?: 'none'),
    (can_any(['funds.view_all', 'funds.manage']) ? button_link('funds/accounts.php', 'All Accounts', 'bi-arrow-left', 'btn-outline-secondary') : '')) ?>

<div class="stat-grid">
    <?= stat_card('bi-safe', 'Balance (posted)', money($total, current_currency()), count($byFund) . ' fund(s)') ?>
    <?= stat_card('bi-person-badge', 'Accountable', $account['custodian_name'] ?: 'No custodian', '', 'info') ?>
    <?= stat_card('bi-flag', 'Status', humanize($account['status']), '', $account['status'] === 'active' ? 'success' : 'warning') ?>
</div>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Balance by fund</h3></div>
            <?php if (!$byFund): ?>
                <div class="card-body"><?= empty_state('bi-wallet', 'No posted money') ?></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($byFund as $row): ?>
                        <li><span><?= e($row['fund_name']) ?></span><span class="fw-semibold"><?= e(m($row['balance'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="card panel mb-3">
            <div class="card-header"><h3>Authorised funds</h3></div>
            <?php if (!$mappings): ?>
                <div class="card-body"><p class="text-muted-sm mb-0">Not mapped to any fund yet. Map it from a fund's page.</p></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($mappings as $row): ?>
                        <li><span><?= e($row['fund_name']) ?></span><span class="text-muted-sm"><?= e(format_date($row['valid_from']) . ' – ' . ($row['valid_until'] ? format_date($row['valid_until']) : 'open')) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php if (can('funds.manage') && $account['status'] !== 'closed'): ?>
            <form method="post" class="card panel" data-confirm="Change account status?" data-reason="Reason for the change">
                <?= csrf_field() ?>
                <div class="card-header"><h3>Account status</h3></div>
                <div class="card-body d-flex gap-2">
                    <select class="form-select" name="status" aria-label="Status"><?= options_html(['active' => 'Active', 'inactive' => 'Inactive', 'closed' => 'Closed (empty accounts only)'], $account['status']) ?></select>
                    <button class="btn btn-outline-primary" type="submit">Apply</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <div class="col-xl-8">
        <div class="card panel">
            <div class="card-header"><h3>Cash movements</h3></div>
            <?php if (!$movements): ?>
                <div class="card-body"><?= empty_state('bi-activity', 'No movements yet') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Date · No.</th><th>Fund</th><th>Type</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($movements as $row): ?>
                            <tr>
                                <td><div><?= e(format_date($row['transaction_date'])) ?></div><div class="text-muted-sm"><?= e($row['transaction_number']) ?></div></td>
                                <td><?= e($row['fund_name']) ?></td>
                                <td><?= e(humanize($row['transaction_type'])) ?><?= str_ends_with($row['source_type'], '_reversal') ? ' <span class="badge badge-soft-neutral">reversal</span>' : '' ?></td>
                                <td class="text-end fw-semibold <?= $row['direction'] === 'inflow' ? 'text-success' : 'text-danger' ?>"><?= $row['direction'] === 'inflow' ? '+' : '−' ?><?= e(m($row['amount'])) ?></td>
                                <td><?= status_badge($row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?= pagination_links($pagination) ?>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
