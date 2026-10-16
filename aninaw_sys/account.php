<?php
/**
 * My Account: profile summary and password change (current password required).
 */
declare(strict_types=1);

define('AUTH_ALLOW_NO_CONTEXT', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/users.php';

$user = auth_user();

if (is_post()) {
    handle_post(function () use ($user) {
        $current = (string) ($_POST['current_password'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $hash = (string) db_value('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
        if (!password_verify($current, $hash)) {
            audit_log('user.password_change_failed', 'user', (int) $user['id'], current_institution_id(), (int) $user['id']);
            fail_user('Your current password is incorrect.');
        }
        if ($error = password_policy_error($password)) {
            fail_user($error);
        }
        if (!hash_equals($password, (string) ($_POST['password_confirmation'] ?? ''))) {
            fail_user('The new passwords do not match.');
        }
        db_transaction(function () use ($user, $password) {
            db_query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            // End every other session; keep this one.
            db_query('UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL AND session_token_hash <> ?',
                [$user['id'], hash('sha256', (string) $_SESSION['session_token'])]);
            audit_log('user.password_changed', 'user', (int) $user['id'], current_institution_id(), (int) $user['id']);
        });
        regenerate_session();
        flash('success', 'Password changed', 'Other signed-in sessions have been signed out.');
        return 'account.php';
    });
}

$memberships = db_all(
    "SELECT i.institution_name, m.membership_status,
            (SELECT GROUP_CONCAT(r.role_name SEPARATOR ', ') FROM membership_roles mr JOIN roles r ON r.id = mr.role_id WHERE mr.membership_id = m.id) AS roles
     FROM institution_memberships m JOIN institutions i ON i.id = m.institution_id
     WHERE m.user_id = ? ORDER BY i.institution_name",
    [$user['id']]
);

$pageTitle = 'My Account';
$activeNav = '';
$breadcrumb = ['Account'];
$layout = auth_context() ? 'app' : 'auth';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-3" style="max-width: 1100px">
    <div class="col-lg-6">
        <div class="card panel h-100">
            <div class="card-header"><h3>Profile</h3></div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Name</dt><dd><?= e($user['full_name']) ?></dd></div>
                    <div><dt>Username</dt><dd>@<?= e($user['username']) ?></dd></div>
                    <div><dt>E-mail</dt><dd><?= e($user['email']) ?></dd></div>
                    <div><dt>Last sign-in</dt><dd><?= e($user['last_login_at'] ? format_datetime($user['last_login_at']) : '—') ?></dd></div>
                </dl>
                <h4 class="form-section-title mt-4">Institutions</h4>
                <ul class="list-clean border rounded">
                    <?php foreach ($memberships as $row): ?>
                        <li><div><div class="fw-semibold"><?= e($row['institution_name']) ?></div><div class="text-muted-sm"><?= e($row['roles'] ?: 'No roles') ?></div></div><?= status_badge($row['membership_status']) ?></li>
                    <?php endforeach; ?>
                    <?php if (!$memberships): ?><li class="text-muted-sm">No institution memberships.</li><?php endif; ?>
                </ul>
                <p class="text-muted-sm mt-3 mb-0">To change your name or e-mail, contact your institution administrator.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <form method="post" class="card panel form-card h-100">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <div class="card-header"><h3>Change Password</h3></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label required" for="current_password">Current password</label><input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password"></div>
                <div class="mb-3"><label class="form-label required" for="password">New password</label><input class="form-control" type="password" id="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password"><div class="form-text">At least 10 characters, with letters and numbers.</div></div>
                <div class="mb-3"><label class="form-label required" for="password_confirmation">Confirm new password</label><input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required minlength="10" maxlength="200" autocomplete="new-password"></div>
                <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Change Password</button></div>
            </div>
        </form>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
