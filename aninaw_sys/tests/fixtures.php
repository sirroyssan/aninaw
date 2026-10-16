<?php
/**
 * Test fixtures: one institution with a member for each role, plus a second
 * institution used to prove isolation. Test data only.
 */
declare(strict_types=1);

const TEST_PASSWORD = 'TestPass2026';

function fixture_user(string $username, string $first, string $last): int
{
    db_query(
        "INSERT INTO users (username, email, password_hash, first_name, last_name, status) VALUES (?, ?, ?, ?, ?, 'active')",
        [$username, $username . '@example.test', password_hash(TEST_PASSWORD, PASSWORD_DEFAULT), $first, $last]
    );
    return (int) db()->lastInsertId();
}

function fixture_member(int $institutionId, int $userId, array $roleCodes): int
{
    db_query("INSERT INTO institution_memberships (institution_id, user_id, membership_status, joined_at) VALUES (?, ?, 'active', NOW())", [$institutionId, $userId]);
    $membershipId = (int) db()->lastInsertId();
    foreach ($roleCodes as $code) {
        db_query('INSERT INTO membership_roles (membership_id, role_id) SELECT ?, id FROM roles WHERE role_code = ?', [$membershipId, $code]);
    }
    return $membershipId;
}

function fixture_institution(string $code, string $name): int
{
    db_query("INSERT INTO institutions (institution_code, institution_name, institution_type, status) VALUES (?, ?, 'College', 'active')", [$code, $name]);
    return (int) db()->lastInsertId();
}

/** Builds the standard cast and returns [username => membership_id]. */
function build_fixtures(): array
{
    $schoolId = fixture_institution('TST', 'Test College');
    $otherId = fixture_institution('OTH', 'Other School');

    $superId = fixture_user('super', 'Platform', 'Admin');
    db_query("INSERT INTO platform_user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE role_code = 'platform_super_admin'", [$superId]);

    $cast = [
        'admin'      => ['Ada', 'Admin', ['institution_admin']],
        'treasurer'  => ['Tess', 'Reyes', ['treasurer']],
        'treasurer2' => ['Troy', 'Lim', ['treasurer']],
        'organizer'  => ['Olga', 'Ramos', ['event_organizer', 'advance_recipient']],
        'cashier'    => ['Carlo', 'Santos', ['cashier']],
        'collector'  => ['Cora', 'Lim', ['collector']],
        'adviser'    => ['Ado', 'Cruz', ['adviser']],
        'auditor'    => ['Aud', 'Tan', ['auditor']],
        'student'    => ['Sam', 'Garcia', ['member']],
    ];
    $members = [];
    foreach ($cast as $username => [$first, $last, $roles]) {
        $members[$username] = fixture_member($schoolId, fixture_user($username, $first, $last), $roles);
    }
    $members['other_treasurer'] = fixture_member($otherId, fixture_user('other_treasurer', 'Otto', 'Other'), ['treasurer']);
    return $members;
}
