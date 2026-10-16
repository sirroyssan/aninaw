<?php
/**
 * Store reports hub: inventory, order, printing and profit reports the user may open.
 * Reachable by store staff who do not hold the general reports permission.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/reports.php';
require_institution_context();
require_permission(['products.view', 'inventory.view', 'inventory.manage', 'orders.view', 'orders.manage', 'reports.view']);

$reports = array_filter(report_catalog()['Store'], static fn($r) => can_any($r[4]));

$pageTitle = 'Store Reports';
$activeNav = 'store-reports';
$breadcrumb = [auth_context()['label'], 'Store'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Inventory, order, printing and profit reports. Each report can be filtered, printed and exported to CSV.',
    can('reports.view') ? button_link('reports/index.php', 'All Reports', 'bi-bar-chart', 'btn-outline-secondary') : '') ?>
<?php if (!$reports): ?>
    <div class="card panel"><div class="card-body"><?= empty_state('bi-bar-chart', 'No store reports available for your role') ?></div></div>
<?php else: ?>
    <div class="report-grid mb-4">
        <?php foreach ($reports as [$title, $description, $icon, $path]): ?>
            <a class="card panel report-card" href="<?= e(url($path)) ?>">
                <span class="report-card-icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></span>
                <span><span class="report-card-title"><?= e($title) ?></span><span class="report-card-text"><?= e($description) ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
