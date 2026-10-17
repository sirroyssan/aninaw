<?php
/** People added directly to an event (no group): faculty, customers and students. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/events.php';
require_once APP_ROOT . '/includes/services/payments.php';

section('Events: people added directly');

test('faculty and customers join an event without a group and are charged at once', function () {
    $fx = $GLOBALS['fx'];
    act_as('admin', 'TST');
    $faculty = save_participant(null, ['participant_number' => 'FAC-001', 'first_name' => 'Fe', 'last_name' => 'Faculty', 'participant_type' => 'employee', 'status' => 'active']);
    $faculty2 = save_participant(null, ['participant_number' => 'FAC-002', 'first_name' => 'Fred', 'last_name' => 'Faculty', 'participant_type' => 'employee', 'status' => 'active']);
    $customer = save_participant(null, ['participant_number' => 'CUS-001', 'first_name' => 'Cy', 'last_name' => 'Customer', 'participant_type' => 'other', 'status' => 'active']);
    assert_same('Faculty / Staff', PARTICIPANT_TYPES['employee']);

    act_as('organizer', 'TST');
    $eventId = save_event(null, ['event_code' => 'facnight', 'event_name' => 'Faculty Night', 'collection_deadline' => date('Y-m-d', strtotime('+10 days'))]);
    add_event_fund($eventId, ['fund_id' => (string) $fx['fund']]);
    $rule = save_collection_rule($eventId, null, ['rule_name' => 'Dinner', 'amount_due' => '200', 'applies_to_type' => 'all', 'allow_partial_payment' => '1']);

    $result = add_event_people($eventId, ['participant_id' => (string) $faculty]);
    assert_same(['added' => 1, 'obligations' => 1], $result, 'charged as soon as added');
    add_event_people($eventId, ['participant_ids' => [(string) $customer]]);
    assert_same(2, (int) db_value("SELECT COUNT(*) FROM event_obligations WHERE event_id = ? AND status <> 'cancelled'", [$eventId]));

    // With people added, "everyone" means the event's people, not the whole institution.
    generate_obligations($eventId);
    assert_same(2, (int) db_value("SELECT COUNT(*) FROM event_obligations WHERE event_id = ?", [$eventId]), 'nobody else is charged');

    $byType = add_event_people($eventId, ['add_type' => 'employee']);
    assert_same(1, $byType['added'], 'only the faculty member not yet in the event is added');
    assert_same(0, add_event_people($eventId, ['add_type' => 'employee'])['added'], 'adding again changes nothing');
    $fromGroup = add_event_people($eventId, ['add_group_id' => (string) $fx['groupId']]);
    assert_true($fromGroup['added'] >= 1, 'a group can be copied in as individuals');

    // No group and no collector needed to start collecting.
    set_event_status($eventId, 'approved', '');
    set_event_status($eventId, 'collecting', '');

    act_as('cashier', 'TST');
    assert_rejected(fn() => add_event_people($eventId, ['participant_id' => (string) $faculty2]), 'creator or its assigned organizers');
    $obligation = (int) db_value('SELECT id FROM event_obligations WHERE event_id = ? AND participant_id = ? AND collection_rule_id = ?', [$eventId, $faculty, $rule]);
    record_payment(['participant_id' => (string) $faculty, 'event_id' => (string) $eventId, 'payment_method' => 'cash', 'financial_account_id' => (string) $fx['cashier_box'],
        'payment_date' => date('Y-m-d'), 'payment_time' => date('H:i'), 'alloc' => [$obligation => '200']]);
    assert_same('paid', db_value('SELECT status FROM event_obligations WHERE id = ?', [$obligation]), 'faculty paid without belonging to any group');

    act_as('organizer', 'TST');
    assert_rejected(fn() => remove_event_person($eventId, $faculty, 'Not attending'), 'already paid');
    assert_rejected(fn() => remove_event_person($eventId, $customer, 'no'), 'reason');
    remove_event_person($eventId, $customer, 'Not attending');
    assert_same('cancelled', db_value('SELECT status FROM event_obligations WHERE event_id = ? AND participant_id = ?', [$eventId, $customer]));
    assert_same('removed', db_value('SELECT status FROM event_participants WHERE event_id = ? AND participant_id = ?', [$eventId, $customer]), 'history kept');
    $back = add_event_people($eventId, ['participant_id' => (string) $customer]);
    assert_same(['added' => 1, 'obligations' => 1], $back);
    assert_same('unpaid', db_value('SELECT status FROM event_obligations WHERE event_id = ? AND participant_id = ?', [$eventId, $customer]), 'charge restored');
    assert_same(count(event_people($eventId)), (int) db_value("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND status = 'active'", [$eventId]));

    // Another institution's people cannot be added.
    act_as('other_treasurer', 'OTH');
    $foreign = save_participant(null, ['participant_number' => 'X-1', 'first_name' => 'Xu', 'last_name' => 'Other', 'participant_type' => 'student', 'status' => 'active']);
    act_as('organizer', 'TST');
    assert_rejected(fn() => add_event_people($eventId, ['participant_id' => (string) $foreign]), 'Only active people');
});

test('a new person registered from the event modal cannot set a linked account or status', function () {
    $input = new_person_input(['participant_number' => 'N-1', 'first_name' => 'Ana', 'last_name' => 'Bo', 'status' => 'inactive', 'link_membership_id' => '1', 'institution_id' => '99']);
    assert_same('active', $input['status']);
    assert_true(!isset($input['link_membership_id']) && !isset($input['institution_id']), 'only person fields pass through');
    assert_same('Ana', $input['first_name']);
});

test('editing a person from the pop-up keeps their status and linked login', function () {
    act_as('admin', 'TST');
    $id = save_participant(null, ['participant_number' => 'ED-1', 'first_name' => 'Edna', 'last_name' => 'Edit', 'participant_type' => 'employee', 'status' => 'inactive']);
    $before = find_participant($id);
    save_participant($id, edit_person_input($before, ['participant_number' => 'ED-1', 'first_name' => 'Edna', 'last_name' => 'Edit', 'participant_type' => 'employee',
        'address' => '1 Main St', 'status' => 'active', 'link_membership_id' => '1']), true);
    $after = find_participant($id);
    assert_same('1 Main St', $after['address']);
    assert_same('inactive', $after['status'], 'status is not changed from the pop-up');
    assert_same($before['user_id'], $after['user_id'], 'linked login is not changed from the pop-up');
});
