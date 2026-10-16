<?php
/**
 * Record a participant payment (full or partial) and issue the receipt.
 * Step 1: find the participant. Step 2: pick the event and enter amounts per obligation.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission('collections.record');

if (is_post()) {
    handle_post(function () {
        $paymentId = record_payment($_POST);
        flash('success', 'Payment recorded', 'The receipt is ready to print.');
        return 'collections/payment.php?id=' . $paymentId;
    });
}

$institutionId = (int) current_institution_id();
$participantId = get_int('participant');
$eventId = get_int('event');
$participant = $participantId ? find_participant($participantId) : null;

$results = [];
$search = get_str('q', 100);
if (!$participant) {
    [$visibility, $params] = participant_visibility('p');
    $where = "p.institution_id = ? AND p.status = 'active' AND {$visibility}";
    array_unshift($params, $institutionId);
    if ($search !== '') {
        $where .= ' AND (p.participant_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, " ", p.last_name) LIKE ?)';
        array_push($params, ...array_fill(0, 4, "%{$search}%"));
    }
    $results = db_all(
        "SELECT p.id, p.participant_number, p.first_name, p.last_name,
                (SELECT COUNT(*) FROM event_obligations o JOIN events e ON e.id = o.event_id AND e.status = 'collecting'
                  WHERE o.institution_id = p.institution_id AND o.participant_id = p.id AND o.status IN ('unpaid', 'partially_paid')) AS open_count
         FROM participants p WHERE {$where}
         HAVING open_count > 0 ORDER BY p.last_name, p.first_name LIMIT 30",
        $params
    );
}

$obligations = [];
$events = [];
if ($participant) {
    foreach (open_obligations((int) $participant['id']) as $row) {
        if ($row['event_status'] === 'collecting') {
            $events[$row['event_id']] = $row['event_name'] . ' (' . $row['event_code'] . ')';
        }
    }
    if (!$eventId && count($events) === 1) {
        $eventId = (int) array_key_first($events);
    }
    if ($eventId && isset($events[$eventId])) {
        $obligations = open_obligations((int) $participant['id'], $eventId);
    }
}
$funds = $eventId ? event_funds($eventId) : [];
$accounts = [];
foreach ($funds as $fund) {
    foreach (usable_financial_accounts((int) $fund['fund_id']) as $account) {
        $accounts[$account['id']] = financial_account_label($account);
    }
}
$currency = current_currency();

$pageTitle = 'Record Payment';
$activeNav = 'collections';
$breadcrumb = [auth_context()['label'], 'Collections'];
$pageScripts = ['js/finance.js'];
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$participant): ?>
    <div class="card panel" style="max-width: 880px">
        <div class="card-header"><h3>1. Find the payer</h3></div>
        <form class="filter-bar" method="get">
            <?php if ($eventId): ?><input type="hidden" name="event" value="<?= e($eventId) ?>"><?php endif; ?>
            <div class="filter-field grow"><label for="q">Payer (student, faculty or customer)</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="ID number or name" autofocus></div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-search me-1" aria-hidden="true"></i>Search</button>
        </form>
        <?php if (!$results): ?>
            <div class="card-body"><?= empty_state('bi-person-search', $search !== '' ? 'No one with unpaid obligations matches' : 'Search for a person', 'Only participants with unpaid obligations in events open for collection are listed.') ?></div>
        <?php else: ?>
            <ul class="list-clean">
                <?php foreach ($results as $row): ?>
                    <li><div><div class="fw-semibold"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></div><div class="text-muted-sm"><?= e($row['participant_number'] . ' · ' . $row['open_count'] . ' open obligation(s)') ?></div></div>
                        <a class="btn btn-primary btn-xs" href="<?= e(url('collections/record_payment.php?participant=' . $row['id'] . ($eventId ? '&event=' . $eventId : ''))) ?>">Select</a></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?= page_actions('Payer: ' . person_name($participant) . ' · ' . $participant['participant_number'],
        button_link('collections/record_payment.php', 'Change Payer', 'bi-arrow-left', 'btn-outline-secondary')) ?>

    <?php if (!$events): ?>
        <div class="card panel"><div class="card-body"><?= empty_state('bi-check2-circle', 'Nothing to collect', 'This person has no unpaid obligations in events open for collection.') ?></div></div>
    <?php else: ?>
        <?php if (count($events) > 1): ?>
            <form method="get" class="card panel mb-3" style="max-width: 880px">
                <input type="hidden" name="participant" value="<?= e($participant['id']) ?>">
                <div class="card-body d-flex gap-2 align-items-end">
                    <div class="flex-grow-1"><label class="form-label" for="event">Event</label><select class="form-select" id="event" name="event" data-autosubmit><?= options_html($events, $eventId, 'Select event…') ?></select></div>
                    <button class="btn btn-outline-primary" type="submit">Select</button>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($obligations): ?>
            <form method="post" enctype="multipart/form-data" class="card panel form-card" style="max-width: 980px" data-confirm="Record this payment?" data-confirm-text="The payment is posted and a receipt is issued. Corrections require an authorised reversal.">
                <?= csrf_field() ?><?= idempotency_field() ?>
                <input type="hidden" name="participant_id" value="<?= e($participant['id']) ?>">
                <input type="hidden" name="event_id" value="<?= e($eventId) ?>">
                <div class="card-header"><h3>2. Amounts for <?= e($events[$eventId]) ?></h3></div>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0" data-money-sum="#payment-total">
                        <thead><tr><th>Collection</th><th class="text-end">Balance</th><th style="width:220px">Amount paid now</th></tr></thead>
                        <tbody>
                        <?php foreach ($obligations as $row): ?>
                            <tr>
                                <td><div class="fw-semibold"><?= e($row['rule_name']) ?></div><div class="text-muted-sm"><?= $row['allow_partial_payment'] ? 'Partial payment allowed' : 'Must be paid in full' ?><?= $row['due_date'] ? ' · due ' . e(format_date($row['due_date'])) : '' ?></div></td>
                                <td class="text-end fw-semibold"><?= e(money($row['balance'], $currency)) ?></td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input class="form-control text-amount" name="alloc[<?= e($row['id']) ?>]" inputmode="decimal" placeholder="0.00" data-max="<?= e(cents_to_decimal($row['balance'])) ?>" aria-label="Amount for <?= e($row['rule_name']) ?>" value="<?= e(old('alloc')[$row['id']] ?? '') ?>">
                                        <button class="btn btn-outline-primary" type="button" data-fill-max>Full</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><th colspan="2" class="text-end">Total received</th><th><span id="payment-total" class="amount-highlight" data-currency="<?= e($currency === 'PHP' ? '₱' : $currency . ' ') ?>">0.00</span></th></tr></tfoot>
                    </table>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php if (count($funds) > 1): ?>
                            <div class="col-md-6"><label class="form-label required" for="fund_id">Receiving fund</label><select class="form-select" id="fund_id" name="fund_id" required><?= options_html(options_from_rows($funds, 'fund_id', 'fund_name'), old('fund_id'), 'Select…') ?></select></div>
                        <?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label required" for="payment_method">Method</label>
                            <select class="form-select" id="payment_method" name="payment_method" required><?= options_html(PAYMENT_METHODS, old('payment_method', 'cash')) ?></select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required" for="financial_account_id">Received into</label>
                            <select class="form-select" id="financial_account_id" name="financial_account_id" required><?= options_html($accounts, old('financial_account_id', count($accounts) === 1 ? array_key_first($accounts) : null), 'Select account…') ?></select>
                            <?php if (!$accounts): ?><div class="form-text text-danger">You are not the custodian of any account authorised for this event's fund. Ask the treasurer to set one up.</div><?php else: ?><div class="form-text">Cash goes to a cash custody account (yours, as collector or cashier).</div><?php endif; ?>
                        </div>
                        <div class="col-md-3"><label class="form-label required" for="payment_date">Payment date</label><input class="form-control" type="date" id="payment_date" name="payment_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('payment_date', date('Y-m-d'))) ?>"></div>
                        <div class="col-md-3"><label class="form-label" for="payment_time">Time</label><input class="form-control" type="time" id="payment_time" name="payment_time" value="<?= e(old('payment_time', date('H:i'))) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="external_reference">Reference no. <span class="text-muted-sm">(required for GCash/bank)</span></label><input class="form-control" id="external_reference" name="external_reference" maxlength="150" value="<?= e(old('external_reference')) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="notes">Remarks</label><input class="form-control" id="notes" name="notes" maxlength="1000" value="<?= e(old('notes')) ?>"></div>
                        <div class="col-md-6"><?= attachment_input('attachment', 'Proof of payment') ?></div>
                    </div>
                    <div class="form-actions">
                        <a class="btn btn-outline-secondary" href="<?= e(url('collections/index.php')) ?>">Cancel</a>
                        <button class="btn btn-primary" type="submit" <?= $accounts ? '' : 'disabled' ?>><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Record Payment &amp; Issue Receipt</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
