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
            case 'add_new_member':
                // A person not yet registered: create them and add them to this group together.
                $name = db_transaction(function () use ($groupId) {
                    $participantId = save_participant(null, ['status' => 'active'] + $_POST, true);
                    add_group_members($groupId, [$participantId], '', '');
                    return post_str('first_name', 100) . ' ' . post_str('last_name', 100);
                });
                flash('success', 'Person added', $name . ' was added to People and to this group.');
                $action = 'add_members';
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

// Search results for adding members when the page is used without JavaScript (the
// search box normally asks participants/search.php as you type). Nothing is listed until
// at least 2 characters are entered.
$find = get_str('find', 100);
$candidates = [];
if ($canManage && mb_strlen($find) >= 2) {
    $like = '%' . addcslashes($find, '%_\\') . '%';
    $candidates = db_all(
        "SELECT p.id, p.participant_number, p.first_name, p.last_name FROM participants p
         WHERE p.institution_id = ? AND p.status = 'active'
           AND NOT EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = p.institution_id AND gm.group_id = ? AND gm.participant_id = p.id AND gm.status = 'active')
           AND (p.participant_number LIKE ? OR p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)
         ORDER BY p.last_name, p.first_name LIMIT 20",
        array_merge([current_institution_id(), $groupId], array_fill(0, 4, $like))
    );
}

$pageTitle = $group['group_name'];
$activeNav = 'groups';
$breadcrumb = [auth_context()['label'], 'Groups'];
$pageScripts = ['js/member_picker.js'];
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
                <div class="card-body" data-member-picker data-search-url="<?= e(url('participants/search.php')) ?>" data-group="<?= (int) $groupId ?>">
                    <form method="get" class="d-flex gap-2 mb-2" data-member-search-form>
                        <input type="hidden" name="id" value="<?= e($groupId) ?>">
                        <input class="form-control" type="search" name="find" value="<?= e($find) ?>" placeholder="Search name or ID number" aria-label="Search people to add" autocomplete="off" data-member-search>
                        <button class="btn btn-outline-primary" type="submit" data-member-search-button><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search</span></button>
                    </form>
                    <form method="post" data-member-add-form>
                        <?= csrf_field() ?><?= idempotency_field() ?>
                        <input type="hidden" name="action" value="add_members">
                        <div class="member-results" data-member-results aria-live="polite">
                            <?php if ($find !== '' && mb_strlen($find) >= 2): ?>
                                <?php if (!$candidates): ?>
                                    <p class="text-muted-sm mb-2">No one found for "<?= e($find) ?>".</p>
                                <?php else: ?>
                                    <?php foreach ($candidates as $row): ?>
                                        <label class="member-result"><input class="form-check-input" type="checkbox" name="participant_ids[]" value="<?= e($row['id']) ?>"> <span><span class="fw-semibold"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></span> <span class="text-muted-sm"><?= e($row['participant_number']) ?></span></span></label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="text-muted-sm mb-2" data-member-hint>Type at least 2 letters of a name or ID number.</p>
                            <?php endif; ?>
                        </div>
                        <div class="member-chips" data-member-chips></div>
                        <div data-member-add-fields>
                            <div class="row g-2 mb-2">
                                <div class="col-6"><input class="form-control" name="academic_year" maxlength="30" placeholder="<?= e($group['academic_year'] ?: 'Year') ?>" aria-label="Academic year"></div>
                                <div class="col-6"><input class="form-control" name="term" maxlength="40" placeholder="<?= e($group['term'] ?: 'Term') ?>" aria-label="Term"></div>
                            </div>
                            <button class="btn btn-primary w-100" type="submit" data-member-add-button><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add Selected</button>
                        </div>
                    </form>
                    <div class="text-center mt-2">
                        <button class="btn btn-link btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#new-member-modal" data-member-new><i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>Not in the list? Add a new person</button>
                    </div>
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
<?php if ($canManage): $reopen = old('action') === 'add_new_member'; ?>
<div class="modal fade" id="new-member-modal" tabindex="-1" aria-labelledby="new-member-title" aria-hidden="true"<?= $reopen ? ' data-open-on-load' : '' ?>>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <form method="post" class="modal-content">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <input type="hidden" name="action" value="add_new_member">
            <div class="modal-header">
                <h2 class="modal-title h5" id="new-member-title">Add a new person to <?= e($group['group_name']) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted-sm">They are added to the People list and to this group at once.</p>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label required" for="nm-number">ID number</label><input class="form-control" id="nm-number" name="participant_number" required maxlength="100" value="<?= e($reopen ? old('participant_number') : '') ?>" placeholder="e.g. 26-00123"></div>
                    <div class="col-md-6"><label class="form-label" for="nm-type">Type</label><select class="form-select" id="nm-type" name="participant_type"><?= options_html(PARTICIPANT_TYPES, $reopen ? old('participant_type', 'student') : ($group['group_type'] === 'other' ? 'employee' : 'student')) ?></select></div>
                    <div class="col-md-6"><label class="form-label required" for="nm-first">First name</label><input class="form-control" id="nm-first" name="first_name" required maxlength="100" value="<?= e($reopen ? old('first_name') : '') ?>" data-member-new-first></div>
                    <div class="col-md-6"><label class="form-label required" for="nm-last">Last name</label><input class="form-control" id="nm-last" name="last_name" required maxlength="100" value="<?= e($reopen ? old('last_name') : '') ?>" data-member-new-last></div>
                    <div class="col-md-6"><label class="form-label" for="nm-middle">Middle name</label><input class="form-control" id="nm-middle" name="middle_name" maxlength="100" value="<?= e($reopen ? old('middle_name') : '') ?>">
                        <div class="form-check mt-1"><input type="hidden" name="no_middle_name" value="0"><input class="form-check-input" type="checkbox" id="nm-no-middle" name="no_middle_name" value="1" <?= $reopen && old('no_middle_name') === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="nm-no-middle">No middle name</label></div></div>
                    <div class="col-md-6"><label class="form-label" for="nm-program">Program</label><select class="form-select" id="nm-program" name="program_id"><?= options_html(program_options(), $reopen ? old('program_id') : null, '—') ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="nm-birth">Birthday</label><input class="form-control" type="date" id="nm-birth" name="birth_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($reopen ? old('birth_date') : '') ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="nm-phone">Phone</label><input class="form-control" id="nm-phone" name="phone" maxlength="50" value="<?= e($reopen ? old('phone') : '') ?>"></div>
                    <div class="col-12"><label class="form-label" for="nm-address">Address</label><input class="form-control" id="nm-address" name="address" maxlength="500" value="<?= e($reopen ? old('address') : '') ?>"></div>
                    <div class="col-md-7"><label class="form-label" for="nm-guardian">Parent / guardian</label><input class="form-control" id="nm-guardian" name="guardian_name" maxlength="180" value="<?= e($reopen ? old('guardian_name') : '') ?>"></div>
                    <div class="col-md-5"><label class="form-label" for="nm-guardian-phone">Guardian's contact</label><input class="form-control" id="nm-guardian-phone" name="guardian_phone" maxlength="50" value="<?= e($reopen ? old('guardian_phone') : '') ?>"></div>
                    <div class="col-12"><label class="form-label" for="nm-email">E-mail</label><input class="form-control" type="email" id="nm-email" name="email" maxlength="190" value="<?= e($reopen ? old('email') : '') ?>"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add to Group</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
