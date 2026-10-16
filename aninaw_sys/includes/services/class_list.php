<?php
/**
 * Class list import: reads a PDF class list (e.g. the enrollment system's printed
 * section list), extracts each student's ID number and name, and registers them as
 * participants, optionally adding them to a section group.
 *
 * Nothing is saved when the PDF is read. The extracted list is shown for review, and
 * only the rows the user confirms are validated again on the server and imported in
 * one transaction. The PDF itself is not stored.
 */
declare(strict_types=1);

require_once APP_ROOT . '/includes/pdf_text.php';
require_once APP_ROOT . '/includes/services/organization.php';

const CLASS_LIST_MAX_ROWS = 1000;
const CLASS_LIST_MAX_BYTES = 10 * 1024 * 1024;

/** Header labels recognised on class lists => meta key. */
const CLASS_LIST_LABELS = [
    'section' => 'section', 'year' => 'academic_year', 'school year' => 'academic_year', 'academic year' => 'academic_year', 'sy' => 'academic_year',
    'semester' => 'term', 'term' => 'term', 'course code' => 'course_code', 'subject code' => 'course_code', 'subject' => 'course_code',
    'units' => 'units', 'instructor' => 'instructor', 'teacher' => 'instructor', 'day' => 'day', 'time' => 'time', 'room' => 'room',
    'total male' => 'total_male', 'total female' => 'total_female', 'total students' => 'total_students', 'program' => 'program', 'course' => 'program',
];

/** Name particles that belong to the following surname (Dela Cruz, Delos Santos, San Jose...). */
const NAME_PARTICLES = ['DE', 'DEL', 'DELA', 'DELAS', 'DELOS', 'DI', 'DA', 'LA', 'LAS', 'LOS', 'SAN', 'STA', 'STA.', 'STO', 'STO.', 'SANTA', 'SANTO', 'VAN', 'VON', 'MC', 'MAC'];
const NAME_SUFFIXES = ['JR' => 'Jr.', 'JR.' => 'Jr.', 'SR' => 'Sr.', 'SR.' => 'Sr.', 'II' => 'II', 'III' => 'III', 'IV' => 'IV'];

/**
 * Reads an uploaded PDF class list. Returns
 * ['meta' => [...], 'students' => [[no, participant_number, last_name, first_name, middle_name, sex, raw_name, page, review], ...],
 *  'warnings' => string[], 'pages' => int]
 */
function read_class_list_pdf(string $path): array
{
    try {
        $pages = pdf_text_lines($path);
    } catch (RuntimeException $exception) {
        fail_user('The PDF could not be read: ' . $exception->getMessage());
    }
    return parse_class_list($pages);
}

