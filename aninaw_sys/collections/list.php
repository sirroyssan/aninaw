<?php
/**
 * Printable collection list for one event: everyone charged, split into "Not yet fully paid" and
 * "Fully paid", with one column per collection (paid / balance) and totals. On screen, print and CSV.
 * Same filters as the Collections page (group, name/ID); paid amounts count posted payments only.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission('collections.view');

$institutionId = (int) current_institution_id();
$events = event_options(['approved', 'collecting', 'completed', 'closed']);
$eventId = get_int('event');
$eventId = $eventId && isset($events[$eventId]) ? $eventId : null;
$groupId = get_int('group');
$search = get_str('q', 100);
$show = in_array(get_str('show', 10), ['unpaid', 'paid'], true) ? get_str('show', 10) : 'all';
$currency = current_currency();

$rules = [];
$people = [];
if ($eventId) {
    $event = require_found(find_event($eventId));
    $rules = db_all("SELECT id, rule_name FROM collection_rules WHERE institution_id = ? AND event_id = ? AND EXISTS
        (SELECT 1 FROM event_obligations o WHERE o.institution_id = collection_rules.institution_id AND o.collection_rule_id = collection_rules.id AND o.status <> 'cancelled') ORDER BY id", [$institutionId, $eventId]);

    [$visibility, $params] = participant_visibility('p');
    $where = ['o.institution_id = ?', 'o.event_id = ?', "o.status <> 'cancelled'", $visibility];
    array_unshift($params, $institutionId, $eventId);
    if ($groupId) {
        $where[] = "EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = o.institution_id AND gm.participant_id = o.participant_id AND gm.group_id = ? AND gm.status = 'active')";
        $params[] = $groupId;
    }
    if ($search !== '') {
        $where[] = '(p.participant_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, " ", p.last_name) LIKE ?)';
        array_push($params, ...array_fill(0, 4, "%{$search}%"));
    }
    $rows = db_all(
        "SELECT o.participant_id, o.collection_rule_id, o.amount_due, o.waived_amount, o.status, p.participant_number, p.last_name, p.first_name,
                (SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa JOIN payments pp ON pp.id = pa.payment_id AND pp.institution_id = pa.institution_id AND pp.status = 'posted'
                  WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id) AS paid
         FROM event_obligations o JOIN participants p ON p.id = o.participant_id AND p.institution_id = o.institution_id
         WHERE " . implode(' AND ', $where) . ' ORDER BY p.last_name, p.first_name, p.id',
        $params
    );
    foreach ($rows as $row) {
        $pid = (int) $row['participant_id'];
        $people[$pid] ??= ['id' => $pid, 'number' => $row['participant_number'], 'name' => $row['last_name'] . ', ' . $row['first_name'], 'due' => 0, 'paid' => 0, 'waived' => 0, 'cells' => []];
        $due = to_cents($row['amount_due']) - to_cents($row['waived_amount']);
        $paid = to_cents($row['paid']);
        $people[$pid]['due'] += $due;
        $people[$pid]['paid'] += $paid;
        $people[$pid]['waived'] += to_cents($row['waived_amount']);
        $people[$pid]['cells'][(int) $row['collection_rule_id']] = ['due' => $due, 'paid' => $paid, 'balance' => max(0, $due - $paid), 'status' => $row['status']];
    }
    foreach ($people as &$person) {
        $person['balance'] = max(0, $person['due'] - $person['paid']);
    }
    unset($person);
}
$unpaid = array_filter($people, static fn($p) => $p['balance'] > 0);
$paid = array_filter($people, static fn($p) => $p['balance'] === 0);
$listSections = array_filter(['unpaid' => ['Not yet fully paid', $unpaid], 'paid' => ['Fully paid', $paid]], static fn($key) => $show === 'all' || $show === $key, ARRAY_FILTER_USE_KEY);
$sum = static fn(array $list, string $key) => array_sum(array_column($list, $key));
$cellText = static function (?array $cell) use ($currency): string {
    if ($cell === null) {
        return '—';
    }
    if ($cell['balance'] === 0) {
        return $cell['status'] === 'waived' && $cell['paid'] === 0 ? 'Waived' : 'Paid';
    }
    return $cell['paid'] > 0 ? 'Paid ' . money($cell['paid'], $currency) . ' · owes ' . money($cell['balance'], $currency) : 'Owes ' . money($cell['balance'], $currency);
};

if ($eventId && get_str('export', 10) === 'csv') {
    audit_log('report.exported', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['report' => 'collection_list', 'rows' => count($people), 'show' => $show]);
    $csvRows = [];
    foreach ($listSections as [$title, $list]) {
        foreach ($list as $p) {
            $csvRows[] = array_merge([$title, $p['number'], $p['name']], array_map(static fn($r) => $cellText($p['cells'][(int) $r['id']] ?? null), $rules),
                [cents_to_decimal($p['due']), cents_to_decimal($p['paid']), cents_to_decimal($p['balance'])]);
        }
    }
    send_csv('collection_list_' . $event['event_code'] . '_' . date('Ymd') . '.csv',
        array_merge(['Section', 'ID number', 'Name'], array_column($rules, 'rule_name'), ['Total due', 'Paid', 'Balance']), $csvRows,
        [['Collection list', $event['event_name']], ['Generated', date('Y-m-d H:i') . ' by ' . auth_user()['username']]]);
}

$pageTitle = 'Collection List';
$activeNav = 'collections';
$breadcrumb = [auth_context()['label'], 'Collections', 'Collection List'];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($eventId): ?>
    <div class="report-header">
        <h2><?= e($event['event_name']) ?> · Collection list</h2>
        <p><?= e(auth_context()['label']) ?><?= $groupId ? ' · ' . e(group_options(false)[$groupId] ?? '') : '' ?> · <?= number_format(count($people)) ?> people · <?= number_format(count($paid)) ?> fully paid · <?= number_format(count($unpaid)) ?> not yet · Printed <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(auth_user()['username']) ?></p>
    </div>
<?php endif; ?>
<?= page_actions('Everyone charged in an event, who has paid and who has not. Paid amounts count posted payments only.',
    button_link('collections/index.php' . ($eventId ? '?event=' . $eventId : ''), 'Collections', 'bi-arrow-left', 'btn-outline-secondary')
    . ($eventId ? '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv'])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
        . '<button class="btn btn-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>' : '')) ?>

<div class="card panel mb-3 d-print-none">
    <form class="filter-bar" method="get">
        <div class="filter-field grow"><label for="event">Event</label><select class="form-select" id="event" name="event" data-autosubmit required><?= options_html($events, $eventId, 'Select event…') ?></select></div>
        <div class="filter-field"><label for="group">Group</label><select class="form-select" id="group" name="group" data-autosubmit><?= options_html(group_options(false), $groupId, 'All groups') ?></select></div>
        <div class="filter-field"><label for="show">Show</label><select class="form-select" id="show" name="show" data-autosubmit><?= options_html(['all' => 'Paid and not paid', 'unpaid' => 'Not yet fully paid', 'paid' => 'Fully paid'], $show) ?></select></div>
        <div class="filter-field"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="ID number or name"></div>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
    </form>
</div>

<?php if (!$eventId): ?>
    <div class="card panel"><div class="card-body"><?= empty_state('bi-list-check', 'Select an event', 'The list shows everyone charged in that event, who has paid and who has not.') ?></div></div>
<?php elseif (!$people): ?>
    <div class="card panel"><div class="card-body"><?= empty_state('bi-people', 'No one is charged yet', 'Add people or groups to the event and its collection rules.') ?></div></div>
<?php else: ?>
    <div class="stat-grid">
        <?= stat_card('bi-people', 'People', number_format(count($people)), number_format(count($paid)) . ' fully paid · ' . number_format(count($unpaid)) . ' not yet') ?>
        <?= stat_card('bi-bullseye', 'Expected', money($sum($people, 'due'), $currency), 'After waivers') ?>
        <?= stat_card('bi-cash-coin', 'Collected', money($sum($people, 'paid'), $currency), 'Posted payments') ?>
        <?= stat_card('bi-hourglass-split', 'Outstanding', money($sum($people, 'balance'), $currency), number_format(count($unpaid)) . ' people', 'warning') ?>
    </div>
    <?php foreach ($listSections as $key => [$title, $list]): ?>
        <div class="card panel mb-3 collection-list-section">
            <div class="card-header"><h3><?= e($title) ?> <span class="text-muted-sm">(<?= number_format(count($list)) ?>)</span></h3></div>
            <?php if (!$list): ?>
                <div class="card-body"><?= empty_state($key === 'paid' ? 'bi-hourglass' : 'bi-check2-all', $key === 'paid' ? 'No one has fully paid yet' : 'Everyone has fully paid') ?></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-clean table-sm align-middle mb-0">
                    <thead><tr><th>#</th><th>Name</th><th>ID number</th>
                        <?php foreach ($rules as $rule): ?><th><?= e($rule['rule_name']) ?></th><?php endforeach; ?>
                        <th class="text-end">Due</th><th class="text-end">Paid</th><th class="text-end">Balance</th></tr></thead>
                    <tbody>
                    <?php $n = 0; foreach ($list as $p): ?>
                        <tr>
                            <td class="text-muted-sm"><?= ++$n ?></td>
                            <td class="fw-semibold"><?= e($p['name']) ?></td>
                            <td><?= e($p['number']) ?></td>
                            <?php foreach ($rules as $rule): $cell = $p['cells'][(int) $rule['id']] ?? null; ?>
                                <td class="<?= $cell === null ? 'text-muted-sm' : ($cell['balance'] === 0 ? 'text-success' : 'text-danger') ?>"><?= e($cellText($cell)) ?></td>
                            <?php endforeach; ?>
                            <td class="text-end"><?= e(money($p['due'], $currency)) ?></td>
                            <td class="text-end"><?= e(money($p['paid'], $currency)) ?></td>
                            <td class="text-end fw-semibold"><?= e(money($p['balance'], $currency)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th colspan="<?= 3 + count($rules) ?>" class="text-end">Total</th>
                        <th class="text-end"><?= e(money($sum($list, 'due'), $currency)) ?></th><th class="text-end"><?= e(money($sum($list, 'paid'), $currency)) ?></th><th class="text-end"><?= e(money($sum($list, 'balance'), $currency)) ?></th></tr></tfoot>
                </table></div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
