<?php
/**
 * Collector turnover preparation: choose the assignment, include payments, declare the cash.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/turnovers.php';
require_institution_context();
require_permission('turnovers.submit');

if (is_post()) {
    handle_post(function () {
        $id = submit_turnover($_POST);
        flash('success', 'Turnover submitted', 'Hand the cash to your cashier for counting.');
        return 'turnovers/view.php?id=' . $id;
    });
}

$assignments = my_collection_assignments();
$assignmentId = get_int('assignment') ?? (count($assignments) === 1 ? (int) $assignments[0]['id'] : null);
$assignment = null;
foreach ($assignments as $row) {
    if ((int) $row['id'] === $assignmentId) {
        $assignment = $row;
    }
}
$payments = $assignment ? turnover_eligible_payments((int) $assignment['event_id'], (int) $assignment['group_id']) : [];
$currency = current_currency();

$pageTitle = 'Prepare Turnover';
$activeNav = 'turnovers';
$breadcrumb = [auth_context()['label'], 'Cash Turnovers'];
$pageScripts = ['js/finance.js'];
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$assignments): ?>
    <div class="card panel"><div class="card-body"><?= empty_state('bi-clipboard', 'No collection assignments', 'You can prepare turnovers once you are assigned as collector of a group in an event.') ?></div></div>
<?php else: ?>
    <form method="get" class="card panel mb-3" style="max-width: 980px">
        <div class="card-body d-flex gap-2 align-items-end">
            <div class="flex-grow-1"><label class="form-label" for="assignment">Collection assignment</label>
                <select class="form-select" id="assignment" name="assignment" data-autosubmit><?= options_html(options_from_rows($assignments, 'id', static fn($r) => $r['event_name'] . ' · ' . $r['group_name'] . ' → ' . $r['cashier_name']), $assignmentId, 'Select…') ?></select></div>
            <button class="btn btn-outline-primary" type="submit">Select</button>
        </div>
    </form>
    <?php if ($assignment): ?>
        <?php if (!$payments): ?>
            <div class="card panel" style="max-width: 980px"><div class="card-body"><?= empty_state('bi-check2-circle', 'Nothing to turn over', 'All payments you recorded for this group are already in a turnover.') ?></div></div>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" class="card panel form-card" style="max-width: 980px" data-confirm="Submit this turnover?" data-confirm-text="The cashier will count the cash and record what was received. Your declared amount cannot be changed afterwards.">
                <?= csrf_field() ?><?= idempotency_field() ?>
                <input type="hidden" name="assignment_id" value="<?= e($assignment['id']) ?>">
                <div class="card-header"><h3>Payments to include</h3><span class="text-muted-sm">Receiving cashier: <?= e($assignment['cashier_name']) ?></span></div>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0" data-checked-sum="#included-total">
                        <thead><tr><th style="width:40px"><input class="form-check-input" type="checkbox" data-check-all checked aria-label="Select all"></th><th>Payment · Receipt</th><th>Payer</th><th>Held in</th><th class="text-end">Amount</th></tr></thead>
                        <tbody>
                        <?php foreach ($payments as $row): ?>
                            <tr>
                                <td><input class="form-check-input" type="checkbox" name="payment_ids[]" value="<?= e($row['id']) ?>" data-amount="<?= e(cents_to_decimal(to_cents($row['amount']))) ?>" checked aria-label="Include <?= e($row['payment_number']) ?>"></td>
                                <td><div class="fw-semibold"><?= e($row['payment_number']) ?></div><div class="text-muted-sm"><?= e(($row['receipt_number'] ?: '—') . ' · ' . format_datetime($row['payment_date'])) ?></div></td>
                                <td><?= e($row['last_name'] . ', ' . $row['first_name']) ?><div class="text-muted-sm"><?= e($row['participant_number']) ?></div></td>
                                <td class="text-muted-sm"><?= e($row['account_name']) ?></td>
                                <td class="text-end fw-semibold"><?= e(m($row['amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><th colspan="4" class="text-end">Total of selected payments</th><th class="text-end"><span id="included-total" data-currency="<?= e($currency === 'PHP' ? '₱' : $currency . ' ') ?>">0.00</span></th></tr></tfoot>
                    </table>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label required" for="declared_amount">Cash you are handing over</label><input class="form-control text-amount" id="declared_amount" name="declared_amount" required inputmode="decimal" placeholder="0.00" value="<?= e(old('declared_amount')) ?>"><div class="form-text">Count it yourself first. If it differs from the payments total, explain below.</div></div>
                        <div class="col-md-8"><label class="form-label" for="notes">Remarks</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="1000"><?= e(old('notes')) ?></textarea></div>
                        <div class="col-md-6"><?= attachment_input('attachment', 'Tally sheet or photo') ?></div>
                    </div>
                    <div class="form-actions">
                        <a class="btn btn-outline-secondary" href="<?= e(url('turnovers/index.php')) ?>">Cancel</a>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Submit Turnover</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
