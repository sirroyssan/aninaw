<?php
/**
 * Error page template. Expects $status, $title, $message, $icon, $loggedIn from render_error_page().
 */
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($status . ' · ' . $title . ' · ' . APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
</head>
<body class="error-body">
<main class="error-card" role="main">
    <div class="error-icon"><i class="bi <?= e($icon) ?>" aria-hidden="true"></i></div>
    <p class="error-code"><?= e((string) $status) ?></p>
    <h1 class="error-title"><?= e($title) ?></h1>
    <p class="error-message"><?= e($message) ?></p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
        <?php if ($loggedIn): ?>
            <a class="btn btn-primary" href="<?= e(url('dashboard.php')) ?>"><i class="bi bi-grid-1x2 me-1" aria-hidden="true"></i>Back to Dashboard</a>
        <?php else: ?>
            <a class="btn btn-primary" href="<?= e(url('login.php')) ?>"><i class="bi bi-box-arrow-in-right me-1" aria-hidden="true"></i>Go to Sign In</a>
        <?php endif; ?>
    </div>
    <p class="error-reference">Reference: <?= e(request_id()) ?></p>
</main>
</body>
</html>
