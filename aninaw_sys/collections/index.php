<?php
/**
 * Participant payment balances across events: one row per person per event with the
 * collections and their total due, paid and balance. "Pay" opens the payment form in a modal.
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
$obligationCount = (int) db_value("SELECT COUNT(*) {$fromSql}", $params);
[$orderBy, $sortKey, $sortDir] = sort_clause(['name' => 'MIN(p.last_name)', 'event' => 'MIN(e.event_name)', 'due' => 'MIN(o.due_date)', 'balance' => 'balance'], 'name');
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM (SELECT 1 {$fromSql} GROUP BY o.participant_id, o.event_id) people", $params), 30);
$rows = db_all(
    "SELECT o.participant_id, MIN(p.participant_number) AS participant_number, MIN(p.first_name) AS first_name, MIN(p.last_name) AS last_name,
            o.event_id, MIN(e.event_name) AS event_name, MIN(e.status) AS event_status, MIN(o.due_date) AS due_date,
            SUM(CASE WHEN o.status <> 'cancelled' THEN o.amount_due - o.waived_amount ELSE 0 END) AS due,
            SUM({$paidSql}) AS paid,
            SUM(CASE WHEN o.status <> 'cancelled' THEN o.amount_due - o.waived_amount - {$paidSql} ELSE 0 END) AS balance,
            GROUP_CONCAT(o.status ORDER BY o.id) AS statuses,
            GROUP_CONCAT(CONCAT(r.rule_name, '\t', CASE WHEN o.status <> 'cancelled' THEN o.amount_due - o.waived_amount - {$paidSql} ELSE 0 END) ORDER BY o.id SEPARATOR '\n') AS lines_text
     {$fromSql} GROUP BY o.participant_id, o.event_id
     ORDER BY {$orderBy}, MIN(p.first_name), o.event_id LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
// Overall status of a person's collections in one event.
$groupStatus = static function (array $row): string {
    $statuses = array_unique(explode(',', (string) $row['statuses']));
    if (to_cents($row['balance']) > 0) {
        return to_cents($row['paid']) > 0 || in_array('partially_paid', $statuses, true) ? 'partially_paid' : 'unpaid';
    }
    return count($statuses) === 1 ? $statuses[0] : 'paid';
};
$currency = current_currency();
$dueCents = to_cents($totals['due']);
$paidCents = to_cents($totals['paid']);

$pageTitle = 'Collections';
$activeNav = 'collections';
$breadcrumb = [auth_context()['label'], 'Collections'];
$pageScripts = ['js/finance.js', 'js/collections.js', 'js/person_modal.js'];
require APP_ROOT . '/includes/header.php';
?>
<?= recent_payment_notice() ?>
<?= page_actions('Payment balances of participants. Paid amounts count posted payments only.',
    button_link('collections/list.php' . ($eventId ? '?event=' . $eventId . ($groupId ? '&group=' . $groupId : '') : ''), 'Print List', 'bi-printer', 'btn-outline-primary')
    . button_link('collections/payments.php', 'Payment History', 'bi-clock-history', 'btn-outline-primary')
    . button_link('collections/verify_receipt.php', 'Verify Receipt', 'bi-patch-check', 'btn-outline-primary')
    . (can('collections.record') ? button_link('collections/record_payment.php', 'Record Payment', 'bi-cash-coin') : '')) ?>

<div class="stat-grid">
    <?= stat_card('bi-bullseye', 'Due (filtered)', money($dueCents, $currency), 'After waivers') ?>
    <?= stat_card('bi-cash-coin', 'Paid', money($paidCents, $currency), 'Posted payments') ?>
    <?= stat_card('bi-hourglass-split', 'Outstanding', money(max(0, $dueCents - $paidCents), $currency), number_format($pagination['total']) . ' payer(s) · ' . number_format($obligationCount) . ' collection(s)', 'warning') ?>
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
                    <th class="text-end">Due</th><th class="text-end">Paid</th><th class="text-end"><?= sort_link('Balance', 'balance', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Due date', 'due', $sortKey, $sortDir) ?></th>
                    <th>Status</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $balance = max(0, to_cents($row['balance'])); $lines = array_map(static fn($line) => explode("\t", $line, 2), explode("\n", (string) $row['lines_text'])); ?>
                    <tr>
                        <td><a class="fw-semibold" href="<?= e(url('participants/view.php?id=' . $row['participant_id'])) ?>" data-person-card="<?= e(url('participants/card.php?id=' . $row['participant_id'] . '&event=' . $row['event_id'] . '&fragment=1')) ?>"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a><div class="text-muted-sm"><?= e($row['participant_number']) ?></div></td>
                        <td><div><?= e($row['event_name']) ?></div>
                            <?php foreach ($lines as [$ruleName, $lineBalance]): ?>
                                <div class="text-muted-sm d-flex justify-content-between gap-3"><span><?= e($ruleName) ?></span><?php if (count($lines) > 1): ?><span class="text-nowrap"><?= e(money(max(0, to_cents($lineBalance)), $currency)) ?></span><?php endif; ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-end"><?= e(m($row['due'])) ?></td>
                        <td class="text-end"><?= e(m($row['paid'])) ?></td>
                        <td class="text-end fw-semibold"><?= e(money($balance, $currency)) ?></td>
                        <td class="text-muted-sm<?= $row['due_date'] && $row['due_date'] < date('Y-m-d') && $balance > 0 ? ' text-danger' : '' ?>"><?= e(format_date($row['due_date'])) ?></td>
                        <td><?= status_badge($groupStatus($row)) ?></td>
                        <td class="actions">
                            <?php if ($balance > 0 && can('collections.record') && $row['event_status'] === 'collecting'): ?>
                                <a class="btn btn-primary btn-xs" data-pay="<?= e($row['last_name'] . ', ' . $row['first_name'] . ' · ' . $row['event_name']) ?>" href="<?= e(url('collections/record_payment.php?participant=' . $row['participant_id'] . '&event=' . $row['event_id'])) ?>">Pay</a>
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

<?php if (can('collections.record')) { require APP_ROOT . '/includes/pay_modal.php'; } ?>
<?php require APP_ROOT . '/includes/person_modal.php'; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
