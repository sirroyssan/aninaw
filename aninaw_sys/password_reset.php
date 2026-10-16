<?php
/**
 * Set a password with a one-time link issued by an administrator.
 * Without a valid link it explains how to get one (no e-mail service is assumed).
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/services/users.php';

send_security_headers();
send_no_cache_headers();
header('Referrer-Policy: no-referrer'); // keep the token out of referer headers
start_secure_session();

$token = get_str('token', 64);
if ($token === '') {
    $token = post_str('token', 64);
}

if (is_post()) {
    require_csrf();
    try {
        complete_password_reset($token, (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirmation'] ?? ''));
        flash('success', 'Password set', 'You can now sign in with your new password.');
        redirect('login.php');
    } catch (UserFacingException $exception) {
        flash('error', 'Password not changed', $exception->getMessage());
        redirect('password_reset.php?token=' . rawurlencode($token));
    }
}

$row = $token !== '' ? find_valid_reset_token($token) : null;

$pageTitle = 'Set Password';
$layout = 'auth';
require APP_ROOT . '/includes/header.php';
?>
<div class="select-wrapper">
    <div class="select-header">
        <span class="brand-mark brand-mark-lg" aria-hidden="true"><i class="bi bi-key"></i></span>
        <h1 class="select-title"><?= $row ? 'Set your password' : 'Password help' ?></h1>
        <p class="select-subtitle"><?= $row ? 'Hello ' . e($row['first_name']) . ' (@' . e($row['username']) . '). Choose a password for your ANINAW account.' : 'Password links are issued by your institution administrator.' ?></p>
    </div>
    <div class="card panel">
        <div class="card-body p-4">
            <?php if ($row): ?>
                <form method="post" action="<?= e(url('password_reset.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="password">New password</label>
                        <div class="input-icon">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input class="form-control has-toggle" type="password" id="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password">
                            <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
                        </div>
                        <div class="form-text">At least 10 characters, with letters and numbers.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label" for="password_confirmation">Confirm password</label>
                        <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required minlength="10" maxlength="200" autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Set Password</button>
                </form>
            <?php else: ?>
                <?php if ($token !== ''): ?>
                    <div class="notice notice-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>This link is invalid, already used or expired.</div></div>
                <?php endif; ?>
                <p>To set or reset your password, ask your institution administrator to issue a new password link from your member record. The link works once and expires after <?= RESET_TOKEN_HOURS ?> hours.</p>
                <a class="btn btn-outline-primary" href="<?= e(url('login.php')) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Sign In</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
