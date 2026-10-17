<?php
/** People details (birthday, program, address, guardian), programs and the student middle-name rule. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/organization.php';

section('People: details and programs');

test('programs: standard list, unique codes, inactive programs stay on records', function () {
    act_as('admin', 'TST');
    $added = seed_default_programs((int) current_institution_id());
    assert_same(count(DEFAULT_PROGRAMS), $added, 'standard programs added once');
    assert_same(0, seed_default_programs((int) current_institution_id()), 'adding again changes nothing');
    assert_rejected(fn() => save_program(null, ['program_code' => 'BSIT', 'program_name' => 'Duplicate']), 'already used');
    $id = save_program(null, ['program_code' => 'BSEntrep', 'program_name' => 'Bachelor of Science in Entrepreneurship']);
    assert_true(isset(program_options()[$id]), 'new program is offered');
    act_as('cashier', 'TST');
    assert_rejected(fn() => save_program(null, ['program_code' => 'X', 'program_name' => 'Xxxx']), 'permission');
});

test('person details are validated and saved; students need a middle name or "No middle name"', function () {
    act_as('admin', 'TST');
    $base = ['participant_number' => 'DET-001', 'first_name' => 'Rosa', 'last_name' => 'Villanueva', 'participant_type' => 'student', 'status' => 'active'];
    assert_rejected(fn() => save_participant(null, $base, true), 'middle name');
    assert_rejected(fn() => save_participant(null, $base + ['no_middle_name' => '1', 'birth_date' => date('Y-m-d', strtotime('+1 day'))], true), 'past date');
    assert_rejected(fn() => save_participant(null, $base + ['no_middle_name' => '1', 'guardian_phone' => 'call me'], true), 'contact number');
    assert_rejected(fn() => save_participant(null, $base + ['no_middle_name' => '1', 'program_code' => 'NOPE'], true), 'does not exist');

    $id = save_participant(null, $base + ['middle_name' => 'Santos', 'birth_date' => '2006-03-09', 'program_code' => 'BSIT', 'address' => 'Purok 2, Botolan, Zambales',
        'guardian_name' => 'Lorna Villanueva', 'guardian_relationship' => 'Mother', 'guardian_phone' => '0918 123 4567'], true);
    $p = find_participant($id);
    assert_same(['2006-03-09', 'BSIT', 'Purok 2, Botolan, Zambales', 'Lorna Villanueva', 'Mother', '0918 123 4567', 0],
        [$p['birth_date'], $p['program_code'], $p['address'], $p['guardian_name'], $p['guardian_relationship'], $p['guardian_phone'], (int) $p['no_middle_name']]);

    $none = save_participant(null, ['participant_number' => 'DET-002', 'first_name' => 'Jay', 'last_name' => 'Uy', 'participant_type' => 'student', 'no_middle_name' => '1'], true);
    assert_same(1, (int) find_participant($none)['no_middle_name']);
    save_participant(null, ['participant_number' => 'DET-003', 'first_name' => 'Fe', 'last_name' => 'Staff', 'participant_type' => 'employee'], true);
    save_participant(null, ['participant_number' => 'DET-004', 'first_name' => 'Imported', 'last_name' => 'Student', 'participant_type' => 'student']);
    assert_true(true, 'imports and non-students are not held to the middle-name rule');

    // Editing keeps the details; a program made inactive stays on the record.
    $bsit = (int) db_value("SELECT id FROM programs WHERE institution_id = ? AND program_code = 'BSIT'", [current_institution_id()]);
    save_program($bsit, ['program_code' => 'BSIT', 'program_name' => 'Bachelor of Science in Information Technology', 'status' => 'inactive']);
    save_participant($id, ['participant_number' => 'DET-001', 'first_name' => 'Rosa', 'middle_name' => 'Santos', 'last_name' => 'Villanueva', 'participant_type' => 'student', 'program_id' => (string) $bsit, 'birth_date' => '2006-03-09'], true);
    assert_same('BSIT', find_participant($id)['program_code']);
    save_program($bsit, ['program_code' => 'BSIT', 'program_name' => 'Bachelor of Science in Information Technology', 'status' => 'active']);
});

test('CSV import accepts program, birthday, address and guardian columns', function () {
    act_as('admin', 'TST');
    $csv = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($csv, "participant_number,first_name,middle_name,last_name,participant_type,program_code,birth_date,address,guardian_name,guardian_relationship,guardian_phone\n"
        . "CSV-901,Ana,Reyes,Cruz,student,BSCS,2007-01-02,\"Sitio 1, Iba, Zambales\",Ben Cruz,Father,09171112222\n");
    $result = import_participants_csv($csv, true);
    assert_same(1, $result['created']);
    $p = db_one("SELECT p.*, pr.program_code FROM participants p LEFT JOIN programs pr ON pr.id = p.program_id WHERE p.participant_number = 'CSV-901'");
    assert_same(['BSCS', '2007-01-02', 'Sitio 1, Iba, Zambales', 'Ben Cruz', '09171112222'], [$p['program_code'], $p['birth_date'], $p['address'], $p['guardian_name'], $p['guardian_phone']]);
});
