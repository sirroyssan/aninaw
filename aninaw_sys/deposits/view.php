<?php
/**
 * Deposit details, verification (by a different person), rejection or reversal.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/movements.php';
require_institution_context();
require_permission(['deposits.manage', 'cashflow.view']);

$depositId = require_get_id();
$deposit = require_found(find_deposit($depositId));

if (is_post()) {
    require_permission('deposits.manage');
    $action = post_str('action', 20);
    handle_post(function () use ($action, $depositId) {
        if ($action === 'confirm') {
            confirm_deposit($depositId);
            flash('success', 'Deposit verified and posted');
        } elseif ($action === 'cancel') {
            cancel_deposit($depositId, post_str('reason', 1000));
            flash('success', 'Deposit reversed');
        } else {
            fail_user('Unknown action.');
        }
        return 'deposits/view.php?id=' . $depositId;
    }, false);
}

$history = db_all(
    "SELECT a.action, a.reason, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE a.institution_id = ? AND a.entity_type = 'deposit' AND a.entity_id = ? ORDER BY a.id",
    [current_institution_id(), $depositId]
);
$canAct = can('deposits.manage');

$pageTitle = 'Deposit ' . $deposit['deposit_number'];
$activeNav = 'deposits';
$breadcrumb = [auth_context()['label'], 'Deposits'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($deposit['source_name'] . ' → ' . $deposit['destination_name'], button_link('deposits/index.php', 'All Deposits', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Details</h3><?= status_badge($deposit['status']) ?></div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Amount</dt><dd class="amount-highlight"><?= e(m($deposit['amount'])) ?></dd></div>
                    <div><dt>Fund</dt><dd><?= e($deposit['fund_name'] ?: '—') ?></dd></div>
                    <div><dt>Deposit date</dt><dd><?= e(format_date($deposit['deposit_date'])) ?></dd></div>
                    <div><dt>Reference</dt><dd><?= e($deposit['reference_number']) ?></dd></div>
                    <div><dt>Source</dt><dd><?= e($deposit['source_name']) ?></dd></div>
                    <div><dt>Destination</dt><dd><?= e($deposit['destination_name']) ?></dd></div>
                    <div><dt>Recorded by</dt><dd><?= e($deposit['recorded_by_name']) ?></dd></div>
                    <div><dt>Verified by</dt><dd><?= e($deposit['confirmed_by_name'] ? $deposit['confirmed_by_name'] . ', ' . format_datetime($deposit['confirmed_at']) : 'Not yet') ?></dd></div>
                </dl>
            </div>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>Deposit slip</h3></div>
            <div class="card-body"><?= attachment_list_html(attachments_for('deposit', $depositId)) ?></div>
        </div>
    </div>
    <div class="col-xl-4">
        <?php if ($canAct && in_array($deposit['status'], ['recorded', 'pending_confirmation'], true)): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Verification</h3></div>
                <div class="card-body">
                    <?php if ((int) $deposit['recorded_by'] === (int) auth_user()['id']): ?>
                        <p class="text-muted-sm">You recorded this deposit. Another authorised person must verify it against the bank record.</p>
                    <?php else: ?>
                        <p class="text-muted-sm">Check the slip and the bank/e-wallet record before verifying. Balances change only after verification.</p>
                        <form method="post" class="mb-2" data-confirm="Verify and post this deposit?"><?= csrf_field() ?><input type="hidden" name="action" value="confirm"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Verify &amp; Post</button></form>
                    <?php endif; ?>
                    <form method="post" data-confirm="Reject this deposit record?" data-reason="Reason" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger w-100" type="submit">Reject</button></form>
                </div>
            </div>
        <?php elseif ($canAct && $deposit['status'] === 'confirmed'): ?>
            <form method="post" class="card panel mb-3" data-confirm="Reverse this posted deposit?" data-reason="Reason for the reversal" data-danger>
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <div class="card-body"><p class="text-muted-sm">A reversing entry moves the amount back to the source account. The original stays on record.</p><button class="btn btn-outline-danger w-100" type="submit">Reverse Deposit</button></div>
            </form>
        <?php endif; ?>
        <div class="card panel">
            <div class="card-header"><h3>History</h3></div>
            <div class="card-body"><ul class="timeline">
                <?php foreach ($history as $row): ?>
                    <li><div class="fw-semibold"><?= e(humanize(str_replace('deposit.', '', $row['action']))) ?></div><div class="text-muted-sm"><?= e($row['actor'] . ' · ' . format_datetime($row['created_at'])) ?></div><?php if ($row['reason']): ?><div class="text-muted-sm">“<?= e($row['reason']) ?>”</div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
