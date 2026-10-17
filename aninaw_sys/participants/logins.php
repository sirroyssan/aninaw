<?php
/**
 * Create logins in bulk so students can see their own charges, payments and receipts.
 * Pick people without a login (by group, event, program, type or name); each gets a Student / Member
 * account with username = ID number and a default password they must change at first sign-in.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/users.php';
require_once APP_ROOT . '/includes/services/event_requirements.php';
require_institution_context();
require_permission('users.manage');

if (is_post()) {
    handle_post(function () {
        $ids = is_array($_POST['participant_ids'] ?? null) ? $_POST['participant_ids'] : [];
        $result = create_student_logins($ids);
        $_SESSION['issued_logins'] = $result + ['at' => time()];
        flash($result['created'] ? 'success' : 'warning', count($result['created']) . ' login(s) created',
            $result['skipped'] ? count($result['skipped']) . ' skipped (see the list).' : 'Print the slips now: the passwords are shown only on this page.');
        return 'participants/login_slips.php';
    });
}

$institutionId = (int) current_institution_id();
$groupId = get_int('group');
$eventId = get_int('event');
$programId = get_int('program');
$type = isset(PARTICIPANT_TYPES[get_str('type', 20)]) ? get_str('type', 20) : (get_str('type', 20) === 'all' ? '' : 'student');
$search = get_str('q', 100);
$events = event_options(['draft', 'pending', 'approved', 'collecting', 'completed']);

$where = ['p.institution_id = ?', "p.status = 'active'"];
$params = [$institutionId];
if ($type !== '') {
    $where[] = 'p.participant_type = ?';
    $params[] = $type;
}
if ($groupId) {
    $where[] = "EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = p.institution_id AND gm.participant_id = p.id AND gm.group_id = ? AND gm.status = 'active')";
    $params[] = $groupId;
}
if ($programId) {
    $where[] = 'p.program_id = ?';
    $params[] = $programId;
}
if ($eventId && isset($events[$eventId])) {
    $roster = array_keys(event_roster($eventId));
    $where[] = $roster ? 'p.id IN (' . implode(',', array_map('intval', $roster)) . ')' : '1 = 0';
}
if ($search !== '') {
    $where[] = '(p.participant_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
}
$whereSql = implode(' AND ', $where);
$people = db_all("SELECT p.id, p.participant_number, p.first_name, p.last_name, p.participant_type, pr.program_code
    FROM participants p LEFT JOIN programs pr ON pr.id = p.program_id AND pr.institution_id = p.institution_id
    WHERE {$whereSql} AND p.user_id IS NULL ORDER BY p.last_name, p.first_name LIMIT 500", $params);
$withLogin = (int) db_value("SELECT COUNT(*) FROM participants p WHERE {$whereSql} AND p.user_id IS NOT NULL", $params);
$ready = default_passwords_ready();

$pageTitle = 'Create Logins';
$activeNav = 'participants';
$breadcrumb = [auth_context()['label'], 'People', 'Create Logins'];
$pageScripts = ['js/finance.js'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Give students a login so they can see what they owe, what it includes, what they paid and their receipts. Nothing else is visible to them.',
    button_link('participants/index.php', 'People', 'bi-arrow-left', 'btn-outline-secondary')) ?>

<?php if (!$ready): ?>
    <div class="notice notice-danger"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>Import <code>database/migration_009_default_passwords.sql</code> once (phpMyAdmin → Import) before creating logins.</div></div>
<?php endif; ?>
<div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div>
    Each person gets the <strong>Student / Member</strong> role. <strong>Username:</strong> their ID number (e.g. 24-00054).
    <strong>Default password:</strong> last name + <?= e(date('Y')) ?> + a special character (e.g. Delacruz<?= e(date('Y')) ?>!). They must change it the first time they sign in.
    The passwords are shown once on the next page for printing; they are not stored.
</div></div>

<div class="card panel mb-3">
    <form class="filter-bar" method="get">
        <div class="filter-field"><label for="type">Type</label><select class="form-select" id="type" name="type" data-autosubmit><?= options_html(['all' => 'Everyone'] + PARTICIPANT_TYPES, $type ?: 'all') ?></select></div>
        <div class="filter-field"><label for="group">Group</label><select class="form-select" id="group" name="group" data-autosubmit><?= options_html(group_options(false), $groupId, 'All groups') ?></select></div>
        <div class="filter-field"><label for="event">Event</label><select class="form-select" id="event" name="event" data-autosubmit><?= options_html($events, $eventId, 'Any event') ?></select></div>
        <div class="filter-field"><label for="program">Program</label><select class="form-select" id="program" name="program" data-autosubmit><?= options_html(program_options(), $programId, 'All programs') ?></select></div>
        <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="ID number or name"></div>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
    </form>
</div>

<form method="post" class="card panel" data-checked-sum="#login-count" data-confirm="Create logins for the ticked people?" data-confirm-text="Each gets a Student / Member login with a default password to change at first sign-in. Print the slips on the next page.">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-header"><h3>Without a login <span class="text-muted-sm">(<?= number_format(count($people)) ?><?= count($people) === 500 ? '+, narrow the filter' : '' ?>)</span></h3>
        <span class="text-muted-sm"><?= number_format($withLogin) ?> already have a login</span></div>
    <?php if (!$people): ?>
        <div class="card-body"><?= empty_state('bi-person-check', 'Everyone here already has a login', 'Change the filters to find others.') ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-clean table-sm align-middle mb-0">
            <thead><tr><th><input class="form-check-input" type="checkbox" data-check-all checked aria-label="Tick everyone"></th><th>ID number → username</th><th>Name</th><th>Type</th><th>Program</th></tr></thead>
            <tbody><?php foreach ($people as $p): $username = username_from_id_number((string) $p['participant_number']); ?>
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="participant_ids[]" value="<?= (int) $p['id'] ?>" data-amount="1" id="lp-<?= (int) $p['id'] ?>" <?= $username !== '' ? 'checked' : 'disabled' ?> aria-label="Create a login for <?= e($p['first_name'] . ' ' . $p['last_name']) ?>"></td>
                    <td><?= $username !== '' ? e($username) : '<span class="text-danger small">ID number cannot be a username</span>' ?></td>
                    <td><label class="fw-semibold" for="lp-<?= (int) $p['id'] ?>"><?= e($p['last_name'] . ', ' . $p['first_name']) ?></label></td>
                    <td><?= e(PARTICIPANT_TYPES[$p['participant_type']] ?? '') ?></td>
                    <td><?= e($p['program_code'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <div class="card-body border-top d-flex align-items-center justify-content-between gap-3 flex-wrap">
            <span class="text-muted-sm">Ticked: <strong id="login-count" data-count>0</strong></span>
            <button class="btn btn-primary" type="submit" <?= $ready ? '' : 'disabled' ?>><i class="bi bi-person-lock me-1" aria-hidden="true"></i>Create Logins &amp; Print Slips</button>
        </div>
    <?php endif; ?>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
