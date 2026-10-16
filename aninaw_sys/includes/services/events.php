<?php
/**
 * Events, organizers, funds and participating groups, collection rules,
 * participant obligations, waivers and the collection dashboard figures.
 *
 * Expected collections are calculated from obligations generated for eligible
 * participants only; nobody is assumed to owe every collection.
 */
declare(strict_types=1);

require_once __DIR__ . '/organization.php';

const EVENT_STATUSES = ['draft', 'pending', 'approved', 'collecting', 'completed', 'closed', 'cancelled'];
const EVENT_TRANSITIONS = [
    'draft'      => ['pending', 'approved', 'cancelled'],
    'pending'    => ['approved', 'draft', 'cancelled'],
    'approved'   => ['collecting', 'cancelled'],
    'collecting' => ['completed'],
    'completed'  => ['closed', 'collecting'],
    'closed'     => [],
    'cancelled'  => [],
];

/** Event in the current institution with the current member's management rights. */
function find_event(int $eventId): ?array
{
    $event = db_one(
        "SELECT e.*, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name
         FROM events e JOIN users u ON u.id = e.created_by
         WHERE e.institution_id = ? AND e.id = ?",
        [current_institution_id(), $eventId]
    );
    if ($event) {
        $event['can_manage'] = can_manage_event($event);
    }
    return $event;
}

/** Event managers: users with events.manage who created the event or are an active organizer. */
function can_manage_event(array $event): bool
{
    if (!can('events.manage')) {
        return false;
    }
    if ((int) $event['created_by'] === (int) auth_user()['id']) {
        return true;
    }
    return (bool) db_value(
        "SELECT 1 FROM event_organizers WHERE institution_id = ? AND event_id = ? AND membership_id = ? AND status = 'active'",
        [current_institution_id(), $event['id'], current_membership_id()]
    );
}

function require_event_manager(int $eventId): array
{
    $event = find_event($eventId) ?? fail_user('Event not found.');
    if (!$event['can_manage']) {
        fail_user('Only the event creator or its assigned organizers can change this event.');
    }
    return $event;
}

function can_view_events(): bool
{
    return can_any(['events.view', 'events.manage', 'collections.view']);
}

/** Events visible as [id => label]; for selects. */
function event_options(array $statuses = ['approved', 'collecting']): array
{
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    return options_from_rows(db_all(
        "SELECT id, event_code, event_name FROM events WHERE institution_id = ? AND status IN ({$placeholders}) ORDER BY start_date DESC, event_name",
        array_merge([current_institution_id()], $statuses)
    ), 'id', static fn($row) => $row['event_name'] . ' (' . $row['event_code'] . ')');
}

// ---------------------------------------------------------------------------
// Events
// ---------------------------------------------------------------------------