/** Parses extracted PDF lines (see PdfTextExtractor::pages()) into class list data. */
function parse_class_list(array $pages): array
{
    $meta = [];
    $students = [];
    $warnings = [];
    $sex = null;
    $labelPattern = implode('|', array_map(static fn($l) => preg_quote($l, '/'), array_keys(CLASS_LIST_LABELS)));

    foreach ($pages as $pageIndex => $lines) {
        foreach ($lines as $line) {
            $text = trim($line['text']);
            $upper = mb_strtoupper($text);
            if (in_array($upper, ['MALE', 'MALES', 'BOYS', 'M'], true)) {
                $sex = 'male';
                continue;
            }
            if (in_array($upper, ['FEMALE', 'FEMALES', 'GIRLS', 'F'], true)) {
                $sex = 'female';
                continue;
            }
            if (preg_match('/^(\d{1,4})\.?\s+([A-Za-z0-9][A-Za-z0-9._\/-]*\d[A-Za-z0-9._\/-]*)\s+(.+)$/u', $text, $m) && preg_match('/\p{L}/u', $m[3])) {
                $students[] = class_list_student((int) $m[1], $m[2], $m[3], $sex, $pageIndex + 1);
                continue;
            }
            // Header fields: "LABEL : value" in one cell, or the value in the next cell.
            $cells = array_column($line['cells'], 'text');
            foreach ($cells as $i => $cell) {
                if (!preg_match('/^(' . $labelPattern . ')\s*:\s*(.*)$/iu', $cell, $h)) {
                    continue;
                }
                $key = CLASS_LIST_LABELS[mb_strtolower($h[1])];
                $value = trim($h[2]);
                if ($value === '' && isset($cells[$i + 1]) && !preg_match('/^(' . $labelPattern . ')\s*:/iu', $cells[$i + 1])) {
                    $value = trim($cells[$i + 1]);
                }
                if ($value !== '' && !isset($meta[$key])) {
                    $meta[$key] = mb_substr($value, 0, 120);
                }
            }
        }
    }

    if (!$students) {
        fail_user('No student rows were found in this PDF. Use the class list printed from the enrollment system (it must contain selectable text: a scanned or photographed list cannot be read). You can also import a CSV file instead.');
    }
    if (count($students) > CLASS_LIST_MAX_ROWS) {
        fail_user('The PDF lists more than ' . CLASS_LIST_MAX_ROWS . ' students. Split it into smaller class lists.');
    }

    // Checks against the list's own totals and numbering.
    $counts = ['male' => 0, 'female' => 0, '' => 0];
    foreach ($students as $student) {
        $counts[$student['sex'] ?? '']++;
    }
    foreach (['total_male' => 'male', 'total_female' => 'female'] as $key => $group) {
        if (isset($meta[$key]) && ctype_digit($meta[$key]) && (int) $meta[$key] !== $counts[$group]) {
            $warnings[] = sprintf('The PDF says %s %s students but %d were read. Check the list against the printout.', $meta[$key], $group, $counts[$group]);
        }
    }
    if (isset($meta['total_students']) && ctype_digit($meta['total_students']) && (int) $meta['total_students'] !== count($students)) {
        $warnings[] = sprintf('The PDF says %s students in total but %d were read.', $meta['total_students'], count($students));
    }
    $bySex = [];
    foreach ($students as $student) {
        $bySex[$student['sex'] ?? ''][] = $student['no'];
    }
    foreach ($bySex as $group => $numbers) {
        $expected = range(1, max($numbers));
        $missing = array_diff($expected, $numbers);
        if ($missing && count($numbers) > 1) {
            $warnings[] = 'Row number' . (count($missing) > 1 ? 's ' : ' ') . implode(', ', array_slice($missing, 0, 10)) . ($group ? ' (' . $group . ')' : '') . ' did not come through. A row may be missing.';
        }
    }
    $seen = [];
    foreach ($students as $student) {
        if (isset($seen[$student['participant_number']])) {
            $warnings[] = 'Student number ' . $student['participant_number'] . ' appears more than once; only one can be imported.';
        }
        $seen[$student['participant_number']] = true;
    }

    return ['meta' => $meta, 'students' => $students, 'warnings' => $warnings, 'pages' => count($pages)];
}

/** Splits "LAST, FIRST MIDDLE" (or "FIRST MIDDLE LAST") into parts. */
function class_list_student(int $no, string $number, string $name, ?string $sex, int $page): array
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    $review = false;
    if (str_contains($name, ',')) {
        [$last, $given] = array_map('trim', explode(',', $name, 2));
        $given = trim(str_replace(',', ' ', $given));
    } else {
        // No comma: assume "First [Middle] Last"; flag for review.
        $tokens = explode(' ', $name);
        $last = (string) array_pop($tokens);
        $given = implode(' ', $tokens);
        $review = true;
    }
    $tokens = $given === '' ? [] : explode(' ', $given);
    $suffix = '';
    foreach ($tokens as $i => $token) {
        if (isset(NAME_SUFFIXES[mb_strtoupper($token)]) && count($tokens) > 1) {
            $suffix = NAME_SUFFIXES[mb_strtoupper($token)];
            unset($tokens[$i]);
        }
    }
    $tokens = array_values($tokens);
    $middle = [];
    if (count($tokens) >= 2) {
        // Philippine convention: the last given word is the middle name (mother's surname), with its particles.
        $middle[] = array_pop($tokens);
        while (count($tokens) > 1 && in_array(mb_strtoupper(end($tokens)), NAME_PARTICLES, true)) {
            array_unshift($middle, array_pop($tokens));
        }
    }
    $first = implode(' ', $tokens) . ($suffix !== '' ? ' ' . $suffix : '');
    if ($first === '' || $last === '') {
        $review = true;
    }
    return [
        'no' => $no,
        'participant_number' => $number,
        'last_name' => $last,
        'first_name' => trim($first),
        'middle_name' => implode(' ', $middle),
        'sex' => $sex,
        'raw_name' => $name,
        'page' => $page,
        'review' => $review,
    ];
}

