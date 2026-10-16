<?php
/**
 * Group details: profile, members and officers (president, collector, adviser).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission(['participants.view', 'organization.manage']);

$groupId = require_get_id();
$group = require_found(find_group($groupId));
[$visibility] = participant_visibility('p');
if ($visibility !== '1 = 1' && !in_array($groupId, my_group_ids(), true)) {
    render_error_page(404, 'Record Not Found', 'The record you requested does not exist or is not available in this institution.');
}
$canManage = can('organization.manage');

if (is_post()) {
    require_permission('organization.manage');
    $action = post_str('action', 30);
    handle_post(function () use ($action, $groupId) {
        switch ($action) {
            case 'update':
                save_group($groupId, $_POST);
                flash('success', 'Group updated');
                break;
            case 'add_members':
                $added = add_group_members($groupId, input_ids($_POST, 'participant_ids'), post_str('academic_year', 30), post_str('term', 40));
                flash('success', $added . ' member(s) added');
                break;
            case 'remove_member':
                end_group_membership(post_int('group_membership_id') ?? 0, post_str('status', 20));
                flash('success', 'Member removed from group');
                break;
            case 'assign_officer':
                assign_officer($groupId, $_POST);
                flash('success', 'Officer assigned');
                break;
            case 'end_officer':
                end_officer(post_int('officer_id') ?? 0);
                flash('success', 'Officer assignment ended');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'groups/view.php?id=' . $groupId . '#' . ($action === 'update' ? 'profile' : ($action === 'assign_officer' || $action === 'end_officer' ? 'officers' : 'members'));
    }, !in_array($action, ['remove_member', 'end_officer'], true));
}

$members = db_all(
    "SELECT gm.id, gm.academic_year, gm.term, gm.start_date, p.id AS participant_id, p.participant_number, p.first_name, p.last_name, p.participant_type
     FROM group_memberships gm JOIN participants p ON p.id = gm.participant_id AND p.institution_id = gm.institution_id
     WHERE gm.institution_id = ? AND gm.group_id = ? AND gm.status = 'active' ORDER BY p.last_name, p.first_name",
    [current_institution_id(), $groupId]
);
$officers = db_all(
    "SELECT go.*, u.first_name, u.last_name, u.email FROM group_officers go
     JOIN institution_memberships m ON m.id = go.membership_id AND m.institution_id = go.institution_id JOIN users u ON u.id = m.user_id
     WHERE go.institution_id = ? AND go.group_id = ? ORDER BY go.status = 'active' DESC, go.valid_from DESC",
    [current_institution_id(), $groupId]
);

// Candidate participants for adding (searchable, limited list).
$find = get_str('find', 100);
$candidates = [];
if ($canManage) {
    $sql = "SELECT p.id, p.participant_number, p.first_name, p.last_name FROM participants p
            WHERE p.institution_id = ? AND p.status = 'active'
              AND NOT EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = p.institution_id AND gm.group_id = ? AND gm.participant_id = p.id AND gm.status = 'active')";
    $params = [current_institution_id(), $groupId];
    if ($find !== '') {
        $sql .= ' AND (p.participant_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, " ", p.last_name) LIKE ?)';
        array_push($params, ...array_fill(0, 4, "%{$find}%"));
    }
    $candidates = db_all($sql . ' ORDER BY p.last_name, p.first_name LIMIT 200', $params);
}

$pageTitle = $group['group_name'];
$activeNav = 'groups';
$breadcrumb = [auth_context()['label'], 'Groups'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($group['group_code'] . ' · ' . (GROUP_TYPES[$group['group_type']] ?? $group['group_type']) . ($group['department_name'] ? ' · ' . $group['department_name'] : ''),
    button_link('groups/index.php', 'All Groups', 'bi-arrow-left', 'btn-outline-secondary')) ?>

<div class="stat-grid">
    <?= stat_card('bi-people', 'Active Members', number_format(count($members))) ?>
    <?= stat_card('bi-person-badge', 'Active Officers', number_format(count(array_filter($officers, fn($o) => $o['status'] === 'active')))) ?>
    <?= stat_card('bi-calendar3', 'Academic Year / Term', trim(($group['academic_year'] ?? '') . ' ' . ($group['term'] ?? '')) ?: '—', '', 'info') ?>
    <?= stat_card('bi-flag', 'Status', humanize($group['status']), '', $group['status'] === 'active' ? 'success' : 'warning') ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3" id="members">
            <div class="card-header"><h3>Members</h3></div>
            <?php if (!$members): ?>
                <div class="card-body"><?= empty_state('bi-person-plus', 'No members yet') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>ID No.</th><th>Name</th><th>Type</th><th>Year / Term</th><th>Since</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($members as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($row['participant_number']) ?></td>
                                <td><a href="<?= e(url('participants/view.php?id=' . $row['participant_id'])) ?>"><?= e(person_name($row)) ?></a></td>
                                <td><?= e(PARTICIPANT_TYPES[$row['participant_type']] ?? $row['participant_type']) ?></td>
                                <td class="text-muted-sm"><?= e(trim(($row['academic_year'] ?? '') . ' ' . ($row['term'] ?? '')) ?: '—') ?></td>
                                <td class="text-muted-sm"><?= e(format_date($row['start_date'])) ?></td>
                                <?php if ($canManage): ?>
                                    <td class="actions">
                                        <form method="post" data-confirm="Remove <?= e(person_name($row)) ?> from this group?" data-confirm-text="Their membership is ended, not deleted. Existing obligations stay." data-danger>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove_member">
                                            <input type="hidden" name="group_membership_id" value="<?= e($row['id']) ?>">
                                            <button class="btn btn-outline-danger btn-xs" type="submit">Remove</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card panel" id="officers">
            <div class="card-header"><h3>Officers</h3></div>
            <?php if (!$officers): ?>
                <div class="card-body"><?= empty_state('bi-person-badge', 'No officers assigned', 'Assign a president, collector or adviser so they can manage this group\'s collections.') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Position</th><th>Person</th><th>Valid</th><th>Status</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($officers as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= e(OFFICER_TYPES[$row['officer_type']] ?? $row['officer_type']) ?></td>
                                <td><div><?= e(person_name($row)) ?></div><div class="text-muted-sm"><?= e($row['email']) ?></div></td>
                                <td class="text-muted-sm"><?= e(format_date($row['valid_from']) . ' – ' . ($row['valid_until'] ? format_date($row['valid_until']) : 'open')) ?></td>
                                <td><?= status_badge($row['status']) ?></td>
                                <?php if ($canManage): ?>
                                    <td class="actions">
                                        <?php if ($row['status'] === 'active'): ?>
                                            <form method="post" data-confirm="End this officer assignment?" data-danger>
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="end_officer">
                                                <input type="hidden" name="officer_id" value="<?= e($row['id']) ?>">
                                                <button class="btn btn-outline-danger btn-xs" type="submit">End</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($canManage): ?>
        <div class="col-xl-4">
            <div class="card panel mb-3">
                <div class="card-header"><h3>Add Members</h3></div>
                <div class="card-body">
                    <form method="get" class="d-flex gap-2 mb-2">
                        <input type="hidden" name="id" value="<?= e($groupId) ?>">
                        <input class="form-control" type="search" name="find" value="<?= e($find) ?>" placeholder="Search participants" aria-label="Search participants">
                        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?><?= idempotency_field() ?>
                        <input type="hidden" name="action" value="add_members">
                        <?php if (!$candidates): ?>
                            <p class="text-muted-sm">No matching participants outside this group. <a href="<?= e(url('participants/form.php')) ?>">Register a participant</a>.</p>
                        <?php else: ?>
                            <select class="form-select mb-2" name="participant_ids[]" multiple size="8" aria-label="People to add" required>
                                <?php foreach ($candidates as $row): ?>
                                    <option value="<?= e($row['id']) ?>"><?= e($row['participant_number'] . ' — ' . $row['last_name'] . ', ' . $row['first_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text mb-2">Hold Ctrl (Windows) or ⌘ (Mac) to select several. Showing up to 200.</div>
                            <div class="row g-2 mb-2">
                                <div class="col-6"><input class="form-control" name="academic_year" maxlength="30" placeholder="<?= e($group['academic_year'] ?: 'Year') ?>" aria-label="Academic year"></div>
                                <div class="col-6"><input class="form-control" name="term" maxlength="40" placeholder="<?= e($group['term'] ?: 'Term') ?>" aria-label="Term"></div>
                            </div>
                            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add Selected</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <form method="post" class="card panel form-card mb-3">
                <?= csrf_field() ?><?= idempotency_field() ?>
                <input type="hidden" name="action" value="assign_officer">
                <div class="card-header"><h3>Assign Officer</h3></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label required" for="officer_type">Position</label><select class="form-select" id="officer_type" name="officer_type" required><?= options_html(OFFICER_TYPES, null, 'Select…') ?></select></div>
                    <div class="mb-2"><label class="form-label required" for="membership_id">Person (institution member)</label><select class="form-select" id="membership_id" name="membership_id" data-searchable required><?= options_html(member_options(), null, 'Select…') ?></select></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label" for="valid_from">From</label><input class="form-control" type="date" id="valid_from" name="valid_from" value="<?= e(date('Y-m-d')) ?>"></div>
                        <div class="col-6"><label class="form-label" for="valid_until">Until</label><input class="form-control" type="date" id="valid_until" name="valid_until"></div>
                    </div>
                    <p class="text-muted-sm mb-2">Officers need a login. Give collectors the Class President / Collector role and advisers the Adviser role under Users.</p>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>Assign</button>
                </div>
            </form>

            <form method="post" class="card panel form-card" id="profile">
                <?= csrf_field() ?><?= idempotency_field() ?>
                <input type="hidden" name="action" value="update">
                <div class="card-header"><h3>Group Profile</h3></div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-5"><label class="form-label required" for="group_code">Code</label><input class="form-control" id="group_code" name="group_code" required maxlength="80" value="<?= e($group['group_code']) ?>"></div>
                        <div class="col-7"><label class="form-label" for="group_type">Type</label><select class="form-select" id="group_type" name="group_type"><?= options_html(GROUP_TYPES, $group['group_type']) ?></select></div>
                        <div class="col-12"><label class="form-label required" for="group_name">Name</label><input class="form-control" id="group_name" name="group_name" required maxlength="180" value="<?= e($group['group_name']) ?>"></div>
                        <div class="col-12"><label class="form-label" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id"><?= options_html(department_options(false), $group['department_id'], 'None') ?></select></div>
                        <div class="col-6"><label class="form-label" for="academic_year">Academic year</label><input class="form-control" id="academic_year" name="academic_year" maxlength="30" value="<?= e($group['academic_year']) ?>"></div>
                        <div class="col-6"><label class="form-label" for="term">Term</label><input class="form-control" id="term" name="term" maxlength="40" value="<?= e($group['term']) ?>"></div>
                        <div class="col-12"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['active' => 'Active', 'inactive' => 'Inactive', 'closed' => 'Closed'], $group['status']) ?></select></div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button></div>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