function save_event(?int $eventId, array $input): int
{
    $institutionId = (int) current_institution_id();
    $data = [
        'event_code'          => strtoupper(input_str($input, 'event_code', 60)),
        'event_name'          => input_str($input, 'event_name', 180),
        'description'         => input_str($input, 'description', 4000),
        'start_date'          => input_date($input, 'start_date'),
        'end_date'            => input_date($input, 'end_date'),
        'collection_deadline' => input_date($input, 'collection_deadline'),
    ];
    if (!preg_match('/^[A-Z0-9_.-]{2,60}$/', $data['event_code']) || $data['event_name'] === '') {
        fail_user('Event code (2-60 letters, numbers, dots, dashes) and name are required.');
    }
    if ($data['start_date'] && $data['end_date'] && $data['end_date'] < $data['start_date']) {
        fail_user('The end date must be on or after the start date.');
    }
    if (db_value('SELECT 1 FROM events WHERE institution_id = ? AND event_code = ? AND id <> ?', [$institutionId, $data['event_code'], $eventId ?? 0])) {
        fail_user('That event code is already used.');
    }
    $values = [$data['event_code'], $data['event_name'], $data['description'] ?: null, $data['start_date'], $data['end_date'], $data['collection_deadline']];

    if ($eventId) {
        $event = require_event_manager($eventId);
        if (in_array($event['status'], ['closed', 'cancelled'], true)) {
            fail_user('A ' . $event['status'] . ' event cannot be edited.');
        }
        db_query('UPDATE events SET event_code = ?, event_name = ?, description = ?, start_date = ?, end_date = ?, collection_deadline = ? WHERE institution_id = ? AND id = ?', [...$values, $institutionId, $eventId]);
        audit_log('event.updated', 'event', $eventId, $institutionId, (int) auth_user()['id'], $data, array_intersect_key($event, $data));
        return $eventId;
    }
    if (!can('events.manage')) {
        fail_user('You do not have permission to create events.');
    }
    db_query("INSERT INTO events (event_code, event_name, description, start_date, end_date, collection_deadline, institution_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)",
        [...$values, $institutionId, auth_user()['id']]);
    $id = (int) db()->lastInsertId();
    audit_log('event.created', 'event', $id, $institutionId, (int) auth_user()['id'], $data);
    return $id;
}

function set_event_status(int $eventId, string $status, string $reason): void
{
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $eventId, $status, $reason) {
        $event = require_event_manager($eventId);
        db_query('SELECT id FROM events WHERE id = ? FOR UPDATE', [$eventId]);
        if (!in_array($status, EVENT_TRANSITIONS[$event['status']] ?? [], true)) {
            fail_user('A ' . $event['status'] . ' event cannot move to ' . $status . '.');
        }
        if ($status === 'collecting') {
            if (!db_value("SELECT 1 FROM event_funds WHERE institution_id = ? AND event_id = ? AND status = 'active'", [$institutionId, $eventId])) {
                fail_user('Assign the fund that will receive the collections before opening collection.');
            }
            if (!db_value("SELECT 1 FROM collection_rules WHERE institution_id = ? AND event_id = ? AND status = 'active'", [$institutionId, $eventId])) {
                fail_user('Add at least one collection rule before opening collection.');
            }
        }
        if ($status === 'cancelled' && db_value(
            "SELECT 1 FROM payment_allocations pa JOIN event_obligations o ON o.id = pa.obligation_id AND o.institution_id = pa.institution_id
             JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
             WHERE o.institution_id = ? AND o.event_id = ? LIMIT 1", [$institutionId, $eventId])) {
            fail_user('This event has posted payments. Reverse or refund them before cancelling, or complete and close the event instead.');
        }
        if (in_array($status, ['cancelled', 'closed'], true) && mb_strlen($reason) < 5) {
            fail_user('Please give a reason.');
        }
        if ($status === 'approved') {
            db_query('UPDATE events SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?', [$status, auth_user()['id'], $eventId]);
        } else {
            db_query('UPDATE events SET status = ? WHERE id = ?', [$status, $eventId]);
        }
        if ($status === 'cancelled') {
            db_query("UPDATE event_obligations SET status = 'cancelled' WHERE institution_id = ? AND event_id = ? AND status IN ('unpaid', 'waived')", [$institutionId, $eventId]);
        }
        audit_log('event.status_changed', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['status' => $status], ['status' => $event['status']], $reason ?: null);
        if ($status === 'collecting') {
            $collectors = db_query("SELECT collector_membership_id FROM event_group_assignments WHERE institution_id = ? AND event_id = ? AND status = 'active'", [$institutionId, $eventId])->fetchAll(PDO::FETCH_COLUMN);
            foreach (array_unique($collectors) as $membershipId) {
                notify_membership($institutionId, (int) $membershipId, 'collection_assigned', 'Collection opened', $event['event_name'] . ' is now open for collection in your assigned group.');
            }
        }
    });
}

