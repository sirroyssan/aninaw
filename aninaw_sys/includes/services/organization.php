<?php
/**
 * Organizational structure: departments, groups (classes, sections, committees,
 * chapters...), participants, group memberships and officers.
 * Nothing here assumes one institution's structure: departments are optional and
 * group types are labels each institution chooses.
 */
declare(strict_types=1);

const GROUP_TYPES = ['class' => 'Class', 'section' => 'Section', 'team' => 'Team / Committee', 'organization' => 'Organization / Chapter', 'other' => 'Other'];
const PARTICIPANT_TYPES = ['student' => 'Student', 'employee' => 'Faculty / Staff', 'member' => 'Member', 'other' => 'Customer / Other'];
/** Programs every new institution starts with (code => name); each institution can edit or add more. */
const DEFAULT_PROGRAMS = [
    'BSIT' => 'Bachelor of Science in Information Technology',
    'BSCS' => 'Bachelor of Science in Computer Science',
    'BSIS' => 'Bachelor of Science in Information Systems',
    'ACT' => 'Associate in Computer Technology',
    'BEEd' => 'Bachelor of Elementary Education',
    'BECEd' => 'Bachelor of Early Childhood Education',
    'BECAEd' => 'Bachelor of Culture and Arts Education',
];
const GUARDIAN_RELATIONSHIPS = ['Mother' => 'Mother', 'Father' => 'Father', 'Guardian' => 'Guardian', 'Grandparent' => 'Grandparent', 'Sibling' => 'Sibling', 'Spouse' => 'Spouse', 'Other' => 'Other'];
const OFFICER_TYPES = ['president' => 'President', 'collector' => 'Collector', 'adviser' => 'Adviser', 'other' => 'Other officer'];

/**
 * Participants the current member may see.
 * Administrators, cashiers, organizers and finance staff see everyone; collectors and
 * advisers see only participants of groups they serve as officer or collector.
 *
 * @return array{0: string, 1: array} SQL condition on alias "p" and its parameters
 */
