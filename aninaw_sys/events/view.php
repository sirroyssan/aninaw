<?php
/**
 * Event: collection dashboard, collection rules, participant obligations (with waivers)
 * and setup (funds, participating groups, organizers, status).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/events.php';
require_once APP_ROOT . '/includes/services/event_requirements.php';
require_once APP_ROOT . '/includes/services/funds.php';
require_institution_context();
require_permission(['events.view', 'events.manage', 'collections.view']);

$eventId = require_get_id();
$event = require_found(find_event($eventId));
$institutionId = (int) current_institution_id();
$tab = in_array(get_str('tab', 20), ['overview', 'rules', 'people', 'requirements', 'obligations', 'setup'], true) ? get_str('tab', 20) : 'overview';

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
                flash('success', 'Collection rule saved', 'Generate obligations to apply it to everyone it covers.');
                break;
            case 'rule_status':
                set_rule_status($eventId, post_int('rule_id') ?? 0, post_str('status', 20), post_str('reason', 1000));
                flash('success', 'Rule updated');
                break;
            case 'generate':
                $created = generate_obligations($eventId);
                flash('success', 'Obligations generated', $created . ' new obligation(s) created. Existing ones were left unchanged.');
                break;
            case 'add_people':
                $result = add_event_people($eventId, $_POST);
                flash($result['added'] ? 'success' : 'info', $result['added'] ? $result['added'] . ' added to the event' : 'Already in the event',
                    $result['obligations'] ? $result['obligations'] . ' charge(s) created from the collection rules.' : ($result['added'] ? 'Add a collection rule to charge them.' : 'Everyone chosen was already in the event.'));
                break;
            case 'remove_person':
                remove_event_person($eventId, post_int('participant_id') ?? 0, post_str('reason', 1000));
                flash('success', 'Removed from the event', 'Their unpaid charges for this event were cancelled.');
                break;
            case 'save_requirement':
                save_event_requirement($eventId, post_int('requirement_id') ?: null, $_POST);
                flash('success', 'Document requirement saved', 'Record papers from the checklist below.');
                break;
            case 'requirement_status':
                set_event_requirement_status($eventId, post_int('requirement_id') ?? 0, post_str('status', 10));
                flash('success', 'Requirement updated');
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
    }, in_array($action, ['save_rule', 'generate', 'add_fund', 'add_group', 'add_organizer', 'add_people', 'save_requirement'], true));
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
$hasMembers = $assignments || db_value("SELECT 1 FROM event_participants WHERE institution_id = ? AND event_id = ? AND status = 'active'", [$institutionId, $eventId]);
$organizers = db_all(
    "SELECT eo.id, eo.membership_id, u.first_name, u.last_name FROM event_organizers eo
     JOIN institution_memberships m ON m.id = eo.membership_id JOIN users u ON u.id = m.user_id
     WHERE eo.institution_id = ? AND eo.event_id = ? AND eo.status = 'active' ORDER BY u.last_name",
    [$institutionId, $eventId]
);

if ($tab === 'requirements' && get_str('export', 10) === 'csv' && can_check_event_documents($event)) {
    $checklist = event_document_checklist($eventId);
    $show = in_array(get_str('show', 12), ['incomplete', 'cleared', 'to_check', 'returned'], true) ? get_str('show', 12) : '';
    send_document_checklist_csv($event, $checklist, filter_document_checklist($checklist['people'], $show, get_str('q', 100)));
}

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
    <?php foreach (['overview' => 'Collection Dashboard', 'rules' => 'Collection Rules', 'people' => 'People', 'requirements' => 'Requirements', 'obligations' => 'Balances', 'setup' => 'Setup'] as $key => $label): ?>
        <?php if ($key === 'requirements' && !can_check_event_documents($event)) { continue; } ?>
        <li class="nav-item"><a class="nav-link<?= $tab === $key ? ' active' : '' ?>" href="<?= e($tabUrl($key)) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
    <div class="stat-grid">
        <?= stat_card('bi-bullseye', 'Expected Collection', money($stats['expected'], $currency), $stats['participants'] . ' people with obligations') ?>
        <?= stat_card('bi-cash-coin', 'Amount Received', money($stats['received'], $currency), 'Posted payments') ?>
        <?= stat_card('bi-hourglass-split', 'Remaining', money($stats['remaining'], $currency), $stats['percent'] . '% collected', $stats['remaining'] > 0 ? 'warning' : 'success') ?>
        <?= stat_card('bi-arrow-left-right', 'Pending Turnover', money($stats['pending_turnover'], $currency), 'Collected by collectors, not yet verified by a cashier', $stats['pending_turnover'] > 0 ? 'warning' : 'success') ?>
        <?= stat_card('bi-check2-circle', 'Fully Paid', number_format($stats['paid_count']), 'people', 'success') ?>
        <?= stat_card('bi-circle-half', 'Partially Paid', number_format($stats['partial_count']), 'people', 'info') ?>
        <?= stat_card('bi-x-circle', 'Unpaid', number_format($stats['unpaid_count']), 'people', $stats['unpaid_count'] ? 'danger' : 'success') ?>
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
                                    <td><?= e(match ($rule['applies_to_type']) { 'group' => 'Group: ' . $rule['group_name'], 'participant' => 'One person: ' . $rule['participant_name'], default => $hasMembers ? 'Everyone in the event' : 'Everyone in the institution (no groups or people added yet)' }) ?></td>
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
                        <div class="mb-2"><label class="form-label required" for="amount_due">Amount per person</label><input class="form-control text-amount" id="amount_due" name="amount_due" required inputmode="decimal" value="<?= e(isset($editRule) ? cents_to_decimal(to_cents($editRule['amount_due'])) : old('amount_due')) ?>" placeholder="150.00"></div>
                        <div class="mb-2"><label class="form-label" for="applies_to_type">Applies to</label><select class="form-select" id="applies_to_type" name="applies_to_type"><?= options_html(['all' => 'Everyone in the event (its groups and people added)', 'group' => 'One group', 'participant' => 'One person'], $editRule['applies_to_type'] ?? old('applies_to_type', 'all')) ?></select></div>
                        <div class="mb-2"><label class="form-label" for="applies_to_group">Group <span class="text-muted-sm">(if one group)</span></label><select class="form-select" id="applies_to_group" name="applies_to_group"><?= options_html(group_options(), ($editRule['applies_to_type'] ?? '') === 'group' ? $editRule['applies_to_id'] : null, '—') ?></select></div>
                        <div class="mb-2"><label class="form-label" for="applies_to_participant">Person <span class="text-muted-sm">(if one person)</span></label>
                            <select class="form-select" id="applies_to_participant" name="applies_to_participant" data-searchable><?= options_html(options_from_rows(db_all("SELECT id, participant_number, first_name, last_name FROM participants WHERE institution_id = ? AND status = 'active' ORDER BY last_name LIMIT 1000", [$institutionId]), 'id', static fn($r) => $r['participant_number'] . ' — ' . $r['last_name'] . ', ' . $r['first_name']), ($editRule['applies_to_type'] ?? '') === 'participant' ? $editRule['applies_to_id'] : null, '—') ?></select></div>
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

<?php elseif ($tab === 'requirements' && can_check_event_documents($event)): ?>
    <?php
    $checklist = event_document_checklist($eventId);
    $allRequirements = event_requirements($eventId);
    $editRequirement = null;
    foreach ($allRequirements as $row) {
        if ((int) $row['id'] === get_int('edit_req')) {
            $editRequirement = $row;
        }
    }
    $show = in_array(get_str('show', 12), ['incomplete', 'cleared', 'to_check', 'returned'], true) ? get_str('show', 12) : '';
    $search = get_str('q', 100);
    $people = filter_document_checklist($checklist['people'], $show, $search);
    $totalPeople = count($checklist['people']);
    $clearedCount = count(array_filter($checklist['people'], static fn($p) => $p['cleared']));
    $toCheck = array_sum(array_column($checklist['people'], 'to_check'));
    $returnedCount = array_sum(array_column($checklist['people'], 'returned'));
    $cellBadge = static function (?array $cell): string {
        if ($cell === null) {
            return '<span class="text-muted-sm" title="Does not apply">—</span>';
        }
        [$class, $icon] = match ($cell['status']) {
            'approved' => ['badge-soft-success', 'bi-check-lg'],
            'waived' => ['badge-soft-secondary', 'bi-dash'],
            'submitted' => ['badge-soft-info', 'bi-hourglass-split'],
            'rejected' => ['badge-soft-danger', 'bi-arrow-return-left'],
            default => ['badge-soft-warning', 'bi-exclamation'],
        };
        return '<span class="badge ' . $class . '" title="' . e(REQUIREMENT_STATUSES[$cell['status']] . ($cell['submission']['remarks'] ?? '' ? ': ' . $cell['submission']['remarks'] : '')) . '"><i class="bi ' . $icon . ' me-1" aria-hidden="true"></i>' . e(REQUIREMENT_STATUSES[$cell['status']]) . '</span>';
    };
    ?>
    <div class="stat-grid">
        <?= stat_card('bi-file-earmark-check', 'Cleared', number_format($clearedCount) . ' / ' . number_format($totalPeople), 'All required papers approved', $clearedCount === $totalPeople && $totalPeople ? 'success' : 'warning') ?>
        <?= stat_card('bi-hourglass-split', 'To check', number_format($toCheck), 'Received, not yet checked', 'info') ?>
        <?= stat_card('bi-arrow-return-left', 'Returned', number_format($returnedCount), 'Need to be submitted again', $returnedCount ? 'danger' : 'success') ?>
        <?= stat_card('bi-list-check', 'Requirements', number_format(count($checklist['requirements'])), count(array_filter($checklist['requirements'], static fn($r) => $r['is_required'])) . ' required') ?>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-8">
            <div class="card panel h-100">
                <div class="card-header"><h3>Required documents</h3></div>
                <?php if (!$allRequirements): ?>
                    <div class="card-body"><?= empty_state('bi-file-earmark-text', 'No documents required yet', 'Add what people must submit for this event, e.g. Parent\'s waiver, Medical certificate, Parent\'s ID with signature.') ?></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Document</th><th>Who</th><th>Due</th><th class="text-end">Approved</th><th class="text-end">To check</th><th class="text-end">Returned</th><th></th></tr></thead>
                        <tbody><?php foreach ($allRequirements as $row): ?>
                            <tr class="<?= $row['status'] === 'inactive' ? 'text-muted' : '' ?>">
                                <td><div class="fw-semibold"><?= e($row['requirement_name']) ?></div><div class="text-muted-sm"><?= e(($row['is_required'] ? 'Required' : 'Optional') . ($row['description'] ? ' · ' . $row['description'] : '') . ($row['status'] === 'inactive' ? ' · no longer asked' : '')) ?></div>
                                    <?php if ($canManage): ?><div class="d-flex gap-2 mt-1 d-print-none">
                                        <a class="text-muted-sm" href="<?= e($tabUrl('requirements') . '&edit_req=' . $row['id']) ?>">Edit</a>
                                        <form method="post" data-confirm="<?= $row['status'] === 'active' ? 'Stop asking for this document?' : 'Ask for this document again?' ?>" data-confirm-text="Papers already recorded are kept.">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="requirement_status"><input type="hidden" name="tab" value="requirements"><input type="hidden" name="requirement_id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'inactive' : 'active' ?>">
                                            <button class="btn btn-link p-0 text-muted-sm align-baseline" type="submit"><?= $row['status'] === 'active' ? 'Remove' : 'Restore' ?></button>
                                        </form>
                                    </div><?php endif; ?></td>
                                <td><?= e(REQUIREMENT_APPLIES[$row['applies_to_type']]) ?></td>
                                <td class="text-muted-sm"><?= e($row['due_date'] ? format_date($row['due_date']) : '—') ?></td>
                                <td class="text-end"><?= (int) $row['done_count'] ?></td><td class="text-end"><?= (int) $row['submitted_count'] ?></td><td class="text-end"><?= (int) $row['rejected_count'] ?></td>
                                <td class="actions"><?php if ($row['status'] === 'active'): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('events/documents.php?event=' . $eventId . '&requirement=' . $row['id'])) ?>">Record</a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-xl-4">
            <?php if ($canManage && !in_array($event['status'], ['closed', 'cancelled'], true)): ?>
                <form method="post" class="card panel form-card h-100">
                    <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="save_requirement"><input type="hidden" name="tab" value="requirements"><input type="hidden" name="requirement_id" value="<?= (int) ($editRequirement['id'] ?? 0) ?>">
                    <div class="card-header"><h3><?= $editRequirement ? 'Edit document' : 'Add a required document' ?></h3></div>
                    <div class="card-body">
                        <div class="mb-2"><label class="form-label required" for="requirement_name">Document</label><input class="form-control" id="requirement_name" name="requirement_name" required maxlength="180" list="requirement-suggestions" value="<?= e($editRequirement['requirement_name'] ?? '') ?>" placeholder="Parent's waiver">
                            <datalist id="requirement-suggestions"><option value="Parent's waiver / consent form"><option value="Medical certificate"><option value="Parent's valid ID with signature"><option value="Student ID (photocopy)"><option value="Certificate of registration"><option value="Insurance"></datalist></div>
                        <div class="mb-2"><label class="form-label" for="req-description">Details</label><input class="form-control" id="req-description" name="description" maxlength="1000" value="<?= e($editRequirement['description'] ?? '') ?>" placeholder="e.g. signed by parent or guardian, notarized"></div>
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label" for="req-applies">Who must submit</label><select class="form-select" id="req-applies" name="applies_to_type"><?= options_html(REQUIREMENT_APPLIES, $editRequirement['applies_to_type'] ?? 'all') ?></select></div>
                            <div class="col-6"><label class="form-label" for="req-due">Due date</label><input class="form-control" type="date" id="req-due" name="due_date" value="<?= e($editRequirement['due_date'] ?? '') ?>"></div>
                        </div>
                        <div class="form-check mb-3"><input type="hidden" name="is_required" value="0"><input class="form-check-input" type="checkbox" id="req-required" name="is_required" value="1" <?= ($editRequirement['is_required'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="req-required">Required to be cleared for the event</label></div>
                        <button class="btn btn-primary w-100" type="submit"><?= $editRequirement ? 'Save Changes' : 'Add Document' ?></button>
                        <?php if ($editRequirement): ?><a class="btn btn-link w-100" href="<?= e($tabUrl('requirements')) ?>">Cancel</a><?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($checklist['requirements']): ?>
    <div class="report-header"><h2><?= e($event['event_name']) ?> · Documents checklist</h2><p><?= e(auth_context()['label']) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
    <div class="card panel">
        <div class="card-header"><h3>Checklist</h3>
            <div class="d-flex gap-2 d-print-none">
                <a class="btn btn-outline-primary btn-xs" href="<?= e(query_url(['export' => 'csv'])) ?>"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>
                <button class="btn btn-outline-primary btn-xs" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
            </div>
        </div>
        <form class="filter-bar d-print-none" method="get">
            <input type="hidden" name="id" value="<?= (int) $eventId ?>"><input type="hidden" name="tab" value="requirements">
            <div class="filter-field"><label for="doc-q">Search</label><input class="form-control" type="search" id="doc-q" name="q" value="<?= e($search) ?>" placeholder="Name or ID number"></div>
            <div class="filter-field"><label for="doc-show">Show</label><select class="form-select" id="doc-show" name="show"><?= options_html(['incomplete' => 'Not yet cleared', 'to_check' => 'Papers to check', 'returned' => 'Papers returned', 'cleared' => 'Cleared'], $show, 'Everyone') ?></select></div>
            <button class="btn btn-primary" type="submit">Apply</button>
        </form>
        <?php if (!$people): ?>
            <div class="card-body"><?= empty_state('bi-people', $totalPeople ? 'No one matches' : 'No one in this event yet', $totalPeople ? '' : 'Add people under the People tab or assign groups under Setup.') ?></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                <thead><tr><th>Person</th><?php foreach ($checklist['requirements'] as $requirement): ?><th><?= e($requirement['requirement_name']) ?><?= $requirement['is_required'] ? '' : ' <span class="text-muted-sm">(optional)</span>' ?></th><?php endforeach; ?><th>Cleared</th><th class="d-print-none"></th></tr></thead>
                <tbody><?php foreach ($people as $pid => $person): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($person['last_name'] . ', ' . $person['first_name']) ?></div><div class="text-muted-sm"><?= e($person['participant_number'] . ' · ' . (PARTICIPANT_TYPES[$person['participant_type']] ?? '') . ($person['group_names'] ? ' · ' . $person['group_names'] : '')) ?></div></td>
                        <?php foreach ($checklist['requirements'] as $requirement): ?><td><?= $cellBadge($person['cells'][(int) $requirement['id']]) ?></td><?php endforeach; ?>
                        <td><?= $person['cleared'] ? '<span class="badge badge-soft-success">Cleared</span>' : '<span class="text-muted-sm">' . e($person['done'] . ' of ' . $person['required']) . '</span>' ?></td>
                        <td class="actions d-print-none"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('events/documents.php?event=' . $eventId . '&person=' . $pid)) ?>">Papers</a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
        <div class="card-footer-note">Cleared = every required document that applies to the person is approved or marked not applicable. "—" means the document does not apply to that person.</div>
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'people'): ?>
    <?php
    $people = event_people($eventId);
    $inEvent = array_flip(array_map('intval', array_column($people, 'participant_id')));
    $groupMembers = (int) db_value(
        "SELECT COUNT(DISTINCT gm.participant_id) FROM event_group_assignments a JOIN group_memberships gm ON gm.institution_id = a.institution_id AND gm.group_id = a.group_id AND gm.status = 'active'
         WHERE a.institution_id = ? AND a.event_id = ? AND a.status = 'active'", [$institutionId, $eventId]);
    $canAddPeople = $canManage && !in_array($event['status'], ['completed', 'closed', 'cancelled'], true);
    ?>
    <div class="stat-grid">
        <?= stat_card('bi-person-plus', 'Added directly', number_format(count($people)), 'Students, faculty or customers') ?>
        <?= stat_card('bi-diagram-3', 'Through groups', number_format($groupMembers), count($assignments) . ' participating group(s)', 'info') ?>
    </div>
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card panel">
                <div class="card-header"><h3>People added to this event</h3></div>
                <?php if (!$people): ?>
                    <div class="card-body"><?= empty_state('bi-people', 'No one added directly', 'Add students, faculty or customers here; no group is needed. People in participating groups are included automatically.') ?></div>
                <?php else: ?>
                    <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                        <thead><tr><th>ID no.</th><th>Name</th><th>Type</th><th class="text-end">Owes</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th></th></tr></thead>
                        <tbody><?php foreach ($people as $row): $balance = max(0, to_cents($row['expected']) - to_cents($row['paid'])); ?>
                            <tr>
                                <td class="fw-semibold"><?= e($row['participant_number']) ?></td>
                                <td><a href="<?= e(url('participants/view.php?id=' . $row['participant_id'])) ?>"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></a></td>
                                <td><?= e(PARTICIPANT_TYPES[$row['participant_type']] ?? $row['participant_type']) ?></td>
                                <td class="text-end"><?= e(m($row['expected'])) ?></td>
                                <td class="text-end"><?= e(m($row['paid'])) ?></td>
                                <td class="text-end fw-semibold<?= $balance > 0 ? ' text-danger' : '' ?>"><?= e(money($balance, $currency)) ?></td>
                                <td class="actions"><?php if ($canManage): ?>
                                    <form method="post" data-confirm="Remove <?= e($row['first_name'] . ' ' . $row['last_name']) ?> from the event?" data-confirm-text="Their unpaid charges for this event are cancelled. People who already paid cannot be removed." data-reason="Reason for removing" data-danger>
                                        <?= csrf_field() ?><input type="hidden" name="action" value="remove_person"><input type="hidden" name="tab" value="people"><input type="hidden" name="participant_id" value="<?= (int) $row['participant_id'] ?>">
                                        <button class="btn btn-outline-danger btn-xs" type="submit">Remove</button>
                                    </form>
                                <?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?></tbody>
                    </table></div>
                <?php endif; ?>
                <div class="card-footer-note">Collection rules that apply to everyone in the event are charged to people as soon as they are added. Rules for one group or one person apply only to them.</div>
            </div>
        </div>
        <div class="col-xl-4">
            <?php if ($canAddPeople): ?>
                <div class="card panel form-card mb-3">
                    <div class="card-header"><h3>Add a person</h3></div>
                    <form method="post" class="card-body">
                        <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="add_people"><input type="hidden" name="tab" value="people">
                        <label class="form-label required" for="ep-person">Student, faculty or customer</label>
                        <select class="form-select mb-2" id="ep-person" name="participant_id" required data-searchable>
                            <option value="">Search name or ID number…</option>
                            <?php foreach (db_all("SELECT id, participant_number, first_name, last_name, participant_type FROM participants WHERE institution_id = ? AND status = 'active' ORDER BY last_name, first_name LIMIT 5000", [$institutionId]) as $person): ?>
                                <?php if (isset($inEvent[(int) $person['id']])) { continue; } ?>
                                <option value="<?= (int) $person['id'] ?>"><?= e($person['last_name'] . ', ' . $person['first_name'] . ' (' . $person['participant_number'] . ') · ' . (PARTICIPANT_TYPES[$person['participant_type']] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add to Event</button>
                        <div class="form-text">Not in the list? <a href="<?= e(url('participants/form.php')) ?>">Register them</a> first.</div>
                    </form>
                </div>
                <div class="card panel form-card mb-3">
                    <div class="card-header"><h3>Add many at once</h3></div>
                    <form method="post" class="card-body border-bottom" data-confirm="Add everyone of this type?" data-confirm-text="Every active person of the chosen type is added to the event and charged its collection rules.">
                        <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="add_people"><input type="hidden" name="tab" value="people">
                        <label class="form-label" for="ep-type">Everyone who is a…</label>
                        <div class="d-flex gap-2"><select class="form-select" id="ep-type" name="add_type" required><?= options_html(PARTICIPANT_TYPES, null, 'Choose type…') ?></select><button class="btn btn-outline-primary" type="submit">Add</button></div>
                    </form>
                    <form method="post" class="card-body" data-confirm="Add this group's members?" data-confirm-text="The group's current members are added as individuals. Later changes to the group do not change the event.">
                        <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="add_people"><input type="hidden" name="tab" value="people">
                        <label class="form-label" for="ep-group">Members of a group (one-time copy)</label>
                        <div class="d-flex gap-2"><select class="form-select" id="ep-group" name="add_group_id" required data-searchable><?= options_html(group_options(), null, 'Choose group…') ?></select><button class="btn btn-outline-primary" type="submit">Add</button></div>
                        <div class="form-text">To collect through a class president, assign the group under Setup instead.</div>
                    </form>
                </div>
            <?php elseif ($canManage): ?>
                <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div>People cannot be added to a <?= e($event['status']) ?> event.</div></div>
            <?php endif; ?>
        </div>
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
                    <thead><tr><th>Person</th><th>Collection</th><th class="text-end">Due</th><th class="text-end">Waived</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
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
                        <select class="form-select" name="membership_id" required aria-label="Organizer" data-searchable><?= options_html(member_options('events.manage'), null, 'Members with the organizer role…') ?></select>
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
