<?php
/**
 * Record a solicitation: money received now (official receipt issued at once) or a pledge to receive
 * later. The donor is picked from People or registered here (Customer / Other, DON- number).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission('collections.record');

if (is_post()) {
    handle_post(function () {
        $result = create_donation($_POST);
        if ($result['payment_id']) {
            flash('success', 'Solicitation recorded', 'The receipt is ready to print.');
            return 'donations/view.php?id=' . $result['donation_id'] . '&paid=' . $result['payment_id'];
        }
        flash('success', 'Pledge recorded', 'Use Receive Payment on it when the money comes in.');
        return 'donations/view.php?id=' . $result['donation_id'];
    });
}

$institutionId = (int) current_institution_id();
$ready = donations_ready();
$eventId = (int) (get_int('event') ?? old('event_id', 0));
$events = event_options(['draft', 'pending', 'approved', 'collecting', 'completed']);
$eventFunds = [];
foreach (array_keys($events) as $id) {
    $eventFunds[$id] = implode(',', array_map(static fn($r) => (int) $r['fund_id'], event_funds((int) $id)));
}
$funds = donation_fund_options($eventId ?: null);
$defaultFund = old('fund_id', $eventId && $eventFunds[$eventId] ?? '' ? explode(',', $eventFunds[$eventId])[0] : '');
$accounts = [];
foreach (usable_financial_accounts() as $account) {
    $fundIds = db_query('SELECT fund_id FROM fund_account_mappings WHERE institution_id = ? AND financial_account_id = ? AND valid_from <= CURDATE() AND (valid_until IS NULL OR valid_until >= CURDATE())',
        [$institutionId, $account['id']])->fetchAll(PDO::FETCH_COLUMN);
    if ($fundIds) {
        $accounts[] = $account + ['fund_ids' => implode(',', $fundIds)];
    }
}
$donors = db_all("SELECT id, participant_number, first_name, last_name, participant_type FROM participants WHERE institution_id = ? AND status = 'active' ORDER BY participant_type = 'other' DESC, last_name, first_name LIMIT 5000", [$institutionId]);
$donorMode = old('donor_mode', 'new');
$mode = old('mode', 'received');

$pageTitle = 'Record Solicitation';
$activeNav = 'donations';
$breadcrumb = [auth_context()['label'], 'Solicitations'];
$pageScripts = ['js/donations.js'];
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$ready): ?>
    <div class="notice notice-danger"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>Import <code>database/migration_010_solicitations.sql</code> once (phpMyAdmin → Import) to start recording solicitations.</div></div>
<?php endif; ?>
<form method="post" enctype="multipart/form-data" class="card panel form-card" data-donation-form data-confirm="Record this solicitation?" data-confirm-text="Money received is posted to the fund and an official receipt is issued. Corrections need an authorised reversal.">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="form-section">
            <h2 class="form-section-title">For</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="event_id">Event <span class="text-muted-sm">(optional)</span></label>
                    <select class="form-select" id="event_id" name="event_id" data-donation-event>
                        <option value="">Not for a specific event</option>
                        <?php foreach ($events as $id => $label): ?><option value="<?= (int) $id ?>" data-funds="<?= e($eventFunds[$id]) ?>"<?= (int) $id === $eventId ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-6"><label class="form-label required" for="fund_id">Goes to fund</label><select class="form-select" id="fund_id" name="fund_id" required data-donation-fund><?= options_html($funds, $defaultFund, 'Select fund…') ?></select>
                    <div class="form-text">e.g. RAITE Fund 2026. Booked as Donations &amp; Solicitations income of this fund.</div></div>
                <div class="col-md-8"><label class="form-label" for="purpose">Purpose / description <span class="text-muted-sm">(printed on the receipt)</span></label><input class="form-control" id="purpose" name="purpose" maxlength="255" value="<?= e(old('purpose')) ?>" placeholder="e.g. Sponsorship for transportation; Solicitation letter no. 12"></div>
                <div class="col-md-4"><label class="form-label" for="solicited_by_membership_id">Solicited by <span class="text-muted-sm">(optional)</span></label><select class="form-select" id="solicited_by_membership_id" name="solicited_by_membership_id" data-searchable><?= options_html(member_options(), old('solicited_by_membership_id'), '—') ?></select></div>
            </div>
        </div>

        <div class="form-section">
            <h2 class="form-section-title">Donor</h2>
            <div class="mb-2">
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="donor_mode" id="donor-new" value="new" <?= $donorMode === 'new' ? 'checked' : '' ?> data-donor-mode><label class="form-check-label" for="donor-new">New donor</label></div>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="donor_mode" id="donor-existing" value="existing" <?= $donorMode === 'existing' ? 'checked' : '' ?> data-donor-mode><label class="form-check-label" for="donor-existing">Already in People (gave before, a parent, a student…)</label></div>
            </div>
            <div class="row g-3" data-donor-existing>
                <div class="col-md-8"><label class="form-label required" for="donor_participant_id">Donor</label>
                    <select class="form-select" id="donor_participant_id" name="donor_participant_id" data-searchable>
                        <option value="">Search name or ID…</option>
                        <?php foreach ($donors as $d): ?><option value="<?= (int) $d['id'] ?>"<?= (string) old('donor_participant_id') === (string) $d['id'] ? ' selected' : '' ?>><?= e(donor_display_name($d) . ' (' . $d['participant_number'] . ')' . ($d['participant_type'] === 'other' ? '' : ' · ' . (PARTICIPANT_TYPES[$d['participant_type']] ?? ''))) ?></option><?php endforeach; ?>
                    </select></div>
            </div>
            <div class="row g-3" data-donor-new>
                <div class="col-md-4"><label class="form-label" for="donor_kind">Donor type</label><select class="form-select" id="donor_kind" name="donor_kind" data-donor-kind><?= options_html(DONOR_KINDS, old('donor_kind', 'individual')) ?></select></div>
                <div class="col-md-8" data-donor-org><label class="form-label required" for="donor_org_name">Company / organization name</label><input class="form-control" id="donor_org_name" name="donor_org_name" maxlength="100" data-uppercase value="<?= e(old('donor_org_name')) ?>" placeholder="e.g. ABC HARDWARE"></div>
                <div class="col-md-4" data-donor-person><label class="form-label required" for="donor_first_name">First name</label><input class="form-control" id="donor_first_name" name="donor_first_name" maxlength="100" data-uppercase value="<?= e(old('donor_first_name')) ?>"></div>
                <div class="col-md-4" data-donor-person><label class="form-label required" for="donor_last_name">Last name</label><input class="form-control" id="donor_last_name" name="donor_last_name" maxlength="100" data-uppercase value="<?= e(old('donor_last_name')) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="donor_phone">Contact number</label><input class="form-control" id="donor_phone" name="donor_phone" maxlength="50" value="<?= e(old('donor_phone')) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="donor_email">E-mail</label><input class="form-control" type="email" id="donor_email" name="donor_email" maxlength="190" value="<?= e(old('donor_email')) ?>"></div>
                <div class="col-12"><div class="form-text">New donors are saved in People as Customer / Other (with a DON- number) so their receipts name them and they can be picked next time.</div></div>
            </div>
        </div>

        <div class="form-section">
            <h2 class="form-section-title">Amount</h2>
            <div class="mb-2">
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="mode" id="mode-received" value="received" <?= $mode === 'received' ? 'checked' : '' ?> data-donation-mode><label class="form-check-label" for="mode-received">Received now (issue the receipt)</label></div>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="mode" id="mode-pledge" value="pledge" <?= $mode === 'pledge' ? 'checked' : '' ?> data-donation-mode><label class="form-check-label" for="mode-pledge">Pledge only (promised, not yet received)</label></div>
            </div>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label required" for="amount" data-amount-label>Amount received</label><input class="form-control text-amount" id="amount" name="amount" required inputmode="decimal" value="<?= e(old('amount')) ?>" placeholder="0.00"></div>
                <div class="col-md-4" data-pledge-only><label class="form-label" for="expected_date">Expected by</label><input class="form-control" type="date" id="expected_date" name="expected_date" value="<?= e(old('expected_date')) ?>"></div>
            </div>
            <div class="row g-3 mt-0" data-received-only>
                <div class="col-md-4"><label class="form-label required" for="payment_method">Method</label><select class="form-select" id="payment_method" name="payment_method"><?= options_html(PAYMENT_METHODS, old('payment_method', 'cash')) ?></select></div>
                <div class="col-md-8"><label class="form-label required" for="financial_account_id">Received into</label>
                    <select class="form-select" id="financial_account_id" name="financial_account_id" data-donation-account data-allowed="<?= e((string) json_encode(PAYMENT_METHOD_ACCOUNT_TYPES)) ?>">
                        <option value="">Select account…</option>
                        <?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" data-type="<?= e($account['account_type']) ?>" data-funds="<?= e($account['fund_ids']) ?>"<?= (string) old('financial_account_id') === (string) $account['id'] ? ' selected' : '' ?>><?= e(financial_account_label($account)) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text text-danger d-none" data-donation-no-account>You have no cash box or account linked to this fund. Ask the treasurer to link one (Funds → the fund → Financial Accounts).</div></div>
                <div class="col-md-3"><label class="form-label required" for="payment_date">Date received</label><input class="form-control" type="date" id="payment_date" name="payment_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('payment_date', date('Y-m-d'))) ?>"></div>
                <div class="col-md-3"><label class="form-label" for="payment_time">Time</label><input class="form-control" type="time" id="payment_time" name="payment_time" value="<?= e(old('payment_time', date('H:i'))) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="external_reference">Reference no. <span class="text-muted-sm">(required for GCash/bank/cheque)</span></label><input class="form-control" id="external_reference" name="external_reference" maxlength="150" value="<?= e(old('external_reference')) ?>"></div>
                <div class="col-md-6"><?= attachment_input('attachment', 'Proof (deposit slip, transfer screenshot, letter)') ?></div>
            </div>
            <div class="row g-3 mt-0">
                <div class="col-md-6"><label class="form-label" for="notes">Notes</label><input class="form-control" id="notes" name="notes" maxlength="2000" value="<?= e(old('notes')) ?>"></div>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url('donations/index.php' . ($eventId ? '?event=' . $eventId : ''))) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit" <?= $ready ? '' : 'disabled' ?>><i class="bi bi-gift me-1" aria-hidden="true"></i><span data-submit-label>Record &amp; Issue Receipt</span></button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
