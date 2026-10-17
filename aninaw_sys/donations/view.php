<?php
/**
 * One solicitation: donor, fund / event, pledged vs received, its payments and receipts.
 * Open pledges can receive payments (each with an official receipt) or be cancelled with a reason.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission(['collections.view', 'collections.record']);
if (!donations_ready()) {
    render_error_page(503, 'Not Set Up Yet', 'Import database/migration_010_solicitations.sql once (phpMyAdmin → Import) to use solicitations.');
}

$donationId = require_get_id();
$donation = require_found(find_donation($donationId));

if (is_post()) {
    $action = post_str('action', 20);
    handle_post(function () use ($donationId, $action) {
        if ($action === 'receive') {
            $paymentId = receive_donation($donationId, $_POST);
            flash('success', 'Payment received', 'The receipt is ready to print.');
            return 'donations/view.php?id=' . $donationId . '&paid=' . $paymentId;
        }
        if ($action === 'cancel') {
            cancel_donation($donationId, post_str('reason', 1000));
            flash('success', 'Pledge closed');
            return 'donations/view.php?id=' . $donationId;
        }
        fail_user('Unknown action.');
    }, $action === 'receive');
}

$currency = current_currency();
$open = in_array($donation['status'], ['pledged', 'partially_received'], true) && $donation['pledged_amount'] !== null;
$canRecord = can('collections.record');
$paid = get_int('paid');
$accounts = [];
if ($open && $canRecord) {
    foreach (usable_financial_accounts((int) $donation['fund_id']) as $account) {
        $accounts[$account['id']] = $account;
    }
}

$pageTitle = $donation['donor_name'];
$activeNav = 'donations';
$breadcrumb = [auth_context()['label'], 'Solicitations', $donation['donation_number']];
$pageScripts = ['js/finance.js'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($donation['donation_number'] . ' · ' . (DONOR_KINDS[$donation['donor_kind']] ?? '') . ' · recorded ' . format_date($donation['created_at']) . ' by ' . $donation['created_by_name'],
    button_link('donations/index.php' . ($donation['event_id'] ? '?event=' . $donation['event_id'] : ''), 'Solicitations', 'bi-arrow-left', 'btn-outline-secondary')
    . ($canRecord ? button_link('donations/create.php' . ($donation['event_id'] ? '?event=' . $donation['event_id'] : ''), 'Record Another', 'bi-gift', 'btn-outline-primary') : '')) ?>

<?php if ($paid && ($row = current(array_filter($donation['payments'], static fn($p) => (int) $p['id'] === $paid)))): ?>
    <div class="notice notice-success"><i class="bi bi-check2-circle" aria-hidden="true"></i>
        <div class="flex-grow-1"><strong><?= e(m($row['amount'])) ?> received from <?= e($donation['donor_name']) ?></strong> · receipt <?= e($row['receipt_number']) ?></div>
        <a class="btn btn-primary btn-sm" href="<?= e(url('collections/print_receipt.php?id=' . $row['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print Receipt</a>
    </div>
<?php endif; ?>

<div class="stat-grid">
    <?= $donation['pledged_amount'] !== null ? stat_card('bi-hand-thumbs-up', 'Pledged', money(to_cents($donation['pledged_amount']), $currency), $donation['expected_date'] ? 'Expected ' . format_date($donation['expected_date']) : 'Promised amount') : '' ?>
    <?= stat_card('bi-gift', 'Received', money($donation['received_cents'], $currency), count(array_filter($donation['payments'], static fn($p) => $p['status'] === 'posted')) . ' payment(s)', 'success') ?>
    <?= $donation['pledged_amount'] !== null ? stat_card('bi-hourglass-split', 'Still pledged', money($donation['outstanding_cents'], $currency), DONATION_STATUSES[$donation['status']], $donation['outstanding_cents'] > 0 ? 'warning' : 'success') : '' ?>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Details</h3><?= status_badge($donation['status']) ?></div>
            <div class="card-body">
                <dl class="detail-grid mb-0">
                    <div><dt>Donor</dt><dd><?= e($donation['donor_name']) ?><div class="text-muted-sm"><?= e($donation['participant_number'] . ($donation['donor_phone'] ? ' · ' . $donation['donor_phone'] : '') . ($donation['donor_email'] ? ' · ' . $donation['donor_email'] : '')) ?></div></dd></div>
                    <div><dt>Fund</dt><dd><?= e($donation['fund_name']) ?></dd></div>
                    <div><dt>Event</dt><dd><?= $donation['event_id'] ? '<a href="' . e(url('events/view.php?id=' . $donation['event_id'])) . '">' . e($donation['event_name']) . '</a>' : '—' ?></dd></div>
                    <div><dt>Purpose</dt><dd><?= e($donation['purpose'] ?: '—') ?></dd></div>
                    <div><dt>Solicited by</dt><dd><?= e($donation['solicited_by_name'] ?: '—') ?></dd></div>
                    <div><dt>Notes</dt><dd><?= e($donation['notes'] ?: '—') ?></dd></div>
                </dl>
            </div>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>Payments &amp; receipts</h3></div>
            <?php if (!$donation['payments']): ?>
                <div class="card-body"><?= empty_state('bi-hourglass', 'Nothing received yet', 'Use Receive Payment when the money comes in.') ?></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                    <tbody><?php foreach ($donation['payments'] as $p): ?>
                        <tr>
                            <td><a class="fw-semibold" href="<?= e(url('collections/payment.php?id=' . $p['id'])) ?>"><?= e($p['receipt_number'] ?: $p['payment_number']) ?></a><div class="text-muted-sm">by <?= e($p['received_by_name']) ?> · <?= e($p['account_name']) ?></div></td>
                            <td><?= e(format_datetime($p['payment_date'])) ?></td>
                            <td><?= e(PAYMENT_METHODS[$p['payment_method']] ?? $p['payment_method']) ?><?= $p['external_reference'] ? '<div class="text-muted-sm">Ref. ' . e($p['external_reference']) . '</div>' : '' ?></td>
                            <td class="text-end fw-semibold"><?= e(m($p['amount'])) ?></td>
                            <td><?= status_badge($p['status']) ?></td>
                            <td class="actions"><?php if ($p['receipt_number'] && $p['status'] === 'posted'): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('collections/print_receipt.php?id=' . $p['id'])) ?>" target="_blank" rel="noopener">Receipt</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <div class="card-footer-note">To correct a payment, open it and use Reverse (needs permission and a reason). The pledge re-opens automatically.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <?php if ($open && $canRecord): ?>
            <form method="post" enctype="multipart/form-data" class="card panel form-card mb-3" data-confirm="Receive this payment?" data-confirm-text="It is posted to <?= e($donation['fund_name']) ?> and an official receipt is issued.">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="receive">
                <div class="card-header"><h3>Receive Payment</h3></div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-6"><label class="form-label required" for="amount">Amount</label><div class="input-group"><input class="form-control text-amount" id="amount" name="amount" required inputmode="decimal" value="<?= e(old('amount', cents_to_decimal($donation['outstanding_cents']))) ?>" data-max="<?= e(cents_to_decimal($donation['outstanding_cents'])) ?>"><button class="btn btn-outline-primary" type="button" data-fill-max>Full</button></div></div>
                        <div class="col-md-6"><label class="form-label required" for="payment_method">Method</label><select class="form-select" id="payment_method" name="payment_method" data-method-select><?= options_html(PAYMENT_METHODS, old('payment_method', 'cash')) ?></select></div>
                        <div class="col-12"><label class="form-label required" for="financial_account_id">Received into</label><select class="form-select" id="financial_account_id" name="financial_account_id" required<?= method_account_select_attrs() ?>><?= account_options_by_type($accounts, old('financial_account_id')) ?></select><div class="form-text text-danger d-none" data-method-none>No account for this method is linked to <?= e($donation['fund_name']) ?>.</div>
                            <?php if (!$accounts): ?><div class="form-text text-danger">You have no cash box or account linked to <?= e($donation['fund_name']) ?>. Ask the treasurer to link one.</div><?php endif; ?></div>
                        <div class="col-md-6"><label class="form-label required" for="payment_date">Date received</label><input class="form-control" type="date" id="payment_date" name="payment_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('payment_date', date('Y-m-d'))) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="payment_time">Time</label><input class="form-control" type="time" id="payment_time" name="payment_time" value="<?= e(old('payment_time', date('H:i'))) ?>"></div>
                        <div class="col-12"><label class="form-label" for="external_reference">Reference no. <span class="text-muted-sm">(GCash/bank/cheque)</span></label><input class="form-control" id="external_reference" name="external_reference" maxlength="150" value="<?= e(old('external_reference')) ?>"></div>
                        <div class="col-12"><?= attachment_input('attachment', 'Proof') ?></div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary w-100" type="submit" <?= $accounts ? '' : 'disabled' ?>><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Receive &amp; Issue Receipt</button></div>
                </div>
            </form>
            <form method="post" class="card panel" data-confirm="Close this pledge?" data-confirm-text="<?= $donation['received_cents'] > 0 ? 'What was received is kept; the rest of the pledge is dropped.' : 'Nothing was received, so the pledge is cancelled.' ?>" data-reason="Why? (e.g. donor withdrew, amount reduced)" data-danger>
                <?= csrf_field() ?><input type="hidden" name="action" value="cancel">
                <div class="card-body"><button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-x-circle me-1" aria-hidden="true"></i><?= $donation['received_cents'] > 0 ? 'Close Pledge (keep what was received)' : 'Cancel Pledge' ?></button></div>
            </form>
        <?php elseif ($donation['status'] === 'cancelled'): ?>
            <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div>This pledge was cancelled. The reason is in the audit log.</div></div>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
