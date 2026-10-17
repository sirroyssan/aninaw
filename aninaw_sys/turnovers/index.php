<?php
/**
 * Cash turnover submissions.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/turnovers.php';
require_institution_context();
require_permission(['turnovers.view', 'turnovers.submit', 'turnovers.receive', 'reconciliation.manage']);

$institutionId = (int) current_institution_id();
$status = get_str('status', 40);
$eventId = get_int('event');
$search = get_str('q', 80);
$where = ['t.institution_id = ?'];
$params = [$institutionId];
// Without institution-wide access, show only turnovers you sent, received or that belong to your groups.
if (!can_any(['funds.view_all', 'reconciliation.manage', 'audit.view'])) {
    $groups = my_group_ids() ?: [0];
    $where[] = '(t.submitted_by_membership_id = ? OR t.received_by_membership_id = ? OR t.group_id IN (' . implode(',', array_fill(0, count($groups), '?')) . '))';
    array_push($params, current_membership_id(), current_membership_id(), ...$groups);
}
if (in_array($status, ['submitted', 'received_pending_verification', 'verified', 'discrepancy', 'cancelled'], true)) {
    $where[] = 't.status = ?';
    $params[] = $status;
}
if ($eventId) {
    $where[] = 't.event_id = ?';
    $params[] = $eventId;
}
if ($search !== '') {
    $where[] = 't.turnover_number LIKE ?';
    $params[] = "%{$search}%";
}
$whereSql = implode(' AND ', $where);
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM cash_turnovers t WHERE {$whereSql}", $params));
$rows = db_all(
    "SELECT t.*, e.event_name, g.group_name, CONCAT(su.first_name, ' ', su.last_name) AS sender, CONCAT(ru.first_name, ' ', ru.last_name) AS receiver,
            (SELECT COUNT(*) FROM cash_turnover_details d WHERE d.institution_id = t.institution_id AND d.turnover_id = t.id) AS payments
     FROM cash_turnovers t
     JOIN events e ON e.id = t.event_id AND e.institution_id = t.institution_id
     JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
     JOIN institution_memberships sm ON sm.id = t.submitted_by_membership_id JOIN users su ON su.id = sm.user_id
     JOIN institution_memberships rm ON rm.id = t.received_by_membership_id JOIN users ru ON ru.id = rm.user_id
     WHERE {$whereSql} ORDER BY t.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Cash Turnovers';
$activeNav = 'turnovers';
$breadcrumb = [auth_context()['label'], 'Collections'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Hand-overs of collected cash from collectors to cashiers. A turnover moves custody; it is not new income.',
    (can('turnovers.receive') ? button_link('turnovers/verify.php', 'Awaiting My Count', 'bi-inbox', 'btn-outline-primary') : '')
    . (can('turnovers.submit') ? button_link('turnovers/submit.php', 'Prepare Turnover', 'bi-box-arrow-up-right') : '')) ?>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field grow"><label for="q">Turnover no.</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>"></div>
        <div class="filter-field"><label for="event">Event</label><select class="form-select" id="event" name="event" data-autosubmit><?= options_html(event_options(['collecting', 'completed', 'closed']), $eventId, 'All') ?></select></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(['submitted' => 'Submitted', 'received_pending_verification' => 'Counted, awaiting sender', 'verified' => 'Verified', 'discrepancy' => 'Discrepancy', 'cancelled' => 'Cancelled'], $status, 'All') ?></select></div>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-arrow-left-right', 'No turnovers', can('turnovers.submit') ? 'Prepare a turnover when you are ready to hand collected cash to your cashier.' : '') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Turnover</th><th>Event · Group</th><th>From → To</th><th class="text-end">Declared</th><th class="text-end">Received</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $mismatch = $row['received_amount'] !== null && to_cents($row['received_amount']) !== to_cents($row['declared_amount']); ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($row['turnover_number']) ?></div><div class="text-muted-sm"><?= e(format_datetime($row['submitted_at']) . ' · ' . $row['payments'] . ' payment(s)') ?></div></td>
                        <td><?= e($row['event_name']) ?><div class="text-muted-sm"><?= e($row['group_name']) ?></div></td>
                        <td class="text-muted-sm"><?= e($row['sender'] . ' → ' . $row['receiver']) ?></td>
                        <td class="text-end"><?= e(m($row['declared_amount'])) ?></td>
                        <td class="text-end<?= $mismatch ? ' text-danger fw-semibold' : '' ?>"><?= $row['received_amount'] === null ? '<span class="text-muted-sm">—</span>' : e(m($row['received_amount'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('turnovers/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
