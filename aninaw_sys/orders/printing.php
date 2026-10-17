<?php
/**
 * Printing and other service jobs: queue by status, assigned staff and date.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/orders.php';
require_institution_context();
require_permission(['orders.view', 'orders.manage']);

$institutionId = (int) current_institution_id();
$filters = [
    'status'   => isset(SERVICE_STATUSES[get_str('status', 20)]) ? get_str('status', 20) : (isset($_GET['status']) ? '' : 'open'),
    'assigned' => get_int('assigned'),
    'mine'     => get_str('mine', 1) === '1',
    'from'     => input_date($_GET, 'from'),
    'to'       => input_date($_GET, 'to'),
    'q'        => get_str('q', 100),
];
$where = ["i.institution_id = ?", "i.fulfillment = 'service'", "o.order_status <> 'cancelled'"];
$params = [$institutionId];
if ($filters['status'] === 'open') {
    $where[] = "i.service_status IN ('pending', 'in_progress', 'completed')";
} elseif ($filters['status']) {
    $where[] = 'i.service_status = ?';
    $params[] = $filters['status'];
}
if ($filters['mine']) {
    $where[] = 'i.assigned_membership_id = ?';
    $params[] = current_membership_id();
} elseif ($filters['assigned']) {
    $where[] = 'i.assigned_membership_id = ?';
    $params[] = $filters['assigned'];
}
if ($filters['from']) {
    $where[] = 'i.service_date >= ?';
    $params[] = $filters['from'];
}
if ($filters['to']) {
    $where[] = 'i.service_date <= ?';
    $params[] = $filters['to'];
}
if ($filters['q'] !== '') {
    $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
    $where[] = '(o.order_number LIKE ? OR pt.last_name LIKE ? OR pt.first_name LIKE ? OR pt.participant_number LIKE ? OR i.description LIKE ?)';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);
$from = "FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
         JOIN participants pt ON pt.id = o.customer_participant_id AND pt.institution_id = o.institution_id
         LEFT JOIN institution_memberships am ON am.id = i.assigned_membership_id AND am.institution_id = i.institution_id LEFT JOIN users au ON au.id = am.user_id";
$counts = db_query("SELECT i.service_status, COUNT(*) FROM sales_order_items i JOIN sales_orders o ON o.id = i.order_id AND o.institution_id = i.institution_id
                    WHERE i.institution_id = ? AND i.fulfillment = 'service' AND o.order_status <> 'cancelled' GROUP BY i.service_status", [$institutionId])->fetchAll(PDO::FETCH_KEY_PAIR);
$pagination = paginate((int) db_value("SELECT COUNT(*) {$from} WHERE {$whereSql}", $params));
$rows = db_all(
    "SELECT i.*, o.order_number, o.payment_status, o.order_status, pt.first_name, pt.last_name, pt.participant_number, CONCAT(au.first_name, ' ', au.last_name) AS assigned_name
     {$from} WHERE {$whereSql} ORDER BY FIELD(i.service_status, 'in_progress', 'pending', 'completed', 'released'), i.service_date, i.id
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Printing Jobs';
$activeNav = 'printing';
$breadcrumb = [auth_context()['label'], 'Orders'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Printing, photocopying, binding and other service jobs. Open the order to start, complete or release a job.',
    can('orders.manage') ? button_link('orders/create.php', 'New Printing Job', 'bi-plus-lg') : '') ?>
<div class="stat-grid">
    <?= stat_card('bi-hourglass', 'Pending', number_format((int) ($counts['pending'] ?? 0)), 'Not started', 'warning') ?>
    <?= stat_card('bi-printer', 'In progress', number_format((int) ($counts['in_progress'] ?? 0)), 'Being printed', 'info') ?>
    <?= stat_card('bi-check2-circle', 'Completed', number_format((int) ($counts['completed'] ?? 0)), 'Waiting to be claimed') ?>
    <?= stat_card('bi-bag-check', 'Released', number_format((int) ($counts['released'] ?? 0)), 'Claimed by customers') ?>
</div>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($filters['q']) ?>" placeholder="Order, customer, job"></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['open' => 'Not yet released'] + SERVICE_STATUSES, $filters['status'], 'All') ?></select></div>
        <div class="filter-field"><label for="assigned">Assigned to</label><select class="form-select" id="assigned" name="assigned"><?= options_html(member_options('orders.manage'), $filters['assigned'], 'Anyone') ?></select></div>
        <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>"></div>
        <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>"></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="mine" name="mine" value="1" <?= $filters['mine'] ? 'checked' : '' ?>><label class="form-check-label" for="mine">My jobs</label></div>
        <button class="btn btn-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-printer', 'No jobs match') ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-clean align-middle mb-0">
            <thead><tr><th>Date · Order</th><th>Customer</th><th>Job</th><th class="text-end">Units</th><th class="text-end">Amount</th><th>Assigned</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e(format_date($row['service_date'])) ?><div class="text-muted-sm"><?= e($row['order_number']) ?></div></td>
                    <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?><div class="text-muted-sm"><?= e($row['participant_number']) ?></div></td>
                    <td><?= e($row['description']) ?><div class="text-muted-sm text-truncate" style="max-width:240px"><?= e(($row['job_pages'] ? $row['job_pages'] . ' pages × ' . ($row['job_copies'] ?: 1) . ' · ' : '') . ($row['job_instructions'] ?: '')) ?></div></td>
                    <td class="text-end"><?= e($row['quantity'] . ' ' . $row['pricing_unit']) ?></td>
                    <td class="text-end"><?= e(m($row['line_total'])) ?><div><?= status_badge($row['payment_status']) ?></div></td>
                    <td class="text-muted-sm"><?= e($row['assigned_name'] ?: '—') ?></td>
                    <td><?= status_badge($row['service_status']) ?></td>
                    <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('orders/view.php?id=' . $row['order_id'])) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