function participant_visibility(string $alias = 'p'): array
{
    if (can_any(['organization.manage', 'receipts.issue', 'events.manage', 'funds.view_all'])) {
        return ['1 = 1', []];
    }
    $sql = "EXISTS (SELECT 1 FROM group_memberships vgm
              WHERE vgm.institution_id = {$alias}.institution_id AND vgm.participant_id = {$alias}.id AND vgm.status = 'active'
                AND vgm.group_id IN (" . my_group_ids_sql() . '))';
    return [$sql, [current_institution_id(), current_membership_id(), current_institution_id(), current_membership_id()]];
}

/** SQL returning group IDs the current member serves (officer or event collector). Two param pairs. */
function my_group_ids_sql(): string
{
    return "SELECT go.group_id FROM group_officers go
              WHERE go.institution_id = ? AND go.membership_id = ? AND go.status = 'active'
                AND go.valid_from <= CURDATE() AND (go.valid_until IS NULL OR go.valid_until >= CURDATE())
            UNION
            SELECT ega.group_id FROM event_group_assignments ega
              WHERE ega.institution_id = ? AND ega.collector_membership_id = ? AND ega.status = 'active'";
}

function my_group_ids(): array
{
    return array_map('intval', db_query(my_group_ids_sql(), [current_institution_id(), current_membership_id(), current_institution_id(), current_membership_id()])->fetchAll(PDO::FETCH_COLUMN));
}

function can_view_participants(): bool
{
    return can_any(['participants.view', 'organization.manage']);
}

// ---------------------------------------------------------------------------
// Departments
// ---------------------------------------------------------------------------

function save_department(?int $departmentId, array $input): int
{
    $institutionId = (int) current_institution_id();
    $code = strtoupper(input_str($input, 'department_code', 60));
    $name = input_str($input, 'department_name', 180);
    $status = input_enum($input, 'status', ['active', 'inactive'], 'active');
    if (!preg_match('/^[A-Z0-9_.-]{1,60}$/', $code) || $name === '') {
        fail_user('Department code (letters, numbers, dots, dashes) and name are required.');
    }
    if (db_value('SELECT 1 FROM departments WHERE institution_id = ? AND department_code = ? AND id <> ?', [$institutionId, $code, $departmentId ?? 0])) {
        fail_user('That department code is already used.');
    }
    if ($departmentId) {
        $before = db_one('SELECT department_code, department_name, status FROM departments WHERE institution_id = ? AND id = ?', [$institutionId, $departmentId]) ?? fail_user('Department not found.');
        db_query('UPDATE departments SET department_code = ?, department_name = ?, status = ? WHERE institution_id = ? AND id = ?', [$code, $name, $status, $institutionId, $departmentId]);
        audit_log('department.updated', 'department', $departmentId, $institutionId, (int) auth_user()['id'], compact('code', 'name', 'status'), $before);
        return $departmentId;
    }
    db_query('INSERT INTO departments (institution_id, department_code, department_name, status) VALUES (?, ?, ?, ?)', [$institutionId, $code, $name, $status]);
    $id = (int) db()->lastInsertId();
    audit_log('department.created', 'department', $id, $institutionId, (int) auth_user()['id'], compact('code', 'name', 'status'));
    return $id;
}

function department_options(bool $activeOnly = true): array
{
    return options_from_rows(db_all(
        'SELECT id, department_code, department_name FROM departments WHERE institution_id = ?' . ($activeOnly ? " AND status = 'active'" : '') . ' ORDER BY department_name',
        [current_institution_id()]
    ), 'id', static fn($row) => $row['department_name'] . ' (' . $row['department_code'] . ')');
}

// ---------------------------------------------------------------------------
// Groups
// ---------------------------------------------------------------------------

function save_group(?int $groupId, array $input): int
{
    $institutionId = (int) current_institution_id();
    $data = [
        'department_id' => input_int($input, 'department_id'),
        'group_code'    => strtoupper(input_str($input, 'group_code', 80)),
        'group_name'    => input_str($input, 'group_name', 180),
        'group_type'    => input_enum($input, 'group_type', array_keys(GROUP_TYPES), 'class'),
        'academic_year' => input_str($input, 'academic_year', 30),
        'term'          => input_str($input, 'term', 40),
        'status'        => input_enum($input, 'status', ['active', 'inactive', 'closed'], 'active'),
    ];
    if (!preg_match('/^[A-Z0-9_. -]{1,80}$/', $data['group_code']) || $data['group_name'] === '') {
        fail_user('Group code and name are required.');
    }
    if ($data['department_id'] && !db_value('SELECT 1 FROM departments WHERE institution_id = ? AND id = ?', [$institutionId, $data['department_id']])) {
        fail_user('Select a valid department.');
    }
    if (db_value('SELECT 1 FROM `groups` WHERE institution_id = ? AND group_code = ? AND id <> ?', [$institutionId, $data['group_code'], $groupId ?? 0])) {
        fail_user('That group code is already used.');
    }
    $values = [$data['department_id'] ?: null, $data['group_code'], $data['group_name'], $data['group_type'], $data['academic_year'] ?: null, $data['term'] ?: null, $data['status']];

    if ($groupId) {
        $before = db_one('SELECT department_id, group_code, group_name, group_type, academic_year, term, status FROM `groups` WHERE institution_id = ? AND id = ?', [$institutionId, $groupId]) ?? fail_user('Group not found.');
        db_query('UPDATE `groups` SET department_id = ?, group_code = ?, group_name = ?, group_type = ?, academic_year = ?, term = ?, status = ? WHERE institution_id = ? AND id = ?', [...$values, $institutionId, $groupId]);
        audit_log('group.updated', 'group', $groupId, $institutionId, (int) auth_user()['id'], $data, $before);
        return $groupId;
    }
    db_query('INSERT INTO `groups` (institution_id, department_id, group_code, group_name, group_type, academic_year, term, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$institutionId, ...$values]);
    $id = (int) db()->lastInsertId();
    audit_log('group.created', 'group', $id, $institutionId, (int) auth_user()['id'], $data);
    return $id;
}

function find_group(int $groupId): ?array
{
    return db_one(
        'SELECT g.*, d.department_name FROM `groups` g LEFT JOIN departments d ON d.id = g.department_id AND d.institution_id = g.institution_id
         WHERE g.institution_id = ? AND g.id = ?',
        [current_institution_id(), $groupId]
    );
}

function group_options(bool $activeOnly = true): array
{
    return options_from_rows(db_all(
        'SELECT id, group_code, group_name FROM `groups` WHERE institution_id = ?' . ($activeOnly ? " AND status = 'active'" : '') . ' ORDER BY group_name',
        [current_institution_id()]
    ), 'id', static fn($row) => $row['group_name'] . ' (' . $row['group_code'] . ')');
}

/** Adds participants to a group for an academic year/term; existing active memberships are skipped. */
function add_group_members(int $groupId, array $participantIds, string $academicYear, string $term): int
{
    $institutionId = (int) current_institution_id();
    $group = find_group($groupId) ?? fail_user('Group not found.');
    if ($group['status'] !== 'active') {
        fail_user('Members can only be added to an active group.');
    }
    if (!$participantIds) {
        fail_user('Select at least one participant.');
    }
    $academicYear = $academicYear !== '' ? $academicYear : (string) $group['academic_year'];
    $term = $term !== '' ? $term : (string) $group['term'];

    return db_transaction(function () use ($institutionId, $groupId, $participantIds, $academicYear, $term) {
        $added = 0;
        foreach ($participantIds as $participantId) {
            if (!db_value("SELECT 1 FROM participants WHERE institution_id = ? AND id = ? AND status = 'active'", [$institutionId, $participantId])) {
                fail_user('One of the selected participants is not active in this institution.');
            }
            $existing = db_one(
                'SELECT id, status FROM group_memberships WHERE institution_id = ? AND group_id = ? AND participant_id = ? AND academic_year <=> ? AND term <=> ?',
                [$institutionId, $groupId, $participantId, $academicYear ?: null, $term ?: null]
            );
            if ($existing && $existing['status'] === 'active') {
                continue;
            }
            if ($existing) {
                db_query("UPDATE group_memberships SET status = 'active', end_date = NULL WHERE id = ?", [$existing['id']]);
            } else {
                db_query(
                    "INSERT INTO group_memberships (institution_id, group_id, participant_id, academic_year, term, status, start_date) VALUES (?, ?, ?, ?, ?, 'active', CURDATE())",
                    [$institutionId, $groupId, $participantId, $academicYear ?: null, $term ?: null]
                );
            }
            $added++;
        }
        audit_log('group.members_added', 'group', $groupId, $institutionId, (int) auth_user()['id'], ['participant_ids' => $participantIds, 'added' => $added]);
        return $added;
    });
}

function end_group_membership(int $groupMembershipId, string $status = 'ended'): void
{
    $institutionId = (int) current_institution_id();
    $row = db_one('SELECT * FROM group_memberships WHERE institution_id = ? AND id = ?', [$institutionId, $groupMembershipId]) ?? fail_user('Membership not found.');
    $status = in_array($status, ['ended', 'inactive'], true) ? $status : 'ended';
    db_query('UPDATE group_memberships SET status = ?, end_date = CURDATE() WHERE id = ?', [$status, $groupMembershipId]);
    audit_log('group.member_removed', 'group', (int) $row['group_id'], $institutionId, (int) auth_user()['id'], ['participant_id' => (int) $row['participant_id'], 'status' => $status]);
}

/** Assigns an officer (president, collector, adviser...) from the institution's members. */
function assign_officer(int $groupId, array $input): void
{
    $institutionId = (int) current_institution_id();
    find_group($groupId) ?? fail_user('Group not found.');
    $membershipId = input_int($input, 'membership_id') ?? 0;
    $type = input_enum($input, 'officer_type', array_keys(OFFICER_TYPES));
    $from = input_date($input, 'valid_from') ?? date('Y-m-d');
    $until = input_date($input, 'valid_until');
    $member = find_member($membershipId);
    if ($member['membership_status'] !== 'active') {
        fail_user('Only active members can be officers.');
    }
    if (!$type) {
        fail_user('Select the officer position.');
    }
    if ($until !== null && $until < $from) {
        fail_user('The end date must be after the start date.');
    }
    if (db_value(
        "SELECT 1 FROM group_officers WHERE institution_id = ? AND group_id = ? AND membership_id = ? AND officer_type = ? AND status = 'active'
           AND (valid_until IS NULL OR valid_until >= CURDATE())",
        [$institutionId, $groupId, $membershipId, $type]
    )) {
        fail_user('This person already holds that position in the group.');
    }
    db_query(
        "INSERT INTO group_officers (institution_id, group_id, membership_id, officer_type, valid_from, valid_until, status) VALUES (?, ?, ?, ?, ?, ?, 'active')",
        [$institutionId, $groupId, $membershipId, $type, $from, $until]
    );
    audit_log('group.officer_assigned', 'group', $groupId, $institutionId, (int) auth_user()['id'], ['membership_id' => $membershipId, 'officer_type' => $type, 'valid_from' => $from, 'valid_until' => $until]);
    notify_membership($institutionId, $membershipId, 'officer', 'Group officer assignment',
        'You were assigned as ' . OFFICER_TYPES[$type] . ' of ' . find_group($groupId)['group_name'] . '.');
}

function end_officer(int $officerId): void
{
    $institutionId = (int) current_institution_id();
    $row = db_one('SELECT * FROM group_officers WHERE institution_id = ? AND id = ?', [$institutionId, $officerId]) ?? fail_user('Officer record not found.');
    db_query("UPDATE group_officers SET status = 'inactive', valid_until = LEAST(COALESCE(valid_until, CURDATE()), CURDATE()) WHERE id = ?", [$officerId]);
    audit_log('group.officer_ended', 'group', (int) $row['group_id'], $institutionId, (int) auth_user()['id'], ['membership_id' => (int) $row['membership_id'], 'officer_type' => $row['officer_type']]);
}

// ---------------------------------------------------------------------------
// Participants
// ---------------------------------------------------------------------------

/** Names are stored in capital letters ("DELA CRUZ", "PEÑA"), single-spaced. */
function person_name_case(string $value): string
{
    return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
}

/**
 * Validates a person's record. $strict (the person form) also requires a student's middle
 * name unless "No middle name" is ticked; imports from class lists and CSV do not, since
 * those lists often leave it out.
 */
function validate_participant(array $input, ?int $participantId, bool $strict = false): array
{
    $institutionId = (int) current_institution_id();
    $data = [
        'participant_number' => input_str($input, 'participant_number', 100),
        'first_name'         => person_name_case(input_str($input, 'first_name', 100)),
        'middle_name'        => person_name_case(input_str($input, 'middle_name', 100)),
        'no_middle_name'     => in_array(strtolower(input_str($input, 'no_middle_name', 5)), ['1', 'yes', 'true', 'y'], true) ? 1 : 0,
        'last_name'          => person_name_case(input_str($input, 'last_name', 100)),
        'email'              => mb_strtolower(input_str($input, 'email', 190)),
        'phone'              => input_str($input, 'phone', 50),
        'participant_type'   => input_enum($input, 'participant_type', array_keys(PARTICIPANT_TYPES), 'student'),
        'status'             => input_enum($input, 'status', ['active', 'inactive', 'archived'], 'active'),
        'birth_date'         => input_date($input, 'birth_date'),
        'address'            => input_str($input, 'address', 500),
        'program_id'         => input_int($input, 'program_id'),
        'guardian_name'      => input_str($input, 'guardian_name', 180),
        'guardian_relationship' => input_str($input, 'guardian_relationship', 60),
        'guardian_phone'     => input_str($input, 'guardian_phone', 50),
    ];
    if ($data['middle_name'] !== '') {
        $data['no_middle_name'] = 0;
    }
    if (!$data['program_id'] && ($code = input_str($input, 'program_code', 30)) !== '') {
        $data['program_id'] = (int) db_value('SELECT id FROM programs WHERE institution_id = ? AND program_code = ?', [$institutionId, $code])
            ?: fail_user('Program "' . $code . '" does not exist. Add it under People → Programs first.');
    }
    if ($data['program_id'] && !db_value('SELECT 1 FROM programs WHERE institution_id = ? AND id = ?', [$institutionId, $data['program_id']])) {
        fail_user('Select a valid program.');
    }
    if ($data['birth_date'] === null && trim((string) ($input['birth_date'] ?? '')) !== '') {
        fail_user('Enter the birthday as a valid date.');
    }
    if ($data['birth_date'] !== null && ($data['birth_date'] > date('Y-m-d') || $data['birth_date'] < '1900-01-01')) {
        fail_user('The birthday must be a past date.');
    }
    foreach (['phone' => 'phone number', 'guardian_phone' => 'guardian\'s contact number'] as $key => $label) {
        if ($data[$key] !== '' && !preg_match('/^[0-9+()\-\s.\/]{7,50}$/', $data[$key])) {
            fail_user('Enter a valid ' . $label . ' (digits, spaces, + - ( ) only).');
        }
    }
    if ($strict && $data['participant_type'] === 'student' && $data['middle_name'] === '' && !$data['no_middle_name']) {
        fail_user('Enter the student\'s middle name, or tick "No middle name".');
    }
    if ($data['participant_number'] === '' || !preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $data['participant_number'])) {
        fail_user('ID number is required (letters, numbers, dots, dashes, slashes).');
    }
    if ($data['first_name'] === '' || $data['last_name'] === '') {
        fail_user('First and last name are required.');
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        fail_user('Enter a valid e-mail address for ' . $data['first_name'] . ' ' . $data['last_name'] . '.');
    }
    if (db_value('SELECT 1 FROM participants WHERE institution_id = ? AND participant_number = ? AND id <> ?', [$institutionId, $data['participant_number'], $participantId ?? 0])) {
        fail_user('ID number ' . $data['participant_number'] . ' is already registered.');
    }
    return $data;
}

/** Creates or updates a participant. A linked login lets the person see their own obligations. */
/** Fields a "new person" modal may set: never a linked user account or a status other than active. */
function new_person_input(array $post): array
{
    $fields = ['participant_number', 'participant_type', 'first_name', 'last_name', 'middle_name', 'no_middle_name', 'program_id',
        'birth_date', 'phone', 'address', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'email'];
    return ['status' => 'active'] + array_intersect_key($post, array_flip($fields));
}

function save_participant(?int $participantId, array $input, bool $strict = false): int
{
    $institutionId = (int) current_institution_id();
    $data = validate_participant($input, $participantId, $strict);

    $linkedUserId = null;
    $linkMembership = input_int($input, 'link_membership_id');
    if ($linkMembership) {
        $linkedUserId = (int) find_member($linkMembership)['user_id'];
        if (db_value('SELECT 1 FROM participants WHERE institution_id = ? AND user_id = ? AND id <> ?', [$institutionId, $linkedUserId, $participantId ?? 0])) {
            fail_user('That login is already linked to another participant record.');
        }
    }

    $values = [$data['participant_number'], $data['first_name'], $data['middle_name'] ?: null, $data['last_name'], $data['email'] ?: null, $data['phone'] ?: null, $data['participant_type'], $data['status'], $linkedUserId,
        $data['no_middle_name'], $data['birth_date'], $data['address'] ?: null, $data['program_id'] ?: null, $data['guardian_name'] ?: null, $data['guardian_relationship'] ?: null, $data['guardian_phone'] ?: null];
    $detailColumns = 'no_middle_name, birth_date, address, program_id, guardian_name, guardian_relationship, guardian_phone';
    if ($participantId) {
        $before = db_one("SELECT participant_number, first_name, middle_name, last_name, email, phone, participant_type, status, user_id, {$detailColumns} FROM participants WHERE institution_id = ? AND id = ?", [$institutionId, $participantId]) ?? fail_user('Participant not found.');
        db_query(
            'UPDATE participants SET participant_number = ?, first_name = ?, middle_name = ?, last_name = ?, email = ?, phone = ?, participant_type = ?, status = ?, user_id = ?,
                    no_middle_name = ?, birth_date = ?, address = ?, program_id = ?, guardian_name = ?, guardian_relationship = ?, guardian_phone = ? WHERE institution_id = ? AND id = ?',
            [...$values, $institutionId, $participantId]
        );
        audit_log('participant.updated', 'participant', $participantId, $institutionId, (int) auth_user()['id'], $data + ['user_id' => $linkedUserId], $before);
        return $participantId;
    }
    db_query(
        "INSERT INTO participants (participant_number, first_name, middle_name, last_name, email, phone, participant_type, status, user_id, {$detailColumns}, institution_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [...$values, $institutionId]
    );
    $id = (int) db()->lastInsertId();
    audit_log('participant.created', 'participant', $id, $institutionId, (int) auth_user()['id'], $data + ['user_id' => $linkedUserId]);
    return $id;
}

function find_participant(int $participantId): ?array
{
    [$visibility, $params] = participant_visibility('p');
    return db_one(
        "SELECT p.*, CONCAT(u.first_name, ' ', u.last_name) AS linked_user_name, u.email AS linked_user_email, pr.program_code, pr.program_name,
                (SELECT m.id FROM institution_memberships m WHERE m.institution_id = p.institution_id AND m.user_id = p.user_id) AS linked_membership_id
         FROM participants p LEFT JOIN users u ON u.id = p.user_id
         LEFT JOIN programs pr ON pr.id = p.program_id AND pr.institution_id = p.institution_id
         WHERE p.institution_id = ? AND p.id = ? AND {$visibility}",
        array_merge([current_institution_id(), $participantId], $params)
    );
}

/**
 * Imports participants from CSV. Header columns (case-insensitive):
 * participant_number, first_name, middle_name, last_name, email, phone, participant_type, group_code
 * All rows are validated first; nothing is saved if any row has an error.
 *
 * @return array{created: int, skipped: int, grouped: int}
 */
function import_participants_csv(string $path, bool $skipExisting): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        fail_user('The file could not be read.');
    }
    $header = fgetcsv($handle, 0, ',', '"', '\\');
    if (!$header) {
        fail_user('The file is empty.');
    }
    $header = array_map(static fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
    foreach (['participant_number', 'first_name', 'last_name'] as $required) {
        if (!in_array($required, $header, true)) {
            fail_user("The header row must include {$required}. Download the template for the expected columns.");
        }
    }

    $institutionId = (int) current_institution_id();
    $groups = db_query('SELECT group_code, id FROM `groups` WHERE institution_id = ? AND status = \'active\'', [$institutionId])->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows = [];
    $errors = [];
    $seen = [];
    $line = 1;
    while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $line++;
        if (count(array_filter($cells, static fn($c) => trim((string) $c) !== '')) === 0) {
            continue;
        }
        if ($line > 5001) {
            $errors[] = 'Import at most 5,000 rows at a time.';
            break;
        }
        $row = [];
        foreach ($header as $index => $column) {
            $row[$column] = trim((string) ($cells[$index] ?? ''));
        }
        $row['participant_type'] = strtolower($row['participant_type'] ?? '') ?: 'student';
        $number = $row['participant_number'] ?? '';
        if (isset($seen[$number])) {
            $errors[] = "Line {$line}: ID number {$number} appears twice in the file.";
            continue;
        }
        $seen[$number] = true;
        $exists = $number !== '' && db_value('SELECT 1 FROM participants WHERE institution_id = ? AND participant_number = ?', [$institutionId, $number]);
        if ($exists && $skipExisting) {
            $row['_skip'] = true;
        } else {
            try {
                validate_participant($row, null);
                if (!isset(PARTICIPANT_TYPES[$row['participant_type']])) {
                    throw new UserFacingException('participant_type must be student, employee, member or other.');
                }
            } catch (UserFacingException $exception) {
                $errors[] = "Line {$line}: " . $exception->getMessage();
            }
        }
        $groupCode = strtoupper($row['group_code'] ?? '');
        if ($groupCode !== '' && !isset($groups[$groupCode])) {
            $errors[] = "Line {$line}: group code {$groupCode} does not exist or is not active.";
        }
        $row['_group_id'] = $groupCode !== '' ? (int) ($groups[$groupCode] ?? 0) : null;
        $rows[] = $row;
        if (count($errors) >= 20) {
            break;
        }
    }
    fclose($handle);

    if ($errors) {
        fail_user("Nothing was imported. Fix these rows and upload again:\n" . implode("\n", array_slice($errors, 0, 20)));
    }
    if (!$rows) {
        fail_user('The file has no data rows.');
    }

    return db_transaction(function () use ($rows, $institutionId) {
        $created = $skipped = $grouped = 0;
        foreach ($rows as $row) {
            if (!empty($row['_skip'])) {
                $skipped++;
                $participantId = (int) db_value('SELECT id FROM participants WHERE institution_id = ? AND participant_number = ?', [$institutionId, $row['participant_number']]);
            } else {
                $participantId = save_participant(null, $row + ['status' => 'active']);
                $created++;
            }
            if ($row['_group_id']) {
                $group = find_group($row['_group_id']);
                if (!db_value("SELECT 1 FROM group_memberships WHERE institution_id = ? AND group_id = ? AND participant_id = ? AND status = 'active'", [$institutionId, $row['_group_id'], $participantId])) {
                    db_query(
                        "INSERT INTO group_memberships (institution_id, group_id, participant_id, academic_year, term, status, start_date) VALUES (?, ?, ?, ?, ?, 'active', CURDATE())",
                        [$institutionId, $row['_group_id'], $participantId, $group['academic_year'], $group['term']]
                    );
                    $grouped++;
                }
            }
        }
        audit_log('participant.imported', 'participant', null, $institutionId, (int) auth_user()['id'], compact('created', 'skipped', 'grouped'));
        return compact('created', 'skipped', 'grouped');
    });
}

