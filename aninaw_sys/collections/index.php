<?php
/**
 * Participant payment balances across events (obligations, paid, remaining).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission('collections.view');

$institutionId = (int) current_institution_id();
$eventId = get_int('event');
$groupId = get_int('group');
$status = get_str('status', 20) ?: 'open';
$search = get_str('q', 100);

[$visibility, $params] = participant_visibility('p');
$where = ['o.institution_id = ?', $visibility];
array_unshift($params, $institutionId);
if ($eventId) {
    $where[] = 'o.event_id = ?';
    $params[] = $eventId;
}
if ($groupId) {
    $where[] = "EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = o.institution_id AND gm.participant_id = o.participant_id AND gm.group_id = ? AND gm.status = 'active')";
    $params[] = $groupId;
}
$where[] = match ($status) {
    'open' => "o.status IN ('unpaid', 'partially_paid')",
    'unpaid', 'partially_paid', 'paid', 'waived', 'cancelled' => 'o.status = ' . db()->quote($status),
    default => '1 = 1',
};
if ($search !== '') {
    $where[] = '(p.participant_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, " ", p.last_name) LIKE ?)';
    array_push($params, ...array_fill(0, 4, "%{$search}%"));
}
$whereSql = implode(' AND ', $where);
$fromSql = "FROM event_obligations o
            JOIN participants p ON p.id = o.participant_id AND p.institution_id = o.institution_id
            JOIN events e ON e.id = o.event_id AND e.institution_id = o.institution_id
            JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
            WHERE {$whereSql}";
$paidSql = "(SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa JOIN payments pp ON pp.id = pa.payment_id AND pp.institution_id = pa.institution_id AND pp.status = 'posted'
              WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id)";

$totals = db_one("SELECT COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.amount_due - o.waived_amount ELSE 0 END), 0) AS due, COALESCE(SUM({$paidSql}), 0) AS paid {$fromSql}", $params);
[$orderBy, $sortKey, $sortDir] = sort_clause(['name' => 'p.last_name', 'event' => 'e.event_name', 'due' => 'o.due_date', 'status' => 'o.status'], 'name');
$pagination = paginate((int) db_value("SELECT COUNT(*) {$fromSql}", $params), 30);
$rows = db_all(
    "SELECT o.id, o.amount_due, o.waived_amount, o.due_date, o.status, p.id AS participant_id, p.participant_number, p.first_name, p.last_name,
            e.id AS event_id, e.event_name, e.status AS event_status, r.rule_name, {$paidSql} AS paid
     {$fromSql} ORDER BY {$orderBy}, p.first_name, o.id LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$currency = current_currency();
$dueCents = to_cents($totals['due']);
$paidCents = to_cents($totals['paid']);

$pageTitle = 'Collections';
$activeNav = 'collections';
$breadcrumb = [auth_context()['label'], 'Collections'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Payment balances of participants. Paid amounts count posted payments only.',
    button_link('collections/payments.php', 'Payment History', 'bi-clock-history', 'btn-outline-primary')
    . button_link('collections/verify_receipt.php', 'Verify Receipt', 'bi-patch-check', 'btn-outline-primary')
    . (can('collections.record') ? button_link('collections/record_payment.php', 'Record Payment', 'bi-cash-coin') : '')) ?>

<div class="stat-grid">
    <?= stat_card('bi-bullseye', 'Due (filtered)', money($dueCents, $currency), 'After waivers') ?>
    <?= stat_card('bi-cash-coin', 'Paid', money($paidCents, $currency), 'Posted payments') ?>
    <?= stat_card('bi-hourglass-split', 'Outstanding', money(max(0, $dueCents - $paidCents), $currency), number_format($pagination['total']) . ' obligation(s) listed', 'warning') ?>
</div>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="ID number or name"></div>
        <div class="filter-field"><label for="event">Event</label><select class="form-select" id="event" name="event" data-autosubmit><?= options_html(event_options(['approved', 'collecting', 'completed', 'closed']), $eventId, 'All events') ?></select></div>
        <div class="filter-field"><label for="group">Group</label><select class="form-select" id="group" name="group" data-autosubmit><?= options_html(group_options(false), $groupId, 'All groups') ?></select></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(['open' => 'Outstanding', 'unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'waived' => 'Waived', 'cancelled' => 'Cancelled', 'all' => 'All'], $status) ?></select></div>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-card-checklist', 'No obligations match', 'Obligations are created from an event\'s collection rules.') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr>
                    <th><?= sort_link('Payer', 'name', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Event · Collection', 'event', $sortKey, $sortDir) ?></th>
                    <th class="text-end">Due</th><th class="text-end">Paid</th><th class="text-end">Balance</th>
                    <th><?= sort_link('Due date', 'due', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Status', 'status', $sortKey, $sortDir) ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $balance = max(0, to_cents($row['amount_due']) - to_cents($row['waived_amount']) - to_cents($row['paid'])); ?>
                    <tr>
                        <td><a class="fw-semibold" href="<?= e(url('participants/view.php?id=' . $row['participant_id'])) ?>"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a><div class="text-muted-sm"><?= e($row['participant_number']) ?></div></td>
                        <td><div><?= e($row['event_name']) ?></div><div class="text-muted-sm"><?= e($row['rule_name']) ?></div></td>
                        <td class="text-end"><?= e(money(to_cents($row['amount_due']) - to_cents($row['waived_amount']), $currency)) ?></td>
                        <td class="text-end"><?= e(m($row['paid'])) ?></td>
                        <td class="text-end fw-semibold"><?= e(money($balance, $currency)) ?></td>
                        <td class="text-muted-sm<?= $row['due_date'] && $row['due_date'] < date('Y-m-d') && $balance > 0 ? ' text-danger' : '' ?>"><?= e(format_date($row['due_date'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions">
                            <?php if ($balance > 0 && can('collections.record') && $row['event_status'] === 'collecting'): ?>
                                <a class="btn btn-primary btn-xs" href="<?= e(url('collections/record_payment.php?participant=' . $row['participant_id'] . '&event=' . $row['event_id'])) ?>">Pay</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
