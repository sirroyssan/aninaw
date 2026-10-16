<?php
/**
 * In-app notifications for the current institution: list, unread filter, mark as read.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_institution_context();

$institutionId = (int) current_institution_id();
$userId = (int) auth_user()['id'];

if (is_post()) {
    handle_post(function () use ($institutionId, $userId) {
        $id = post_int('id');
        if ($id) {
            db_query('UPDATE notifications SET read_at = NOW() WHERE institution_id = ? AND recipient_user_id = ? AND id = ? AND read_at IS NULL', [$institutionId, $userId, $id]);
        } else {
            db_query('UPDATE notifications SET read_at = NOW() WHERE institution_id = ? AND recipient_user_id = ? AND read_at IS NULL', [$institutionId, $userId]);
            flash('success', 'All notifications marked as read');
        }
        $back = post_str('back', 300);
        return $back !== '' && str_starts_with($back, 'notifications/') ? $back : 'notifications/index.php';
    }, false);
}

generate_due_reminders($institutionId);
$unreadOnly = get_str('show', 10) === 'unread';
$where = 'institution_id = ? AND recipient_user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '');
$params = [$institutionId, $userId];
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM notifications WHERE {$where}", $params), 25);
$rows = db_all("SELECT * FROM notifications WHERE {$where} ORDER BY created_at DESC, id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}", $params);
$unread = (int) db_value('SELECT COUNT(*) FROM notifications WHERE institution_id = ? AND recipient_user_id = ? AND read_at IS NULL', $params);
$icons = [
    'advance' => 'bi-wallet2', 'liquidation' => 'bi-journal-check', 'liquidation_deadline' => 'bi-alarm', 'collection_deadline' => 'bi-alarm', 'collection_assigned' => 'bi-cash-coin',
    'turnover' => 'bi-arrow-left-right', 'discrepancy' => 'bi-exclamation-diamond', 'expense' => 'bi-receipt', 'deposit' => 'bi-bank', 'transfer' => 'bi-shuffle',
    'budget' => 'bi-clipboard-data', 'ledger' => 'bi-journal-text', 'account' => 'bi-person-gear', 'payment' => 'bi-cash', 'custody' => 'bi-safe', 'fund' => 'bi-wallet',
];

$pageTitle = 'Notifications';
$activeNav = '';
$breadcrumb = [auth_context()['label']];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($unread ? number_format($unread) . ' unread notification' . ($unread === 1 ? '' : 's') . '.' : 'You\'re all caught up.',
    $unread ? '<form method="post" class="d-inline">' . csrf_field() . '<button class="btn btn-outline-primary" type="submit"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Mark all as read</button></form>' : '') ?>
<div class="card panel">
    <ul class="nav nav-tabs-clean px-3 pt-2 mb-0">
        <li class="nav-item"><a class="nav-link<?= $unreadOnly ? '' : ' active' ?>" href="<?= e(url('notifications/index.php')) ?>">All</a></li>
        <li class="nav-item"><a class="nav-link<?= $unreadOnly ? ' active' : '' ?>" href="<?= e(url('notifications/index.php?show=unread')) ?>">Unread<?= $unread ? ' (' . e((string) $unread) . ')' : '' ?></a></li>
    </ul>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-bell-slash', $unreadOnly ? 'No unread notifications' : 'No notifications yet') ?></div>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($rows as $row): ?>
                <li class="list-group-item d-flex gap-3 align-items-start py-3<?= $row['read_at'] ? '' : ' notification-unread' ?>">
                    <span class="report-card-icon"><i class="bi <?= e($icons[$row['notification_type']] ?? 'bi-bell') ?>" aria-hidden="true"></i></span>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= e($row['title']) ?><?= $row['read_at'] ? '' : ' <span class="badge badge-soft-success">New</span>' ?></div>
                        <div><?= e($row['message']) ?></div>
                        <div class="text-muted-sm"><?= e(format_datetime($row['created_at'])) ?></div>
                    </div>
                    <?php if (!$row['read_at']): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="back" value="<?= e('notifications/index.php' . ($unreadOnly ? '?show=unread' : '')) ?>"><button class="btn btn-outline-secondary btn-xs" type="submit">Mark read</button></form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
