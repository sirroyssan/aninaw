<?php
/**
 * Sign-in page. Accepts a username or e-mail address and a password.
 * Works with JavaScript (JSON response + SweetAlert2) and without it (classic form post).
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

send_security_headers();
send_no_cache_headers();

if (auth_bootstrap_request() === 'authenticated') {
    redirect(auth_context() ? 'dashboard.php' : 'select_institution.php');
}
start_secure_session();

$identifier = '';
$errors = [];

if (is_post()) {
    require_csrf();

    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($identifier === '') {
        $errors['identifier'] = 'Enter your username or e-mail address.';
    } elseif (mb_strlen($identifier) > 190) {
        $errors['identifier'] = 'This value is too long.';
    }
    if ($password === '') {
        $errors['password'] = 'Enter your password.';
    } elseif (strlen($password) > 1024) {
        $errors['password'] = 'This value is too long.';
    }

    if ($errors) {
        if (wants_json()) {
            json_response(['ok' => false, 'reason' => 'validation', 'message' => 'Please complete the required fields.', 'errors' => $errors], 422);
        }
    } else {
        $result = auth_attempt_login($identifier, $password);

        if (wants_json()) {
            $status = $result['ok'] ? 200 : (($result['reason'] ?? '') === 'throttled' ? 429 : 401);
            json_response($result, $status);
        }
        if ($result['ok']) {
            flash('success', $result['message']);
            header('Location: ' . $result['redirect']);
            exit;
        }
        flash(($result['reason'] ?? '') === 'invalid' ? 'error' : 'warning', 'Sign-in failed', $result['message']);
    }
} else {
    if (isset($_GET['expired'])) {
        flash('warning', 'Session expired', 'For your security, you were signed out after a period of inactivity. Please sign in again.');
    }
    if (isset($_GET['logged_out'])) {
        flash('success', 'Signed out', 'You have been signed out securely.');
    }
}

$pageTitle = 'Sign In';
$layout = 'auth';
$pageScripts = ['js/validation.js'];
require APP_ROOT . '/includes/header.php';
?>
<div class="login-wrapper">
    <section class="login-card" aria-labelledby="login-heading">
        <div class="login-brand-panel">
            <div class="login-brand">
                <span class="brand-mark brand-mark-lg" aria-hidden="true"><i class="bi bi-eye"></i></span>
                <div>
                    <div class="login-brand-name"><?= e(APP_NAME) ?></div>
                    <div class="login-brand-full"><?= e(APP_FULL_NAME) ?></div>
                </div>
            </div>
            <p class="login-tagline">&ldquo;<?= e(APP_TAGLINE) ?>&rdquo;</p>
            <ul class="login-points">
                <li><span class="icon-soft"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>Collections, turnovers and receipts in one record</li>
                <li><span class="icon-soft"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span>Every hand-off of cash acknowledged and verified</li>
                <li><span class="icon-soft"><i class="bi bi-clipboard-check" aria-hidden="true"></i></span>Complete audit trail for each institution</li>
            </ul>
        </div>

        <div class="login-form-panel">
            <h1 id="login-heading" class="login-heading">Sign in</h1>
            <p class="login-subheading">Use the account issued by your institution.</p>

            <form id="login-form" method="post" action="<?= e(url('login.php')) ?>" novalidate>
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label for="identifier" class="form-label">Username or e-mail</label>
                    <div class="input-icon">
                        <i class="bi bi-person" aria-hidden="true"></i>
                        <input type="text" class="form-control<?= isset($errors['identifier']) ? ' is-invalid' : '' ?>" id="identifier" name="identifier"
                               value="<?= e($identifier) ?>" autocomplete="username" autocapitalize="none" spellcheck="false"
                               maxlength="190" required autofocus aria-describedby="identifier-feedback">
                        <div class="invalid-feedback" id="identifier-feedback"><?= e($errors['identifier'] ?? 'Enter your username or e-mail address.') ?></div>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-icon">
                        <i class="bi bi-lock" aria-hidden="true"></i>
                        <input type="password" class="form-control has-toggle<?= isset($errors['password']) ? ' is-invalid' : '' ?>" id="password" name="password"
                               autocomplete="current-password" maxlength="1024" required aria-describedby="password-feedback">
                        <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Show password" aria-pressed="false">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                        <div class="invalid-feedback" id="password-feedback"><?= e($errors['password'] ?? 'Enter your password.') ?></div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 btn-lg-compact" id="login-submit">
                    <span class="btn-label"><i class="bi bi-box-arrow-in-right me-2" aria-hidden="true"></i>Sign in</span>
                    <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Signing in…</span>
                </button>
            </form>

            <p class="login-help">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                Forgot your password or need an account? Contact your institution administrator.
            </p>
        </div>
    </section>
    <p class="login-footer">&copy; <?= e(date('Y')) ?> <?= e(APP_NAME) ?></p>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
