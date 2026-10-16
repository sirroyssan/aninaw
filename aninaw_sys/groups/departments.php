<?php
/**
 * Departments (optional level above groups).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission('organization.manage');

if (is_post()) {
    handle_post(function () {
        $id = post_int('department_id');
        save_department($id ?: null, $_POST);
        flash('success', $id ? 'Department updated' : 'Department created');
        return 'groups/departments.php';
    });
}

$editId = get_int('edit');
$departments = db_all(
    "SELECT d.*, (SELECT COUNT(*) FROM `groups` g WHERE g.institution_id = d.institution_id AND g.department_id = d.id AND g.status = 'active') AS active_groups
     FROM departments d WHERE d.institution_id = ? ORDER BY d.department_name",
    [current_institution_id()]
);
$editing = null;
foreach ($departments as $department) {
    if ((int) $department['id'] === $editId) {
        $editing = $department;
    }
}

$pageTitle = 'Departments';
$activeNav = 'groups';
$breadcrumb = [auth_context()['label'], 'Organization'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Optional grouping above classes or committees. Institutions without departments can skip this.', button_link('groups/index.php', 'Groups', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel">
            <?php if (!$departments): ?>
                <div class="card-body"><?= empty_state('bi-diagram-2', 'No departments yet') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Code</th><th>Name</th><th class="text-end">Active groups</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($departments as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($row['department_code']) ?></td>
                                <td><?= e($row['department_name']) ?></td>
                                <td class="text-end"><?= e((string) $row['active_groups']) ?></td>
                                <td><?= status_badge($row['status']) ?></td>
                                <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('groups/departments.php?edit=' . $row['id'])) ?>">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-4">
        <form method="post" class="card panel form-card">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <input type="hidden" name="department_id" value="<?= e($editing['id'] ?? '') ?>">
            <div class="card-header"><h3><?= $editing ? 'Edit Department' : 'New Department' ?></h3></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label required" for="department_code">Code</label><input class="form-control" id="department_code" name="department_code" required maxlength="60" value="<?= e($editing['department_code'] ?? old('department_code')) ?>"></div>
                <div class="mb-3"><label class="form-label required" for="department_name">Name</label><input class="form-control" id="department_name" name="department_name" required maxlength="180" value="<?= e($editing['department_name'] ?? old('department_name')) ?>"></div>
                <div class="mb-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['active' => 'Active', 'inactive' => 'Inactive'], $editing['status'] ?? 'active') ?></select></div>
                <div class="form-actions">
                    <?php if ($editing): ?><a class="btn btn-outline-secondary" href="<?= e(url('groups/departments.php')) ?>">Cancel</a><?php endif; ?>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
