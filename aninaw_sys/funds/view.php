<?php
/**
 * Fund statement: what belongs to the fund, where it is held, who is accountable,
 * the posted ledger movements with a running balance, account mappings, opening
 * balances, access assignments and status.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/funds.php';
require_institution_context();
require_permission('funds.view');

$fundId = require_get_id();
$fund = require_found(find_visible_fund($fundId));
$institutionId = (int) current_institution_id();
$canManage = can('funds.manage');

if (is_post()) {
    require_permission('funds.manage');
    $action = post_str('action', 30);
    handle_post(function () use ($action, $fundId) {
        switch ($action) {
            case 'status':
                set_fund_status($fundId, post_str('status', 20), post_str('reason', 1000));
                flash('success', 'Fund status updated');
                break;
            case 'add_mapping':
                add_fund_mapping($fundId, $_POST);
                flash('success', 'Account mapped to fund');
                break;
            case 'end_mapping':
                end_fund_mapping(post_int('mapping_id') ?? 0);
                flash('success', 'Mapping ended');
                break;
            case 'opening_balance':
                post_opening_balance($fundId, $_POST);
                flash('success', 'Opening balance posted');
                break;
            case 'reverse_opening':
                reverse_opening_balance(post_int('mapping_id') ?? 0, post_str('reason', 1000));
                flash('success', 'Opening balance reversed');
                break;
            case 'grant_access':
                grant_fund_access($fundId, $_POST);
                flash('success', 'Access granted');
                break;
            case 'revoke_access':
                revoke_fund_access(post_int('assignment_id') ?? 0);
                flash('success', 'Access revoked');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'funds/view.php?id=' . $fundId;
    }, in_array($action, ['add_mapping', 'opening_balance', 'grant_access'], true));
}

$summary = fund_summary($fundId);

// Statement: posted asset lines (cash and advances) of this fund, oldest first, with running balance.
$from = input_date($_GET, 'from');
$to = input_date($_GET, 'to');
$assetCodes = array_merge(cash_account_codes($institutionId), [ledger_account($institutionId, 'advances')['code']]);
$codeMarks = implode(',', array_fill(0, count($assetCodes), '?'));
$statementWhere = "l.institution_id = ? AND l.fund_id = ? AND l.account_code IN ({$codeMarks})";
$statementParams = array_merge([$institutionId, $fundId], $assetCodes);
$balanceBefore = 0;
if ($from) {
    $balanceBefore = to_cents(db_value(
        "SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0) FROM journal_lines l
         JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
         WHERE {$statementWhere} AND j.journal_date < ?", array_merge($statementParams, [$from])
    ));
    $statementWhere .= ' AND j.journal_date >= ?';
    $statementParams[] = $from;
}
if ($to) {
    $statementWhere .= ' AND j.journal_date <= ?';
    $statementParams[] = $to;
}
$baseSql = "FROM journal_lines l
            JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
            LEFT JOIN financial_accounts fa ON fa.id = l.financial_account_id AND fa.institution_id = l.institution_id
            WHERE {$statementWhere}";
$pagination = paginate((int) db_value("SELECT COUNT(*) {$baseSql}", $statementParams), 25);
if ($pagination['offset'] > 0) {
    $balanceBefore += to_cents(db_value(
        "SELECT COALESCE(SUM(t.amount), 0) FROM (SELECT l.debit_amount - l.credit_amount AS amount {$baseSql}
         ORDER BY j.journal_date, j.id, l.id LIMIT {$pagination['offset']}) t", $statementParams
    ));
}
$lines = db_all(
    "SELECT j.journal_number, j.journal_date, j.description, j.source_type, j.reversal_of_id, l.account_name, l.debit_amount, l.credit_amount, fa.account_name AS financial_account
     {$baseSql} ORDER BY j.journal_date, j.id, l.id LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $statementParams
);

$mappings = db_all(
    "SELECT fm.*, fa.account_name, fa.account_type, fa.status AS account_status, CONCAT(u.first_name, ' ', u.last_name) AS custodian_name,
            (SELECT j.id FROM journal_entries j WHERE j.institution_id = fm.institution_id AND j.source_type = 'opening_balance' AND j.source_id = fm.id
               AND j.reversal_of_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.reversal_of_id = j.id) LIMIT 1) AS opening_entry
     FROM fund_account_mappings fm
     JOIN financial_accounts fa ON fa.id = fm.financial_account_id AND fa.institution_id = fm.institution_id
     LEFT JOIN institution_memberships m ON m.id = fa.custodian_membership_id AND m.institution_id = fa.institution_id
     LEFT JOIN users u ON u.id = m.user_id
     WHERE fm.institution_id = ? AND fm.fund_id = ? ORDER BY fm.valid_until IS NULL DESC, fm.valid_from DESC",
    [$institutionId, $fundId]
);
$access = db_all(
    "SELECT fa.*, u.first_name, u.last_name FROM fund_access_assignments fa
     JOIN institution_memberships m ON m.id = fa.membership_id AND m.institution_id = fa.institution_id JOIN users u ON u.id = m.user_id
     WHERE fa.institution_id = ? AND fa.fund_id = ? AND fa.status = 'active' ORDER BY u.last_name",
    [$institutionId, $fundId]
);
$unmappedAccounts = options_from_rows(db_all("SELECT * FROM financial_accounts WHERE institution_id = ? AND status = 'active' ORDER BY account_name", [$institutionId]), 'id', 'account_name');
$openableMappings = array_filter($mappings, static fn($m) => !$m['opening_entry'] && ($m['valid_until'] === null || $m['valid_until'] >= date('Y-m-d')));
$currency = current_currency();
$transitions = ['draft' => ['active' => 'Activate'], 'pending' => ['active' => 'Activate'], 'active' => ['suspended' => 'Suspend', 'closed' => 'Close'], 'suspended' => ['active' => 'Reactivate', 'closed' => 'Close']];

$pageTitle = $fund['fund_name'];
$activeNav = 'funds';
$breadcrumb = [auth_context()['label'], 'Funds'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2><?= e($fund['fund_name']) ?> — Fund Statement</h2><p><?= e(auth_context()['label']) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions($fund['fund_code'] . ($fund['fund_category'] ? ' · ' . $fund['fund_category'] : '') . ' · Custodian: ' . $fund['custodian_name'],
    button_link('funds/index.php', 'All Funds', 'bi-arrow-left', 'btn-outline-secondary')
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>'
    . ($canManage && $fund['status'] !== 'closed' ? button_link('funds/create.php?id=' . $fundId, 'Edit', 'bi-pencil', 'btn-outline-primary') : '')) ?>

<?php if ($fund['status'] !== 'active'): ?>
    <div class="notice notice-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>This fund is <strong><?= e($fund['status']) ?></strong>. Collections, expenses and transfers can only use active funds.</div></div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('bi-wallet', 'Belongs to Fund', money($summary['total'], $currency), 'Cash held + advances outstanding') ?>
    <?= stat_card('bi-cash-stack', 'Available Cash', money($summary['cash'], $currency), count($summary['holdings']) . ' account(s) hold it') ?>
    <?= stat_card('bi-cash-coin', 'Collected', money($summary['collected'], $currency), 'Posted collections, net of reversals') ?>
    <?= stat_card('bi-receipt', 'Spent', money($summary['spent'], $currency), 'Posted expenses incl. liquidated advances', 'info') ?>
    <?= stat_card('bi-arrow-left-right', 'Turned Over', money($summary['turned_over'], $currency), 'Custody moved to cashiers (not income)', 'info') ?>
    <?= stat_card('bi-bank', 'Deposited', money($summary['deposited'], $currency), 'Moved into banks/e-wallets (not income)', 'info') ?>
    <?= stat_card('bi-wallet2', 'Advances Outstanding', money($summary['advances'], $currency), 'Released, not yet liquidated', $summary['advances'] > 0 ? 'warning' : 'success') ?>
    <?= stat_card('bi-flag', 'Opening Balances', money($summary['opening'], $currency), $fund['opening_date'] ? 'Since ' . format_date($fund['opening_date']) : 'None posted') ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Where the money is held</h3></div>
            <?php if (!$summary['holdings']): ?>
                <div class="card-body"><?= empty_state('bi-safe', 'No cash held yet', 'Balances appear once collections or opening balances are posted.') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Financial account</th><th>Accountable custodian</th><th class="text-end">Balance</th></tr></thead>
                        <tbody>
                        <?php foreach ($summary['holdings'] as $row): ?>
                            <tr>
                                <td><a class="fw-semibold" href="<?= e(url('funds/account.php?id=' . $row['id'])) ?>"><?= e($row['account_name']) ?></a><div class="text-muted-sm"><?= e(FINANCIAL_ACCOUNT_TYPES[$row['account_type']] ?? $row['account_type']) ?></div></td>
                                <td><?= e($row['custodian_name'] ?: '—') ?></td>
                                <td class="text-end fw-semibold"><?= e(m($row['balance'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card panel">
            <div class="card-header"><h3>Statement (posted)</h3></div>
            <form class="filter-bar" method="get">
                <input type="hidden" name="id" value="<?= e($fundId) ?>">
                <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
                <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
                <button class="btn btn-outline-primary" type="submit">Apply</button>
            </form>
            <?php if (!$lines): ?>
                <div class="card-body"><?= empty_state('bi-journal', 'No posted movements in this period') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Date · Entry</th><th>Description</th><th>Account</th><th class="text-end">In</th><th class="text-end">Out</th><th class="text-end">Balance</th></tr></thead>
                        <tbody>
                        <tr class="table-light"><td colspan="5" class="text-muted-sm">Balance brought forward</td><td class="text-end fw-semibold"><?= e(money($balanceBefore, $currency)) ?></td></tr>
                        <?php $running = $balanceBefore; foreach ($lines as $row): $running += to_cents($row['debit_amount']) - to_cents($row['credit_amount']); ?>
                            <tr>
                                <td><div><?= e(format_date($row['journal_date'])) ?></div><div class="text-muted-sm"><?= e($row['journal_number']) ?><?= $row['reversal_of_id'] ? ' · reversal' : '' ?></div></td>
                                <td class="text-muted-sm" style="max-width:300px"><?= e($row['description']) ?></td>
                                <td class="text-muted-sm"><?= e($row['account_name']) ?><?= $row['financial_account'] ? '<br>' . e($row['financial_account']) : '' ?></td>
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
    </div>

    <div class="col-xl-4">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Status</h3><?= status_badge($fund['status']) ?></div>
            <div class="card-body">
                <p class="text-muted-sm mb-2"><?= e($fund['purpose'] ?: 'No purpose recorded.') ?></p>
                <?php if ($canManage && isset($transitions[$fund['status']])): ?>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($transitions[$fund['status']] as $next => $label): ?>
                            <form method="post" data-confirm="<?= e($label) ?> this fund?" <?= $next !== 'active' ? 'data-reason="Reason" data-danger' : '' ?>>
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="status"><input type="hidden" name="status" value="<?= e($next) ?>">
                                <button class="btn <?= $next === 'active' ? 'btn-primary' : 'btn-outline-danger' ?> btn-sm" type="submit"><?= e($label) ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card panel mb-3">
            <div class="card-header"><h3>Authorised accounts</h3></div>
            <?php if (!$mappings): ?>
                <div class="card-body"><?= empty_state('bi-link-45deg', 'No accounts mapped', 'Map at least one financial account that may hold this fund\'s money.') ?></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($mappings as $row): ?>
                        <?php $current = $row['valid_until'] === null || $row['valid_until'] >= date('Y-m-d'); ?>
                        <li>
                            <div>
                                <div class="fw-semibold"><?= e($row['account_name']) ?></div>
                                <div class="text-muted-sm"><?= e(($row['custodian_name'] ?: 'No custodian') . ' · ' . format_date($row['valid_from']) . ' – ' . ($row['valid_until'] ? format_date($row['valid_until']) : 'open')) ?></div>
                                <?php if ($row['opening_entry']): ?><span class="badge badge-soft-success">Opening balance posted</span><?php endif; ?>
                            </div>
                            <?php if ($canManage): ?>
                                <div class="d-flex flex-column gap-1">
                                    <?php if ($current): ?>
                                        <form method="post" data-confirm="End this mapping?" data-confirm-text="New transactions for this fund will no longer be allowed in this account.">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="end_mapping"><input type="hidden" name="mapping_id" value="<?= e($row['id']) ?>">
                                            <button class="btn btn-outline-secondary btn-xs" type="submit">End</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($row['opening_entry']): ?>
                                        <form method="post" data-confirm="Reverse the opening balance?" data-reason="Why is the opening balance being reversed?" data-danger>
                                            <?= csrf_field() ?><input type="hidden" name="action" value="reverse_opening"><input type="hidden" name="mapping_id" value="<?= e($row['id']) ?>">
                                            <button class="btn btn-outline-danger btn-xs" type="submit">Reverse opening</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canManage && $fund['status'] !== 'closed'): ?>
                <form method="post" class="card-body border-top">
                    <?= csrf_field() ?><?= idempotency_field() ?>
                    <input type="hidden" name="action" value="add_mapping">
                    <label class="form-label" for="map-account">Map financial account</label>
                    <select class="form-select mb-2" id="map-account" name="financial_account_id" required><?= options_html($unmappedAccounts, null, 'Select account…') ?></select>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><input class="form-control" type="date" name="valid_from" value="<?= e(date('Y-m-d')) ?>" aria-label="Valid from"></div>
                        <div class="col-6"><input class="form-control" type="date" name="valid_until" aria-label="Valid until"></div>
                    </div>
                    <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Map Account</button>
                    <?php if (!$unmappedAccounts): ?><p class="text-muted-sm mt-2 mb-0">No financial accounts yet. <a href="<?= e(url('funds/accounts.php')) ?>">Create one</a>.</p><?php endif; ?>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($canManage && $openableMappings && in_array($fund['status'], ['draft', 'pending', 'active'], true)): ?>
            <form method="post" class="card panel form-card mb-3" data-confirm="Post this opening balance?" data-confirm-text="It is posted to the ledger immediately. A mistake can only be corrected by a reversal with a reason.">
                <?= csrf_field() ?><?= idempotency_field() ?>
                <input type="hidden" name="action" value="opening_balance">
                <div class="card-header"><h3>Record Opening Balance</h3></div>
                <div class="card-body">
                    <p class="text-muted-sm">For money the fund already held before using ANINAW. One opening balance per mapped account.</p>
                    <div class="mb-2"><label class="form-label required" for="ob-account">Held in</label>
                        <select class="form-select" id="ob-account" name="financial_account_id" required><?= options_html(options_from_rows($openableMappings, 'financial_account_id', 'account_name'), null, 'Select…') ?></select></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label required" for="ob-amount">Amount</label><input class="form-control text-amount" id="ob-amount" name="amount" inputmode="decimal" required placeholder="0.00"></div>
                        <div class="col-6"><label class="form-label required" for="ob-date">As of</label><input class="form-control" type="date" id="ob-date" name="opening_date" required value="<?= e(date('Y-m-d')) ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label required" for="ob-note">Basis</label><input class="form-control" id="ob-note" name="note" required maxlength="500" placeholder="e.g. Cash count sheet signed 2026-10-01"></div>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-journal-plus me-1" aria-hidden="true"></i>Post Opening Balance</button>
                </div>
            </form>
        <?php endif; ?>

        <div class="card panel">
            <div class="card-header"><h3>Fund handlers</h3></div>
            <?php if (!$access): ?>
                <div class="card-body"><p class="text-muted-sm mb-0">No additional handlers. Members with finance roles can work with all funds.</p></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($access as $row): ?>
                        <li><div><div class="fw-semibold"><?= e(person_name($row)) ?></div><div class="text-muted-sm"><?= e(FUND_ACCESS_LEVELS[$row['permission_level']] ?? $row['permission_level']) ?><?= $row['valid_until'] ? ' · until ' . e(format_date($row['valid_until'])) : '' ?></div></div>
                            <?php if ($canManage): ?>
                                <form method="post" data-confirm="Revoke this access?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="revoke_access"><input type="hidden" name="assignment_id" value="<?= e($row['id']) ?>"><button class="btn btn-outline-danger btn-xs" type="submit">Revoke</button></form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <form method="post" class="card-body border-top">
                    <?= csrf_field() ?><?= idempotency_field() ?>
                    <input type="hidden" name="action" value="grant_access">
                    <label class="form-label" for="access-member">Grant access</label>
                    <select class="form-select mb-2" id="access-member" name="membership_id" required><?= options_html(member_options(), null, 'Member…') ?></select>
                    <select class="form-select mb-2" name="permission_level" required aria-label="Access level"><?= options_html(FUND_ACCESS_LEVELS, 'view') ?></select>
                    <input class="form-control mb-2" type="date" name="valid_until" aria-label="Valid until (optional)">
                    <button class="btn btn-outline-primary w-100" type="submit">Grant</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
