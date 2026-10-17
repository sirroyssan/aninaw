<?php
/**
 * Participant profile: identity, groups, obligations and payment history.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission(['participants.view', 'organization.manage']);

$participant = require_found(find_participant(require_get_id()));
$participantId = (int) $participant['id'];

$groups = db_all(
    "SELECT gm.academic_year, gm.term, gm.status, gm.start_date, gm.end_date, g.id AS group_id, g.group_name, g.group_code
     FROM group_memberships gm JOIN `groups` g ON g.id = gm.group_id AND g.institution_id = gm.institution_id
     WHERE gm.institution_id = ? AND gm.participant_id = ? ORDER BY gm.status = 'active' DESC, gm.start_date DESC",
    [current_institution_id(), $participantId]
);
$obligations = can('collections.view') ? db_all(
    "SELECT o.id, o.amount_due, o.waived_amount, o.due_date, o.status, e.id AS event_id, e.event_name, cr.rule_name,
            (SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa
               JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
              WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id) AS paid
     FROM event_obligations o
     JOIN events e ON e.id = o.event_id AND e.institution_id = o.institution_id
     JOIN collection_rules cr ON cr.id = o.collection_rule_id AND cr.institution_id = o.institution_id
     WHERE o.institution_id = ? AND o.participant_id = ? ORDER BY o.created_at DESC",
    [current_institution_id(), $participantId]
) : [];
$payments = can('collections.view') ? db_all(
    "SELECT p.id, p.payment_number, p.payment_date, p.payment_method, p.amount, p.status, r.receipt_number, r.receipt_status
     FROM payments p LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id
     WHERE p.institution_id = ? AND p.payer_participant_id = ? ORDER BY p.payment_date DESC, p.id DESC LIMIT 50",
    [current_institution_id(), $participantId]
) : [];

$pageTitle = person_name($participant);
$activeNav = 'participants';
$breadcrumb = [auth_context()['label'], 'People'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($participant['participant_number'] . ' · ' . (PARTICIPANT_TYPES[$participant['participant_type']] ?? $participant['participant_type']),
    button_link('participants/index.php', 'All People', 'bi-arrow-left', 'btn-outline-secondary')
    . (can('collections.record') && $participant['status'] === 'active' ? button_link('collections/record_payment.php?participant=' . $participantId, 'Record Payment', 'bi-cash-coin') : '')
    . (can('organization.manage') ? button_link('participants/form.php?id=' . $participantId, 'Edit', 'bi-pencil', 'btn-outline-primary') : '')) ?>

<div class="row g-3">
    <div class="col-xl-4">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Details</h3><?= status_badge($participant['status']) ?></div>
            <div class="card-body">
                <dl class="detail-grid" style="grid-template-columns: 1fr">
                    <div><dt>Full name</dt><dd><?= e(trim($participant['first_name'] . ' ' . ($participant['middle_name'] ?? '') . ' ' . $participant['last_name'])) ?></dd></div>
                    <div><dt>E-mail</dt><dd><?= e($participant['email'] ?: '—') ?></dd></div>
                    <div><dt>Phone</dt><dd><?= e($participant['phone'] ?: '—') ?></dd></div>
                    <div><dt>Birthday</dt><dd><?= e($participant['birth_date'] ? format_date($participant['birth_date']) : '—') ?></dd></div>
                    <div><dt>Program</dt><dd><?= e($participant['program_code'] ? $participant['program_code'] . ' — ' . $participant['program_name'] : '—') ?></dd></div>
                    <div><dt>Address</dt><dd><?= e($participant['address'] ?: '—') ?></dd></div>
                    <div><dt>Parent / guardian</dt><dd><?= e($participant['guardian_name'] ? $participant['guardian_name'] . ($participant['guardian_relationship'] ? ' (' . $participant['guardian_relationship'] . ')' : '') : '—') ?><?= $participant['guardian_phone'] ? '<div class="text-muted-sm">' . e($participant['guardian_phone']) . '</div>' : '' ?></dd></div>
                    <div><dt>Linked login</dt><dd><?= $participant['user_id'] ? e($participant['linked_user_name'] . ' · ' . $participant['linked_user_email']) : '<span class="text-muted-sm">Not linked</span>' ?></dd></div>
                    <div><dt>Registered</dt><dd><?= e(format_date($participant['created_at'])) ?></dd></div>
                </dl>
            </div>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>Groups</h3></div>
            <?php if (!$groups): ?>
                <div class="card-body"><?= empty_state('bi-diagram-3', 'Not in any group') ?></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($groups as $row): ?>
                        <li><div><a class="fw-semibold" href="<?= e(url('groups/view.php?id=' . $row['group_id'])) ?>"><?= e($row['group_name']) ?></a><div class="text-muted-sm"><?= e(trim(($row['academic_year'] ?? '') . ' ' . ($row['term'] ?? '')) ?: $row['group_code']) ?></div></div><?= status_badge($row['status']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-8">
        <?php if (can('collections.view')): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Obligations</h3></div>
                <?php if (!$obligations): ?>
                    <div class="card-body"><?= empty_state('bi-card-checklist', 'No obligations') ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-clean align-middle mb-0">
                            <thead><tr><th>Event · Collection</th><th class="text-end">Due</th><th class="text-end">Waived</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($obligations as $row): ?>
                                <?php $balance = max(0, to_cents($row['amount_due']) - to_cents($row['waived_amount']) - to_cents($row['paid'])); ?>
                                <tr>
                                    <td><div class="fw-semibold"><?= e($row['event_name']) ?></div><div class="text-muted-sm"><?= e($row['rule_name']) ?><?= $row['due_date'] ? ' · due ' . e(format_date($row['due_date'])) : '' ?></div></td>
                                    <td class="text-end"><?= e(m($row['amount_due'])) ?></td>
                                    <td class="text-end"><?= e(m($row['waived_amount'])) ?></td>
                                    <td class="text-end"><?= e(m($row['paid'])) ?></td>
                                    <td class="text-end fw-semibold"><?= e(money($balance, current_currency())) ?></td>
                                    <td><?= status_badge($row['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card panel">
                <div class="card-header"><h3>Payment History</h3></div>
                <?php if (!$payments): ?>
                    <div class="card-body"><?= empty_state('bi-receipt', 'No payments recorded') ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-clean align-middle mb-0">
                            <thead><tr><th>Payment</th><th>Receipt</th><th>Method</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($payments as $row): ?>
                                <tr>
                                    <td><div class="fw-semibold"><?= e($row['payment_number']) ?></div><div class="text-muted-sm"><?= e(format_datetime($row['payment_date'])) ?></div></td>
                                    <td><?= e($row['receipt_number'] ?: '—') ?><?= $row['receipt_status'] === 'voided' ? ' ' . status_badge('voided') : '' ?></td>
                                    <td><?= e(humanize($row['payment_method'])) ?></td>
                                    <td class="text-end"><?= e(m($row['amount'])) ?></td>
                                    <td><?= status_badge($row['status']) ?></td>
                                    <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('collections/payment.php?id=' . $row['id'])) ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="card panel"><div class="card-body"><?= empty_state('bi-lock', 'Collections are not visible with your role') ?></div></div>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