/** "DEL CASTILLO" → "Del Castillo"; keeps Jr./II/III and short consonant-only initials (MJ). */
function class_list_name_case(string $value): string
{
    $words = preg_split('/(\s+|-)/u', trim($value), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    return implode('', array_map(static function (string $word): string {
        $upper = mb_strtoupper($word);
        if (in_array($upper, ['II', 'III', 'IV'], true) || (mb_strlen($word) <= 3 && !preg_match('/[AEIOUY]/u', $upper) && preg_match('/^\p{L}+$/u', $word))) {
            return $upper;
        }
        return mb_convert_case(mb_strtolower($word), MB_CASE_TITLE);
    }, $words));
}

/** Suggested group code for a section ("BSIT - IA" → "BSIT-IA"). */
function class_list_group_code(string $section): string
{
    $code = strtoupper(preg_replace('/\s*-\s*/', '-', trim($section)));
    $code = preg_replace('/[^A-Z0-9_. -]/', '', $code);
    return substr(preg_replace('/\s+/', ' ', $code), 0, 80);
}

/**
 * Imports reviewed class list rows. $rows: [[participant_number, first_name, middle_name, last_name], ...]
 * $group: ['mode' => 'none'|'existing'|'new', 'group_id', 'group_code', 'group_name', 'academic_year', 'term', 'department_id']
 * Students whose ID number is already registered are not changed; they are only added to the group.
 *
 * @return array{created: int, existing: int, grouped: int, group_id: ?int}
 */
function import_class_list(array $rows, array $group, string $sourceName): array
{
    require_permission('organization.manage');
    $institutionId = (int) current_institution_id();
    if (!$rows) {
        fail_user('Select at least one student to import.');
    }
    if (count($rows) > CLASS_LIST_MAX_ROWS) {
        fail_user('Import at most ' . CLASS_LIST_MAX_ROWS . ' students at a time.');
    }

    $prepared = [];
    $errors = [];
    $seen = [];
    foreach (array_values($rows) as $i => $row) {
        $row = array_map(static fn($v) => is_string($v) ? $v : '', array_intersect_key($row, array_flip(['participant_number', 'first_name', 'middle_name', 'last_name'])));
        $row['participant_type'] = 'student';
        $row['status'] = 'active';
        $number = trim($row['participant_number'] ?? '');
        $label = 'Row ' . ($i + 1) . ($number !== '' ? " ({$number})" : '');
        if (isset($seen[$number])) {
            $errors[] = "{$label}: the student number appears twice.";
            continue;
        }
        $seen[$number] = true;
        $existingId = $number !== '' ? (int) db_value('SELECT id FROM participants WHERE institution_id = ? AND participant_number = ?', [$institutionId, $number]) : 0;
        if (!$existingId) {
            try {
                validate_participant($row, null);
            } catch (UserFacingException $exception) {
                $errors[] = "{$label}: " . $exception->getMessage();
                continue;
            }
        }
        $prepared[] = ['row' => $row, 'existing_id' => $existingId];
    }
    if ($errors) {
        fail_user("Nothing was imported. Correct these rows and try again:\n" . implode("\n", array_slice($errors, 0, 20)));
    }

    $mode = in_array($group['mode'] ?? 'none', ['none', 'existing', 'new'], true) ? $group['mode'] : 'none';
    if ($mode === 'existing') {
        $target = find_group((int) ($group['group_id'] ?? 0)) ?? fail_user('Select the group to add the students to.');
        if ($target['status'] !== 'active') {
            fail_user('Students can only be added to an active group.');
        }
    }

    return db_transaction(function () use ($prepared, $group, $mode, $institutionId, $sourceName) {
        $groupId = null;
        if ($mode === 'new') {
            $groupId = save_group(null, [
                'group_code' => (string) ($group['group_code'] ?? ''), 'group_name' => (string) ($group['group_name'] ?? ''), 'group_type' => 'section',
                'academic_year' => (string) ($group['academic_year'] ?? ''), 'term' => (string) ($group['term'] ?? ''),
                'department_id' => (string) ($group['department_id'] ?? ''), 'status' => 'active',
            ]);
        } elseif ($mode === 'existing') {
            $groupId = (int) $group['group_id'];
        }
        $target = $groupId ? find_group($groupId) : null;

        $created = $existing = $grouped = 0;
        foreach ($prepared as $item) {
            if ($item['existing_id']) {
                $participantId = $item['existing_id'];
                $existing++;
            } else {
                $participantId = save_participant(null, $item['row']);
                $created++;
            }
            if ($target && !db_value("SELECT 1 FROM group_memberships WHERE institution_id = ? AND group_id = ? AND participant_id = ? AND status = 'active'", [$institutionId, $groupId, $participantId])) {
                db_query(
                    "INSERT INTO group_memberships (institution_id, group_id, participant_id, academic_year, term, status, start_date) VALUES (?, ?, ?, ?, ?, 'active', CURDATE())",
                    [$institutionId, $groupId, $participantId, $target['academic_year'], $target['term']]
                );
                $grouped++;
            }
        }
        audit_log('participant.class_list_imported', 'participant', null, $institutionId, (int) auth_user()['id'],
            ['source' => mb_substr($sourceName, 0, 180), 'created' => $created, 'existing' => $existing, 'grouped' => $grouped, 'group_id' => $groupId]);
        return ['created' => $created, 'existing' => $existing, 'grouped' => $grouped, 'group_id' => $groupId];
    });
}
