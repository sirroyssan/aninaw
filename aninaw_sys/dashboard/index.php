<?php
/**
 * Role-based dashboard. The panels shown depend on the permissions the user's roles
 * grant in the active context; a user with several roles sees each role's panels.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/dashboard_data.php';

$context = auth_context();
if ($context['type'] === 'platform') {
    require_permission('platform.institutions.view');
} else {
    require_permission('dashboard.view');
    generate_due_reminders((int) current_institution_id());
}

$currency = $context['currency_code'] ?? 'PHP';
$panels = dashboard_panels();
$firstName = auth_user()['first_name'];

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
$breadcrumb = [$context['label']];
require APP_ROOT . '/includes/header.php';
?>
<section class="welcome-banner">
    <div>
        <h2 class="welcome-title">Good <?= e((int) date('G') < 12 ? 'morning' : ((int) date('G') < 18 ? 'afternoon' : 'evening')) ?>, <?= e($firstName) ?></h2>
        <p class="welcome-text">
            <?= $context['type'] === 'platform'
                ? 'Platform overview across all institutions.'
                : 'Here is what needs your attention in ' . e($context['label']) . ' today.' ?>
        </p>
    </div>
    <div class="welcome-date"><i class="bi bi-calendar3 me-2" aria-hidden="true"></i><?= e(date('l, F j, Y')) ?></div>
</section>

<?php if ($panels === []): ?>
    <div class="card panel">
        <div class="card-body">
            <?= empty_state('bi-layout-text-window', 'Nothing to show yet', 'Your roles in this institution do not include any dashboard sections. Contact your institution administrator if you need additional access.') ?>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($panels as $panel): ?>
    <?php
    $result = dashboard_load($panel);
    $panelData = $result['data'];
    $panelFailed = $result['error'];
    require APP_ROOT . '/includes/dashboard/' . $panel . '.php';
    ?>
<?php endforeach; ?>

<?php require APP_ROOT . '/includes/footer.php'; ?>
