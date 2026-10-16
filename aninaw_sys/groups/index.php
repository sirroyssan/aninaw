<?php
/**
 * Groups: classes, sections, committees, chapters or any unit the institution uses.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission(['participants.view', 'organization.manage']);

$canManage = can('organization.manage');

if (is_post()) {
    require_permission('organization.manage');
    handle_post(function () {
        $groupId = save_group(null, $_POST);
        flash('success', 'Group created');
        return 'groups/view.php?id=' . $groupId;
    });
}

$search = get_str('q', 100);
$departmentId = get_int('department');
$type = get_str('type', 20);
$status = get_str('status', 20) ?: 'active';

$where = ['g.institution_id = ?'];
$params = [current_institution_id()];
[$visibility] = participant_visibility('p');
if ($visibility !== '1 = 1') {
    $ids = my_group_ids() ?: [0];
    $where[] = 'g.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    array_push($params, ...$ids);
}
if ($search !== '') {
    $where[] = '(g.group_name LIKE ? OR g.group_code LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%");
}
if ($departmentId) {
    $where[] = 'g.department_id = ?';
    $params[] = $departmentId;
}
if (isset(GROUP_TYPES[$type])) {
    $where[] = 'g.group_type = ?';
    $params[] = $type;
}
if ($status !== 'all') {
    $where[] = 'g.status = ?';
    $params[] = in_array($status, ['active', 'inactive', 'closed'], true) ? $status : 'active';
}
$whereSql = implode(' AND ', $where);
[$orderBy, $sortKey, $sortDir] = sort_clause(['name' => 'g.group_name', 'code' => 'g.group_code', 'members' => 'members', 'year' => 'g.academic_year'], 'name');

$pagination = paginate((int) db_value("SELECT COUNT(*) FROM `groups` g WHERE {$whereSql}", $params));
$groups = db_all(
    "SELECT g.id, g.group_code, g.group_name, g.group_type, g.academic_year, g.term, g.status, d.department_name,
            (SELECT COUNT(*) FROM group_memberships gm WHERE gm.institution_id = g.institution_id AND gm.group_id = g.id AND gm.status = 'active') AS members,
            (SELECT GROUP_CONCAT(CONCAT(u.first_name, ' ', u.last_name, ' (', go.officer_type, ')') SEPARATOR ', ')
               FROM group_officers go JOIN institution_memberships m ON m.id = go.membership_id JOIN users u ON u.id = m.user_id
              WHERE go.institution_id = g.institution_id AND go.group_id = g.id AND go.status = 'active'
                AND (go.valid_until IS NULL OR go.valid_until >= CURDATE())) AS officers
     FROM `groups` g LEFT JOIN departments d ON d.id = g.department_id AND d.institution_id = g.institution_id
     WHERE {$whereSql} ORDER BY {$orderBy}, g.id LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);
$departments = department_options(false);

$pageTitle = 'Groups';
$activeNav = 'groups';
$breadcrumb = [auth_context()['label'], 'Organization'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Classes, sections, committees or other units. Members and officers are managed inside each group.',
    ($canManage ? button_link('groups/departments.php', 'Departments', 'bi-diagram-2', 'btn-outline-primary') : '')
    . (can_view_participants() ? button_link('participants/index.php', 'Participants', 'bi-person-vcard', 'btn-outline-primary') : '')) ?>

<div class="row g-3">
    <div class="<?= $canManage ? 'col-xl-8' : 'col-12' ?>">
        <div class="card panel">
            <form class="filter-bar" method="get">
                <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Name or code"></div>
                <div class="filter-field"><label for="department">Department</label><select class="form-select" id="department" name="department" data-autosubmit><?= options_html($departments, $departmentId, 'All') ?></select></div>
                <div class="filter-field"><label for="type">Type</label><select class="form-select" id="type" name="type" data-autosubmit><?= options_html(GROUP_TYPES, $type, 'All') ?></select></div>
                <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(['active' => 'Active', 'inactive' => 'Inactive', 'closed' => 'Closed', 'all' => 'All'], $status) ?></select></div>
                <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
            </form>
            <?php if (!$groups): ?>
                <div class="card-body"><?= empty_state('bi-diagram-3', 'No groups found', $canManage ? 'Create a group to organise participants.' : 'You are not assigned to any group yet.') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr>
                            <th><?= sort_link('Group', 'name', $sortKey, $sortDir) ?></th>
                            <th>Department</th>
                            <th><?= sort_link('Year / Term', 'year', $sortKey, $sortDir) ?></th>
                            <th class="text-end"><?= sort_link('Members', 'members', $sortKey, $sortDir) ?></th>
                            <th>Officers</th><th>Status</th><th></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($groups as $row): ?>
                            <tr>
                                <td><div class="fw-semibold"><?= e($row['group_name']) ?></div><div class="text-muted-sm"><?= e($row['group_code'] . ' · ' . (GROUP_TYPES[$row['group_type']] ?? $row['group_type'])) ?></div></td>
                                <td><?= e($row['department_name'] ?: '—') ?></td>
                                <td class="text-muted-sm"><?= e(trim(($row['academic_year'] ?? '') . ' ' . ($row['term'] ?? '')) ?: '—') ?></td>
                                <td class="text-end"><?= e((string) $row['members']) ?></td>
                                <td class="text-muted-sm" style="max-width:240px"><?= e($row['officers'] ?: '—') ?></td>
                                <td><?= status_badge($row['status']) ?></td>
                                <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('groups/view.php?id=' . $row['id'])) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            <?= pagination_links($pagination) ?>
        </div>
    </div>

    <?php if ($canManage): ?>
        <div class="col-xl-4">
            <form method="post" class="card panel form-card">
                <?= csrf_field() ?><?= idempotency_field() ?>
                <div class="card-header"><h3>New Group</h3></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-5"><label class="form-label required" for="group_code">Code</label><input class="form-control" id="group_code" name="group_code" required maxlength="80" value="<?= e(old('group_code')) ?>"></div>
                        <div class="col-7"><label class="form-label" for="group_type">Type</label><select class="form-select" id="group_type" name="group_type"><?= options_html(GROUP_TYPES, old('group_type', 'class')) ?></select></div>
                        <div class="col-12"><label class="form-label required" for="group_name">Name</label><input class="form-control" id="group_name" name="group_name" required maxlength="180" value="<?= e(old('group_name')) ?>" placeholder="e.g. BSCS 3-A, Finance Committee"></div>
                        <div class="col-12"><label class="form-label" for="department_id">Department <span class="text-muted-sm">(optional)</span></label><select class="form-select" id="department_id" name="department_id"><?= options_html(department_options(), old('department_id'), 'None') ?></select></div>
                        <div class="col-6"><label class="form-label" for="academic_year">Academic year</label><input class="form-control" id="academic_year" name="academic_year" maxlength="30" value="<?= e(old('academic_year')) ?>" placeholder="2026-2027"></div>
                        <div class="col-6"><label class="form-label" for="term">Term</label><input class="form-control" id="term" name="term" maxlength="40" value="<?= e(old('term')) ?>" placeholder="1st Semester"></div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Create Group</button></div>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
