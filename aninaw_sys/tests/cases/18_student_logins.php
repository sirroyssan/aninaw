<?php
/** Bulk student logins with default passwords, forced change, and the payer's own receipts. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/users.php';
require_once APP_ROOT . '/includes/services/payments.php';

section('Student logins');

test('logins are created in bulk with the ID number as username, linked, and must change the password', function () {
    act_as('admin', 'TST');
    $a = save_participant(null, ['participant_number' => '26-90001', 'first_name' => 'Lia', 'last_name' => 'Peña', 'participant_type' => 'student', 'status' => 'active', 'no_middle_name' => '1']);
    $b = save_participant(null, ['participant_number' => '26-90002', 'first_name' => 'Ben', 'last_name' => 'Uy', 'participant_type' => 'student', 'status' => 'active', 'no_middle_name' => '1']);
    $taken = save_participant(null, ['participant_number' => 'newcashier', 'first_name' => 'Dup', 'last_name' => 'Name', 'participant_type' => 'student', 'status' => 'active', 'no_middle_name' => '1']);
    $result = create_student_logins([$a, $b, $taken]);
    assert_same(2, count($result['created']));
    assert_same(['Username newcashier is already used by another account'], array_column($result['skipped'], 'reason'));
    $lia = array_values(array_filter($result['created'], static fn($r) => $r['number'] === '26-90001'))[0];
    assert_same('26-90001', $lia['username']);
    assert_true((bool) preg_match('/^Pena' . date('Y') . '[!@#$%&*?]$/', $lia['password']), 'Peña becomes Pena + year + special character');
    $user = db_one('SELECT u.id, u.password_hash, u.must_change_password, u.email FROM users u JOIN participants p ON p.user_id = u.id WHERE p.id = ?', [$a]);
    assert_true(password_verify($lia['password'], $user['password_hash']), 'only the hash is stored');
    assert_same(1, (int) $user['must_change_password']);
    assert_same(null, $user['email']);
    assert_same('member', db_value('SELECT r.role_code FROM institution_memberships m JOIN membership_roles mr ON mr.membership_id = m.id JOIN roles r ON r.id = mr.role_id WHERE m.user_id = ?', [$user['id']]));
    assert_same(0, count(create_student_logins([$a])['created']), 'a second run skips people who already have a login');
    $GLOBALS['fx']['login_student'] = ['participant' => $a, 'user' => (int) $user['id']];

    act_as('cashier', 'TST');
    assert_rejected(fn() => create_student_logins([$b]), 'permission');
});

test('a student sees only their own receipts as a payer\'s copy', function () {
    $fx = $GLOBALS['fx'];
    act_as('admin', 'TST');
    $paymentId = (int) db_value("SELECT id FROM payments WHERE status = 'posted' ORDER BY id LIMIT 1");
    $payer = (int) db_value('SELECT payer_participant_id FROM payments WHERE id = ?', [$paymentId]);
    // Sign in as the new student (a Student / Member membership) and look at someone else's payment.
    act_as('26-90001', 'TST');
    assert_true(!can('collections.view') && !can('collections.record'), 'a student has no collections access');
    assert_same(null, find_payment($paymentId, true), 'not their payment');
    // Link them to the payer record instead: now it is theirs.
    db_query('UPDATE participants SET user_id = NULL WHERE id = ?', [$fx['login_student']['participant']]);
    db_query('UPDATE participants SET user_id = ? WHERE id = ?', [$fx['login_student']['user'], $payer]);
    assert_same($paymentId, (int) find_payment($paymentId, true)['id'], 'their own payment');
    db_query('UPDATE participants SET user_id = NULL WHERE id = ?', [$payer]);
    db_query('UPDATE participants SET user_id = ? WHERE id = ?', [$fx['login_student']['user'], $fx['login_student']['participant']]);
});
