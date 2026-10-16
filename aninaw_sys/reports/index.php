<?php
/**
 * Reports hub: every financial report the user may open, grouped by area.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/reports.php';
require_institution_context();
require_permission('reports.view');

$pageTitle = 'Reports';
$activeNav = 'reports';
$breadcrumb = [auth_context()['label'], 'Oversight'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Reports use posted, authorised data. Each report states its period, filters and the statuses it includes; pending items are always shown apart from posted totals.') ?>
<?php foreach (report_catalog() as $group => $reports): $visible = array_filter($reports, static fn($r) => can_any($r[4])); ?>
    <?php if (!$visible) { continue; } ?>
    <h3 class="section-title"><?= e($group) ?></h3>
    <div class="report-grid mb-4">
        <?php foreach ($visible as [$title, $description, $icon, $path]): ?>
            <a class="card panel report-card" href="<?= e(url($path)) ?>">
                <span class="report-card-icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></span>
                <span><span class="report-card-title"><?= e($title) ?></span><span class="report-card-text"><?= e($description) ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
