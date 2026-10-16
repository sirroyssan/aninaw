<?php
/**
 * Event document requirements: the papers each person in an event must submit
 * (parent's waiver, medical certificate, parent's ID with signature...), chosen per event.
 *
 * - Requirements belong to one event and may apply to everyone or to one kind of person
 *   (e.g. parent's waiver for students only); optional ones are not counted for clearance.
 * - No submission row = not yet submitted. A row records submitted / approved / returned
 *   (rejected, with the reason) / not applicable (waived, with the reason), who recorded and
 *   who checked it, and optionally a scanned copy. Changes are audited, never deleted.
 * - A person is cleared when every required paper that applies to them is approved or
 *   marked not applicable.
 * - Event managers (creator, organizers) and holders of events.requirements record and check
 *   papers. Scans are personal data (medical, IDs): only they may open them.
 */
declare(strict_types=1);

require_once __DIR__ . '/events.php';

const REQUIREMENT_STATUSES = [
    'missing'   => 'Not submitted',
    'submitted' => 'Submitted, to check',
    'approved'  => 'Approved',
    'rejected'  => 'Returned',
    'waived'    => 'Not applicable',
];
const REQUIREMENT_APPLIES = ['all' => 'Everyone'] + PARTICIPANT_TYPES;

function can_check_event_documents(array $event): bool
{
    return $event['can_manage'] || can('events.requirements');
}

function require_document_checker(int $eventId): array
{
    $event = find_event($eventId) ?? fail_user('Event not found.');
    if (!can_check_event_documents($event)) {
        fail_user('Only the event\'s organizers or document checkers can record documents.');
    }
    return $event;
}

/** Requirements of an event with counts of approved, submitted and returned papers. */
function event_requirements(int $eventId, bool $activeOnly = false): array
{
    return db_all(
        "SELECT r.*,
                (SELECT COUNT(*) FROM event_requirement_submissions s WHERE s.institution_id = r.institution_id AND s.requirement_id = r.id AND s.status IN ('approved', 'waived')) AS done_count,
                (SELECT COUNT(*) FROM event_requirement_submissions s WHERE s.institution_id = r.institution_id AND s.requirement_id = r.id AND s.status = 'submitted') AS submitted_count,
                (SELECT COUNT(*) FROM event_requirement_submissions s WHERE s.institution_id = r.institution_id AND s.requirement_id = r.id AND s.status = 'rejected') AS rejected_count
         FROM event_requirements r WHERE r.institution_id = ? AND r.event_id = ?" . ($activeOnly ? " AND r.status = 'active'" : '') . '
         ORDER BY r.status, r.sort_order, r.id',
        [current_institution_id(), $eventId]
    );
}

