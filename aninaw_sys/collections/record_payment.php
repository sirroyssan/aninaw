<?php
/**
 * Record a participant payment (full or partial) and issue the receipt.
 * Find the participant, pick the event and enter amounts per obligation.
 * Event managers can also waive a balance here (e.g. insurance the person already has).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission('collections.record');

// Waive from inside the Pay pop-up: answered as JSON so the pop-up stays open and reloads.
if (is_post() && post_str('action', 10) === 'waive' && wants_json()) {
    require_csrf();
    try {
        waive_obligation(post_int('obligation_id') ?? 0, post_str('amount', 20), post_str('reason', 1000));
        json_response(['ok' => true, 'message' => 'Balance waived. The reason is kept in the audit log.']);
    } catch (UserFacingException $exception) {
        if (db_in_transaction()) {
            db()->rollBack();
        }
        json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
    }
}
if (is_post() && post_str('action', 10) === 'waive') {
    handle_post(function () {
        $obligationId = post_int('obligation_id') ?? 0;
        $row = db_one('SELECT participant_id, event_id FROM event_obligations WHERE institution_id = ? AND id = ?', [current_institution_id(), $obligationId]) ?? fail_user('Obligation not found.');
        waive_obligation($obligationId, post_str('amount', 20), post_str('reason', 1000));
        flash('success', 'Balance waived', 'The reason is kept in the audit log.');
        if (post_str('back', 10) === 'referer') {
            redirect_back('collections/index.php'); // waived from the Collections modal
        }
        return 'collections/record_payment.php?participant=' . $row['participant_id'] . '&event=' . $row['event_id'];
    }, false);
}
if (is_post()) {
    handle_post(function () {
        $paymentId = record_payment($_POST);
        flash('success', 'Payment recorded', 'The receipt is ready to print.');
        if (post_str('back', 10) === 'referer') {
            remember_recent_payment($paymentId);
            redirect_back('collections/index.php'); // paid from a pop-up: stay on that page
        }
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
         HAVING open_count > 0 ORDER BY p.last_name, p.first_name LIMIT " . LIST_PAGE_SIZE,
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
$canWaive = $eventId && $obligations && (find_event($eventId)['can_manage'] ?? false);
$inModal = false;

// The Collections list opens this form in a modal: return just the form.
if (get_str('fragment', 1) === '1') {
    if ($obligations) {
        $inModal = true;
        require APP_ROOT . '/includes/payment_form.php';
    } else {
        echo empty_state('bi-check2-circle', 'Nothing to collect', 'This person has no unpaid balance in this event, or the event is not open for collection.');
    }
    exit;
}

$pageTitle = 'Record Payment';
$activeNav = 'collections';
$breadcrumb = [auth_context()['label'], 'Collections'];
$pageScripts = ['js/finance.js'];
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$participant): ?>
    <div class="card panel" style="max-width: 880px">
        <div class="card-header"><h3>Find the payer</h3></div>
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
            <?php require APP_ROOT . '/includes/payment_form.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
