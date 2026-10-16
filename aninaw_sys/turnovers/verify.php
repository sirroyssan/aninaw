<?php
/**
 * Cashier queue: turnovers assigned to me that need counting or are awaiting the sender.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/turnovers.php';
require_institution_context();
require_permission('turnovers.receive');

$rows = db_all(
    "SELECT t.id, t.turnover_number, t.status, t.declared_amount, t.received_amount, t.submitted_at, e.event_name, g.group_name,
            CONCAT(u.first_name, ' ', u.last_name) AS sender,
            (SELECT COALESCE(SUM(d.amount_included), 0) FROM cash_turnover_details d WHERE d.institution_id = t.institution_id AND d.turnover_id = t.id) AS payments_total
     FROM cash_turnovers t
     JOIN events e ON e.id = t.event_id AND e.institution_id = t.institution_id
     JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
     JOIN institution_memberships m ON m.id = t.submitted_by_membership_id JOIN users u ON u.id = m.user_id
     WHERE t.institution_id = ? AND t.received_by_membership_id = ? AND t.status IN ('submitted', 'received_pending_verification')
     ORDER BY t.status = 'submitted' DESC, t.submitted_at",
    [current_institution_id(), current_membership_id()]
);

$pageTitle = 'Turnovers Awaiting Me';
$activeNav = 'turnovers';
$breadcrumb = [auth_context()['label'], 'Cash Turnovers'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Count each hand-over in front of the collector, then record the amount actually received.', button_link('turnovers/index.php', 'All Turnovers', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<div class="card panel">
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-inbox', 'Nothing waiting', 'Turnovers submitted to you will appear here.') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Turnover</th><th>From</th><th class="text-end">Payments</th><th class="text-end">Declared</th><th>Next step</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($row['turnover_number']) ?></div><div class="text-muted-sm"><?= e($row['event_name'] . ' · ' . $row['group_name']) ?></div></td>
                        <td><?= e($row['sender']) ?><div class="text-muted-sm"><?= e(format_datetime($row['submitted_at'])) ?></div></td>
                        <td class="text-end"><?= e(m($row['payments_total'])) ?></td>
                        <td class="text-end fw-semibold"><?= e(m($row['declared_amount'])) ?></td>
                        <td><?= $row['status'] === 'submitted' ? '<span class="badge badge-soft-warning">Count the cash</span>' : '<span class="badge badge-soft-neutral">Waiting for collector</span>' ?></td>
                        <td class="actions"><a class="btn btn-<?= $row['status'] === 'submitted' ? 'primary' : 'outline-primary' ?> btn-xs" href="<?= e(url('turnovers/view.php?id=' . $row['id'])) ?>"><?= $row['status'] === 'submitted' ? 'Count' : 'Open' ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
