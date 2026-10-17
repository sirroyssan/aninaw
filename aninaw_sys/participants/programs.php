<?php
/**
 * Academic programs (BSIT, BSCS, ...) people can be assigned to. Programs in use are
 * deactivated, never deleted, so existing records keep their program.
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
        if (post_str('action', 20) === 'seed') {
            $added = seed_default_programs((int) current_institution_id());
            audit_log('program.defaults_added', 'program', null, (int) current_institution_id(), (int) auth_user()['id'], ['added' => $added]);
            flash('success', $added . ' program(s) added');
            return 'participants/programs.php';
        }
        save_program(post_int('program_id') ?: null, $_POST);
        flash('success', 'Program saved');
        return 'participants/programs.php';
    });
}

$programs = db_all(
    "SELECT pr.*, d.department_name,
            (SELECT COUNT(*) FROM participants p WHERE p.institution_id = pr.institution_id AND p.program_id = pr.id AND p.status = 'active') AS people
     FROM programs pr LEFT JOIN departments d ON d.id = pr.department_id AND d.institution_id = pr.institution_id
     WHERE pr.institution_id = ? ORDER BY pr.status, pr.program_code",
    [current_institution_id()]
);
$edit = null;
foreach ($programs as $row) {
    if ((int) $row['id'] === get_int('edit')) {
        $edit = $row;
    }
}
$missingDefaults = array_diff(array_keys(DEFAULT_PROGRAMS), array_column($programs, 'program_code'));

$pageTitle = 'Programs';
$activeNav = 'participants';
$breadcrumb = [auth_context()['label'], 'People'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Academic programs people can be assigned to (shown on their record and in event exports).',
    button_link('participants/index.php', 'People', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel">
            <div class="card-header"><h3>Programs</h3>
                <?php if ($canManage && $missingDefaults): ?>
                    <form method="post"><?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="seed"><button class="btn btn-outline-primary btn-xs" type="submit">Add standard programs (<?= e(implode(', ', $missingDefaults)) ?>)</button></form>
                <?php endif; ?>
            </div>
            <?php if (!$programs): ?>
                <div class="card-body"><?= empty_state('bi-mortarboard', 'No programs yet', 'Add your programs, or use "Add standard programs".') ?></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Code</th><th>Program</th><th>Department</th><th class="text-end">People</th><th>Status</th><th></th></tr></thead>
                    <tbody><?php foreach ($programs as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($row['program_code']) ?></td>
                            <td><?= e($row['program_name']) ?></td>
                            <td class="text-muted-sm"><?= e($row['department_name'] ?: '—') ?></td>
                            <td class="text-end"><a href="<?= e(url('participants/index.php?program=' . $row['id'])) ?>"><?= number_format((int) $row['people']) ?></a></td>
                            <td><?= status_badge($row['status']) ?></td>
                            <td class="actions"><?php if ($canManage): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('participants/programs.php?edit=' . $row['id'])) ?>">Edit</a><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($canManage): ?>
    <div class="col-xl-4">
        <form method="post" class="card panel form-card">
            <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="program_id" value="<?= (int) ($edit['id'] ?? 0) ?>">
            <div class="card-header"><h3><?= $edit ? 'Edit ' . e($edit['program_code']) : 'Add a program' ?></h3></div>
            <div class="card-body">
                <div class="mb-2"><label class="form-label required" for="program_code">Code</label><input class="form-control" id="program_code" name="program_code" required maxlength="30" value="<?= e($edit['program_code'] ?? old('program_code')) ?>" placeholder="BSIT"></div>
                <div class="mb-2"><label class="form-label required" for="program_name">Name</label><input class="form-control" id="program_name" name="program_name" required maxlength="180" value="<?= e($edit['program_name'] ?? old('program_name')) ?>" placeholder="Bachelor of Science in Information Technology"></div>
                <div class="mb-2"><label class="form-label" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id"><?= options_html(department_options(), $edit['department_id'] ?? null, 'None') ?></select></div>
                <div class="mb-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['active' => 'Active', 'inactive' => 'Inactive (kept on existing records)'], $edit['status'] ?? 'active') ?></select></div>
                <button class="btn btn-primary w-100" type="submit"><?= $edit ? 'Save Changes' : 'Add Program' ?></button>
                <?php if ($edit): ?><a class="btn btn-link w-100" href="<?= e(url('participants/programs.php')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