function add_event_fund(int $eventId, array $input): void
{
    $institutionId = (int) current_institution_id();
    require_event_manager($eventId);
    $fundId = input_int($input, 'fund_id') ?? 0;
    find_fund($fundId, ['active']);
    db_query(
        "INSERT INTO event_funds (institution_id, event_id, fund_id, allocation_purpose, status) VALUES (?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE status = 'active', allocation_purpose = VALUES(allocation_purpose)",
        [$institutionId, $eventId, $fundId, input_str($input, 'allocation_purpose', 255) ?: null]
    );
    audit_log('event.fund_assigned', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['fund_id' => $fundId]);
}

function remove_event_fund(int $eventId, int $eventFundId): void
{
    $institutionId = (int) current_institution_id();
    require_event_manager($eventId);
    $row = db_one('SELECT * FROM event_funds WHERE institution_id = ? AND id = ? AND event_id = ?', [$institutionId, $eventFundId, $eventId]) ?? fail_user('Not found.');
    db_query("UPDATE event_funds SET status = 'inactive' WHERE id = ?", [$eventFundId]);
    audit_log('event.fund_removed', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['fund_id' => (int) $row['fund_id']]);
}

/** Active funds of an event (the funds its collections may be credited to). */
function event_funds(int $eventId): array
{
    return db_all(
        "SELECT ef.id, ef.allocation_purpose, f.id AS fund_id, f.fund_code, f.fund_name, f.status AS fund_status
         FROM event_funds ef JOIN fund_accounts f ON f.id = ef.fund_id AND f.institution_id = ef.institution_id
         WHERE ef.institution_id = ? AND ef.event_id = ? AND ef.status = 'active' ORDER BY f.fund_name",
        [current_institution_id(), $eventId]
    );
}

function add_event_organizer(int $eventId, int $membershipId): void
{
    $institutionId = (int) current_institution_id();
    $event = require_event_manager($eventId);
    $member = find_member($membershipId);
    if (!member_has_permission($membershipId, 'events.manage')) {
        fail_user(person_name($member) . ' needs the Event Organizer role first.');
    }
    db_query(
        "INSERT INTO event_organizers (institution_id, event_id, membership_id, assigned_by, status) VALUES (?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE status = 'active', assigned_by = VALUES(assigned_by), assigned_at = NOW()",
        [$institutionId, $eventId, $membershipId, auth_user()['id']]
    );
    audit_log('event.organizer_assigned', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['membership_id' => $membershipId]);
    notify_membership($institutionId, $membershipId, 'event', 'Event organizer', 'You were assigned as organizer of ' . $event['event_name'] . '.');
}

function remove_event_organizer(int $eventId, int $organizerId): void
{
    $institutionId = (int) current_institution_id();
    require_event_manager($eventId);
    $row = db_one('SELECT * FROM event_organizers WHERE institution_id = ? AND id = ? AND event_id = ?', [$institutionId, $organizerId, $eventId]) ?? fail_user('Not found.');
    if ((int) $row['membership_id'] === current_membership_id()) {
        fail_user('You cannot remove yourself as organizer. Ask another organizer.');
    }
    db_query("UPDATE event_organizers SET status = 'inactive' WHERE id = ?", [$organizerId]);
    audit_log('event.organizer_removed', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['membership_id' => (int) $row['membership_id']]);
}

