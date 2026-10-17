<?php
/**
 * Liquidation summaries: released advances with verified expenses, confirmed
 * returns, items still pending review and the outstanding balance.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/advances.php';
require_institution_context();
require_permission(['liquidation.submit', 'liquidation.review', 'advances.view']);

$seeAll = can_any(['liquidation.review', 'advances.view']);
$status = get_str('status', 30);
$where = ["a.status IN ('" . implode("','", array_merge(ADVANCE_OPEN_STATUSES, ['liquidated'])) . "')"];
$params = [];
if (!$seeAll) {
    $where[] = 'a.recipient_membership_id = ?';
    $params[] = current_membership_id();
}
if ($status === 'open') {
    $where[] = "a.status <> 'liquidated'";
} elseif (in_array($status, array_merge(ADVANCE_OPEN_STATUSES, ['liquidated']), true)) {
    $where[] = 'a.status = ?';
    $params[] = $status;
}
[$rows, $pagination] = advance_queue($where, $params, "a.status = 'liquidated', a.liquidation_deadline, a.id DESC");

$scope = $seeAll ? '' : ' AND a.recipient_membership_id = ' . (int) current_membership_id();
$summary = db_one(
    "SELECT COUNT(*) AS open_count, COALESCE(SUM(a.released_amount), 0) AS released,
            SUM(a.status = 'submitted_for_review') AS to_review, SUM(a.status = 'overdue') AS overdue
     FROM cash_advances a WHERE a.institution_id = ? AND a.status IN ('" . implode("','", ADVANCE_OPEN_STATUSES) . "'){$scope}",
    [current_institution_id()]
);
$settled = db_one(
    "SELECT COALESCE(SUM(x.amount), 0) AS verified FROM cash_advance_expenses x JOIN cash_advances a ON a.id = x.cash_advance_id AND a.institution_id = x.institution_id
     WHERE a.institution_id = ? AND a.status IN ('" . implode("','", ADVANCE_OPEN_STATUSES) . "') AND x.verification_status = 'verified'{$scope}",
    [current_institution_id()]
);
$returned = db_value(
    "SELECT COALESCE(SUM(r.returned_amount), 0) FROM cash_advance_returns r JOIN cash_advances a ON a.id = r.cash_advance_id AND a.institution_id = r.institution_id
     WHERE a.institution_id = ? AND a.status IN ('" . implode("','", ADVANCE_OPEN_STATUSES) . "') AND r.status = 'confirmed'{$scope}",
    [current_institution_id()]
);
$outstanding = to_cents($summary['released']) - to_cents($settled['verified']) - to_cents($returned);

$pageTitle = 'Liquidation';
$activeNav = 'liquidation';
$breadcrumb = [auth_context()['label'], 'Finance'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Released advances and how much of each has been accounted for. Only verified receipts and confirmed returns reduce the outstanding balance.',
    (can('liquidation.submit') ? button_link('liquidation/submit.php', 'My Liquidations', 'bi-upload', 'btn-outline-primary') : '')
    . (can('liquidation.review') ? button_link('liquidation/review.php', 'Review Queue', 'bi-journal-check') : '')) ?>
<div class="stat-grid">
    <?= stat_card('bi-wallet2', 'Open Advances', number_format((int) $summary['open_count']), m($summary['released']) . ' released') ?>
    <?= stat_card('bi-hourglass-split', 'Unliquidated Balance', money(max(0, $outstanding), current_currency()), 'Released − verified − returned', $outstanding > 0 ? 'warning' : 'success') ?>
    <?= stat_card('bi-journal-check', 'Submitted for Review', number_format((int) $summary['to_review']), 'Waiting for a reviewer') ?>
    <?= stat_card('bi-alarm', 'Overdue', number_format((int) $summary['overdue']), 'Past liquidation deadline', (int) $summary['overdue'] ? 'danger' : 'success') ?>
</div>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(['open' => 'Not yet liquidated', 'released' => 'Released', 'partially_liquidated' => 'Partially liquidated', 'submitted_for_review' => 'Submitted for review', 'returned_for_correction' => 'Returned for correction', 'overdue' => 'Overdue', 'liquidated' => 'Liquidated'], $status, 'All') ?></select></div>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-journal-check', 'No liquidations', 'Released advances appear here.') ?></div>
    <?php else: ?>
        <?= advance_queue_table($rows, 'liquidation') ?>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
