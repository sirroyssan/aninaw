<?php
/**
 * Add a member: an existing account (by exact e-mail) joins this institution,
 * or a new account is created with a one-time password link.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/users.php';
require_institution_context();
require_permission('users.manage');

if (is_post()) {
    handle_post(function () {
        if (!can('roles.assign')) {
            fail_user('You need permission to assign roles in order to add members.');
        }
        $result = create_member($_POST);
        if ($result['reset_link']) {
            remember_issued_link($result['reset_link'], post_str('email', 190));
        }
        flash('success', $result['existing'] ? 'Existing account added' : 'Member created',
            $result['existing'] ? 'The person can now switch to this institution after signing in.' : '');
        return 'users/edit.php?id=' . $result['membership_id'];
    });
}

$roles = assignable_roles();
$oldRoles = array_map('intval', (array) old('roles', []));

$pageTitle = 'Add Member';
$activeNav = 'users';
$breadcrumb = [auth_context()['label'], 'Users'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" class="card panel form-card" style="max-width: 880px">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>If the e-mail already belongs to an account (for example someone from another institution), that account is added to this institution and the name fields below are ignored. Otherwise a new account is created and you get a one-time link for the person to set their own password.</div>
        </div>

        <div class="form-section">
            <h2 class="form-section-title">Person</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label required" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" required maxlength="190" value="<?= e(old('email')) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="username">Username <span class="text-muted-sm">(new accounts)</span></label><input class="form-control" id="username" name="username" maxlength="100" value="<?= e(old('username')) ?>" autocapitalize="none"></div>
                <div class="col-md-4"><label class="form-label" for="first_name">First name</label><input class="form-control" id="first_name" name="first_name" maxlength="100" value="<?= e(old('first_name')) ?>"></div>
                <div class="col-md-4"><label class="form-label" for="middle_name">Middle name</label><input class="form-control" id="middle_name" name="middle_name" maxlength="100" value="<?= e(old('middle_name')) ?>"></div>
                <div class="col-md-4"><label class="form-label" for="last_name">Last name</label><input class="form-control" id="last_name" name="last_name" maxlength="100" value="<?= e(old('last_name')) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="50" value="<?= e(old('phone')) ?>"></div>
            </div>
        </div>

        <div class="form-section">
            <h2 class="form-section-title">Roles in <?= e(auth_context()['label']) ?></h2>
            <div class="row g-2">
                <?php foreach ($roles as $role): ?>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="roles[]" value="<?= e($role['id']) ?>" id="role-<?= e($role['id']) ?>" <?= in_array((int) $role['id'], $oldRoles, true) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="role-<?= e($role['id']) ?>"><span class="fw-semibold"><?= e($role['role_name']) ?></span><br><span class="text-muted-sm"><?= e($role['description']) ?></span></label>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url('users/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Add Member</button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
