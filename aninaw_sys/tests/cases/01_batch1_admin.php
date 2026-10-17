<?php
/** Batch 1: institutions, users, roles, organization structure. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/institutions.php';
require_once APP_ROOT . '/includes/services/organization.php';

section('Batch 1: institutions');

test('platform admin creates an institution with a new first administrator', function () {
    act_as('super');
    assert_true(can('platform.institutions.manage'), 'super admin manages institutions');
    $result = create_institution([
        'institution_code' => 'new1', 'institution_name' => 'New School', 'status' => 'active',
        'admin_email' => 'newadmin@example.test', 'admin_username' => 'newadmin', 'admin_first_name' => 'Nina', 'admin_last_name' => 'Admin',
    ]);
    assert_same('NEW1', db_value('SELECT institution_code FROM institutions WHERE id = ?', [$result['institution_id']]));
    assert_true(str_contains((string) $result['reset_link'], 'password_reset.php?token='), 'reset link returned');
    $roles = db_value("SELECT r.role_code FROM institution_memberships m JOIN users u ON u.id = m.user_id AND u.username = 'newadmin'
                       JOIN membership_roles mr ON mr.membership_id = m.id JOIN roles r ON r.id = mr.role_id WHERE m.institution_id = ?", [$result['institution_id']]);
    assert_same('institution_admin', $roles);
});

test('duplicate institution codes are rejected', function () {
    act_as('super');
    assert_rejected(fn() => create_institution(['institution_code' => 'TST', 'institution_name' => 'Copy']), 'already used');
});

test('institution administrators cannot manage platform institutions', function () {
    act_as('admin', 'TST');
    assert_forbidden('platform.institutions.manage');
    assert_true(can('institution.settings.manage'), 'admin manages own settings');
});

test('ledger codes lock after transactions but names can change', function () {
    act_as('admin', 'TST');
    $input = [];
    foreach (LEDGER_ACCOUNTS as $key => [$code, $name]) {
        $input["ledger_{$key}_code"] = $code;
        $input["ledger_{$key}_name"] = $name;
    }
    $input += ['receipt_title' => 'Acknowledgment Receipt', 'receipt_footer' => '', 'expense_categories' => "Food\nSupplies", 'advance_default_days' => '10'];
    $input['ledger_expenses_name'] = 'Operating Expenses';
    save_institution_settings($input);
    assert_same('Operating Expenses', db_value("SELECT setting_value FROM institution_settings WHERE institution_id = ? AND setting_key = 'ledger.expenses.name'", [current_institution_id()]));
    $input['ledger_expenses_code'] = '1010'; // duplicate of cash on hand
    assert_rejected(fn() => save_institution_settings($input), 'used twice');
});

section('Batch 1: users and roles');

test('admin adds a new member with a default password that must be changed at first sign-in', function () {
    act_as('admin', 'TST');
    $roleId = (int) db_value("SELECT id FROM roles WHERE role_code = 'cashier'");
    $result = create_member(['email' => 'newcashier@example.test', 'username' => 'newcashier', 'first_name' => 'New', 'last_name' => 'dela Cruz', 'roles' => [(string) $roleId]]);
    assert_true(!$result['existing'] && $result['default_password'] !== null, 'new account with a default password');
    assert_true((bool) preg_match('/^Delacruz' . date('Y') . '[!@#$%&*?]$/', $result['default_password']), 'last name + year + special character');
    $row = db_one("SELECT password_hash, must_change_password FROM users WHERE username = 'newcashier'");
    assert_true(password_verify($result['default_password'], $row['password_hash']), 'only the hash is stored');
    assert_same(1, (int) $row['must_change_password'], 'must change at first sign-in');
    assert_rejected(fn() => change_default_password($result['user_id'], $result['default_password'], $result['default_password']), 'not the default');
    assert_rejected(fn() => change_default_password($result['user_id'], 'short1', 'short1'), '10 or more');
    assert_rejected(fn() => change_default_password($result['user_id'], 'BrandNew2026x', 'BrandNew2026y'), 'do not match');
    change_default_password($result['user_id'], 'BrandNew2026x', 'BrandNew2026x');
    $row = db_one("SELECT password_hash, must_change_password FROM users WHERE username = 'newcashier'");
    assert_true(password_verify('BrandNew2026x', $row['password_hash']) && (int) $row['must_change_password'] === 0, 'own password set, no longer forced');
    // E-mail is optional for new accounts (students sign in with their ID number).
    $noEmail = create_member(['username' => 'student-no-mail', 'first_name' => 'No', 'last_name' => 'Mail', 'roles' => [(string) $roleId]]);
    assert_same(null, db_value('SELECT email FROM users WHERE id = ?', [$noEmail['user_id']]));
    // Reset to a new default password.
    $password = reset_member_to_default_password($result['membership_id']);
    assert_true(password_verify($password, (string) db_value("SELECT password_hash FROM users WHERE username = 'newcashier'")), 'reset to a new default');
    assert_same(1, (int) db_value("SELECT must_change_password FROM users WHERE username = 'newcashier'"));
    assert_same('user.password_reset_default', db_value("SELECT action FROM audit_logs ORDER BY id DESC LIMIT 1"));
});

test('weak passwords are refused on reset', function () {
    $token = db_transaction(fn() => create_password_reset_token((int) db_value("SELECT id FROM users WHERE username = 'newcashier'")));
    assert_rejected(fn() => complete_password_reset($token, 'short1', 'short1'), '10 or more');
    assert_rejected(fn() => complete_password_reset($token, 'longpassword', 'longpassword'), 'letters and numbers');
});

test('an existing account from another institution joins by e-mail without changing its profile', function () {
    act_as('admin', 'TST');
    $roleId = (int) db_value("SELECT id FROM roles WHERE role_code = 'auditor'");
    $result = create_member(['email' => 'other_treasurer@example.test', 'first_name' => 'Changed', 'last_name' => 'Name', 'username' => 'x', 'roles' => [(string) $roleId]]);
    assert_true($result['existing'] && $result['default_password'] === null, 'existing account keeps its own password');
    assert_same('Otto', db_value("SELECT first_name FROM users WHERE username = 'other_treasurer'"));
    // Shared account: this institution may not edit its details or reset its password.
    assert_rejected(fn() => update_member_profile($result['membership_id'], ['username' => 'other_treasurer', 'email' => 'other_treasurer@example.test', 'first_name' => 'X', 'last_name' => 'Y']), 'another institution');
    assert_rejected(fn() => issue_member_reset_link($result['membership_id']), 'another institution');
    assert_rejected(fn() => reset_member_to_default_password($result['membership_id']), 'another institution');
    assert_rejected(fn() => create_member(['email' => 'other_treasurer@example.test', 'roles' => [(string) $roleId]]), 'already a member');
});

test('administrators cannot change their own roles or status', function () use ($members) {
    act_as('admin', 'TST');
    $roleId = (int) db_value("SELECT id FROM roles WHERE role_code = 'treasurer'");
    assert_rejected(fn() => set_member_roles($members['admin'], [$roleId]), 'own account');
    assert_rejected(fn() => set_membership_status($members['admin'], 'suspended', 'testing self'), 'own account');
});

test('the last active institution administrator cannot be removed', function () use ($members) {
    // A member who may assign roles but is not an administrator (e.g. a custom role).
    act_as('treasurer', 'TST');
    $state = &auth_state();
    $state['permissions'] += ['users.manage' => true, 'roles.assign' => true];
    $treasurerRole = (int) db_value("SELECT id FROM roles WHERE role_code = 'treasurer'");
    assert_same(0, other_active_admins($members['admin']), 'admin is the only one');
    assert_rejected(fn() => set_member_roles($members['admin'], [$treasurerRole]), 'only active Institution Administrator');
    assert_rejected(fn() => set_membership_status($members['admin'], 'suspended', 'should fail'), 'only active Institution Administrator');
    assert_true(membership_is_admin($members['admin']), 'admin role kept');
});

test('role changes take effect on the next request (permissions reload from the database)', function () use ($members) {
    act_as('auditor', 'TST');
    assert_forbidden('organization.manage');
    act_as('admin', 'TST');
    $before = membership_role_ids($members['auditor']);
    set_member_roles($members['auditor'], [...$before, (int) db_value("SELECT id FROM roles WHERE role_code = 'institution_admin'")]);
    act_as('auditor', 'TST');
    assert_true(can('organization.manage'), 'new permission visible');
    act_as('admin', 'TST');
    set_member_roles($members['auditor'], $before);
    act_as('auditor', 'TST');
    assert_forbidden('organization.manage');
});

test('one institution cannot read another institution\'s members', function () use ($members) {
    act_as('other_treasurer', 'OTH');
    assert_same(null, find_membership_detail($members['cashier']));
});

section('Batch 1: organization');

test('departments, groups, participants and officers', function () use ($members) {
    act_as('admin', 'TST');
    $deptId = save_department(null, ['department_code' => 'cs', 'department_name' => 'Computer Studies']);
    $groupId = save_group(null, ['group_code' => 'BSCS-3A', 'group_name' => 'BSCS 3-A', 'department_id' => (string) $deptId, 'academic_year' => '2026-2027']);
    $p1 = save_participant(null, ['participant_number' => '2026-001', 'first_name' => 'Sam', 'last_name' => 'Garcia', 'link_membership_id' => (string) $members['student']]);
    $p2 = save_participant(null, ['participant_number' => '2026-002', 'first_name' => 'Bea', 'last_name' => 'Lopez']);
    $p3 = save_participant(null, ['participant_number' => '2026-003', 'first_name' => 'Cy', 'last_name' => 'Dizon']);
    assert_same(3, add_group_members($groupId, [$p1, $p2, $p3], '', ''));
    assert_same(0, add_group_members($groupId, [$p1], '', ''), 'existing members are skipped');
    assert_rejected(fn() => save_participant(null, ['participant_number' => '2026-001', 'first_name' => 'Dup', 'last_name' => 'Licate']), 'already registered');
    assign_officer($groupId, ['membership_id' => (string) $members['collector'], 'officer_type' => 'president']);
    assign_officer($groupId, ['membership_id' => (string) $members['adviser'], 'officer_type' => 'adviser']);
    assert_rejected(fn() => assign_officer($groupId, ['membership_id' => (string) $members['collector'], 'officer_type' => 'president']), 'already holds');
    // An outsider group the collector does not serve.
    $otherGroup = save_group(null, ['group_code' => 'BSIT-1A', 'group_name' => 'BSIT 1-A']);
    $p4 = save_participant(null, ['participant_number' => '2026-004', 'first_name' => 'Dee', 'last_name' => 'Ong']);
    add_group_members($otherGroup, [$p4], '', '');
    $GLOBALS['fx'] = compact('groupId', 'otherGroup', 'p1', 'p2', 'p3', 'p4');
});

test('collectors and advisers only see participants of groups they serve', function () {
    act_as('collector', 'TST');
    assert_true(find_participant($GLOBALS['fx']['p1']) !== null, 'own group participant visible');
    assert_same(null, find_participant($GLOBALS['fx']['p4']), 'other group participant hidden');
    act_as('adviser', 'TST');
    assert_same(null, find_participant($GLOBALS['fx']['p4']), 'adviser scoped too');
    act_as('cashier', 'TST');
    assert_true(find_participant($GLOBALS['fx']['p4']) !== null, 'cashier sees everyone');
});

test('CSV import is all-or-nothing and links groups', function () {
    act_as('admin', 'TST');
    $bad = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($bad, "participant_number,first_name,last_name,group_code\n2026-010,Ana,Reyes,BSCS-3A\n2026-011,,NoFirst,BSCS-3A\n");
    $before = (int) db_value('SELECT COUNT(*) FROM participants');
    assert_rejected(fn() => import_participants_csv($bad, true), 'Line 3');
    assert_same($before, (int) db_value('SELECT COUNT(*) FROM participants'), 'nothing imported on error');

    $good = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($good, "\xEF\xBB\xBFparticipant_number,first_name,last_name,participant_type,group_code\n2026-010,Ana,Reyes,student,BSCS-3A\n2026-001,Sam,Garcia,student,BSCS-3A\n");
    $result = import_participants_csv($good, true);
    assert_same(['created' => 1, 'skipped' => 1, 'grouped' => 1], $result);
    unlink($bad);
    unlink($good);
});

test('only organization managers can change the structure', function () {
    act_as('cashier', 'TST');
    assert_forbidden('organization.manage');
    act_as('auditor', 'TST');
    assert_forbidden(['organization.manage', 'users.manage', 'roles.assign']);
});
