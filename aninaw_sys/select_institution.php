<?php
/**
 * Lets a user with several memberships (or platform access) choose where to work.
 * Only contexts read from the database for this user can be selected.
 */
declare(strict_types=1);

define('AUTH_ALLOW_NO_CONTEXT', true);
require_once __DIR__ . '/includes/auth_check.php';

$user = auth_user();
$contexts = auth_available_contexts((int) $user['id']);

if (is_post()) {
    require_csrf();
    $requested = (string) ($_POST['context'] ?? '');

    if (auth_select_context((int) $user['id'], $requested)) {
        flash('success', 'Workspace selected', 'You are now working in ' . $contexts[$requested]['label'] . '.');
        $destination = safe_redirect_path($_SESSION['intended_path'] ?? null);
        unset($_SESSION['intended_path']);
        redirect($destination);
    }

    flash('error', 'Not available', 'You do not have access to the selected institution.');
    redirect('select_institution.php');
}

if ($contexts === []) {
    // All access was removed after sign-in.
    auth_end_session('auth.session_no_access');
    start_secure_session();
    flash('warning', 'No active access', 'Your account no longer has access to any institution. Please contact your institution administrator.');
    redirect('login.php');
}

$currentKey = auth_context()['key'] ?? null;
$pageTitle = 'Choose Workspace';
$layout = 'auth';
require APP_ROOT . '/includes/header.php';
?>
<div class="select-wrapper">
    <div class="select-header">
        <span class="brand-mark brand-mark-lg" aria-hidden="true"><i class="bi bi-eye"></i></span>
        <h1 class="select-title">Welcome, <?= e($user['first_name']) ?></h1>
        <p class="select-subtitle">You have access to more than one workspace. Choose where you want to work.</p>
    </div>

    <div class="select-list">
        <?php foreach ($contexts as $key => $context): ?>
            <form method="post" action="<?= e(url('select_institution.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="context" value="<?= e($key) ?>">
                <button type="submit" class="select-option<?= $key === $currentKey ? ' is-current' : '' ?>">
                    <span class="icon-soft icon-soft-lg"><i class="bi <?= $context['type'] === 'platform' ? 'bi-globe2' : 'bi-building' ?>" aria-hidden="true"></i></span>
                    <span class="select-option-body">
                        <span class="select-option-name"><?= e($context['label']) ?></span>
                        <span class="select-option-meta"><?= e($context['code']) ?><?= !empty($context['institution_type']) ? ' · ' . e($context['institution_type']) : '' ?></span>
                        <span class="select-option-roles">
                            <?php foreach ($context['roles'] as $role): ?>
                                <span class="badge badge-soft-success"><?= e($role['role_name']) ?></span>
                            <?php endforeach; ?>
                        </span>
                    </span>
                    <?php if ($key === $currentKey): ?>
                        <span class="badge badge-soft-neutral">Current</span>
                    <?php else: ?>
                        <i class="bi bi-chevron-right text-secondary" aria-hidden="true"></i>
                    <?php endif; ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>

    <div class="text-center mt-4">
        <form id="logout-form" method="post" action="<?= e(url('logout.php')) ?>" class="d-inline">
            <?= csrf_field() ?>
            <button type="button" class="btn btn-link text-secondary" data-logout><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Sign out</button>
        </form>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
