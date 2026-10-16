<?php
/**
 * Reviewer queue: liquidations submitted for review and advances with returns
 * waiting for confirmation. Reviewers never review their own advance.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/advances.php';
require_institution_context();
require_permission('liquidation.review');

[$rows, $pagination] = advance_queue(
    ['a.recipient_membership_id <> ?', "(a.status = 'submitted_for_review' OR EXISTS (SELECT 1 FROM cash_advance_returns r WHERE r.institution_id = a.institution_id AND r.cash_advance_id = a.id AND r.status = 'pending_verification'))"],
    [current_membership_id()],
    25,
    "a.status = 'submitted_for_review' DESC, a.updated_at, a.id"
);

$pageTitle = 'Review Liquidations';
$activeNav = 'liquidation';
$breadcrumb = [auth_context()['label'], 'Liquidation'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Verify each receipt against the advance purpose, confirm returned cash against the account, then complete the review. Verified expenses are posted to the ledger.', button_link('liquidation/index.php', 'Summary', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<div class="card panel">
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-check2-circle', 'Nothing to review', 'Submitted liquidations will appear here.') ?></div>
    <?php else: ?>
        <?= advance_queue_table($rows, 'liquidation', 'Review') ?>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
