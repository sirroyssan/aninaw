<?php
/**
 * Fund transfer details: approval (different person), posting, withdrawal or reversal.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/movements.php';
require_institution_context();
require_permission(['transfers.manage', 'transfers.approve', 'cashflow.view']);

$transferId = require_get_id();
$transfer = require_found(find_transfer($transferId));

if (is_post()) {
    $action = post_str('action', 20);
    handle_post(function () use ($action, $transferId) {
        switch ($action) {
            case 'approve':
                decide_fund_transfer($transferId, true, post_str('notes', 1000));
                flash('success', 'Transfer approved', 'It can now be posted.');
                break;
            case 'reject':
                decide_fund_transfer($transferId, false, post_str('reason', 1000));
                flash('success', 'Transfer rejected');
                break;
            case 'post':
                post_fund_transfer($transferId);
                flash('success', 'Transfer posted');
                break;
            case 'cancel':
                cancel_fund_transfer($transferId, post_str('reason', 1000));
                flash('success', 'Transfer cancelled');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'transfers/view.php?id=' . $transferId;
    }, false);
}

$isRequester = (int) $transfer['requested_by'] === (int) auth_user()['id'];
$history = db_all(
    "SELECT a.action, a.reason, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE a.institution_id = ? AND a.entity_type = 'fund_transfer' AND a.entity_id = ? ORDER BY a.id",
    [current_institution_id(), $transferId]
);

$pageTitle = 'Transfer ' . $transfer['transfer_number'];
$activeNav = 'transfers';
$breadcrumb = [auth_context()['label'], 'Fund Transfers'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($transfer['from_fund_name'] . ' → ' . $transfer['to_fund_name'], button_link('transfers/index.php', 'All Transfers', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Details</h3><?= status_badge($transfer['status']) ?></div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Amount</dt><dd class="amount-highlight"><?= e(m($transfer['amount'])) ?></dd></div>
                    <div><dt>From fund</dt><dd><?= e($transfer['from_fund_name']) ?></dd></div>
                    <div><dt>To fund</dt><dd><?= e($transfer['to_fund_name']) ?></dd></div>
                    <div><dt>Account</dt><dd><?= e($transfer['account']['account_name'] ?? '—') ?></dd></div>
                    <div><dt>Transfer date</dt><dd><?= e(format_date($transfer['transfer_date'])) ?></dd></div>
                    <div><dt>Requested by</dt><dd><?= e($transfer['requested_by_name']) ?></dd></div>
                    <div><dt>Approval</dt><dd><?= e($transfer['approved_by_name'] ?: 'Pending') ?></dd></div>
                    <div><dt>Posted</dt><dd><?= e($transfer['posted_at'] ? format_datetime($transfer['posted_at']) : 'Not posted') ?></dd></div>
                </dl>
                <p class="mt-3 mb-0"><span class="text-muted-sm">Purpose:</span> <?= e($transfer['purpose']) ?></p>
            </div>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>Supporting documents</h3></div>
            <div class="card-body"><?= attachment_list_html(attachments_for('fund_transfer', $transferId)) ?></div>
        </div>
    </div>
    <div class="col-xl-4">
        <?php if ($transfer['status'] === 'submitted' && can('transfers.approve')): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Approval</h3></div>
                <div class="card-body">
                    <?php if ($isRequester): ?>
                        <p class="text-muted-sm mb-0">You requested this transfer, so another authorised person must approve it.</p>
                    <?php else: ?>
                        <form method="post" class="mb-2" data-confirm="Approve this transfer?"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input class="form-control mb-2" name="notes" maxlength="1000" placeholder="Approval notes (optional)" aria-label="Approval notes"><button class="btn btn-primary w-100" type="submit">Approve</button></form>
                        <form method="post" data-confirm="Reject this transfer?" data-reason="Reason for rejecting" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="reject"><button class="btn btn-outline-danger w-100" type="submit">Reject</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($transfer['status'] === 'approved' && can('transfers.manage')): ?>
            <form method="post" class="card panel mb-3" data-confirm="Post this transfer to the ledger?" data-confirm-text="Fund balances change now. Mistakes require a reversal with a reason.">
                <?= csrf_field() ?><input type="hidden" name="action" value="post">
                <div class="card-body"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-journal-check me-1" aria-hidden="true"></i>Post Transfer</button></div>
            </form>
        <?php endif; ?>
        <?php if (can('transfers.manage') && in_array($transfer['status'], ['submitted', 'approved', 'posted'], true)): ?>
            <form method="post" class="card panel mb-3" data-confirm="<?= $transfer['status'] === 'posted' ? 'Reverse this posted transfer?' : 'Withdraw this transfer request?' ?>" data-reason="Reason" data-danger>
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <div class="card-body"><button class="btn btn-outline-danger w-100" type="submit"><?= $transfer['status'] === 'posted' ? 'Reverse Transfer' : 'Withdraw Request' ?></button></div>
            </form>
        <?php endif; ?>
        <div class="card panel">
            <div class="card-header"><h3>History</h3></div>
            <div class="card-body"><ul class="timeline">
                <?php foreach ($history as $row): ?>
                    <li><div class="fw-semibold"><?= e(humanize(str_replace('transfer.', '', $row['action']))) ?></div><div class="text-muted-sm"><?= e($row['actor'] . ' · ' . format_datetime($row['created_at'])) ?></div><?php if ($row['reason']): ?><div class="text-muted-sm">“<?= e($row['reason']) ?>”</div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