/** Assigns a participating group with its collector and the cashier who receives turnovers. */
function assign_event_group(int $eventId, array $input): void
{
    $institutionId = (int) current_institution_id();
    $event = require_event_manager($eventId);
    $groupId = input_int($input, 'group_id') ?? 0;
    $collectorId = input_int($input, 'collector_membership_id') ?? 0;
    $cashierId = input_int($input, 'cashier_membership_id') ?? 0;
    $group = find_group($groupId) ?? fail_user('Select a group.');
    if ($group['status'] !== 'active') {
        fail_user('Only active groups can participate.');
    }
    $collector = find_member($collectorId);
    if (!member_has_permission($collectorId, 'turnovers.submit') && !member_has_permission($collectorId, 'collections.record')) {
        fail_user(person_name($collector) . ' needs a role that can collect (e.g. Class President / Collector).');
    }
    $cashier = find_member($cashierId);
    if (!member_has_permission($cashierId, 'turnovers.receive')) {
        fail_user(person_name($cashier) . ' needs a role that can receive turnovers (e.g. Faculty Cashier).');
    }
    if ($collectorId === $cashierId) {
        fail_user('The collector and the receiving cashier must be different people.');
    }
    db_query(
        "INSERT INTO event_group_assignments (institution_id, event_id, group_id, collector_membership_id, cashier_membership_id, status) VALUES (?, ?, ?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE collector_membership_id = VALUES(collector_membership_id), cashier_membership_id = VALUES(cashier_membership_id), status = 'active', assigned_at = NOW()",
        [$institutionId, $eventId, $groupId, $collectorId, $cashierId]
    );
    audit_log('event.group_assigned', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['group_id' => $groupId, 'collector' => $collectorId, 'cashier' => $cashierId]);
    notify_membership($institutionId, $collectorId, 'collection_assigned', 'Collection assigned', 'You collect for ' . $group['group_name'] . ' in ' . $event['event_name'] . '.');
}

function remove_event_group(int $eventId, int $assignmentId): void
{
    $institutionId = (int) current_institution_id();
    require_event_manager($eventId);
    $row = db_one('SELECT * FROM event_group_assignments WHERE institution_id = ? AND id = ? AND event_id = ?', [$institutionId, $assignmentId, $eventId]) ?? fail_user('Not found.');
    db_query("UPDATE event_group_assignments SET status = 'inactive' WHERE id = ?", [$assignmentId]);
    audit_log('event.group_removed', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['group_id' => (int) $row['group_id']]);
}

// ---------------------------------------------------------------------------
// Collection rules and obligations
// ---------------------------------------------------------------------------

