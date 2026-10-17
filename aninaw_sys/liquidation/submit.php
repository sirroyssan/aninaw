<?php
/**
 * The recipient's open advances: add receipts and returned cash, then submit for review.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/advances.php';
require_institution_context();
require_permission('liquidation.submit');

[$rows, $pagination] = advance_queue(
    ['a.recipient_membership_id = ?', "a.status IN ('" . implode("','", ADVANCE_OPEN_STATUSES) . "')"],
    [current_membership_id()],
    "FIELD(a.status, 'returned_for_correction', 'overdue') DESC, a.liquidation_deadline, a.id"
);

$pageTitle = 'My Liquidations';
$activeNav = 'liquidation';
$breadcrumb = [auth_context()['label'], 'Liquidation'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Open an advance to add each expense with its receipt and record any unused cash you return, then submit it for review.', button_link('liquidation/index.php', 'Summary', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<?php if (array_filter($rows, static fn($row) => $row['status'] === 'returned_for_correction')): ?>
    <div class="notice notice-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>A reviewer returned at least one liquidation for correction. Open it to see the remarks.</div></div>
<?php endif; ?>
<div class="card panel" data-modal-body>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-check2-circle', 'Nothing to liquidate', 'Advances released to you will appear here until they are fully liquidated.') ?></div>
    <?php else: ?>
        <?= advance_queue_table($rows, 'liquidation', 'Liquidate') ?>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
