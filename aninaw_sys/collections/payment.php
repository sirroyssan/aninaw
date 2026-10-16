<?php
/**
 * Payment details: allocations, receipt, ledger posting, attachments, reprint history
 * and the authorised reversal procedure.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission(['collections.view', 'collections.record']);

$paymentId = require_get_id();
$payment = require_found(find_payment($paymentId));

if (is_post()) {
    require_permission('payments.reverse');
    handle_post(function () use ($paymentId) {
        reverse_payment($paymentId, post_str('reason', 1000));
        flash('success', 'Payment reversed', 'The receipt is now void and the balances have been restored.');
        return 'collections/payment.php?id=' . $paymentId;
    }, false);
}

$journal = db_all(
    "SELECT j.id, j.journal_number, j.journal_date, j.reversal_of_id, j.description FROM journal_entries j
     WHERE j.institution_id = ? AND j.source_type = 'payment' AND j.source_id = ? ORDER BY j.id",
    [current_institution_id(), $paymentId]
);
$history = $payment['receipt_id'] ? receipt_print_history((int) $payment['receipt_id']) : [];
$reversal = db_one("SELECT a.reason, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
                    WHERE a.institution_id = ? AND a.entity_type = 'payment' AND a.entity_id = ? AND a.action = 'payment.reversed'", [current_institution_id(), $paymentId]);
$turnover = payment_turnover($paymentId);

$pageTitle = 'Payment ' . $payment['payment_number'];
$activeNav = 'collections';
$breadcrumb = [auth_context()['label'], 'Collections'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Received from ' . person_name($payment) . ' (' . $payment['participant_number'] . ')',
    button_link('collections/payments.php', 'Payment History', 'bi-arrow-left', 'btn-outline-secondary')
    . ($payment['receipt_id'] ? '<a class="btn btn-primary" href="' . e(url('collections/print_receipt.php?id=' . $paymentId)) . '" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>' . ($history ? 'Reprint Receipt' : 'Print Receipt') . '</a>' : '')) ?>

<?php if ($payment['status'] === 'reversed'): ?>
    <div class="notice notice-danger"><i class="bi bi-x-octagon" aria-hidden="true"></i><div><strong>Reversed.</strong> Receipt <?= e($payment['receipt_number']) ?> is void.<?php if ($reversal): ?> Reason: “<?= e($reversal['reason']) ?>” — <?= e($reversal['actor'] . ', ' . format_datetime($reversal['created_at'])) ?>.<?php endif; ?></div></div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('bi-cash-coin', 'Amount', m($payment['amount']), humanize($payment['payment_method']) . ($payment['external_reference'] ? ' · ref ' . $payment['external_reference'] : '')) ?>
    <?= stat_card('bi-receipt', 'Receipt', $payment['receipt_number'] ?: '—', $payment['receipt_status'] ? humanize($payment['receipt_status']) : '', $payment['receipt_status'] === 'voided' ? 'danger' : 'success') ?>
    <?= stat_card('bi-calendar-check', 'Paid On', format_date($payment['payment_date']), format_datetime($payment['payment_date']), 'info') ?>
    <?= stat_card('bi-safe', 'Received Into', $payment['account_name'] ?: '—', 'by ' . $payment['received_by_name'] . ($payment['fund'] ? ' · ' . $payment['fund']['fund_name'] : ''), 'info') ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Applied to</h3><?= status_badge($payment['status']) ?></div>
            <div class="table-responsive">
                <table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Event · Collection</th><th class="text-end">Applied</th><th>Obligation status now</th></tr></thead>
                    <tbody>
                    <?php foreach ($payment['allocations'] as $row): ?>
                        <tr>
                            <td><a href="<?= e(url('events/view.php?id=' . $row['event_id'])) ?>"><?= e($row['event_name']) ?></a><div class="text-muted-sm"><?= e($row['rule_name']) ?></div></td>
                            <td class="text-end fw-semibold"><?= e(m($row['allocated_amount'])) ?></td>
                            <td><?= status_badge($row['obligation_status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($payment['order']): ?>
                        <tr>
                            <td><?= can_any(['orders.view', 'orders.manage']) ? '<a href="' . e(url('orders/view.php?id=' . $payment['order']['id'])) . '">Order ' . e($payment['order']['order_number']) . '</a>' : 'Order ' . e($payment['order']['order_number']) ?>
                                <div class="text-muted-sm"><?= e(implode(', ', array_map(static fn($i) => $i['description'] . ' × ' . $i['quantity'], $payment['order_items']))) ?></div></td>
                            <td class="text-end fw-semibold"><?= e(m($payment['amount'])) ?></td>
                            <td class="text-muted-sm">Order total <?= e(m($payment['order']['total_amount'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>Ledger</h3></div>
            <ul class="list-clean">
                <?php foreach ($journal as $row): ?>
                    <li><div><div class="fw-semibold"><?= e($row['journal_number']) ?> <?= $row['reversal_of_id'] ? '<span class="badge badge-soft-danger">reversal</span>' : '' ?></div><div class="text-muted-sm"><?= e($row['description']) ?></div></div>
                        <?php if (can('ledger.view')): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('ledger/entry.php?id=' . $row['id'])) ?>">View</a><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Receipt prints</h3></div>
            <div class="card-body">
                <?php if (!$history): ?>
                    <p class="text-muted-sm mb-0">Not printed yet.</p>
                <?php else: ?>
                    <ul class="timeline">
                        <?php foreach ($history as $row): ?>
                            <li><div class="fw-semibold"><?= $row['action'] === 'receipt.printed' ? 'Original printed' : 'Reprinted' ?></div><div class="text-muted-sm"><?= e($row['actor'] . ' · ' . format_datetime($row['created_at'])) ?></div></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <div class="card panel mb-3">
            <div class="card-header"><h3>Documents</h3></div>
            <div class="card-body"><?= attachment_list_html(attachments_for('payment', $paymentId)) ?></div>
        </div>
        <?php if ($payment['notes']): ?>
            <div class="card panel mb-3"><div class="card-header"><h3>Remarks</h3></div><div class="card-body"><?= e($payment['notes']) ?></div></div>
        <?php endif; ?>
        <?php if (can('payments.reverse') && $payment['status'] === 'posted'): ?>
            <div class="card panel">
                <div class="card-header"><h3>Reverse payment</h3></div>
                <div class="card-body">
                    <?php if ($turnover): ?>
                        <p class="text-muted-sm mb-0">Included in turnover <?= e($turnover['turnover_number']) ?> (<?= e(humanize($turnover['status'])) ?>). It cannot be reversed here.</p>
                    <?php else: ?>
                        <p class="text-muted-sm">For payments entered incorrectly. The receipt is voided (its number is never reused) and a reversing entry restores the balances. The original record stays visible.</p>
                        <form method="post" data-confirm="Reverse payment <?= e($payment['payment_number']) ?>?" data-reason="Reason for the reversal" data-danger data-confirm-button="Reverse">
                            <?= csrf_field() ?>
                            <button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reverse &amp; Void Receipt</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
