<?php
/**
 * Submit a cash advance or temporary borrowing request.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/advances.php';
require_institution_context();
require_permission('advances.request');

if (is_post()) {
    handle_post(function () {
        $id = request_advance($_POST);
        flash('success', 'Request submitted', 'An approver will review it. You will be notified.');
        return 'advances/view.php?id=' . $id;
    });
}

$funds = options_from_rows(db_all("SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? AND status = 'active' ORDER BY fund_name", [current_institution_id()]), 'id', 'fund_name');

$pageTitle = 'Request Cash Advance';
$activeNav = 'advances';
$breadcrumb = [auth_context()['label'], 'Cash Advances'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" class="card panel form-card" style="max-width: 880px">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div>After release you must liquidate the advance by the deadline: submit receipts for what you spend and return any unused cash.</div></div>
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label required" for="fund_id">Charge to fund</label><select class="form-select" id="fund_id" name="fund_id" required><?= options_html($funds, old('fund_id'), 'Select…') ?></select></div>
            <div class="col-md-6"><label class="form-label" for="event_id">Related event</label><select class="form-select" id="event_id" name="event_id"><?= options_html(event_options(['approved', 'collecting', 'completed']), old('event_id'), 'None') ?></select></div>
            <div class="col-md-6"><label class="form-label required" for="requested_amount">Amount requested</label><input class="form-control text-amount" id="requested_amount" name="requested_amount" required inputmode="decimal" value="<?= e(old('requested_amount')) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="advance_type">Type</label><select class="form-select" id="advance_type" name="advance_type"><?= options_html(ADVANCE_TYPES, old('advance_type', 'activity_advance')) ?></select></div>
            <div class="col-12"><label class="form-label required" for="purpose">Purpose and planned expenses</label><textarea class="form-control" id="purpose" name="purpose" rows="4" required minlength="10" maxlength="3000"><?= e(old('purpose')) ?></textarea></div>
        </div>
        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url('advances/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit Request</button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
