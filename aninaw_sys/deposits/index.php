<?php
/**
 * Bank and e-wallet deposits (movements of existing cash between financial accounts).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/movements.php';
require_institution_context();
require_permission(['deposits.manage', 'cashflow.view']);

$institutionId = (int) current_institution_id();
if (is_post()) {
    require_permission('deposits.manage');
    handle_post(function () {
        $id = record_deposit($_POST);
        flash('success', 'Deposit recorded', 'Another authorised person must verify it before balances change.');
        return 'deposits/view.php?id=' . $id;
    });
}

$status = get_str('status', 30);
$fundFilter = get_int('fund');
$from = input_date($_GET, 'from');
$to = input_date($_GET, 'to');
$where = ['d.institution_id = ?'];
$params = [$institutionId];
if (in_array($status, ['recorded', 'pending_confirmation', 'confirmed', 'reversed'], true)) {
    $where[] = 'd.status = ?';
    $params[] = $status;
}
if ($fundFilter) {
    $where[] = "EXISTS (SELECT 1 FROM cash_flow_transactions c WHERE c.institution_id = d.institution_id AND c.source_type = 'deposit' AND c.source_id = d.id AND c.fund_id = ?)";
    $params[] = $fundFilter;
}
if ($from) {
    $where[] = 'd.deposit_date >= ?';
    $params[] = $from;
}
if ($to) {
    $where[] = 'd.deposit_date <= ?';
    $params[] = $to;
}
$whereSql = implode(' AND ', $where);
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM deposits d WHERE {$whereSql}", $params));
$rows = db_all(
    "SELECT d.*, s.account_name AS source_name, t.account_name AS destination_name, CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name,
            (SELECT f.fund_name FROM cash_flow_transactions c JOIN fund_accounts f ON f.id = c.fund_id WHERE c.institution_id = d.institution_id AND c.source_type = 'deposit' AND c.source_id = d.id LIMIT 1) AS fund_name
     FROM deposits d
     JOIN financial_accounts s ON s.id = d.source_account_id AND s.institution_id = d.institution_id
     JOIN financial_accounts t ON t.id = d.destination_account_id AND t.institution_id = d.institution_id
     JOIN users u ON u.id = d.recorded_by
     WHERE {$whereSql} ORDER BY d.deposit_date DESC, d.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$fundOptions = options_from_rows(db_all("SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? AND status = 'active' ORDER BY fund_name", [$institutionId]), 'id', 'fund_name');
$sourceAccounts = options_from_rows(usable_financial_accounts(), 'id', static fn($row) => financial_account_label($row));
$destinationAccounts = options_from_rows(db_all("SELECT * FROM financial_accounts WHERE institution_id = ? AND status = 'active' ORDER BY account_name", [$institutionId]), 'id', static fn($row) => $row['account_name'] . ' (' . humanize($row['account_type']) . ')');

$pageTitle = 'Deposits';
$activeNav = 'deposits';
$breadcrumb = [auth_context()['label'], 'Finance'];
$pageScripts = array_merge($pageScripts ?? [], ['js/page_modal.js']);
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Moving collected cash into a bank, e-wallet or another account. A deposit is a movement between accounts, not income.',
    can('deposits.manage') ? '<a class="btn btn-primary" href="#deposit-modal" data-bs-toggle="modal" data-bs-target="#deposit-modal"><i class="bi bi-bank me-1" aria-hidden="true"></i>Record Deposit</a>' : '') ?>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="fund">Fund</label><select class="form-select" id="fund" name="fund"><?= options_html($fundOptions, $fundFilter, 'All') ?></select></div>
        <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['recorded' => 'Recorded', 'pending_confirmation' => 'Pending confirmation', 'confirmed' => 'Confirmed', 'reversed' => 'Reversed'], $status, 'All') ?></select></div>
        <button class="btn btn-outline-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-bank', 'No deposits') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Deposit</th><th>Fund</th><th>From → To</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($row['deposit_number']) ?></div><div class="text-muted-sm"><?= e(format_date($row['deposit_date']) . ' · ref ' . $row['reference_number']) ?></div></td>
                        <td><?= e($row['fund_name'] ?: '—') ?></td>
                        <td class="text-muted-sm"><?= e($row['source_name'] . ' → ' . $row['destination_name']) ?></td>
                        <td class="text-end fw-semibold"><?= e(m($row['amount'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('deposits/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php if (can('deposits.manage')): ?>
<div class="modal fade" id="deposit-modal" tabindex="-1" aria-labelledby="deposit-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form method="post" enctype="multipart/form-data" class="modal-content" data-ajax data-modal-ajax>
            <?= csrf_field() ?><?= idempotency_field() ?>
            <div class="modal-header"><h2 class="modal-title h5" id="deposit-modal-title">Record Deposit</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label required" for="fund_id">Fund</label><select class="form-select" id="fund_id" name="fund_id" required><?= options_html($fundOptions, old('fund_id'), 'Select…') ?></select></div>
                <div class="mb-3"><label class="form-label required" for="source_account_id">From (cash custody)</label><select class="form-select" id="source_account_id" name="source_account_id" required><?= options_html($sourceAccounts, old('source_account_id'), 'Select…') ?></select></div>
                <div class="mb-3"><label class="form-label required" for="destination_account_id">Into</label><select class="form-select" id="destination_account_id" name="destination_account_id" required><?= options_html($destinationAccounts, old('destination_account_id'), 'Select…') ?></select></div>
                <div class="row g-3 mb-3">
                    <div class="col-6"><label class="form-label required" for="amount">Amount</label><input class="form-control text-amount" id="amount" name="amount" required inputmode="decimal" value="<?= e(old('amount')) ?>"></div>
                    <div class="col-6"><label class="form-label required" for="deposit_date">Date</label><input class="form-control" type="date" id="deposit_date" name="deposit_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('deposit_date', date('Y-m-d'))) ?>"></div>
                </div>
                <div class="mb-3"><label class="form-label required" for="reference_number">Bank / slip reference</label><input class="form-control" id="reference_number" name="reference_number" required maxlength="150" value="<?= e(old('reference_number')) ?>"></div>
                <div><?= attachment_input('attachment', 'Deposit slip') ?></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-bank me-1" aria-hidden="true"></i>Record Deposit</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
