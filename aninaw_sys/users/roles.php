<?php
/**
 * Assign institution roles to a member, and review what each role allows.
 * Without ?id= it shows the role and permission matrix.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/users.php';
require_institution_context();
require_permission('roles.assign');

$membershipId = get_int('id');
$member = $membershipId ? require_found(find_membership_detail($membershipId)) : null;

if ($member && is_post()) {
    handle_post(function () use ($membershipId) {
        set_member_roles($membershipId, input_ids($_POST, 'roles'));
        flash('success', 'Roles updated');
        return 'users/edit.php?id=' . $membershipId;
    });
}

$roles = assignable_roles();
$permissionsByRole = [];
foreach (db_all("SELECT rp.role_id, p.permission_code, p.permission_name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id ORDER BY p.permission_code") as $row) {
    $permissionsByRole[(int) $row['role_id']][] = $row;
}
$current = $member ? membership_role_ids($membershipId) : [];
$isSelf = $member && (int) $member['user_id'] === (int) auth_user()['id'];

$pageTitle = $member ? 'Roles · ' . person_name($member) : 'Roles & Permissions';
$activeNav = 'roles';
$breadcrumb = [auth_context()['label'], 'Administration'];
require APP_ROOT . '/includes/header.php';
?>
<?php if ($member): ?>
    <?= page_actions('Choose the roles ' . person_name($member) . ' holds in ' . auth_context()['label'] . '. Permissions combine across roles.',
        button_link('users/edit.php?id=' . $membershipId, 'Back to Member', 'bi-arrow-left', 'btn-outline-secondary')) ?>
    <?php if ($isSelf): ?>
        <div class="notice notice-warning"><i class="bi bi-shield-exclamation" aria-hidden="true"></i><div>You cannot change your own roles. Another administrator must do it.</div></div>
    <?php endif; ?>
    <form method="post" data-confirm="Save role changes?" data-confirm-text="The change takes effect on the member's next page load.">
        <?= csrf_field() ?><?= idempotency_field() ?>
<?php else: ?>
    <?= page_actions('Roles available in this institution and the permissions each one grants. Assign roles from a member\'s page.',
        button_link('users/index.php', 'Members', 'bi-people', 'btn-outline-secondary')) ?>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($roles as $role): ?>
        <?php $checked = in_array((int) $role['id'], $current, true); ?>
        <div class="col-lg-6 col-xxl-4">
            <div class="card panel h-100<?= $checked ? ' border-success' : '' ?>">
                <div class="card-header">
                    <?php if ($member): ?>
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="roles[]" value="<?= e($role['id']) ?>" id="role-<?= e($role['id']) ?>" <?= $checked ? 'checked' : '' ?> <?= $isSelf ? 'disabled' : '' ?>>
                            <label class="form-check-label fw-semibold" for="role-<?= e($role['id']) ?>"><?= e($role['role_name']) ?></label>
                        </div>
                    <?php else: ?>
                        <h3><?= e($role['role_name']) ?></h3>
                    <?php endif; ?>
                    <span class="badge badge-soft-neutral"><?= e($role['role_code']) ?></span>
                </div>
                <div class="card-body">
                    <p class="text-muted-sm"><?= e($role['description']) ?></p>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($permissionsByRole[(int) $role['id']] ?? [] as $permission): ?>
                            <span class="badge badge-soft-success" title="<?= e($permission['permission_name']) ?>"><?= e($permission['permission_code']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($member): ?>
        <?php if (!$isSelf): ?>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Roles</button></div>
        <?php endif; ?>
    </form>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
