<?php
/**
 * Page head and top navigation.
 *
 * Variables (set before including):
 *   $pageTitle  string  Title shown in the header and <title>.
 *   $activeNav  string  Key of the active sidebar item (see navigation_sections()).
 *   $layout     string  "app" (default, sidebar + header) or "auth" (centered pages such as login).
 *   $breadcrumb array   Optional list of labels shown above the title.
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? APP_NAME;
$activeNav = $activeNav ?? '';
$layout = $layout ?? 'app';
$breadcrumb = $breadcrumb ?? [];

$user = auth_user();
$context = auth_context();
$isAppLayout = $layout === 'app' && $user !== null;

$roleNames = $context ? array_column($context['roles'], 'role_name') : [];
$primaryRole = $roleNames[0] ?? '';

// Unread notifications for the current institution, when the user is working in one.
$notifications = [];
$unreadCount = 0;
if ($isAppLayout && current_institution_id() !== null) {
    try {
        $unreadCount = (int) db_value(
            'SELECT COUNT(*) FROM notifications WHERE institution_id = ? AND recipient_user_id = ? AND read_at IS NULL',
            [current_institution_id(), $user['id']]
        );
        $notifications = db_all(
            'SELECT title, message, notification_type, read_at, created_at FROM notifications
             WHERE institution_id = ? AND recipient_user_id = ?
             ORDER BY created_at DESC, id DESC LIMIT 5',
            [current_institution_id(), $user['id']]
        );
    } catch (Throwable $exception) {
        log_error('Notifications failed to load', ['message' => $exception->getMessage()]);
    }
}

$bodyData = [
    'base-url'     => app_base_path(),
    'flash'        => json_encode(take_flashes(), JSON_UNESCAPED_UNICODE),
    'idle-timeout' => $user ? (string) SESSION_IDLE_TIMEOUT : '',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="theme-color" content="#12372A">
    <title><?= e($pageTitle === APP_NAME ? APP_NAME : $pageTitle . ' · ' . APP_NAME) ?></title>
    <link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e(asset('images/logo.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <?php if ($isAppLayout): ?>
        <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
    <?php endif; ?>
</head>
<body class="<?= $isAppLayout ? 'app-body' : 'auth-body' ?>"<?php foreach ($bodyData as $bodyDataName => $bodyDataValue): ?> data-<?= e($bodyDataName) ?>="<?= e($bodyDataValue) ?>"<?php endforeach; ?>>
<?php if ($isAppLayout): ?>
<a class="skip-link" href="#main-content">Skip to main content</a>
<div class="app-shell">
    <?php require APP_ROOT . '/includes/sidebar.php'; ?>
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <div class="app-main">
        <header class="app-header">
            <button type="button" class="btn btn-icon d-lg-none" data-sidebar-toggle aria-label="Open navigation" aria-controls="app-sidebar" aria-expanded="false">
                <i class="bi bi-list" aria-hidden="true"></i>
            </button>

            <div class="header-title">
                <?php if ($breadcrumb): ?>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-0">
                            <?php foreach ($breadcrumb as $crumb): ?>
                                <li class="breadcrumb-item"><?= e($crumb) ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </nav>
                <?php endif; ?>
                <h1 class="page-title"><?= e($pageTitle) ?></h1>
            </div>

            <div class="header-actions">
                <?php if ($context): ?>
                    <span class="context-chip d-none d-md-inline-flex" title="<?= e($context['label']) ?>">
                        <i class="bi <?= $context['type'] === 'platform' ? 'bi-globe2' : 'bi-building' ?>" aria-hidden="true"></i>
                        <span class="text-truncate"><?= e($context['label']) ?></span>
                    </span>
                <?php endif; ?>

                <?php if (current_institution_id() !== null): ?>
                    <div class="dropdown">
                        <button class="btn btn-icon position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications<?= $unreadCount ? ' (' . $unreadCount . ' unread)' : '' ?>">
                            <i class="bi bi-bell" aria-hidden="true"></i>
                            <?php if ($unreadCount > 0): ?>
                                <span class="notification-dot"><?= e($unreadCount > 9 ? '9+' : (string) $unreadCount) ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end notification-menu shadow-sm">
                            <div class="dropdown-header">Notifications</div>
                            <?php if (!$notifications): ?>
                                <div class="notification-empty">
                                    <i class="bi bi-bell-slash" aria-hidden="true"></i>
                                    <span>You're all caught up.</span>
                                </div>
                            <?php endif; ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item<?= $notification['read_at'] ? '' : ' is-unread' ?>">
                                    <div class="notification-title"><?= e($notification['title']) ?></div>
                                    <div class="notification-message"><?= e($notification['message']) ?></div>
                                    <div class="notification-time"><?= e(format_datetime($notification['created_at'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn user-menu-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="avatar"><?= e(initials($user['full_name'])) ?></span>
                        <span class="user-menu-text d-none d-sm-flex">
                            <span class="user-menu-name"><?= e($user['full_name']) ?></span>
                            <span class="user-menu-role"><?= e($primaryRole) ?><?= count($roleNames) > 1 ? ' +' . (count($roleNames) - 1) : '' ?></span>
                        </span>
                        <i class="bi bi-chevron-down small text-secondary" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow-sm user-menu">
                        <div class="px-3 py-2">
                            <div class="fw-semibold"><?= e($user['full_name']) ?></div>
                            <div class="small text-secondary"><?= e($user['email']) ?></div>
                        </div>
                        <?php if ($roleNames): ?>
                            <div class="px-3 pb-2 d-flex flex-wrap gap-1">
                                <?php foreach ($roleNames as $roleName): ?>
                                    <span class="badge badge-soft-success"><?= e($roleName) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <?php if ($context && ($context['available_count'] ?? 1) > 1): ?>
                            <a class="dropdown-item" href="<?= e(url('select_institution.php')) ?>"><i class="bi bi-arrow-repeat me-2" aria-hidden="true"></i>Switch institution</a>
                        <?php endif; ?>
                        <a class="dropdown-item" href="<?= e(url('account.php')) ?>"><i class="bi bi-person-gear me-2" aria-hidden="true"></i>My account</a>
                        <button type="button" class="dropdown-item text-danger" data-logout><i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i>Sign out</button>
                    </div>
                </div>
            </div>
        </header>

        <main id="main-content" class="app-content" tabindex="-1">
<?php else: ?>
<main id="main-content" class="auth-main">
<?php endif; ?>
