<?php
/**
 * Shared page for modules scheduled for a later milestone.
 * The page still enforces sign-in, workspace and permission rules, so the
 * access model can be tested now and each module only needs its content later.
 */
declare(strict_types=1);

/**
 * @param array{
 *   title: string, nav: string, section: string, permissions: string[],
 *   context: string, description: string, features: string[]
 * } $page  context is "institution", "platform" or "any".
 */
function render_module_page(array $page): never
{
    $context = auth_context();
    if ($page['context'] === 'institution') {
        require_institution_context();
    } elseif ($page['context'] === 'platform' && $context['type'] !== 'platform') {
        render_error_page(403, 'Platform Only', 'This page is available only in the Platform Administration workspace.');
    }
    require_permission($page['permissions']);

    $pageTitle = $page['title'];
    $activeNav = $page['nav'];
    $breadcrumb = [$context['label'], $page['section']];
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="card panel module-placeholder">
        <div class="card-body">
            <div class="module-placeholder-head">
                <span class="icon-soft icon-soft-lg"><i class="bi bi-tools" aria-hidden="true"></i></span>
                <div>
                    <span class="badge badge-soft-warning mb-1">Planned for an upcoming milestone</span>
                    <h2 class="module-placeholder-title"><?= e($page['title']) ?></h2>
                    <p class="text-muted mb-0"><?= e($page['description']) ?></p>
                </div>
            </div>
            <?php if ($page['features']): ?>
                <div class="subheading mt-4">This page will let you</div>
                <ul class="task-list module-feature-list">
                    <?php foreach ($page['features'] as $feature): ?>
                        <li><i class="bi bi-check2-circle" aria-hidden="true"></i><span><?= e($feature) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <a class="btn btn-outline-primary mt-3" href="<?= e(url('dashboard/index.php')) ?>"><i class="bi bi-grid-1x2 me-1" aria-hidden="true"></i>Back to Dashboard</a>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}
