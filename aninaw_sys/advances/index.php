<?php
/**
 * Cash advances: active, overdue and settled. Recipients see their own; finance sees all.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/advances.php';
require_institution_context();
require_permission(['advances.view', 'advances.request', 'advances.approve', 'advances.release', 'self.advances.view', 'liquidation.review']);

$institutionId = (int) current_institution_id();
$seeAll = can_any(['advances.view', 'advances.approve', 'advances.release', 'liquidation.review']);
$status = get_str('status', 30);
$where = ['a.institution_id = ?'];
$params = [$institutionId];
if (!$seeAll || get_str('mine', 1) === '1') {
    $where[] = 'a.recipient_membership_id = ?';
    $params[] = current_membership_id();
}
if ($status === 'open') {
    $where[] = "a.status IN ('" . implode("','", ADVANCE_OPEN_STATUSES) . "')";
} elseif (in_array($status, ['pending_approval', 'approved', 'released', 'partially_liquidated', 'submitted_for_review', 'returned_for_correction', 'liquidated', 'overdue', 'cancelled'], true)) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM cash_advances a WHERE {$whereSql}", $params));
$rows = db_all(
    "SELECT a.*, f.fund_name, CONCAT(u.first_name, ' ', u.last_name) AS recipient_name,
            (SELECT COALESCE(SUM(x.amount), 0) FROM cash_advance_expenses x WHERE x.institution_id = a.institution_id AND x.cash_advance_id = a.id AND x.verification_status = 'verified') AS verified,
            (SELECT COALESCE(SUM(r.returned_amount), 0) FROM cash_advance_returns r WHERE r.institution_id = a.institution_id AND r.cash_advance_id = a.id AND r.status = 'confirmed') AS returned
     FROM cash_advances a JOIN fund_accounts f ON f.id = a.fund_id JOIN institution_memberships m ON m.id = a.recipient_membership_id JOIN users u ON u.id = m.user_id
     WHERE {$whereSql} ORDER BY FIELD(a.status, 'partially_liquidated', 'released', 'returned_for_correction', 'approved', 'pending_approval', 'submitted_for_review', 'overdue') DESC, a.id DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$totals = db_one(
    "SELECT COALESCE(SUM(CASE WHEN a.status IN ('" . implode("','", ADVANCE_OPEN_STATUSES) . "') THEN a.released_amount END), 0) AS released_open,
            SUM(a.status = 'overdue') AS overdue, SUM(a.status = 'pending_approval') AS awaiting
     FROM cash_advances a WHERE {$whereSql}",
    $params
);
$currency = current_currency();

$pageTitle = 'Cash Advances';
$activeNav = 'advances';
$breadcrumb = [auth_context()['label'], 'Finance'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($seeAll ? 'Advances released to members and their liquidation. An advance is money the fund is still owed until it is liquidated.' : 'Your cash advances and their liquidation status.',
    (can('advances.release') ? button_link('advances/release.php', 'Awaiting Release', 'bi-box-arrow-up-right', 'btn-outline-primary') : '')
    . (can('liquidation.review') ? button_link('liquidation/review.php', 'Liquidations to Review', 'bi-journal-check', 'btn-outline-primary') : '')
    . (can('advances.request') ? button_link('advances/request.php', 'Request Advance', 'bi-plus-lg') : '')) ?>
<div class="stat-grid">
    <?= stat_card('bi-wallet2', 'Released, not liquidated', m($totals['released_open']), 'Gross released on open advances') ?>
    <?= stat_card('bi-alarm', 'Overdue', number_format((int) $totals['overdue']), 'Past liquidation deadline', (int) $totals['overdue'] ? 'danger' : 'success') ?>
    <?= stat_card('bi-hourglass-split', 'Awaiting Approval', number_format((int) $totals['awaiting']), 'Requests', 'warning') ?>
</div>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(['open' => 'Open (released)', 'pending_approval' => 'Awaiting approval', 'approved' => 'Approved (to release)', 'submitted_for_review' => 'Under review', 'returned_for_correction' => 'Returned for correction', 'overdue' => 'Overdue', 'liquidated' => 'Liquidated', 'cancelled' => 'Cancelled / rejected'], $status, 'All') ?></select></div>
        <?php if ($seeAll): ?><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="mine" name="mine" value="1" <?= get_str('mine', 1) === '1' ? 'checked' : '' ?> data-autosubmit><label class="form-check-label" for="mine">Mine only</label></div><?php endif; ?>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-wallet2', 'No cash advances') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Advance</th><th>Recipient</th><th class="text-end">Released</th><th class="text-end">Verified · Returned</th><th class="text-end">Outstanding</th><th>Deadline</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $outstanding = to_cents($row['released_amount']) - to_cents($row['verified']) - to_cents($row['returned']); ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($row['advance_number']) ?></div><div class="text-muted-sm text-truncate" style="max-width:240px"><?= e($row['fund_name'] . ' · ' . $row['purpose']) ?></div></td>
                        <td><?= e($row['recipient_name']) ?></td>
                        <td class="text-end"><?= to_cents($row['released_amount']) ? e(m($row['released_amount'])) : '<span class="text-muted-sm">Requested ' . e(m($row['requested_amount'])) . '</span>' ?></td>
                        <td class="text-end text-muted-sm"><?= e(m($row['verified']) . ' · ' . m($row['returned'])) ?></td>
                        <td class="text-end fw-semibold"><?= e(money(max(0, $outstanding), $currency)) ?><?= $outstanding < 0 ? '<div class="text-muted-sm">excess ' . e(money(-$outstanding, $currency)) . '</div>' : '' ?></td>
                        <td class="text-muted-sm<?= $row['status'] === 'overdue' ? ' text-danger' : '' ?>"><?= e(format_date($row['liquidation_deadline'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('advances/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
