<?php
/**
 * A person's card for pop-ups (?id=&event=&fragment=1): details, their charges in the event and, for
 * people managers, an "Edit details" form. Saving returns to the page the pop-up was opened from.
 * Without JavaScript the names link to participants/view.php instead.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission(['participants.view', 'organization.manage']);

if (is_post()) {
    handle_post(function () {
        require_permission('organization.manage');
        $participantId = post_int('participant_id') ?? 0;
        $participant = find_participant($participantId) ?? fail_user('Person not found.');
        save_participant($participantId, edit_person_input($participant, $_POST), true);
        flash('success', 'Details saved', person_name_case(post_str('first_name', 100) . ' ' . post_str('last_name', 100)) . '\'s details were updated.');
        redirect_back('participants/view.php?id=' . $participantId);
    });
}

$participantId = (int) (get_int('id') ?? 0);
if (get_str('fragment', 1) !== '1') {
    redirect('participants/view.php?id=' . $participantId); // opened directly: the full profile page
}
$participant = find_participant($participantId);
if (!$participant) {
    http_response_code(404);
    echo empty_state('bi-person-x', 'Person not found');
    exit;
}
$eventId = get_int('event');
$canEdit = can('organization.manage');
$currency = current_currency();
$charges = $eventId && can('collections.view') ? db_all(
    "SELECT r.rule_name, o.amount_due, o.waived_amount, o.status,
            (SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa JOIN payments pp ON pp.id = pa.payment_id AND pp.institution_id = pa.institution_id AND pp.status = 'posted'
              WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id) AS paid
     FROM event_obligations o JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
     WHERE o.institution_id = ? AND o.event_id = ? AND o.participant_id = ? AND o.status <> 'cancelled' ORDER BY r.id",
    [current_institution_id(), $eventId, $participantId]
) : [];
$groups = db_all("SELECT g.group_name FROM group_memberships gm JOIN `groups` g ON g.id = gm.group_id AND g.institution_id = gm.institution_id
    WHERE gm.institution_id = ? AND gm.participant_id = ? AND gm.status = 'active' ORDER BY g.group_name", [current_institution_id(), $participantId]);
$reopen = old('action') === 'edit_person' && (int) old('participant_id') === $participantId;
$middle = $participant['middle_name'] ?: ($participant['no_middle_name'] ? 'None' : '—');
?>
<div class="p-3" data-person-card data-person-name="<?= e($participant['last_name'] . ', ' . $participant['first_name']) ?>">
    <dl class="detail-grid mb-3">
        <div><dt>ID number</dt><dd><?= e($participant['participant_number']) ?></dd></div>
        <div><dt>Type</dt><dd><?= e(PARTICIPANT_TYPES[$participant['participant_type']] ?? '') ?><?= $participant['status'] !== 'active' ? ' · ' . status_badge($participant['status']) : '' ?></dd></div>
        <div><dt>Middle name</dt><dd><?= e($middle) ?></dd></div>
        <div><dt>Program</dt><dd><?= e($participant['program_code'] ? $participant['program_code'] . ' · ' . $participant['program_name'] : '—') ?></dd></div>
        <div><dt>Birthday</dt><dd><?= e($participant['birth_date'] ? format_date($participant['birth_date']) : '—') ?></dd></div>
        <div><dt>Phone · E-mail</dt><dd><?= e(trim(($participant['phone'] ?? '') . ' · ' . ($participant['email'] ?? ''), ' ·') ?: '—') ?></dd></div>
        <div><dt>Parent / guardian</dt><dd><?= e($participant['guardian_name'] ?: '—') ?><?= $participant['guardian_phone'] ? '<div class="text-muted-sm">' . e($participant['guardian_phone']) . '</div>' : '' ?></dd></div>
        <div><dt>Address</dt><dd><?= e($participant['address'] ?: '—') ?></dd></div>
        <div><dt>Groups</dt><dd><?= e(implode(', ', array_column($groups, 'group_name')) ?: '—') ?></dd></div>
    </dl>

    <?php if ($charges): $due = 0; $paid = 0; ?>
        <h3 class="h6">Charges in this event</h3>
        <div class="table-responsive mb-3"><table class="table table-clean table-sm align-middle mb-0">
            <thead><tr><th>Collection</th><th class="text-end">Due</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
            <tbody><?php foreach ($charges as $row): $rowDue = to_cents($row['amount_due']) - to_cents($row['waived_amount']); $rowPaid = to_cents($row['paid']); $due += $rowDue; $paid += $rowPaid; ?>
                <tr><td><?= e($row['rule_name']) ?></td><td class="text-end"><?= e(money($rowDue, $currency)) ?></td><td class="text-end"><?= e(money($rowPaid, $currency)) ?></td>
                    <td class="text-end fw-semibold"><?= e(money(max(0, $rowDue - $rowPaid), $currency)) ?></td><td><?= status_badge($row['status']) ?></td></tr>
            <?php endforeach; ?></tbody>
            <tfoot><tr><th>Total</th><th class="text-end"><?= e(money($due, $currency)) ?></th><th class="text-end"><?= e(money($paid, $currency)) ?></th><th class="text-end"><?= e(money(max(0, $due - $paid), $currency)) ?></th><th></th></tr></tfoot>
        </table></div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <details class="person-edit"<?= $reopen ? ' open' : '' ?>>
            <summary class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit details</summary>
            <form method="post" action="<?= e(url('participants/card.php')) ?>" class="mt-3">
                <?= csrf_field() ?><?= idempotency_field() ?>
                <input type="hidden" name="action" value="edit_person"><input type="hidden" name="participant_id" value="<?= (int) $participantId ?>">
                <?php $pf = static fn(string $key, $default = '') => $reopen ? old($key, $default) : ($participant[$key] ?? $default); $pfPrefix = 'pe'; $pfType = $participant['participant_type']; require APP_ROOT . '/includes/person_fields.php'; ?>
                <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Details</button></div>
            </form>
        </details>
    <?php endif; ?>
</div>
