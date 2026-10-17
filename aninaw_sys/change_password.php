<?php
/**
 * First sign-in with a default password (new account or reset by an administrator):
 * the person must choose their own password before they can open anything else.
 */
declare(strict_types=1);

define('AUTH_ALLOW_NO_CONTEXT', true);
define('AUTH_PASSWORD_CHANGE_PAGE', true);
require_once __DIR__ . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/users.php';

$user = auth_user();
if (empty($user['must_change_password'])) {
    redirect(auth_context() ? 'dashboard/index.php' : 'select_institution.php');
}

if (is_post()) {
    handle_post(function () use ($user) {
        change_default_password((int) $user['id'], (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirmation'] ?? ''));
        flash('success', 'Password changed', 'Welcome, ' . $user['first_name'] . '! Use your new password from now on.');
        return auth_context() ? 'dashboard/index.php' : 'select_institution.php';
    }, false);
}

$pageTitle = 'Change Your Password';
$layout = 'auth';
require APP_ROOT . '/includes/header.php';
?>
<div class="login-wrapper">
    <section class="login-card" aria-labelledby="change-heading">
        <div class="login-brand-panel">
            <div class="login-brand">
                <span class="brand-mark brand-mark-lg" aria-hidden="true"><i class="bi bi-eye"></i></span>
                <div>
                    <div class="login-brand-name"><?= e(APP_NAME) ?></div>
                    <div class="login-brand-full"><?= e(APP_FULL_NAME) ?></div>
                </div>
            </div>
            <p class="login-tagline">Welcome, <?= e($user['first_name']) ?>.</p>
            <ul class="login-points">
                <li><span class="icon-soft"><i class="bi bi-shield-lock" aria-hidden="true"></i></span>You signed in with a default password given by your institution.</li>
                <li><span class="icon-soft"><i class="bi bi-key" aria-hidden="true"></i></span>Choose your own password to continue. Do not share it with anyone.</li>
            </ul>
        </div>

        <div class="login-form-panel">
            <h1 id="change-heading" class="login-heading">Change your password</h1>
            <p class="login-subheading">Signed in as <strong><?= e($user['username']) ?></strong>. You need a new password before you can see your dashboard.</p>

            <form method="post">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label for="password" class="form-label required">New password</label>
                    <div class="input-icon">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input type="password" class="form-control has-toggle" id="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password" autofocus aria-describedby="password-help">
                        <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                    </div>
                    <div class="form-text" id="password-help">At least 10 characters, with letters and numbers. It cannot be the default password.</div>
                </div>
                <div class="mb-4">
                    <label for="password_confirmation" class="form-label required">Confirm new password</label>
                    <div class="input-icon">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required minlength="10" maxlength="200" autocomplete="new-password">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check2-circle me-2" aria-hidden="true"></i>Save Password &amp; Continue</button>
            </form>

            <form id="logout-form" method="post" action="<?= e(url('logout.php')) ?>" class="mt-3 text-center">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-link btn-sm">Sign out instead</button>
            </form>
        </div>
    </section>
    <p class="login-footer">&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?></p>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