function save_collection_rule(int $eventId, ?int $ruleId, array $input): int
{
    $institutionId = (int) current_institution_id();
    $event = require_event_manager($eventId);
    if (in_array($event['status'], ['completed', 'closed', 'cancelled'], true)) {
        fail_user('Rules cannot change on a ' . $event['status'] . ' event.');
    }
    $amount = parse_money(input_str($input, 'amount_due', 20)) ?? fail_user('Enter the amount required (greater than zero).');
    $data = [
        'rule_name'             => input_str($input, 'rule_name', 180),
        'amount_due'            => cents_to_decimal($amount),
        'applies_to_type'       => input_enum($input, 'applies_to_type', ['all', 'group', 'participant'], 'all'),
        'applies_to_id'         => null,
        'effective_from'        => input_date($input, 'effective_from'),
        'effective_until'       => input_date($input, 'effective_until'),
        'allow_partial_payment' => input_str($input, 'allow_partial_payment', 1) === '1' ? 1 : 0,
    ];
    if ($data['rule_name'] === '') {
        fail_user('Name the collection (e.g. Registration Fee).');
    }
    if ($data['applies_to_type'] === 'group') {
        $data['applies_to_id'] = input_int($input, 'applies_to_group') ?? 0;
        find_group($data['applies_to_id']) ?? fail_user('Select the group this collection applies to.');
    } elseif ($data['applies_to_type'] === 'participant') {
        $data['applies_to_id'] = input_int($input, 'applies_to_participant') ?? 0;
        if (!db_value('SELECT 1 FROM participants WHERE institution_id = ? AND id = ?', [$institutionId, $data['applies_to_id']])) {
            fail_user('Select the participant this collection applies to.');
        }
    }
    if ($data['effective_from'] && $data['effective_until'] && $data['effective_until'] < $data['effective_from']) {
        fail_user('The due date must be on or after the start date.');
    }

    return db_transaction(function () use ($institutionId, $eventId, $ruleId, $data, $amount) {
        if ($ruleId) {
            $before = db_one('SELECT * FROM collection_rules WHERE institution_id = ? AND id = ? AND event_id = ? FOR UPDATE', [$institutionId, $ruleId, $eventId]) ?? fail_user('Rule not found.');
            $amountChanged = to_cents($before['amount_due']) !== $amount;
            $targetChanged = $before['applies_to_type'] !== $data['applies_to_type'] || (int) $before['applies_to_id'] !== (int) $data['applies_to_id'];
            if (($amountChanged || $targetChanged) && rule_has_payments($ruleId)) {
                fail_user('Payments were already received under this rule, so its amount and coverage are locked. Use waivers for individual adjustments or add a new rule.');
            }
            db_query(
                'UPDATE collection_rules SET rule_name = ?, amount_due = ?, applies_to_type = ?, applies_to_id = ?, effective_from = ?, effective_until = ?, allow_partial_payment = ? WHERE id = ?',
                [$data['rule_name'], $data['amount_due'], $data['applies_to_type'], $data['applies_to_id'], $data['effective_from'], $data['effective_until'], $data['allow_partial_payment'], $ruleId]
            );
            if ($amountChanged) {
                db_query("UPDATE event_obligations SET amount_due = ? WHERE institution_id = ? AND collection_rule_id = ? AND status <> 'cancelled'", [$data['amount_due'], $institutionId, $ruleId]);
            }
            if ($data['effective_until'] !== $before['effective_until']) {
                db_query("UPDATE event_obligations SET due_date = ? WHERE institution_id = ? AND collection_rule_id = ? AND status IN ('unpaid', 'partially_paid')", [$data['effective_until'], $institutionId, $ruleId]);
            }
            if ($targetChanged) {
                // Coverage changed before any payment: drop obligations that no longer apply.
                db_query("UPDATE event_obligations SET status = 'cancelled' WHERE institution_id = ? AND collection_rule_id = ? AND status <> 'cancelled'", [$institutionId, $ruleId]);
            }
            foreach (db_query('SELECT id FROM event_obligations WHERE institution_id = ? AND collection_rule_id = ?', [$institutionId, $ruleId])->fetchAll(PDO::FETCH_COLUMN) as $obligationId) {
                refresh_obligation_status((int) $obligationId);
            }
            audit_log('collection_rule.updated', 'collection_rule', $ruleId, $institutionId, (int) auth_user()['id'], $data, array_intersect_key($before, $data));
            return $ruleId;
        }
        db_query(
            "INSERT INTO collection_rules (institution_id, event_id, rule_name, amount_due, applies_to_type, applies_to_id, effective_from, effective_until, allow_partial_payment, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')",
            [$institutionId, $eventId, $data['rule_name'], $data['amount_due'], $data['applies_to_type'], $data['applies_to_id'], $data['effective_from'], $data['effective_until'], $data['allow_partial_payment']]
        );
        $id = (int) db()->lastInsertId();
        audit_log('collection_rule.created', 'collection_rule', $id, $institutionId, (int) auth_user()['id'], $data);
        return $id;
    });
}

function rule_has_payments(int $ruleId): bool
{
    return (bool) db_value(
        "SELECT 1 FROM payment_allocations pa
         JOIN event_obligations o ON o.id = pa.obligation_id AND o.institution_id = pa.institution_id
         JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status IN ('posted', 'pending')
         WHERE o.institution_id = ? AND o.collection_rule_id = ? LIMIT 1",
        [current_institution_id(), $ruleId]
    );
}

