<?php
/** Cash advance requesters and event document requirements. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/event_requirements.php';
require_once APP_ROOT . '/includes/services/advances.php';

section('Cash advance requesters and event documents');

test('faculty, deans, program chairs, class presidents and advisers can request cash advances', function () {
    $institutionId = (int) db_value("SELECT id FROM institutions WHERE institution_code = 'TST'");
    foreach (['faculty' => 'Fiona', 'dean' => 'Dante', 'program_chair' => 'Paula'] as $role => $first) {
        $GLOBALS['members'][$role . '_user'] = fixture_member($institutionId, fixture_user($role . '_user', $first, 'Tester'), [$role]);
        act_as($role . '_user', 'TST');
        assert_true(can('advances.request') && can('liquidation.submit') && can('self.advances.view'), $role . ' may request and liquidate');
        assert_true(!can('advances.approve') && !can('advances.release'), $role . ' cannot approve or release');
    }
    foreach (['collector', 'adviser', 'organizer'] as $username) {
        act_as($username, 'TST');
        assert_true(can('advances.request') && can('liquidation.submit'), $username . ' may request and liquidate');
    }
    act_as('dean_user', 'TST');
    $advance = request_advance(['fund_id' => (string) $GLOBALS['fx']['fund'], 'requested_amount' => '1500', 'purpose' => 'Seminar speaker honorarium and snacks', 'advance_type' => 'activity_advance']);
    assert_same('pending_approval', db_value('SELECT status FROM cash_advances WHERE id = ?', [$advance]));
});

test('events list the papers people must submit and show who is cleared', function () {
    $eventId = (int) db_value("SELECT id FROM events WHERE event_code = 'facnight'");
    $faculty = (int) db_value("SELECT id FROM participants WHERE participant_number = 'FAC-001'");
    $customer = (int) db_value("SELECT id FROM participants WHERE participant_number = 'CUS-001'");
    $student = (int) db_value("SELECT participant_id FROM event_participants ep JOIN participants p ON p.id = ep.participant_id WHERE ep.event_id = ? AND p.participant_type = 'student' AND ep.status = 'active' LIMIT 1", [$eventId]);

    act_as('admin', 'TST');
    $outsider = save_participant(null, ['participant_number' => 'OUT-001', 'first_name' => 'Otto', 'last_name' => 'Outside', 'participant_type' => 'student', 'status' => 'active']);
    act_as('organizer', 'TST');
    $waiver = save_event_requirement($eventId, null, ['requirement_name' => "Parent's waiver", 'applies_to_type' => 'student', 'is_required' => '1']);
    $medical = save_event_requirement($eventId, null, ['requirement_name' => 'Medical certificate', 'applies_to_type' => 'all', 'is_required' => '1', 'due_date' => date('Y-m-d', strtotime('+5 days'))]);
    $insurance = save_event_requirement($eventId, null, ['requirement_name' => 'Insurance', 'applies_to_type' => 'all', 'is_required' => '0']);
    assert_rejected(fn() => save_event_requirement($eventId, null, ['requirement_name' => 'Medical certificate']), 'already has');

    $list = event_document_checklist($eventId);
    assert_same(null, $list['people'][$faculty]['cells'][$waiver], "parent's waiver does not apply to faculty");
    assert_same('missing', $list['people'][$faculty]['cells'][$medical]['status']);
    assert_same([1, false], [$list['people'][$faculty]['required'], $list['people'][$faculty]['cleared']]);
    assert_same(2, $list['people'][$student]['required'], 'students need the waiver and the medical certificate');

    record_event_documents($eventId, $medical, [$faculty], 'submitted', '');
    assert_same(1, event_document_checklist($eventId)['people'][$faculty]['to_check']);
    assert_rejected(fn() => record_event_documents($eventId, $medical, [$customer], 'rejected', ''), 'returned');
    record_event_documents($eventId, $medical, [$customer], 'rejected', 'No doctor signature');
    record_event_documents($eventId, $medical, [$faculty], 'approved', '');
    $list = event_document_checklist($eventId);
    assert_true($list['people'][$faculty]['cleared'], 'faculty cleared: medical approved, waiver n/a, insurance optional');
    assert_same(1, $list['people'][$customer]['returned']);
    assert_rejected(fn() => record_event_documents($eventId, $waiver, [$faculty], 'submitted', ''), 'does not apply');
    assert_rejected(fn() => record_event_documents($eventId, $medical, [$outsider], 'submitted', ''), 'not in this event');

    // Several people at once (e.g. a stack of waivers handed in by the class president).
    $students = array_keys(array_filter($list['people'], static fn($p) => $p['participant_type'] === 'student'));
    assert_same(count($students), record_event_documents($eventId, $waiver, $students, 'submitted', ''));
    assert_same(count($students), (int) db_value("SELECT COUNT(*) FROM event_requirement_submissions WHERE requirement_id = ? AND status = 'submitted'", [$waiver]));

    // Who may record and open the papers.
    act_as('cashier', 'TST');
    assert_rejected(fn() => record_event_documents($eventId, $medical, [$customer], 'approved', ''), 'organizers or document checkers');
    act_as('admin', 'TST');
    record_event_documents($eventId, $medical, [$customer], 'waived', 'Certificate on file from last semester');
    assert_true(event_document_checklist($eventId)['people'][$customer]['cleared'], 'not applicable counts as done');

    $submission = (int) db_value('SELECT id FROM event_requirement_submissions WHERE requirement_id = ? AND participant_id = ?', [$medical, $faculty]);
    act_as('organizer', 'TST');
    db_query("INSERT INTO file_attachments (institution_id, uploaded_by_membership_id, entity_type, entity_id, storage_key, original_filename, mime_type, file_size, checksum) VALUES (?, ?, 'event_document', ?, 'x/med.pdf', 'med.pdf', 'application/pdf', 10, ?)",
        [current_institution_id(), current_membership_id(), $submission, str_repeat('a', 64)]);
    $attachment = db_one('SELECT * FROM file_attachments WHERE id = ?', [(int) db()->lastInsertId()]);
    assert_true(attachment_can_view($attachment), 'organizer can open the scan');
    act_as('cashier', 'TST');
    assert_true(!attachment_can_view($attachment), 'others cannot open medical papers');
    act_as('auditor', 'TST');
    assert_true(!attachment_can_view($attachment), 'not even an auditor without the document permission');

    act_as('organizer', 'TST');
    set_event_requirement_status($eventId, $insurance, 'inactive');
    assert_same(2, count(event_document_checklist($eventId)['requirements']), 'removed requirement no longer listed');
    assert_true((bool) db_value("SELECT 1 FROM audit_logs WHERE action = 'event.document_approved'"), 'document checks are audited');
});
