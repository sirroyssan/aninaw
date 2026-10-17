<?php
/**
 * Participants: students, employees or members who owe or pay collections.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission(['participants.view', 'organization.manage']);

$search = get_str('q', 100);
$groupId = get_int('group');
$type = get_str('type', 20);
$programId = get_int('program');
$status = get_str('status', 20) ?: 'active';

[$visibility, $params] = participant_visibility('p');
$where = ['p.institution_id = ?', $visibility];
array_unshift($params, current_institution_id());
if ($search !== '') {
    $where[] = '(p.participant_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR p.email LIKE ? OR CONCAT(p.first_name, " ", p.last_name) LIKE ?)';
    array_push($params, ...array_fill(0, 5, "%{$search}%"));
}
if ($groupId) {
    $where[] = "EXISTS (SELECT 1 FROM group_memberships fgm WHERE fgm.institution_id = p.institution_id AND fgm.participant_id = p.id AND fgm.group_id = ? AND fgm.status = 'active')";
    $params[] = $groupId;
}
if ($programId) {
    $where[] = 'p.program_id = ?';
    $params[] = $programId;
}
if (isset(PARTICIPANT_TYPES[$type])) {
    $where[] = 'p.participant_type = ?';
    $params[] = $type;
}
if ($status !== 'all') {
    $where[] = 'p.status = ?';
    $params[] = in_array($status, ['active', 'inactive', 'archived'], true) ? $status : 'active';
}
$whereSql = implode(' AND ', $where);
[$orderBy, $sortKey, $sortDir] = sort_clause(['name' => 'p.last_name', 'number' => 'p.participant_number', 'type' => 'p.participant_type', 'created' => 'p.created_at'], 'name');

$pagination = paginate((int) db_value("SELECT COUNT(*) FROM participants p WHERE {$whereSql}", $params));
$participants = db_all(
    "SELECT p.id, p.participant_number, p.first_name, p.middle_name, p.last_name, p.email, p.participant_type, p.status, p.user_id,
            (SELECT pr.program_code FROM programs pr WHERE pr.institution_id = p.institution_id AND pr.id = p.program_id) AS program_code,
            (SELECT GROUP_CONCAT(g.group_name SEPARATOR ', ') FROM group_memberships gm JOIN `groups` g ON g.id = gm.group_id
              WHERE gm.institution_id = p.institution_id AND gm.participant_id = p.id AND gm.status = 'active') AS group_names
     FROM participants p WHERE {$whereSql}
     ORDER BY {$orderBy}, p.first_name, p.id LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$canManage = can('organization.manage');

$pageTitle = 'People';
$activeNav = 'participants';
$breadcrumb = [auth_context()['label'], 'Organization'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($visibility === '1 = 1' ? 'Students, faculty / staff and customers: everyone who can owe, pay or buy. Add them to events directly; groups are optional.' : 'People in the groups you serve.',
    $canManage ? button_link('participants/import_pdf.php', 'Import Class List (PDF)', 'bi-file-earmark-pdf', 'btn-outline-primary') . button_link('participants/import.php', 'Import CSV', 'bi-upload', 'btn-outline-primary') . button_link('participants/programs.php', 'Programs', 'bi-mortarboard', 'btn-outline-primary') . button_link('participants/form.php', 'Add Person', 'bi-person-plus') : '')
    . (can('users.manage') ? button_link('participants/logins.php', 'Create Logins', 'bi-person-lock', 'btn-outline-primary') : '') ?>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="ID number, name or e-mail"></div>
        <div class="filter-field"><label for="group">Group</label><select class="form-select" id="group" name="group" data-autosubmit><?= options_html(group_options(false), $groupId, 'All groups') ?></select></div>
        <div class="filter-field"><label for="program">Program</label><select class="form-select" id="program" name="program" data-autosubmit><?= options_html(options_from_rows(db_all('SELECT id, program_code FROM programs WHERE institution_id = ? ORDER BY program_code', [current_institution_id()]), 'id', 'program_code'), $programId, 'All') ?></select></div>
        <div class="filter-field"><label for="type">Type</label><select class="form-select" id="type" name="type" data-autosubmit><?= options_html(PARTICIPANT_TYPES, $type, 'All') ?></select></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived', 'all' => 'All'], $status) ?></select></div>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
    </form>
    <?php if (!$participants): ?>
        <div class="card-body"><?= empty_state('bi-person-vcard', 'No participants found', $canManage ? 'Register participants one by one or import a CSV file.' : 'Try a different search.') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr>
                    <th><?= sort_link('ID No.', 'number', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Name', 'name', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Type', 'type', $sortKey, $sortDir) ?></th><th>Program</th>
                    <th>Groups</th><th>Login</th><th>Status</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($participants as $row): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($row['participant_number']) ?></td>
                        <td><div><?= e($row['last_name'] . ', ' . $row['first_name'] . ($row['middle_name'] ? ' ' . mb_substr($row['middle_name'], 0, 1) . '.' : '')) ?></div><div class="text-muted-sm"><?= e($row['email'] ?: '') ?></div></td>
                        <td><?= e(PARTICIPANT_TYPES[$row['participant_type']] ?? $row['participant_type']) ?></td>
                        <td><?= e($row['program_code'] ?: '—') ?></td>
                        <td class="text-muted-sm" style="max-width:220px"><?= e($row['group_names'] ?: '—') ?></td>
                        <td><?= $row['user_id'] ? '<span class="badge badge-soft-success">Linked</span>' : '<span class="text-muted-sm">—</span>' ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('participants/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
