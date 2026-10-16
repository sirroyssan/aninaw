<?php
/**
 * Turnover details and actions: cashier count (receiver acknowledgment), collector
 * acknowledgment or dispute, cancellation, and discrepancy resolution.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/turnovers.php';
require_institution_context();

$turnoverId = require_get_id();
$turnover = require_found(find_turnover($turnoverId));
$institutionId = (int) current_institution_id();

if (is_post()) {
    $action = post_str('action', 30);
    handle_post(function () use ($action, $turnoverId) {
        switch ($action) {
            case 'count':
                record_turnover_count($turnoverId, $_POST);
                flash('success', 'Count recorded', 'The collector must now acknowledge the count.');
                break;
            case 'acknowledge':
                acknowledge_turnover($turnoverId, true, post_str('ack_notes', 1000));
                flash('success', 'Turnover acknowledged', 'Custody of the cash has been transferred.');
                break;
            case 'dispute':
                acknowledge_turnover($turnoverId, false, post_str('reason', 1000));
                flash('warning', 'Count disputed', 'The cashier has been asked to recount.');
                break;
            case 'cancel':
                cancel_turnover($turnoverId, post_str('reason', 1000));
                flash('success', 'Turnover cancelled', 'Its payments can be included in a new turnover.');
                break;
            case 'resolve':
                resolve_discrepancy(post_int('discrepancy_id') ?? 0, post_str('method', 20), post_str('resolution_notes', 2000));
                flash('success', 'Discrepancy resolved');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'turnovers/view.php?id=' . $turnoverId;
    }, in_array($action, ['count', 'acknowledge', 'resolve'], true));
}

$details = db_all(
    "SELECT d.amount_included, p.id AS payment_id, p.payment_number, p.payment_date, r.receipt_number, pt.participant_number, pt.first_name, pt.last_name
     FROM cash_turnover_details d
     JOIN payments p ON p.id = d.payment_id AND p.institution_id = d.institution_id
     JOIN participants pt ON pt.id = p.payer_participant_id AND pt.institution_id = p.institution_id
     LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id
     WHERE d.institution_id = ? AND d.turnover_id = ? ORDER BY p.payment_date",
    [$institutionId, $turnoverId]
);
$acks = db_all(
    "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS person FROM turnover_acknowledgments a
     JOIN institution_memberships m ON m.id = a.acknowledged_by_membership_id JOIN users u ON u.id = m.user_id
     WHERE a.institution_id = ? AND a.turnover_id = ? ORDER BY a.id",
    [$institutionId, $turnoverId]
);
$discrepancies = db_all(
    "SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) AS resolver FROM turnover_discrepancies d
     LEFT JOIN institution_memberships m ON m.id = d.resolved_by_membership_id LEFT JOIN users u ON u.id = m.user_id
     WHERE d.institution_id = ? AND d.turnover_id = ? ORDER BY d.id",
    [$institutionId, $turnoverId]
);
$sourceAccount = $turnover['source_account_id'] ? db_one('SELECT account_name FROM financial_accounts WHERE id = ?', [$turnover['source_account_id']]) : null;
$destinationAccounts = $turnover['is_receiver'] ? options_from_rows(array_filter(
    usable_financial_accounts((int) $turnover['fund_id']),
    static fn($row) => (int) $row['custodian_membership_id'] === current_membership_id() && (int) $row['id'] !== $turnover['source_account_id']
), 'id', 'account_name') : [];
$pendingDestination = db_value("SELECT fa.account_name FROM cash_flow_transactions c JOIN financial_accounts fa ON fa.id = c.financial_account_id
                                WHERE c.institution_id = ? AND c.source_type = 'turnover' AND c.source_id = ? AND c.direction = 'inflow' AND c.status IN ('pending', 'posted') ORDER BY c.id DESC LIMIT 1", [$institutionId, $turnoverId]);
$declared = to_cents($turnover['declared_amount']);
$received = $turnover['received_amount'] === null ? null : to_cents($turnover['received_amount']);
$currency = current_currency();

$pageTitle = 'Turnover ' . $turnover['turnover_number'];
$activeNav = 'turnovers';
$breadcrumb = [auth_context()['label'], 'Cash Turnovers'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Cash Turnover <?= e($turnover['turnover_number']) ?></h2><p><?= e(auth_context()['label']) ?></p></div>
<?= page_actions($turnover['event_name'] . ' · ' . $turnover['group_name'] . ' · Fund: ' . $turnover['fund_name'],
    button_link('turnovers/index.php', 'All Turnovers', 'bi-arrow-left', 'btn-outline-secondary') . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>

<div class="stat-grid">
    <?= stat_card('bi-receipt', 'Payments Included', money($turnover['payments_total'], $currency), count($details) . ' receipt(s)') ?>
    <?= stat_card('bi-box-arrow-up-right', 'Declared by Sender', money($declared, $currency), $turnover['submitted_by_name']) ?>
    <?= stat_card('bi-cash-stack', 'Verified Received', $received === null ? 'Not counted' : money($received, $currency), $turnover['received_by_name'], $received === null ? 'warning' : ($received === $turnover['payments_total'] ? 'success' : 'danger')) ?>
    <?= stat_card('bi-plus-slash-minus', 'Difference', $received === null ? '—' : money($received - $turnover['payments_total'], $currency), 'Received − payments', $received !== null && $received !== $turnover['payments_total'] ? 'danger' : 'info') ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Details</h3><?= status_badge($turnover['status']) ?></div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Institution</dt><dd><?= e(auth_context()['label']) ?></dd></div>
                    <div><dt>Fund</dt><dd><?= e($turnover['fund_name']) ?></dd></div>
                    <div><dt>Event · Group</dt><dd><?= e($turnover['event_name'] . ' · ' . $turnover['group_name']) ?></dd></div>
                    <div><dt>Sender</dt><dd><?= e($turnover['submitted_by_name']) ?></dd></div>
                    <div><dt>Receiver</dt><dd><?= e($turnover['received_by_name']) ?></dd></div>
                    <div><dt>From account</dt><dd><?= e($sourceAccount['account_name'] ?? '—') ?></dd></div>
                    <div><dt>To account</dt><dd><?= e($pendingDestination ?: '—') ?></dd></div>
                    <div><dt>Submitted</dt><dd><?= e(format_datetime($turnover['submitted_at'])) ?></dd></div>
                    <div><dt>Counted</dt><dd><?= e(format_datetime($turnover['received_at'])) ?></dd></div>
                    <div><dt>Custody transferred</dt><dd><?= e(format_datetime($turnover['verified_at'])) ?></dd></div>
                </dl>
                <?php if ($turnover['notes']): ?><p class="mt-3 mb-0"><span class="text-muted-sm">Remarks:</span> <?= e($turnover['notes']) ?></p><?php endif; ?>
                <?php if ($turnover['discrepancy_reason']): ?><p class="mt-2 mb-0 text-danger"><?= e($turnover['discrepancy_reason']) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="card panel mb-3">
            <div class="card-header"><h3>Included payments</h3></div>
            <div class="table-responsive">
                <table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Receipt · Payment</th><th>Payer</th><th>Date</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php foreach ($details as $row): ?>
                        <tr>
                            <td><a class="fw-semibold" href="<?= e(url('collections/payment.php?id=' . $row['payment_id'])) ?>"><?= e($row['receipt_number'] ?: $row['payment_number']) ?></a><div class="text-muted-sm"><?= e($row['payment_number']) ?></div></td>
                            <td><?= e($row['last_name'] . ', ' . $row['first_name']) ?></td>
                            <td class="text-muted-sm"><?= e(format_datetime($row['payment_date'])) ?></td>
                            <td class="text-end"><?= e(m($row['amount_included'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php foreach ($discrepancies as $row): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Discrepancy</h3><?= status_badge($row['status']) ?></div>
                <div class="card-body">
                    <dl class="detail-grid">
                        <div><dt>Expected</dt><dd><?= e(m($row['expected_amount'])) ?></dd></div>
                        <div><dt>Actual</dt><dd><?= e(m($row['actual_amount'])) ?></dd></div>
                        <div><dt>Difference</dt><dd class="<?= to_cents($row['difference_amount']) < 0 ? 'text-danger' : 'text-success' ?>"><?= e(m($row['difference_amount'])) ?> <?= to_cents($row['difference_amount']) < 0 ? '(shortage)' : '(excess)' ?></dd></div>
                        <div><dt>Reported</dt><dd><?= e(format_datetime($row['reported_at'])) ?></dd></div>
                    </dl>
                    <p class="mt-3 mb-1"><span class="text-muted-sm">Reason:</span> <?= e($row['reason']) ?></p>
                    <?php if ($row['resolution_notes']): ?><p class="mb-0"><span class="text-muted-sm">Resolution by <?= e($row['resolver']) ?> on <?= e(format_datetime($row['resolved_at'])) ?>:</span> <?= e($row['resolution_notes']) ?></p><?php endif; ?>
                    <?php if (can('reconciliation.manage') && in_array($row['status'], ['open', 'under_review'], true) && !$turnover['is_sender'] && !$turnover['is_receiver']): ?>
                        <form method="post" class="border-top mt-3 pt-3" data-confirm="Record this resolution?" data-confirm-text="A ledger entry is posted for the difference. This cannot be edited later.">
                            <?= csrf_field() ?><?= idempotency_field() ?>
                            <input type="hidden" name="action" value="resolve"><input type="hidden" name="discrepancy_id" value="<?= e($row['id']) ?>">
                            <div class="mb-2"><label class="form-label required" for="method-<?= e($row['id']) ?>">Resolution</label>
                                <select class="form-select" id="method-<?= e($row['id']) ?>" name="method" required><?= options_html(
                                    to_cents($row['difference_amount']) < 0
                                        ? ['settled' => 'Settled: the missing cash was handed over to the cashier', 'write_off' => 'Accepted exception: write off as cash shortage']
                                        : ['settled' => 'Settled: the excess was returned to the collector', 'write_off' => 'Accepted exception: recognise excess as other income'], null, 'Select…') ?></select></div>
                            <div class="mb-2"><label class="form-label required" for="notes-<?= e($row['id']) ?>">Explanation and approval</label><textarea class="form-control" id="notes-<?= e($row['id']) ?>" name="resolution_notes" rows="2" required minlength="10" maxlength="2000"></textarea></div>
                            <button class="btn btn-primary" type="submit">Resolve Discrepancy</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="col-xl-4">
        <?php if ($turnover['is_receiver'] && $turnover['status'] === 'submitted' && can('turnovers.receive')): ?>
            <form method="post" class="card panel form-card mb-3" data-confirm="Record the counted amount?" data-confirm-text="The collector then confirms your count. The declared amount is kept separately.">
                <?= csrf_field() ?><?= idempotency_field() ?>
                <input type="hidden" name="action" value="count">
                <div class="card-header"><h3>Count the cash</h3></div>
                <div class="card-body">
                    <p class="text-muted-sm">Count the cash with the collector present. Record exactly what you received, even if it differs from the declared <?= e(money($declared, $currency)) ?>.</p>
                    <div class="mb-2"><label class="form-label required" for="received_amount">Amount received</label><input class="form-control text-amount" id="received_amount" name="received_amount" required inputmode="decimal" placeholder="0.00"></div>
                    <div class="mb-2"><label class="form-label required" for="destination_account_id">Placed in</label><select class="form-select" id="destination_account_id" name="destination_account_id" required><?= options_html($destinationAccounts, count($destinationAccounts) === 1 ? array_key_first($destinationAccounts) : null, 'Select your custody account…') ?></select>
                        <?php if (!$destinationAccounts): ?><div class="form-text text-danger">You hold no custody account authorised for this fund. Ask the treasurer to create one.</div><?php endif; ?></div>
                    <div class="mb-2"><label class="form-label" for="count_notes">Count notes</label><textarea class="form-control" id="count_notes" name="count_notes" rows="2" maxlength="1000" placeholder="Required when the count differs"></textarea></div>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-cash-stack me-1" aria-hidden="true"></i>Record Count</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($turnover['is_sender'] && $turnover['status'] === 'received_pending_verification'): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Acknowledge the count</h3></div>
                <div class="card-body">
                    <p>The cashier counted <strong><?= e(money((int) $received, $currency)) ?></strong>. You declared <?= e(money($declared, $currency)) ?>.</p>
                    <form method="post" class="mb-2" data-confirm="Acknowledge <?= e(money((int) $received, $currency)) ?>?" data-confirm-text="Custody passes to the cashier. Any difference is recorded as a discrepancy for review.">
                        <?= csrf_field() ?><?= idempotency_field() ?>
                        <input type="hidden" name="action" value="acknowledge">
                        <input class="form-control mb-2" name="ack_notes" maxlength="1000" placeholder="Notes (optional)" aria-label="Notes">
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Acknowledge</button>
                    </form>
                    <form method="post" data-confirm="Dispute the count?" data-reason="What is wrong with the count?" data-danger>
                        <?= csrf_field() ?><input type="hidden" name="action" value="dispute">
                        <button class="btn btn-outline-danger w-100" type="submit">Dispute &amp; Ask for Recount</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if (($turnover['is_sender'] || $turnover['is_receiver']) && $turnover['status'] === 'submitted'): ?>
            <form method="post" class="card panel mb-3" data-confirm="Cancel this turnover?" data-reason="Reason for cancelling" data-danger>
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <div class="card-body"><button class="btn btn-outline-danger w-100" type="submit">Cancel Turnover</button><div class="form-text">Only before the cash is counted. The payments become available again.</div></div>
            </form>
        <?php endif; ?>

        <div class="card panel mb-3">
            <div class="card-header"><h3>Acknowledgments</h3></div>
            <div class="card-body">
                <ul class="timeline">
                    <li><div class="fw-semibold">Submitted · declared <?= e(money($declared, $currency)) ?></div><div class="text-muted-sm"><?= e($turnover['submitted_by_name'] . ' · ' . format_datetime($turnover['submitted_at'])) ?></div></li>
                    <?php foreach ($acks as $row): ?>
                        <li><div class="fw-semibold"><?= e(match ($row['acknowledgment_type']) { 'received' => 'Counted by receiver', 'verified' => 'Acknowledged by sender', default => 'Adjustment' }) ?> · <?= e(m($row['acknowledged_amount'])) ?></div>
                            <div class="text-muted-sm"><?= e($row['person'] . ' · ' . format_datetime($row['acknowledged_at'])) ?></div>
                            <?php if ($row['notes']): ?><div class="text-muted-sm">“<?= e($row['notes']) ?>”</div><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>Documents</h3></div>
            <div class="card-body"><?= attachment_list_html(attachments_for('turnover', $turnoverId)) ?></div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