function save_event_requirement(int $eventId, ?int $requirementId, array $input): int
{
    $institutionId = (int) current_institution_id();
    $event = require_event_manager($eventId);
    if (in_array($event['status'], ['closed', 'cancelled'], true)) {
        fail_user('Requirements cannot be changed on a ' . $event['status'] . ' event.');
    }
    $data = [
        'requirement_name' => input_str($input, 'requirement_name', 180),
        'description'      => input_str($input, 'description', 1000),
        'applies_to_type'  => input_enum($input, 'applies_to_type', array_keys(REQUIREMENT_APPLIES), 'all'),
        'is_required'      => input_str($input, 'is_required', 1) === '0' ? 0 : 1,
        'due_date'         => input_date($input, 'due_date'),
        'sort_order'       => input_int($input, 'sort_order') ?? 0,
    ];
    if (mb_strlen($data['requirement_name']) < 3) {
        fail_user('Name the required document (e.g. Parent\'s waiver).');
    }
    if (db_value('SELECT 1 FROM event_requirements WHERE institution_id = ? AND event_id = ? AND requirement_name = ? AND id <> ?', [$institutionId, $eventId, $data['requirement_name'], $requirementId ?? 0])) {
        fail_user('This event already has a requirement with that name.');
    }
    $values = [$data['requirement_name'], $data['description'] ?: null, $data['applies_to_type'], $data['is_required'], $data['due_date'], $data['sort_order']];
    if ($requirementId) {
        $before = db_one('SELECT requirement_name, description, applies_to_type, is_required, due_date, sort_order FROM event_requirements WHERE institution_id = ? AND event_id = ? AND id = ?', [$institutionId, $eventId, $requirementId])
            ?? fail_user('Requirement not found.');
        db_query('UPDATE event_requirements SET requirement_name = ?, description = ?, applies_to_type = ?, is_required = ?, due_date = ?, sort_order = ? WHERE institution_id = ? AND id = ?', [...$values, $institutionId, $requirementId]);
        audit_log('event.requirement_updated', 'event', $eventId, $institutionId, (int) auth_user()['id'], $data + ['requirement_id' => $requirementId], $before);
        return $requirementId;
    }
    db_query('INSERT INTO event_requirements (institution_id, event_id, requirement_name, description, applies_to_type, is_required, due_date, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$institutionId, $eventId, ...$values, (int) auth_user()['id']]);
    $id = (int) db()->lastInsertId();
    audit_log('event.requirement_added', 'event', $eventId, $institutionId, (int) auth_user()['id'], $data + ['requirement_id' => $id]);
    return $id;
}

/** Stops (or restarts) asking for a document. Papers already recorded are kept. */
function set_event_requirement_status(int $eventId, int $requirementId, string $status): void
{
    $institutionId = (int) current_institution_id();
    require_event_manager($eventId);
    $status = in_array($status, ['active', 'inactive'], true) ? $status : fail_user('Invalid status.');
    $row = db_one('SELECT id, status FROM event_requirements WHERE institution_id = ? AND event_id = ? AND id = ?', [$institutionId, $eventId, $requirementId]) ?? fail_user('Requirement not found.');
    db_query('UPDATE event_requirements SET status = ? WHERE id = ?', [$status, $requirementId]);
    audit_log('event.requirement_' . ($status === 'active' ? 'restored' : 'removed'), 'event', $eventId, $institutionId, (int) auth_user()['id'], ['requirement_id' => $requirementId, 'status' => $status], ['status' => $row['status']]);
}

/**
 * Everyone in the event: members of its active groups, people added directly, and anyone
 * with an obligation in it. [participant_id => row with name, number, type, groups]
 */
function event_roster(int $eventId): array
{
    $institutionId = (int) current_institution_id();
    $rows = db_all(
        "SELECT p.id, p.participant_number, p.first_name, p.last_name, p.participant_type,
                (SELECT GROUP_CONCAT(DISTINCT g.group_name ORDER BY g.group_name SEPARATOR ', ')
                   FROM event_group_assignments a JOIN group_memberships gm ON gm.institution_id = a.institution_id AND gm.group_id = a.group_id AND gm.status = 'active'
                   JOIN `groups` g ON g.id = a.group_id AND g.institution_id = a.institution_id
                  WHERE a.institution_id = p.institution_id AND a.event_id = ? AND a.status = 'active' AND gm.participant_id = p.id) AS group_names
         FROM participants p
         WHERE p.institution_id = ? AND p.status = 'active' AND p.id IN (
             SELECT gm.participant_id FROM event_group_assignments a JOIN group_memberships gm ON gm.institution_id = a.institution_id AND gm.group_id = a.group_id AND gm.status = 'active'
              WHERE a.institution_id = ? AND a.event_id = ? AND a.status = 'active'
             UNION SELECT ep.participant_id FROM event_participants ep WHERE ep.institution_id = ? AND ep.event_id = ? AND ep.status = 'active'
             UNION SELECT o.participant_id FROM event_obligations o WHERE o.institution_id = ? AND o.event_id = ? AND o.status <> 'cancelled')
         ORDER BY p.last_name, p.first_name, p.id",
        [$eventId, $institutionId, $institutionId, $eventId, $institutionId, $eventId, $institutionId, $eventId]
    );
    return array_column($rows, null, 'id');
}

function requirement_applies(array $requirement, string $participantType): bool
{
    return $requirement['applies_to_type'] === 'all' || $requirement['applies_to_type'] === $participantType;
}

/**
 * Checklist for the event: one row per person with the status of each active requirement
 * that applies to them, and whether they are cleared.
 * @return array{requirements: array, people: array}
 */
function event_document_checklist(int $eventId): array
{
    $institutionId = (int) current_institution_id();
    $requirements = event_requirements($eventId, true);
    $roster = event_roster($eventId);
    $submissions = [];
    foreach (db_all('SELECT id, requirement_id, participant_id, status, remarks, received_at, reviewed_at FROM event_requirement_submissions WHERE institution_id = ? AND event_id = ?', [$institutionId, $eventId]) as $row) {
        $submissions[(int) $row['participant_id']][(int) $row['requirement_id']] = $row;
    }
    $people = [];
    foreach ($roster as $id => $person) {
        $cells = [];
        $required = $done = $submitted = $returned = 0;
        foreach ($requirements as $requirement) {
            $rid = (int) $requirement['id'];
            if (!requirement_applies($requirement, $person['participant_type'])) {
                $cells[$rid] = null; // does not apply to this person
                continue;
            }
            $submission = $submissions[$id][$rid] ?? null;
            $status = $submission['status'] ?? 'missing';
            $cells[$rid] = ['status' => $status, 'submission' => $submission];
            if ($requirement['is_required']) {
                $required++;
                $done += in_array($status, ['approved', 'waived'], true) ? 1 : 0;
                $submitted += $status === 'submitted' ? 1 : 0;
                $returned += $status === 'rejected' ? 1 : 0;
            }
        }
        $people[$id] = $person + ['cells' => $cells, 'required' => $required, 'done' => $done, 'to_check' => $submitted, 'returned' => $returned,
            'missing' => $required - $done - $submitted - $returned, 'cleared' => $done === $required];
    }
    return ['requirements' => $requirements, 'people' => $people];
}

/**
 * Records a paper for one or more people: submitted (received, to check), approved,
 * returned (with the reason) or not applicable (with the reason). An optional scan
 * (field "document") is attached when recording for one person.
 * @return int number of people updated
 */
function record_event_documents(int $eventId, int $requirementId, array $participantIds, string $status, string $remarks, ?string $fileField = null): int
{
    $institutionId = (int) current_institution_id();
    $event = require_document_checker($eventId);
    if (in_array($event['status'], ['closed', 'cancelled'], true)) {
        fail_user('Documents cannot be recorded on a ' . $event['status'] . ' event.');
    }
    if (!in_array($status, ['submitted', 'approved', 'rejected', 'waived'], true)) {
        fail_user('Choose what happened to the document.');
    }
    $remarks = mb_substr(trim($remarks), 0, 500);
    if (in_array($status, ['rejected', 'waived'], true) && mb_strlen($remarks) < 3) {
        fail_user($status === 'rejected' ? 'Say why the document was returned (e.g. no parent signature).' : 'Say why the document is not needed.');
    }
    $requirement = db_one("SELECT * FROM event_requirements WHERE institution_id = ? AND event_id = ? AND id = ? AND status = 'active'", [$institutionId, $eventId, $requirementId])
        ?? fail_user('Requirement not found.');
    $participantIds = array_values(array_unique(array_map('intval', $participantIds)));
    if (!$participantIds) {
        fail_user('Select at least one person.');
    }
    $roster = event_roster($eventId);
    foreach ($participantIds as $participantId) {
        if (!isset($roster[$participantId])) {
            fail_user('One of the selected people is not in this event.');
        }
        if (!requirement_applies($requirement, $roster[$participantId]['participant_type'])) {
            fail_user('"' . $requirement['requirement_name'] . '" does not apply to ' . $roster[$participantId]['first_name'] . ' ' . $roster[$participantId]['last_name'] . '.');
        }
    }
    if ($fileField !== null && has_upload($fileField) && count($participantIds) !== 1) {
        fail_user('Attach a scanned copy when recording one person at a time.');
    }
    $userId = (int) auth_user()['id'];
    $reviewed = in_array($status, ['approved', 'rejected', 'waived'], true);

    return db_transaction(function () use ($institutionId, $eventId, $requirementId, $participantIds, $status, $remarks, $fileField, $userId, $reviewed, $requirement) {
        foreach ($participantIds as $participantId) {
            $before = db_one('SELECT id, status, remarks FROM event_requirement_submissions WHERE institution_id = ? AND requirement_id = ? AND participant_id = ? FOR UPDATE', [$institutionId, $requirementId, $participantId]);
            if ($before) {
                db_query('UPDATE event_requirement_submissions SET status = ?, remarks = ?, recorded_by = ?, reviewed_by = ?, reviewed_at = ?' . ($before['status'] === 'rejected' && $status === 'submitted' ? ', received_at = NOW()' : '') . ' WHERE id = ?',
                    [$status, $remarks ?: null, $userId, $reviewed ? $userId : null, $reviewed ? date('Y-m-d H:i:s') : null, $before['id']]);
                $submissionId = (int) $before['id'];
            } else {
                db_query('INSERT INTO event_requirement_submissions (institution_id, event_id, requirement_id, participant_id, status, remarks, received_at, recorded_by, reviewed_by, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)',
                    [$institutionId, $eventId, $requirementId, $participantId, $status, $remarks ?: null, $userId, $reviewed ? $userId : null, $reviewed ? date('Y-m-d H:i:s') : null]);
                $submissionId = (int) db()->lastInsertId();
            }
            if ($fileField !== null && has_upload($fileField)) {
                store_attachment($fileField, 'event_document', $submissionId);
            }
            audit_log('event.document_' . $status, 'event', $eventId, $institutionId, $userId,
                ['requirement' => $requirement['requirement_name'], 'participant_id' => $participantId, 'status' => $status, 'remarks' => $remarks],
                $before ? ['status' => $before['status'], 'remarks' => $before['remarks']] : ['status' => 'missing']);
        }
        return count($participantIds);
    });
}

/** Narrows checklist rows: show = incomplete | cleared | to_check | returned | '' ; search on name / ID number. */
function filter_document_checklist(array $people, string $show, string $search): array
{
    return array_filter($people, static function (array $p) use ($show, $search): bool {
        if (($show === 'incomplete' && $p['cleared']) || ($show === 'cleared' && !$p['cleared']) || ($show === 'to_check' && !$p['to_check']) || ($show === 'returned' && !$p['returned'])) {
            return false;
        }
        return $search === '' || stripos($p['participant_number'] . ' ' . $p['first_name'] . ' ' . $p['last_name'] . ' ' . $p['last_name'] . ', ' . $p['first_name'], $search) !== false;
    });
}

/** Sends the checklist as CSV (ends the request). */
function send_document_checklist_csv(array $event, array $checklist, array $people): never
{
    audit_log('report.exported', 'event', (int) $event['id'], (int) current_institution_id(), (int) auth_user()['id'], ['report' => 'event_documents', 'rows' => count($people)]);
    send_csv('documents_' . $event['event_code'] . '_' . date('Ymd') . '.csv',
        array_merge(['ID no.', 'Name', 'Type', 'Groups'], array_column($checklist['requirements'], 'requirement_name'), ['Cleared']),
        array_map(static fn($p) => array_merge(
            [$p['participant_number'], $p['last_name'] . ', ' . $p['first_name'], PARTICIPANT_TYPES[$p['participant_type']] ?? '', (string) $p['group_names']],
            array_map(static fn($cell) => $cell === null ? 'n/a' : REQUIREMENT_STATUSES[$cell['status']] . (!empty($cell['submission']['remarks']) ? ' (' . $cell['submission']['remarks'] . ')' : ''), array_values($p['cells'])),
            [$p['cleared'] ? 'Yes' : 'No']
        ), array_values($people)),
        [['Event documents', $event['event_name']], ['Generated', date('Y-m-d H:i') . ' by ' . auth_user()['username']]]);
}
