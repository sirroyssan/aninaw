<?php
/**
 * Events with collection progress.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/events.php';
require_institution_context();
require_permission(['events.view', 'events.manage']);

$search = get_str('q', 100);
$status = get_str('status', 20);
$mine = get_str('mine', 1) === '1';
$where = ['e.institution_id = ?'];
$params = [current_institution_id()];
if ($search !== '') {
    $where[] = '(e.event_name LIKE ? OR e.event_code LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%");
}
if (in_array($status, EVENT_STATUSES, true)) {
    $where[] = 'e.status = ?';
    $params[] = $status;
}
if ($mine) {
    $where[] = "(e.created_by = ? OR EXISTS (SELECT 1 FROM event_organizers eo WHERE eo.institution_id = e.institution_id AND eo.event_id = e.id AND eo.membership_id = ? AND eo.status = 'active'))";
    array_push($params, auth_user()['id'], current_membership_id());
}
$whereSql = implode(' AND ', $where);
[$orderBy, $sortKey, $sortDir] = sort_clause(['date' => 'COALESCE(e.start_date, e.created_at)', 'name' => 'e.event_name', 'deadline' => 'e.collection_deadline', 'status' => 'e.status'], 'date', 'desc');
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM events e WHERE {$whereSql}", $params));
$events = db_all(
    "SELECT e.id, e.event_code, e.event_name, e.status, e.start_date, e.end_date, e.collection_deadline,
            (SELECT GROUP_CONCAT(f.fund_name SEPARATOR ', ') FROM event_funds ef JOIN fund_accounts f ON f.id = ef.fund_id
              WHERE ef.institution_id = e.institution_id AND ef.event_id = e.id AND ef.status = 'active') AS funds
     FROM events e WHERE {$whereSql} ORDER BY {$orderBy}, e.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
foreach ($events as &$event) {
    $event['stats'] = event_collection_stats((int) $event['id']);
}
unset($event);
$currency = current_currency();

$pageTitle = 'Events';
$activeNav = 'events';
$breadcrumb = [auth_context()['label'], 'Collections'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Events and their collection requirements. Progress uses posted payments only.',
    can('events.manage') ? button_link('events/create.php', 'New Event', 'bi-calendar-plus') : '') ?>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Event name or code"></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(array_combine(EVENT_STATUSES, array_map('humanize', EVENT_STATUSES)), $status, 'All') ?></select></div>
        <?php if (can('events.manage')): ?>
            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="mine" name="mine" value="1" <?= $mine ? 'checked' : '' ?> data-autosubmit><label class="form-check-label" for="mine">My events</label></div>
        <?php endif; ?>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
    </form>
    <?php if (!$events): ?>
        <div class="card-body"><?= empty_state('bi-calendar-event', 'No events found', can('events.manage') ? 'Create an event such as ICS Fest and define what each participant must pay.' : '') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr>
                    <th><?= sort_link('Event', 'name', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Deadline', 'deadline', $sortKey, $sortDir) ?></th>
                    <th class="text-end">Expected</th><th class="text-end">Received</th><th style="min-width:150px">Progress</th>
                    <th><?= sort_link('Status', 'status', $sortKey, $sortDir) ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($events as $row): $s = $row['stats']; ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($row['event_name']) ?></div><div class="text-muted-sm"><?= e($row['event_code'] . ($row['funds'] ? ' · ' . $row['funds'] : ' · no fund')) ?></div></td>
                        <td class="text-muted-sm"><?= e(format_date($row['collection_deadline'])) ?></td>
                        <td class="text-end"><?= e(money($s['expected'], $currency)) ?></td>
                        <td class="text-end"><?= e(money($s['received'], $currency)) ?></td>
                        <td><?= progress_bar($s['percent']) ?><div class="text-muted-sm mt-1"><?= e((string) $s['percent']) ?>% · <?= e($s['paid_count'] . '/' . $s['participants']) ?> paid</div></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('events/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