/** Deactivating a rule cancels its unpaid obligations; rules with payments cannot be deactivated. */
function set_rule_status(int $eventId, int $ruleId, string $status, string $reason): void
{
    $institutionId = (int) current_institution_id();
    require_event_manager($eventId);
    if (!in_array($status, ['active', 'inactive'], true)) {
        fail_user('Invalid status.');
    }
    db_transaction(function () use ($institutionId, $eventId, $ruleId, $status, $reason) {
        $rule = db_one('SELECT * FROM collection_rules WHERE institution_id = ? AND id = ? AND event_id = ? FOR UPDATE', [$institutionId, $ruleId, $eventId]) ?? fail_user('Rule not found.');
        if ($status === 'inactive') {
            if (rule_has_payments($ruleId)) {
                fail_user('Payments were received under this rule. Waive individual balances instead of deactivating it.');
            }
            if (mb_strlen($reason) < 5) {
                fail_user('Please give a reason.');
            }
            db_query("UPDATE event_obligations SET status = 'cancelled' WHERE institution_id = ? AND collection_rule_id = ? AND status <> 'cancelled'", [$institutionId, $ruleId]);
        }
        db_query('UPDATE collection_rules SET status = ? WHERE id = ?', [$status, $ruleId]);
        audit_log('collection_rule.status_changed', 'collection_rule', $ruleId, $institutionId, (int) auth_user()['id'], ['status' => $status], ['status' => $rule['status']], $reason ?: null);
    });
}

/**
 * Participants a rule applies to:
 *  - all: active members of the event's participating groups (or, when the event has no
 *         groups, every active participant of the institution);
 *  - group: active members of that group;
 *  - participant: that participant.
 */
