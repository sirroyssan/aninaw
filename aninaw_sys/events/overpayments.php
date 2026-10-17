<?php
/**
 * Overpayments of an event (money paid above a corrected amount): refund it in cash with a slip,
 * or apply it to the same person's other unpaid charges. Lists the settlements already made.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission(['collections.view', 'collections.record']);

$eventId = (int) (get_int('event') ?? 0);
$event = require_found(find_event($eventId));
if (!corrections_ready()) {
    render_error_page(503, 'Not Set Up Yet', 'Import database/migration_011_rule_corrections.sql once (phpMyAdmin → Import).');
}

if (is_post()) {
    handle_post(function () use ($eventId) {
        $obligationId = post_int('obligation_id') ?? 0;
        if (!db_value('SELECT 1 FROM event_obligations WHERE institution_id = ? AND id = ? AND event_id = ?', [current_institution_id(), $obligationId, $eventId])) {
            fail_user('Charge not found in this event.');
        }
        if (post_str('action', 10) === 'refund') {
            $id = refund_overpayment($obligationId, $_POST);
            flash('success', 'Refund recorded', 'Print the refund slip and have the payer sign it.');
            return 'events/refund_slip.php?id=' . $id;
        }
        apply_overpayment($obligationId, $_POST);
        flash('success', 'Overpayment applied', 'It now pays the other charge. No cash moved.');
        return 'events/overpayments.php?event=' . $eventId;
    });
}

$rows = event_overpayments($eventId);
$currency = current_currency();
$canRefund = can('payments.reverse');
$canApply = can('collections.record');
$history = db_all(
    "SELECT a.id, a.adjustment_number, a.adjustment_type, a.amount, a.adjustment_date, a.reason, p.first_name, p.last_name, p.participant_number,
            fr.rule_name AS from_rule, tr.rule_name AS to_rule, fa.account_name, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
     FROM payment_adjustments a
     JOIN participants p ON p.id = a.participant_id AND p.institution_id = a.institution_id
     JOIN event_obligations fo ON fo.id = a.from_obligation_id AND fo.institution_id = a.institution_id
     JOIN collection_rules fr ON fr.id = fo.collection_rule_id AND fr.institution_id = fo.institution_id
     LEFT JOIN event_obligations tob ON tob.id = a.to_obligation_id AND tob.institution_id = a.institution_id
     LEFT JOIN collection_rules tr ON tr.id = tob.collection_rule_id AND tr.institution_id = tob.institution_id
     LEFT JOIN financial_accounts fa ON fa.id = a.financial_account_id AND fa.institution_id = a.institution_id
     JOIN users u ON u.id = a.created_by
     WHERE a.institution_id = ? AND a.event_id = ? ORDER BY a.id DESC",
    [current_institution_id(), $eventId]
);
$corrections = db_all("SELECT c.*, r.rule_name, CONCAT(u.first_name, ' ', u.last_name) AS by_name FROM rule_corrections c
    JOIN collection_rules r ON r.id = c.collection_rule_id JOIN users u ON u.id = c.created_by WHERE c.institution_id = ? AND c.event_id = ? ORDER BY c.id DESC", [current_institution_id(), $eventId]);
$fundIds = array_map(static fn($f) => (int) $f['fund_id'], event_funds($eventId));
$accounts = [];
if ($canRefund) {
    foreach ($fundIds as $fundId) {
        foreach (usable_financial_accounts($fundId) as $account) {
            $accounts[$account['id']] = financial_account_label($account);
        }
    }
}

$pageTitle = 'Overpayments';
$activeNav = 'events';
$breadcrumb = [auth_context()['label'], 'Events', $event['event_name']];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($event['event_name'] . ' · money paid above a corrected amount, owed back to the payer.',
    button_link('events/view.php?id=' . $eventId . '&tab=obligations', 'Balances', 'bi-arrow-left', 'btn-outline-secondary')) ?>

<div class="stat-grid">
    <?= stat_card('bi-arrow-counterclockwise', 'Still owed back', money(array_sum(array_column($rows, 'overpaid')), $currency), count($rows) . ' charge(s) to settle', $rows ? 'warning' : 'success') ?>
    <?= stat_card('bi-cash', 'Refunded', money(to_cents((string) array_sum(array_map(static fn($h) => $h['adjustment_type'] === 'refund' ? (float) $h['amount'] : 0, $history))), $currency), 'Cash given back') ?>
    <?= stat_card('bi-arrow-left-right', 'Applied', money(to_cents((string) array_sum(array_map(static fn($h) => $h['adjustment_type'] === 'apply' ? (float) $h['amount'] : 0, $history))), $currency), 'Moved to other charges') ?>
</div>

<div class="card panel mb-3">
    <div class="card-header"><h3>To settle</h3></div>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-check2-all', 'Nothing is owed back', 'Overpayments appear here after a collection amount is corrected downward.') ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-clean align-middle mb-0">
            <thead><tr><th>Person</th><th>Charge</th><th class="text-end">Paid</th><th class="text-end">Now due</th><th class="text-end">Overpaid</th><th style="min-width:460px">Settle</th></tr></thead>
            <tbody><?php foreach ($rows as $row): $due = to_cents($row['amount_due']) - to_cents($row['waived_amount']); ?>
                <tr>
                    <td><div class="fw-semibold"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></div><div class="text-muted-sm"><?= e($row['participant_number']) ?></div></td>
                    <td><?= e($row['rule_name']) ?></td>
                    <td class="text-end"><?= e(m($row['paid'])) ?></td>
                    <td class="text-end"><?= e(money($due, $currency)) ?></td>
                    <td class="text-end fw-semibold text-warning-emphasis"><?= e(money($row['overpaid'], $currency)) ?></td>
                    <td>
                        <?php if ($canApply && $row['open_charges']): $first = $row['open_charges'][0]; ?>
                            <form method="post" class="d-flex flex-wrap gap-2 mb-2" data-confirm="Apply to another charge?" data-confirm-text="The overpaid money pays the chosen charge of the same person. No cash moves.">
                                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="obligation_id" value="<?= (int) $row['id'] ?>">
                                <select class="form-select form-select-sm w-auto" name="to_obligation_id" aria-label="Apply to"><?php foreach ($row['open_charges'] as $charge): ?><option value="<?= (int) $charge['id'] ?>"><?= e($charge['rule_name'] . ' (owes ' . money($charge['balance'], $currency) . ')') ?></option><?php endforeach; ?></select>
                                <input class="form-control form-control-sm text-amount" style="width:110px" name="amount" value="<?= e(cents_to_decimal(min($row['overpaid'], (int) $first['balance']))) ?>" aria-label="Amount to apply">
                                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Apply</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($canRefund): ?>
                            <form method="post" class="d-flex flex-wrap gap-2" data-confirm="Refund <?= e(money($row['overpaid'], $currency)) ?> to <?= e($row['first_name'] . ' ' . $row['last_name']) ?>?" data-confirm-text="The cash leaves the account you choose. Print the refund slip for the payer to sign." data-reason="Reason (e.g. registration corrected to ₱1,500; ₱250 returned)">
                                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="refund"><input type="hidden" name="obligation_id" value="<?= (int) $row['id'] ?>">
                                <select class="form-select form-select-sm w-auto" name="financial_account_id" required aria-label="Refund from"><?= options_html($accounts, count($accounts) === 1 ? array_key_first($accounts) : null, 'Refund from…') ?></select>
                                <input class="form-control form-control-sm text-amount" style="width:110px" name="amount" value="<?= e(cents_to_decimal($row['overpaid'])) ?>" aria-label="Amount to refund">
                                <input class="form-control form-control-sm w-auto" type="date" name="refund_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" aria-label="Refund date">
                                <button class="btn btn-outline-danger btn-sm" type="submit" <?= $accounts ? '' : 'disabled' ?>><i class="bi bi-cash me-1" aria-hidden="true"></i>Refund</button>
                            </form>
                        <?php elseif (!$row['open_charges']): ?>
                            <span class="text-muted-sm">Ask the treasurer to refund it.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if ($canRefund && !$accounts): ?><div class="card-footer-note text-danger">You have no account linked to this event's fund to refund from.</div><?php endif; ?>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card panel">
            <div class="card-header"><h3>Settled</h3></div>
            <?php if (!$history): ?>
                <div class="card-body"><?= empty_state('bi-clock-history', 'No refunds or applied overpayments yet') ?></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-clean table-sm align-middle mb-0">
                    <thead><tr><th>Reference</th><th>Person</th><th>What</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody><?php foreach ($history as $h): ?>
                        <tr>
                            <td><span class="fw-semibold"><?= e($h['adjustment_number']) ?></span><div class="text-muted-sm"><?= e(format_date($h['adjustment_date']) . ' · ' . $h['created_by_name']) ?></div></td>
                            <td><?= e($h['last_name'] . ', ' . $h['first_name']) ?></td>
                            <td><?= $h['adjustment_type'] === 'refund' ? 'Refunded from ' . e($h['account_name']) : 'Applied: ' . e($h['from_rule']) . ' → ' . e($h['to_rule']) ?><div class="text-muted-sm"><?= e($h['reason']) ?></div></td>
                            <td class="text-end fw-semibold"><?= e(m($h['amount'])) ?></td>
                            <td class="actions"><?php if ($h['adjustment_type'] === 'refund'): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('events/refund_slip.php?id=' . $h['id'])) ?>">Slip</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card panel">
            <div class="card-header"><h3>Amount corrections</h3></div>
            <?php if (!$corrections): ?>
                <div class="card-body text-muted-sm">None.</div>
            <?php else: ?>
                <ul class="list-clean"><?php foreach ($corrections as $c): ?>
                    <li><div><div class="fw-semibold"><?= e($c['rule_name']) ?>: <?= e(m($c['old_amount'])) ?> → <?= e(m($c['new_amount'])) ?></div>
                        <div class="text-muted-sm"><?= e(format_datetime($c['created_at']) . ' · ' . $c['by_name'] . ' · ' . $c['charges_updated'] . ' charge(s) · overpaid ' . m($c['overpaid_total'])) ?></div><div class="text-muted-sm"><?= e($c['reason']) ?></div></div></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
