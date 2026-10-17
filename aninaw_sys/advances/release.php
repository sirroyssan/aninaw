<?php
/**
 * Approved advances waiting for the cashier/custodian to release the cash.
 * The release itself happens on the advance page (account, deadline, confirmation).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/advances.php';
require_institution_context();
require_permission('advances.release');

[$rows, $pagination] = advance_queue(["a.status = 'approved'"], [], 'a.request_date, a.id');
$total = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(approved_amount), 0) AS amount FROM cash_advances WHERE institution_id = ? AND status = 'approved'", [current_institution_id()]);

$pageTitle = 'Advances Awaiting Release';
$activeNav = 'advances';
$breadcrumb = [auth_context()['label'], 'Cash Advances'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Approved requests not yet paid out. You cannot release an advance to yourself; the fund and account must have enough available cash.', button_link('advances/index.php', 'All Advances', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<div class="stat-grid">
    <?= stat_card('bi-box-arrow-up-right', 'Awaiting Release', number_format((int) $total['n']), 'Approved advances', (int) $total['n'] ? 'warning' : 'success') ?>
    <?= stat_card('bi-cash-stack', 'Amount to Release', m($total['amount']), 'Total approved') ?>
</div>
<div class="card panel">
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-check2-circle', 'Nothing to release', 'Approved advances will appear here.') ?></div>
    <?php else: ?>
        <?= advance_queue_table($rows, 'release', 'Release') ?>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