// ---------------------------------------------------------------------------
// Academic programs (BSIT, BSCS, ...)
// ---------------------------------------------------------------------------

/** [id => "BSIT — Bachelor of Science in ..."] */
function program_options(bool $activeOnly = true, ?int $alwaysInclude = null): array
{
    // An inactive program still shows when it is the one already on the record being edited.
    $sql = 'SELECT id, program_code, program_name FROM programs WHERE institution_id = ?';
    $params = [current_institution_id()];
    if ($activeOnly) {
        $sql .= " AND (status = 'active' OR id = ?)";
        $params[] = $alwaysInclude ?? 0;
    }
    return options_from_rows(db_all($sql . ' ORDER BY program_code', $params), 'id', static fn($r) => $r['program_code'] . ' — ' . $r['program_name']);
}

function save_program(?int $programId, array $input): int
{
    $institutionId = (int) current_institution_id();
    if (!can('organization.manage')) {
        fail_user('You do not have permission to manage programs.');
    }
    $data = [
        'program_code'  => input_str($input, 'program_code', 30),
        'program_name'  => input_str($input, 'program_name', 180),
        'department_id' => input_int($input, 'department_id'),
        'status'        => input_enum($input, 'status', ['active', 'inactive'], 'active'),
    ];
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9.\- ]{0,29}$/', $data['program_code']) || mb_strlen($data['program_name']) < 3) {
        fail_user('Enter the program code (e.g. BSIT) and its full name.');
    }
    if ($data['department_id'] && !db_value('SELECT 1 FROM departments WHERE institution_id = ? AND id = ?', [$institutionId, $data['department_id']])) {
        fail_user('Select a valid department.');
    }
    if (db_value('SELECT 1 FROM programs WHERE institution_id = ? AND program_code = ? AND id <> ?', [$institutionId, $data['program_code'], $programId ?? 0])) {
        fail_user('That program code is already used.');
    }
    $values = [$data['program_code'], $data['program_name'], $data['department_id'] ?: null, $data['status']];
    if ($programId) {
        $before = db_one('SELECT program_code, program_name, department_id, status FROM programs WHERE institution_id = ? AND id = ?', [$institutionId, $programId]) ?? fail_user('Program not found.');
        db_query('UPDATE programs SET program_code = ?, program_name = ?, department_id = ?, status = ? WHERE institution_id = ? AND id = ?', [...$values, $institutionId, $programId]);
        audit_log('program.updated', 'program', $programId, $institutionId, (int) auth_user()['id'], $data, $before);
        return $programId;
    }
    db_query('INSERT INTO programs (program_code, program_name, department_id, status, institution_id) VALUES (?, ?, ?, ?, ?)', [...$values, $institutionId]);
    $id = (int) db()->lastInsertId();
    audit_log('program.created', 'program', $id, $institutionId, (int) auth_user()['id'], $data);
    return $id;
}

/** Adds the standard programs an institution does not have yet. Returns how many were added. */
function seed_default_programs(int $institutionId): int
{
    $added = 0;
    foreach (DEFAULT_PROGRAMS as $code => $name) {
        $added += db_query('INSERT IGNORE INTO programs (institution_id, program_code, program_name) VALUES (?, ?, ?)', [$institutionId, $code, $name])->rowCount();
    }
    return $added;
}