function rule_eligible_participants(array $rule): array
{
    $institutionId = (int) current_institution_id();
    if ($rule['applies_to_type'] === 'participant') {
        return array_map('intval', db_query("SELECT id FROM participants WHERE institution_id = ? AND id = ? AND status = 'active'", [$institutionId, $rule['applies_to_id']])->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($rule['applies_to_type'] === 'group') {
        return array_map('intval', db_query(
            "SELECT DISTINCT gm.participant_id FROM group_memberships gm JOIN participants p ON p.id = gm.participant_id AND p.institution_id = gm.institution_id AND p.status = 'active'
             WHERE gm.institution_id = ? AND gm.group_id = ? AND gm.status = 'active'",
            [$institutionId, $rule['applies_to_id']]
        )->fetchAll(PDO::FETCH_COLUMN));
    }
    $hasGroups = db_value("SELECT 1 FROM event_group_assignments WHERE institution_id = ? AND event_id = ? AND status = 'active'", [$institutionId, $rule['event_id']]);
    if ($hasGroups) {
        return array_map('intval', db_query(
            "SELECT DISTINCT gm.participant_id FROM event_group_assignments a
             JOIN group_memberships gm ON gm.institution_id = a.institution_id AND gm.group_id = a.group_id AND gm.status = 'active'
             JOIN participants p ON p.id = gm.participant_id AND p.institution_id = gm.institution_id AND p.status = 'active'
             WHERE a.institution_id = ? AND a.event_id = ? AND a.status = 'active'",
            [$institutionId, $rule['event_id']]
        )->fetchAll(PDO::FETCH_COLUMN));
    }
    return array_map('intval', db_query("SELECT id FROM participants WHERE institution_id = ? AND status = 'active'", [$institutionId])->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Creates missing obligations for every active rule of the event. Safe to run again:
 * existing obligations (unique per event/participant/rule) are left untouched.
 * @return int number of obligations created
 */
function generate_obligations(int $eventId): int
{
    $institutionId = (int) current_institution_id();
    $event = require_event_manager($eventId);
    if (!in_array($event['status'], ['draft', 'pending', 'approved', 'collecting'], true)) {
        fail_user('Obligations can only be generated before an event is completed.');
    }
    return db_transaction(function () use ($institutionId, $eventId, $event) {
        $created = 0;
        $rules = db_all("SELECT * FROM collection_rules WHERE institution_id = ? AND event_id = ? AND status = 'active' FOR UPDATE", [$institutionId, $eventId]);
        if (!$rules) {
            fail_user('Add an active collection rule first.');
        }
        foreach ($rules as $rule) {
            foreach (rule_eligible_participants($rule) as $participantId) {
                $statement = db_query(
                    "INSERT IGNORE INTO event_obligations (institution_id, event_id, participant_id, collection_rule_id, amount_due, waived_amount, due_date, status)
                     VALUES (?, ?, ?, ?, ?, 0, ?, 'unpaid')",
                    [$institutionId, $eventId, $participantId, $rule['id'], $rule['amount_due'], $rule['effective_until'] ?? $event['collection_deadline']]
                );
                $created += $statement->rowCount();
            }
        }
        audit_log('event.obligations_generated', 'event', $eventId, $institutionId, (int) auth_user()['id'], ['created' => $created]);
        return $created;
    });
}

/** Posted payments allocated to an obligation, in centavos. */
function obligation_paid_cents(int $obligationId): int
{
    return to_cents(db_value(
        "SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa
         JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
         WHERE pa.institution_id = ? AND pa.obligation_id = ?",
        [current_institution_id(), $obligationId]
    ));
}

/** Recomputes the stored status of an obligation from its amounts (never from user input). */
function refresh_obligation_status(int $obligationId): void
{
    $institutionId = (int) current_institution_id();
    $obligation = db_one('SELECT amount_due, waived_amount, status FROM event_obligations WHERE institution_id = ? AND id = ?', [$institutionId, $obligationId]);
    if (!$obligation || $obligation['status'] === 'cancelled') {
        return;
    }
    $net = to_cents($obligation['amount_due']) - to_cents($obligation['waived_amount']);
    $paid = obligation_paid_cents($obligationId);
    $status = match (true) {
        $net <= 0 && $paid === 0 => 'waived',
        $paid >= $net            => 'paid',
        $paid > 0                => 'partially_paid',
        default                  => 'unpaid',
    };
    if ($status !== $obligation['status']) {
        db_query('UPDATE event_obligations SET status = ? WHERE id = ?', [$status, $obligationId]);
    }
}

/** Approved exception: waives part or all of the unpaid balance, with a reason. */
function waive_obligation(int $obligationId, string $amountInput, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (mb_strlen($reason) < 5) {
        fail_user('A reason is required for every waiver.');
    }
    $amount = parse_money($amountInput) ?? fail_user('Enter the amount to waive.');
    db_transaction(function () use ($institutionId, $obligationId, $amount, $reason) {
        $obligation = db_one('SELECT * FROM event_obligations WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $obligationId]) ?? fail_user('Obligation not found.');
        require_event_manager((int) $obligation['event_id']);
        if ($obligation['status'] === 'cancelled') {
            fail_user('This obligation is cancelled.');
        }
        $balance = to_cents($obligation['amount_due']) - to_cents($obligation['waived_amount']) - obligation_paid_cents($obligationId);
        if ($amount > $balance) {
            fail_user('You can waive at most the unpaid balance of ' . money(max(0, $balance), current_currency()) . '.');
        }
        $newWaived = to_cents($obligation['waived_amount']) + $amount;
        db_query('UPDATE event_obligations SET waived_amount = ? WHERE id = ?', [cents_to_decimal($newWaived), $obligationId]);
        refresh_obligation_status($obligationId);
        audit_log('obligation.waived', 'event_obligation', $obligationId, $institutionId, (int) auth_user()['id'],
            ['waived_amount' => cents_to_decimal($newWaived), 'waived_now' => cents_to_decimal($amount)], ['waived_amount' => $obligation['waived_amount']], $reason);
    });
}

/** Cancels an obligation that has no payments (e.g. created in error). */
function cancel_obligation(int $obligationId, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (mb_strlen($reason) < 5) {
        fail_user('A reason is required.');
    }
    db_transaction(function () use ($institutionId, $obligationId, $reason) {
        $obligation = db_one('SELECT * FROM event_obligations WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $obligationId]) ?? fail_user('Obligation not found.');
        require_event_manager((int) $obligation['event_id']);
        if (db_value("SELECT 1 FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id AND p.status IN ('posted', 'pending') WHERE pa.institution_id = ? AND pa.obligation_id = ?", [$institutionId, $obligationId])) {
            fail_user('This obligation has payments. Waive the balance instead, or reverse the payments first.');
        }
        db_query("UPDATE event_obligations SET status = 'cancelled' WHERE id = ?", [$obligationId]);
        audit_log('obligation.cancelled', 'event_obligation', $obligationId, $institutionId, (int) auth_user()['id'], ['status' => 'cancelled'], ['status' => $obligation['status']], $reason);
    });
}

// ---------------------------------------------------------------------------
// Collection dashboard
// ---------------------------------------------------------------------------

/**
 * Figures for one event (optionally one group), from database records only.
 * Expected = due − waived on non-cancelled obligations; received = allocations of posted payments;
 * pending turnover = posted payments received by the event's collectors not yet in a completed turnover.
 */
function event_collection_stats(int $eventId, ?int $groupId = null): array
{
    $institutionId = (int) current_institution_id();
    $groupFilter = '';
    $params = [$institutionId, $eventId];
    if ($groupId) {
        $groupFilter = "AND EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = o.institution_id AND gm.group_id = ? AND gm.participant_id = o.participant_id AND gm.status = 'active')";
        $params[] = $groupId;
    }
    $rows = db_all(
        "SELECT o.participant_id, SUM(o.amount_due - o.waived_amount) AS net,
                SUM((SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa
                     JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
                     WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id)) AS paid
         FROM event_obligations o
         WHERE o.institution_id = ? AND o.event_id = ? AND o.status <> 'cancelled' {$groupFilter}
         GROUP BY o.participant_id",
        $params
    );
    $expected = $received = 0;
    $paidCount = $partialCount = $unpaidCount = 0;
    foreach ($rows as $row) {
        $net = to_cents($row['net']);
        $paid = to_cents($row['paid']);
        $expected += $net;
        $received += min($paid, max($net, 0));
        if ($net <= 0 || $paid >= $net) {
            $paidCount++;
        } elseif ($paid > 0) {
            $partialCount++;
        } else {
            $unpaidCount++;
        }
    }

    $pendingParams = [$institutionId, $eventId, $institutionId, $eventId];
    $pendingGroup = '';
    if ($groupId) {
        $pendingGroup = 'AND a.group_id = ?';
        $pendingParams[] = $groupId;
    }
    $pendingTurnover = to_cents(db_value(
        "SELECT COALESCE(SUM(pa.allocated_amount), 0)
         FROM payment_allocations pa
         JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
         JOIN event_obligations o ON o.id = pa.obligation_id AND o.institution_id = pa.institution_id
         WHERE pa.institution_id = ? AND o.event_id = ?
           AND p.received_by_membership_id IN (SELECT a.collector_membership_id FROM event_group_assignments a WHERE a.institution_id = ? AND a.event_id = ? {$pendingGroup})
           AND NOT EXISTS (SELECT 1 FROM cash_turnover_details d JOIN cash_turnovers t ON t.id = d.turnover_id AND t.institution_id = d.institution_id
                            WHERE d.institution_id = p.institution_id AND d.payment_id = p.id AND t.status IN ('verified', 'discrepancy'))",
        $pendingParams
    ));

    return [
        'expected'        => $expected,
        'received'        => $received,
        'remaining'       => max(0, $expected - $received),
        'percent'         => $expected > 0 ? round($received / $expected * 100, 1) : 0.0,
        'participants'    => count($rows),
        'paid_count'      => $paidCount,
        'partial_count'   => $partialCount,
        'unpaid_count'    => $unpaidCount,
        'pending_turnover'=> $pendingTurnover,
    ];
}
