<?php
/**
 * Role-based sidebar. Included by header.php; uses $user, $context, $activeNav, $roleNames.
 * Visibility here is a convenience only; every page enforces its own permission check.
 */
declare(strict_types=1);

$sections = navigation_sections();
?>
<aside class="app-sidebar" id="app-sidebar" aria-label="Main navigation">
    <div class="sidebar-brand">
        <span class="brand-mark" aria-hidden="true"><i class="bi bi-eye"></i></span>
        <span class="brand-text">
            <span class="brand-name"><?= e(APP_NAME) ?></span>
            <span class="brand-sub">Fund Accountability</span>
        </span>
        <button type="button" class="btn btn-icon sidebar-close d-lg-none" data-sidebar-close aria-label="Close navigation">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>

    <?php if ($context): ?>
        <div class="sidebar-context">
            <span class="sidebar-context-label"><?= $context['type'] === 'platform' ? 'Workspace' : 'Institution' ?></span>
            <span class="sidebar-context-name" title="<?= e($context['label']) ?>"><?= e($context['label']) ?></span>
            <span class="sidebar-context-code"><?= e($context['code']) ?></span>
            <?php if (($context['available_count'] ?? 1) > 1): ?>
                <a class="sidebar-context-switch" href="<?= e(url('select_institution.php')) ?>"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Switch</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <nav class="sidebar-nav">
        <?php foreach ($sections as $title => $items): ?>
            <div class="nav-section">
                <div class="nav-section-title"><?= e($title) ?></div>
                <ul class="nav-list">
                    <?php foreach ($items as $item): ?>
                        <?php $isActive = $item['key'] === $activeNav; ?>
                        <li>
                            <?php if ($item['ready']): ?>
                                <a class="nav-link-item<?= $isActive ? ' active' : '' ?>" href="<?= e(url($item['path'])) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                                    <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i>
                                    <span><?= e($item['label']) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="nav-link-item is-disabled" aria-disabled="true" title="Available in an upcoming release">
                                    <i class="bi <?= e($item['icon']) ?>" aria-hidden="true"></i>
                                    <span><?= e($item['label']) ?></span>
                                    <span class="nav-soon">Soon</span>
                                </span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-user">
        <span class="avatar avatar-sidebar"><?= e(initials($user['full_name'])) ?></span>
        <span class="sidebar-user-text">
            <span class="sidebar-user-name"><?= e($user['full_name']) ?></span>
            <span class="sidebar-user-role"><?= e($roleNames[0] ?? '') ?></span>
        </span>
        <button type="button" class="btn btn-icon sidebar-logout" data-logout aria-label="Sign out" title="Sign out">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
        </button>
    </div>
</aside>
