<?php
/**
 * Inter-fund transfers: request, approval by a different person, posting.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/movements.php';
require_institution_context();
require_permission(['transfers.manage', 'transfers.approve', 'cashflow.view']);

$institutionId = (int) current_institution_id();
if (is_post()) {
    require_permission('transfers.manage');
    handle_post(function () {
        $id = request_fund_transfer($_POST);
        flash('success', 'Transfer requested', 'It needs approval by another authorised person before it can be posted.');
        return 'transfers/view.php?id=' . $id;
    });
}

$status = get_str('status', 20);
$where = ['t.institution_id = ?'];
$params = [$institutionId];
if (in_array($status, ['submitted', 'approved', 'posted', 'rejected', 'reversed'], true)) {
    $where[] = 't.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM fund_transfers t WHERE {$whereSql}", $params));
$rows = db_all(
    "SELECT t.*, ff.fund_name AS from_name, tf.fund_name AS to_name, CONCAT(u.first_name, ' ', u.last_name) AS requested_by_name
     FROM fund_transfers t
     JOIN fund_accounts ff ON ff.id = t.from_fund_id AND ff.institution_id = t.institution_id
     JOIN fund_accounts tf ON tf.id = t.to_fund_id AND tf.institution_id = t.institution_id
     JOIN users u ON u.id = t.requested_by
     WHERE {$whereSql} ORDER BY t.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$fundOptions = options_from_rows(db_all("SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? AND status = 'active' ORDER BY fund_name", [$institutionId]), 'id', 'fund_name');
$accountOptions = options_from_rows(db_all("SELECT * FROM financial_accounts WHERE institution_id = ? AND status = 'active' ORDER BY account_name", [$institutionId]), 'id', static fn($row) => $row['account_name'] . ' (' . humanize($row['account_type']) . ')');

$pageTitle = 'Fund Transfers';
$activeNav = 'transfers';
$breadcrumb = [auth_context()['label'], 'Finance'];
$pageScripts = array_merge($pageScripts ?? [], ['js/page_modal.js']);
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Re-allocating money from one fund to another within the same account. Transfers are not revenue or expense, and balances change only when an approved transfer is posted.',
    can('transfers.manage') ? '<a class="btn btn-primary" href="#transfer-modal" data-bs-toggle="modal" data-bs-target="#transfer-modal"><i class="bi bi-shuffle me-1" aria-hidden="true"></i>Request Transfer</a>' : '') ?>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(['submitted' => 'Awaiting approval', 'approved' => 'Approved (not posted)', 'posted' => 'Posted', 'rejected' => 'Rejected / withdrawn', 'reversed' => 'Reversed'], $status, 'All') ?></select></div>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-shuffle', 'No transfers') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Transfer</th><th>From → To</th><th>Requested by</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($row['transfer_number']) ?></div><div class="text-muted-sm"><?= e(format_date($row['transfer_date'])) ?></div></td>
                        <td><?= e($row['from_name'] . ' → ' . $row['to_name']) ?></td>
                        <td class="text-muted-sm"><?= e($row['requested_by_name']) ?></td>
                        <td class="text-end fw-semibold"><?= e(m($row['amount'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('transfers/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php if (can('transfers.manage')): ?>
<div class="modal fade" id="transfer-modal" tabindex="-1" aria-labelledby="transfer-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form method="post" enctype="multipart/form-data" class="modal-content" data-ajax data-modal-ajax>
            <?= csrf_field() ?><?= idempotency_field() ?>
            <div class="modal-header"><h2 class="modal-title h5" id="transfer-modal-title">Request Transfer</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label required" for="from_fund_id">From fund</label><select class="form-select" id="from_fund_id" name="from_fund_id" required><?= options_html($fundOptions, old('from_fund_id'), 'Select…') ?></select></div>
                <div class="mb-3"><label class="form-label required" for="to_fund_id">To fund</label><select class="form-select" id="to_fund_id" name="to_fund_id" required><?= options_html($fundOptions, old('to_fund_id'), 'Select…') ?></select></div>
                <div class="mb-3"><label class="form-label required" for="financial_account_id">Held in account</label><select class="form-select" id="financial_account_id" name="financial_account_id" required><?= options_html($accountOptions, old('financial_account_id'), 'Select…') ?></select><div class="form-text">Both funds must be authorised in this account.</div></div>
                <div class="row g-3 mb-3">
                    <div class="col-6"><label class="form-label required" for="amount">Amount</label><input class="form-control text-amount" id="amount" name="amount" required inputmode="decimal" value="<?= e(old('amount')) ?>"></div>
                    <div class="col-6"><label class="form-label required" for="transfer_date">Date</label><input class="form-control" type="date" id="transfer_date" name="transfer_date" required value="<?= e(old('transfer_date', date('Y-m-d'))) ?>"></div>
                </div>
                <div class="mb-3"><label class="form-label required" for="purpose">Purpose and authority</label><textarea class="form-control" id="purpose" name="purpose" rows="3" required minlength="10" maxlength="2000"><?= e(old('purpose')) ?></textarea></div>
                <div><?= attachment_input('attachment', 'Supporting document (e.g. resolution)') ?></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-shuffle me-1" aria-hidden="true"></i>Submit Request</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
