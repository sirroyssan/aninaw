<?php
/**
 * Event: collection dashboard, collection rules, participant obligations (with waivers)
 * and setup (funds, participating groups, organizers, status).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/events.php';
require_once APP_ROOT . '/includes/services/funds.php';
require_institution_context();
require_permission(['events.view', 'events.manage', 'collections.view']);

$eventId = require_get_id();
$event = require_found(find_event($eventId));
$institutionId = (int) current_institution_id();
$tab = in_array(get_str('tab', 20), ['overview', 'rules', 'obligations', 'setup'], true) ? get_str('tab', 20) : 'overview';

if (is_post()) {
    $action = post_str('action', 30);
    $returnTab = post_str('tab', 20) ?: $tab;
    handle_post(function () use ($action, $eventId, $returnTab) {
        switch ($action) {
            case 'status':
                set_event_status($eventId, post_str('status', 20), post_str('reason', 1000));
                flash('success', 'Event status updated');
                break;
            case 'save_rule':
                save_collection_rule($eventId, post_int('rule_id') ?: null, $_POST);
                flash('success', 'Collection rule saved', 'Generate obligations to apply it to eligible participants.');
                break;
            case 'rule_status':
                set_rule_status($eventId, post_int('rule_id') ?? 0, post_str('status', 20), post_str('reason', 1000));
                flash('success', 'Rule updated');
                break;
            case 'generate':
                $created = generate_obligations($eventId);
                flash('success', 'Obligations generated', $created . ' new obligation(s) created. Existing ones were left unchanged.');
                break;
            case 'waive':
                waive_obligation(post_int('obligation_id') ?? 0, post_str('amount', 20), post_str('reason', 1000));
                flash('success', 'Waiver recorded');
                break;
            case 'cancel_obligation':
                cancel_obligation(post_int('obligation_id') ?? 0, post_str('reason', 1000));
                flash('success', 'Obligation cancelled');
                break;
            case 'add_fund':
                add_event_fund($eventId, $_POST);
                flash('success', 'Fund assigned');
                break;
            case 'remove_fund':
                remove_event_fund($eventId, post_int('event_fund_id') ?? 0);
                flash('success', 'Fund removed');
                break;
            case 'add_group':
                assign_event_group($eventId, $_POST);
                flash('success', 'Group assigned');
                break;
            case 'remove_group':
                remove_event_group($eventId, post_int('assignment_id') ?? 0);
                flash('success', 'Group removed');
                break;
            case 'add_organizer':
                add_event_organizer($eventId, post_int('membership_id') ?? 0);
                flash('success', 'Organizer assigned');
                break;
            case 'remove_organizer':
                remove_event_organizer($eventId, post_int('organizer_id') ?? 0);
                flash('success', 'Organizer removed');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'events/view.php?id=' . $eventId . '&tab=' . $returnTab;
    }, in_array($action, ['save_rule', 'generate', 'add_fund', 'add_group', 'add_organizer'], true));
}

$canManage = $event['can_manage'];
$currency = current_currency();
$stats = event_collection_stats($eventId);
$funds = event_funds($eventId);
$rules = db_all(
    "SELECT r.*, g.group_name, CONCAT(p.first_name, ' ', p.last_name) AS participant_name,
            (SELECT COUNT(*) FROM event_obligations o WHERE o.institution_id = r.institution_id AND o.collection_rule_id = r.id AND o.status <> 'cancelled') AS obligations
     FROM collection_rules r
     LEFT JOIN `groups` g ON r.applies_to_type = 'group' AND g.id = r.applies_to_id AND g.institution_id = r.institution_id
     LEFT JOIN participants p ON r.applies_to_type = 'participant' AND p.id = r.applies_to_id AND p.institution_id = r.institution_id
     WHERE r.institution_id = ? AND r.event_id = ? ORDER BY r.status, r.id",
    [$institutionId, $eventId]
);
$assignments = db_all(
    "SELECT a.id, a.group_id, g.group_name, g.group_code, CONCAT(cu.first_name, ' ', cu.last_name) AS collector, CONCAT(ku.first_name, ' ', ku.last_name) AS cashier
     FROM event_group_assignments a
     JOIN `groups` g ON g.id = a.group_id AND g.institution_id = a.institution_id
     JOIN institution_memberships cm ON cm.id = a.collector_membership_id JOIN users cu ON cu.id = cm.user_id
     JOIN institution_memberships km ON km.id = a.cashier_membership_id JOIN users ku ON ku.id = km.user_id
     WHERE a.institution_id = ? AND a.event_id = ? AND a.status = 'active' ORDER BY g.group_name",
    [$institutionId, $eventId]
);
$organizers = db_all(
    "SELECT eo.id, eo.membership_id, u.first_name, u.last_name FROM event_organizers eo
     JOIN institution_memberships m ON m.id = eo.membership_id JOIN users u ON u.id = m.user_id
     WHERE eo.institution_id = ? AND eo.event_id = ? AND eo.status = 'active' ORDER BY u.last_name",
    [$institutionId, $eventId]
);

$pageTitle = $event['event_name'];
$activeNav = 'events';
$breadcrumb = [auth_context()['label'], 'Events'];
require APP_ROOT . '/includes/header.php';
$tabUrl = static fn(string $name) => url('events/view.php?id=' . $eventId . '&tab=' . $name);
?>
<?= page_actions($event['event_code'] . ' · ' . humanize($event['status']) . ($event['collection_deadline'] ? ' · collection deadline ' . format_date($event['collection_deadline']) : '') . ' · created by ' . $event['created_by_name'],
    button_link('events/index.php', 'All Events', 'bi-arrow-left', 'btn-outline-secondary')
    . (can('collections.record') && $event['status'] === 'collecting' ? button_link('collections/record_payment.php?event=' . $eventId, 'Record Payment', 'bi-cash-coin') : '')
    . ($canManage && !in_array($event['status'], ['closed', 'cancelled'], true) ? button_link('events/create.php?id=' . $eventId, 'Edit', 'bi-pencil', 'btn-outline-primary') : '')) ?>

<ul class="nav nav-tabs-clean">
    <?php foreach (['overview' => 'Collection Dashboard', 'rules' => 'Collection Rules', 'obligations' => 'Participants', 'setup' => 'Setup'] as $key => $label): ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $key ? ' active' : '' ?>" href="<?= e($tabUrl($key)) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
    <div class="stat-grid">
        <?= stat_card('bi-bullseye', 'Expected Collection', money($stats['expected'], $currency), $stats['participants'] . ' participant(s) with obligations') ?>
        <?= stat_card('bi-cash-coin', 'Amount Received', money($stats['received'], $currency), 'Posted payments') ?>
        <?= stat_card('bi-hourglass-split', 'Remaining', money($stats['remaining'], $currency), $stats['percent'] . '% collected', $stats['remaining'] > 0 ? 'warning' : 'success') ?>
        <?= stat_card('bi-arrow-left-right', 'Pending Turnover', money($stats['pending_turnover'], $currency), 'Collected by collectors, not yet verified by a cashier', $stats['pending_turnover'] > 0 ? 'warning' : 'success') ?>
        <?= stat_card('bi-check2-circle', 'Fully Paid', number_format($stats['paid_count']), 'participants', 'success') ?>
        <?= stat_card('bi-circle-half', 'Partially Paid', number_format($stats['partial_count']), 'participants', 'info') ?>
        <?= stat_card('bi-x-circle', 'Unpaid', number_format($stats['unpaid_count']), 'participants', $stats['unpaid_count'] ? 'danger' : 'success') ?>
    </div>
    <div class="card panel mb-3"><div class="card-body"><div class="d-flex justify-content-between mb-1"><span class="fw-semibold">Collection progress</span><span><?= e((string) $stats['percent']) ?>%</span></div><?= progress_bar($stats['percent']) ?></div></div>

    <div class="card panel">
        <div class="card-header"><h3>Progress by group</h3></div>
        <?php if (!$assignments): ?>
            <div class="card-body"><?= empty_state('bi-diagram-3', 'No participating groups', 'Assign groups under Setup, or rules apply to every active participant.') ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Group</th><th>Collector → Cashier</th><th class="text-end">Expected</th><th class="text-end">Received</th><th class="text-end">Pending turnover</th><th style="min-width:140px">Progress</th></tr></thead>
                    <tbody>
                    <?php foreach ($assignments as $row): $gs = event_collection_stats($eventId, (int) $row['group_id']); ?>
                        <tr>
                            <td class="fw-semibold"><?= e($row['group_name']) ?></td>
                            <td class="text-muted-sm"><?= e($row['collector'] . ' → ' . $row['cashier']) ?></td>
                            <td class="text-end"><?= e(money($gs['expected'], $currency)) ?></td>
                            <td class="text-end"><?= e(money($gs['received'], $currency)) ?></td>
                            <td class="text-end"><?= e(money($gs['pending_turnover'], $currency)) ?></td>
                            <td><?= progress_bar($gs['percent']) ?><div class="text-muted-sm mt-1"><?= e($gs['paid_count'] . ' paid · ' . $gs['partial_count'] . ' partial · ' . $gs['unpaid_count'] . ' unpaid') ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'rules'): ?>
    <div class="row g-3">
        <div class="<?= $canManage ? 'col-xl-8' : 'col-12' ?>">
            <div class="card panel">
                <div class="card-header"><h3>Collection rules</h3>
                    <?php if ($canManage && $rules): ?>
                        <form method="post" data-confirm="Generate obligations now?" data-confirm-text="Creates an obligation for every eligible participant who does not have one yet. Existing obligations are not changed.">
                            <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="generate"><input type="hidden" name="tab" value="rules">
                            <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-people me-1" aria-hidden="true"></i>Generate Obligations</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if (!$rules): ?>
                    <div class="card-body"><?= empty_state('bi-list-check', 'No collection rules', 'Example: "Registration fee — ₱150 per student, all participating groups".') ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-clean align-middle mb-0">
                            <thead><tr><th>Collection</th><th>Applies to</th><th class="text-end">Amount</th><th>Due</th><th class="text-end">Obligations</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($rules as $rule): ?>
                                <tr>
                                    <td><div class="fw-semibold"><?= e($rule['rule_name']) ?></div><div class="text-muted-sm"><?= $rule['allow_partial_payment'] ? 'Partial payments allowed' : 'Full payment only' ?></div></td>
                                    <td><?= e(match ($rule['applies_to_type']) { 'group' => 'Group: ' . $rule['group_name'], 'participant' => 'Participant: ' . $rule['participant_name'], default => $assignments ? 'All participating groups' : 'All active participants' }) ?></td>
                                    <td class="text-end fw-semibold"><?= e(m($rule['amount_due'])) ?></td>
                                    <td class="text-muted-sm"><?= e(format_date($rule['effective_until'] ?? $event['collection_deadline'])) ?></td>
                                    <td class="text-end"><?= e((string) $rule['obligations']) ?></td>
                                    <td><?= status_badge($rule['status']) ?></td>
                                    <?php if ($canManage): ?>
                                        <td class="actions">
                                            <a class="btn btn-outline-primary btn-xs" href="<?= e($tabUrl('rules') . '&rule=' . $rule['id']) ?>">Edit</a>
                                            <form method="post" class="d-inline" data-confirm="<?= $rule['status'] === 'active' ? 'Deactivate this rule?' : 'Reactivate this rule?' ?>" <?= $rule['status'] === 'active' ? 'data-reason="Reason (unpaid obligations will be cancelled)" data-danger' : '' ?>>
                                                <?= csrf_field() ?><input type="hidden" name="action" value="rule_status"><input type="hidden" name="tab" value="rules"><input type="hidden" name="rule_id" value="<?= e($rule['id']) ?>"><input type="hidden" name="status" value="<?= $rule['status'] === 'active' ? 'inactive' : 'active' ?>">
                                                <button class="btn btn-outline-<?= $rule['status'] === 'active' ? 'danger' : 'secondary' ?> btn-xs" type="submit"><?= $rule['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($canManage): ?>
            <?php $editRule = null; foreach ($rules as $rule) { if ((int) $rule['id'] === get_int('rule')) { $editRule = $rule; } } ?>
            <div class="col-xl-4">
                <form method="post" class="card panel form-card">
                    <?= csrf_field() ?><?= idempotency_field() ?>
                    <input type="hidden" name="action" value="save_rule"><input type="hidden" name="tab" value="rules"><input type="hidden" name="rule_id" value="<?= e($editRule['id'] ?? '') ?>">
                    <div class="card-header"><h3><?= $editRule ? 'Edit Rule' : 'New Collection Rule' ?></h3></div>
                    <div class="card-body">
                        <div class="mb-2"><label class="form-label required" for="rule_name">Collection name</label><input class="form-control" id="rule_name" name="rule_name" required maxlength="180" value="<?= e($editRule['rule_name'] ?? old('rule_name')) ?>" placeholder="Registration fee"></div>
                        <div class="mb-2"><label class="form-label required" for="amount_due">Amount per participant</label><input class="form-control text-amount" id="amount_due" name="amount_due" required inputmode="decimal" value="<?= e(isset($editRule) ? cents_to_decimal(to_cents($editRule['amount_due'])) : old('amount_due')) ?>" placeholder="150.00"></div>
                        <div class="mb-2"><label class="form-label" for="applies_to_type">Applies to</label><select class="form-select" id="applies_to_type" name="applies_to_type"><?= options_html(['all' => 'All participating groups (or everyone if none)', 'group' => 'One group', 'participant' => 'One participant'], $editRule['applies_to_type'] ?? old('applies_to_type', 'all')) ?></select></div>
                        <div class="mb-2"><label class="form-label" for="applies_to_group">Group <span class="text-muted-sm">(if one group)</span></label><select class="form-select" id="applies_to_group" name="applies_to_group"><?= options_html(group_options(), ($editRule['applies_to_type'] ?? '') === 'group' ? $editRule['applies_to_id'] : null, '—') ?></select></div>
                        <div class="mb-2"><label class="form-label" for="applies_to_participant">Participant ID <span class="text-muted-sm">(if one participant)</span></label>
                            <select class="form-select" id="applies_to_participant" name="applies_to_participant"><?= options_html(options_from_rows(db_all("SELECT id, participant_number, first_name, last_name FROM participants WHERE institution_id = ? AND status = 'active' ORDER BY last_name LIMIT 1000", [$institutionId]), 'id', static fn($r) => $r['participant_number'] . ' — ' . $r['last_name'] . ', ' . $r['first_name']), ($editRule['applies_to_type'] ?? '') === 'participant' ? $editRule['applies_to_id'] : null, '—') ?></select></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label" for="effective_from">From</label><input class="form-control" type="date" id="effective_from" name="effective_from" value="<?= e($editRule['effective_from'] ?? '') ?>"></div>
                            <div class="col-6"><label class="form-label" for="effective_until">Due date</label><input class="form-control" type="date" id="effective_until" name="effective_until" value="<?= e($editRule['effective_until'] ?? $event['collection_deadline']) ?>"></div>
                        </div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="allow_partial_payment" name="allow_partial_payment" value="1" <?= ($editRule['allow_partial_payment'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="allow_partial_payment">Allow partial payments (e.g. ₱50 three times)</label></div>
                        <p class="text-muted-sm mb-0">Once payments are received, the amount and coverage of a rule are locked.</p>
                        <div class="form-actions">
                            <?php if ($editRule): ?><a class="btn btn-outline-secondary" href="<?= e($tabUrl('rules')) ?>">Cancel</a><?php endif; ?>
                            <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Rule</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'obligations'): ?>
    <?php
    $obStatus = get_str('ostatus', 20);
    $obGroup = get_int('group');
    $obSearch = get_str('q', 100);
    $where = ['o.institution_id = ?', 'o.event_id = ?'];
    $params = [$institutionId, $eventId];
    [$visibility, $visibilityParams] = participant_visibility('p');
    $where[] = $visibility;
    array_push($params, ...$visibilityParams);
    if (in_array($obStatus, ['unpaid', 'partially_paid', 'paid', 'waived', 'cancelled'], true)) {
        $where[] = 'o.status = ?';
        $params[] = $obStatus;
    }
    if ($obGroup) {
        $where[] = "EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = o.institution_id AND gm.participant_id = o.participant_id AND gm.group_id = ? AND gm.status = 'active')";
        $params[] = $obGroup;
    }
    if ($obSearch !== '') {
        $where[] = '(p.participant_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)';
        array_push($params, "%{$obSearch}%", "%{$obSearch}%", "%{$obSearch}%");
    }
    $whereSql = implode(' AND ', $where);
    $obPagination = paginate((int) db_value("SELECT COUNT(*) FROM event_obligations o JOIN participants p ON p.id = o.participant_id AND p.institution_id = o.institution_id WHERE {$whereSql}", $params), 30);
    $obligations = db_all(
        "SELECT o.*, p.participant_number, p.first_name, p.last_name, r.rule_name,
                (SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa JOIN payments pp ON pp.id = pa.payment_id AND pp.institution_id = pa.institution_id AND pp.status = 'posted'
                  WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id) AS paid
         FROM event_obligations o
         JOIN participants p ON p.id = o.participant_id AND p.institution_id = o.institution_id
         JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
         WHERE {$whereSql} ORDER BY p.last_name, p.first_name, r.id LIMIT {$obPagination['per_page']} OFFSET {$obPagination['offset']}",
        $params
    );
    ?>
    <div class="card panel">
        <form class="filter-bar" method="get">
            <input type="hidden" name="id" value="<?= e($eventId) ?>"><input type="hidden" name="tab" value="obligations">
            <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($obSearch) ?>" placeholder="ID number or name"></div>
            <div class="filter-field"><label for="group">Group</label><select class="form-select" id="group" name="group" data-autosubmit><?= options_html(options_from_rows($assignments, 'group_id', 'group_name'), $obGroup, 'All') ?></select></div>
            <div class="filter-field"><label for="ostatus">Status</label><select class="form-select" id="ostatus" name="ostatus" data-autosubmit><?= options_html(['unpaid' => 'Unpaid', 'partially_paid' => 'Partially paid', 'paid' => 'Paid', 'waived' => 'Waived', 'cancelled' => 'Cancelled'], $obStatus, 'All') ?></select></div>
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
        </form>
        <?php if (!$obligations): ?>
            <div class="card-body"><?= empty_state('bi-people', 'No obligations', $rules ? 'Generate obligations from the Collection Rules tab.' : 'Add a collection rule first.') ?></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Participant</th><th>Collection</th><th class="text-end">Due</th><th class="text-end">Waived</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($obligations as $row): $balance = max(0, to_cents($row['amount_due']) - to_cents($row['waived_amount']) - to_cents($row['paid'])); ?>
                        <tr>
                            <td><a class="fw-semibold" href="<?= e(url('participants/view.php?id=' . $row['participant_id'])) ?>"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a><div class="text-muted-sm"><?= e($row['participant_number']) ?></div></td>
                            <td><?= e($row['rule_name']) ?></td>
                            <td class="text-end"><?= e(m($row['amount_due'])) ?></td>
                            <td class="text-end"><?= e(m($row['waived_amount'])) ?></td>
                            <td class="text-end"><?= e(m($row['paid'])) ?></td>
                            <td class="text-end fw-semibold"><?= e(money($balance, $currency)) ?></td>
                            <td><?= status_badge($row['status']) ?></td>
                            <?php if ($canManage): ?>
                                <td class="actions">
                                    <?php if ($balance > 0 && $row['status'] !== 'cancelled'): ?>
                                        <form method="post" class="d-inline-flex gap-1" data-confirm="Record this waiver?" data-reason="Who approved this exception and why?">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="waive"><input type="hidden" name="tab" value="obligations"><input type="hidden" name="obligation_id" value="<?= e($row['id']) ?>">
                                            <input class="form-control form-control-sm text-amount" style="width:90px" name="amount" value="<?= e(cents_to_decimal($balance)) ?>" aria-label="Amount to waive" required>
                                            <button class="btn btn-outline-secondary btn-xs" type="submit">Waive</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (to_cents($row['paid']) === 0 && $row['status'] !== 'cancelled'): ?>
                                        <form method="post" class="d-inline" data-confirm="Cancel this obligation?" data-reason="Reason for cancelling" data-danger>
                                            <?= csrf_field() ?><input type="hidden" name="action" value="cancel_obligation"><input type="hidden" name="tab" value="obligations"><input type="hidden" name="obligation_id" value="<?= e($row['id']) ?>">
                                            <button class="btn btn-outline-danger btn-xs" type="submit">Cancel</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        <?= pagination_links($obPagination) ?>
    </div>

<?php else: ?>
    <?php
    $transitions = EVENT_TRANSITIONS[$event['status']] ?? [];
    $labels = ['pending' => 'Submit for approval', 'approved' => 'Approve', 'collecting' => 'Open collection', 'completed' => 'Mark completed', 'closed' => 'Close event', 'cancelled' => 'Cancel event', 'draft' => 'Return to draft'];
    ?>
    <div class="row g-3">
        <div class="col-xl-6">
            <div class="card panel mb-3">
                <div class="card-header"><h3>Status</h3><?= status_badge($event['status']) ?></div>
                <div class="card-body">
                    <p class="text-muted-sm"><?= e($event['description'] ?: 'No description.') ?></p>
                    <dl class="detail-grid mb-3">
                        <div><dt>Dates</dt><dd><?= e(format_date($event['start_date']) . ' – ' . format_date($event['end_date'])) ?></dd></div>
                        <div><dt>Collection deadline</dt><dd><?= e(format_date($event['collection_deadline'])) ?></dd></div>
                        <div><dt>Approved</dt><dd><?= e($event['approved_at'] ? format_datetime($event['approved_at']) : '—') ?></dd></div>
                    </dl>
                    <?php if ($canManage && $transitions): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($transitions as $next): $danger = in_array($next, ['cancelled', 'closed'], true); ?>
                                <form method="post" data-confirm="<?= e($labels[$next] ?? humanize($next)) ?>?" <?= $danger ? 'data-reason="Reason" data-danger' : '' ?>>
                                    <?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="tab" value="setup"><input type="hidden" name="status" value="<?= e($next) ?>">
                                    <button class="btn btn-sm <?= $danger ? 'btn-outline-danger' : ($next === 'draft' ? 'btn-outline-secondary' : 'btn-primary') ?>" type="submit"><?= e($labels[$next] ?? humanize($next)) ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card panel mb-3">
                <div class="card-header"><h3>Receiving fund</h3></div>
                <?php if (!$funds): ?>
                    <div class="card-body"><?= empty_state('bi-wallet', 'No fund assigned', 'Collections are credited to the event\'s fund.') ?></div>
                <?php else: ?>
                    <ul class="list-clean">
                        <?php foreach ($funds as $row): ?>
                            <li><div><a class="fw-semibold" href="<?= e(url('funds/view.php?id=' . $row['fund_id'])) ?>"><?= e($row['fund_name']) ?></a><div class="text-muted-sm"><?= e($row['allocation_purpose'] ?: $row['fund_code']) ?></div></div>
                                <?php if ($canManage): ?><form method="post" data-confirm="Remove this fund from the event?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="remove_fund"><input type="hidden" name="tab" value="setup"><input type="hidden" name="event_fund_id" value="<?= e($row['id']) ?>"><button class="btn btn-outline-danger btn-xs" type="submit">Remove</button></form><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <form method="post" class="card-body border-top d-flex flex-wrap gap-2">
                        <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="add_fund"><input type="hidden" name="tab" value="setup">
                        <select class="form-select" name="fund_id" required aria-label="Fund" style="flex:1;min-width:180px"><?= options_html(options_from_rows(db_all("SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? AND status = 'active' ORDER BY fund_name", [$institutionId]), 'id', 'fund_name'), null, 'Select active fund…') ?></select>
                        <input class="form-control" name="allocation_purpose" maxlength="255" placeholder="Purpose (optional)" style="flex:1;min-width:160px" aria-label="Purpose">
                        <button class="btn btn-outline-primary" type="submit">Assign</button>
                    </form>
                <?php endif; ?>
            </div>

            <div class="card panel">
                <div class="card-header"><h3>Organizers</h3></div>
                <ul class="list-clean">
                    <li><div><div class="fw-semibold"><?= e($event['created_by_name']) ?></div><div class="text-muted-sm">Creator</div></div></li>
                    <?php foreach ($organizers as $row): ?>
                        <li><div class="fw-semibold"><?= e(person_name($row)) ?></div>
                            <?php if ($canManage): ?><form method="post" data-confirm="Remove this organizer?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="remove_organizer"><input type="hidden" name="tab" value="setup"><input type="hidden" name="organizer_id" value="<?= e($row['id']) ?>"><button class="btn btn-outline-danger btn-xs" type="submit">Remove</button></form><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($canManage): ?>
                    <form method="post" class="card-body border-top d-flex gap-2">
                        <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="add_organizer"><input type="hidden" name="tab" value="setup">
                        <select class="form-select" name="membership_id" required aria-label="Organizer"><?= options_html(member_options('events.manage'), null, 'Members with the organizer role…') ?></select>
                        <button class="btn btn-outline-primary" type="submit">Add</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card panel">
                <div class="card-header"><h3>Participating groups</h3></div>
                <?php if (!$assignments): ?>
                    <div class="card-body"><?= empty_state('bi-diagram-3', 'No groups yet', 'Each group has a collector (e.g. class president) and the cashier who receives their turnovers.') ?></div>
                <?php else: ?>
                    <ul class="list-clean">
                        <?php foreach ($assignments as $row): ?>
                            <li><div><div class="fw-semibold"><?= e($row['group_name']) ?></div><div class="text-muted-sm">Collector: <?= e($row['collector']) ?> · Cashier: <?= e($row['cashier']) ?></div></div>
                                <?php if ($canManage): ?><form method="post" data-confirm="Remove this group from the event?" data-confirm-text="Existing obligations stay." data-danger><?= csrf_field() ?><input type="hidden" name="action" value="remove_group"><input type="hidden" name="tab" value="setup"><input type="hidden" name="assignment_id" value="<?= e($row['id']) ?>"><button class="btn btn-outline-danger btn-xs" type="submit">Remove</button></form><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($canManage): ?>
                    <form method="post" class="card-body border-top">
                        <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="add_group"><input type="hidden" name="tab" value="setup">
                        <div class="row g-2">
                            <div class="col-12"><label class="form-label required" for="eg-group">Group</label><select class="form-select" id="eg-group" name="group_id" required><?= options_html(group_options(), null, 'Select…') ?></select></div>
                            <div class="col-md-6"><label class="form-label required" for="eg-collector">Collector</label><select class="form-select" id="eg-collector" name="collector_membership_id" required><?= options_html(member_options('turnovers.submit'), null, 'Select…') ?></select></div>
                            <div class="col-md-6"><label class="form-label required" for="eg-cashier">Receiving cashier</label><select class="form-select" id="eg-cashier" name="cashier_membership_id" required><?= options_html(member_options('turnovers.receive'), null, 'Select…') ?></select></div>
                        </div>
                        <button class="btn btn-outline-primary w-100 mt-2" type="submit">Assign Group</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
