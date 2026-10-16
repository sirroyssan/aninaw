<?php
/**
 * In-app notifications (notifications table). No e-mail or SMS is assumed.
 * Recipients are always members of the institution the notification belongs to.
 */
declare(strict_types=1);

/** Notifies one user, if they are an active member of the institution. */
function notify_user(int $institutionId, int $userId, string $type, string $title, string $message): void
{
    try {
        $isMember = db_value(
            "SELECT 1 FROM institution_memberships WHERE institution_id = ? AND user_id = ? AND membership_status = 'active'",
            [$institutionId, $userId]
        );
        if (!$isMember) {
            return;
        }
        db_query(
            'INSERT INTO notifications (institution_id, recipient_user_id, notification_type, title, message) VALUES (?, ?, ?, ?, ?)',
            [$institutionId, $userId, mb_substr($type, 0, 80), mb_substr($title, 0, 180), $message]
        );
    } catch (Throwable $exception) {
        log_error('Notification failed', ['type' => $type, 'message' => $exception->getMessage()]);
    }
}

function notify_membership(int $institutionId, int $membershipId, string $type, string $title, string $message): void
{
    $userId = db_value('SELECT user_id FROM institution_memberships WHERE institution_id = ? AND id = ?', [$institutionId, $membershipId]);
    if ($userId) {
        notify_user($institutionId, (int) $userId, $type, $title, $message);
    }
}

/** Notifies every active member whose roles grant a permission, except the acting user. */
function notify_permission(int $institutionId, string $permission, string $type, string $title, string $message): void
{
    $actor = auth_user() ? (int) auth_user()['id'] : 0;
    $userIds = db_query(
        "SELECT DISTINCT m.user_id
         FROM institution_memberships m
         JOIN users u ON u.id = m.user_id AND u.status = 'active'
         JOIN membership_roles mr ON mr.membership_id = m.id
         JOIN role_permissions rp ON rp.role_id = mr.role_id
         JOIN permissions p ON p.id = rp.permission_id AND p.permission_code = ?
         WHERE m.institution_id = ? AND m.membership_status = 'active'",
        [$permission, $institutionId]
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($userIds as $userId) {
        if ((int) $userId !== $actor) {
            notify_user($institutionId, (int) $userId, $type, $title, $message);
        }
    }
}

/**
 * Creates deadline reminders (collection and liquidation deadlines within 3 days)
 * at most once per day per institution. Each reminder is sent once per record and day.
 */
function generate_due_reminders(int $institutionId): void
{
    $today = date('Y-m-d');
    if (institution_setting($institutionId, 'reminders.last_run') === $today) {
        return;
    }
    try {
        $soon = date('Y-m-d', strtotime('+3 days'));

        $events = db_all(
            "SELECT e.id, e.event_name, e.collection_deadline FROM events e
             WHERE e.institution_id = ? AND e.status = 'collecting' AND e.collection_deadline BETWEEN ? AND ?",
            [$institutionId, $today, $soon]
        );
        foreach ($events as $event) {
            $collectors = db_query(
                "SELECT DISTINCT m.user_id FROM event_group_assignments a
                 JOIN institution_memberships m ON m.id = a.collector_membership_id AND m.institution_id = a.institution_id
                 WHERE a.institution_id = ? AND a.event_id = ? AND a.status = 'active'",
                [$institutionId, $event['id']]
            )->fetchAll(PDO::FETCH_COLUMN);
            foreach ($collectors as $userId) {
                notify_user($institutionId, (int) $userId, 'collection_deadline', 'Collection deadline approaching',
                    $event['event_name'] . ' collections are due on ' . format_date($event['collection_deadline']) . '.');
            }
        }

        $advances = db_all(
            "SELECT a.advance_number, a.liquidation_deadline, m.user_id FROM cash_advances a
             JOIN institution_memberships m ON m.id = a.recipient_membership_id AND m.institution_id = a.institution_id
             WHERE a.institution_id = ? AND a.status IN ('released', 'partially_liquidated', 'returned_for_correction')
               AND a.liquidation_deadline BETWEEN ? AND ?",
            [$institutionId, $today, $soon]
        );
        foreach ($advances as $advance) {
            notify_user($institutionId, (int) $advance['user_id'], 'liquidation_deadline', 'Liquidation deadline approaching',
                'Cash advance ' . $advance['advance_number'] . ' must be liquidated by ' . format_date($advance['liquidation_deadline']) . '.');
        }

        // Advances past their deadline become overdue (a status change, not a financial posting).
        db_query(
            "UPDATE cash_advances SET status = 'overdue'
             WHERE institution_id = ? AND status IN ('released', 'partially_liquidated') AND liquidation_deadline < ?",
            [$institutionId, $today]
        );

        $userId = auth_user() ? (int) auth_user()['id'] : null;
        db_query(
            'INSERT INTO institution_settings (institution_id, setting_key, setting_value, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [$institutionId, 'reminders.last_run', $today, $userId]
        );
    } catch (Throwable $exception) {
        log_error('Reminder generation failed', ['message' => $exception->getMessage()]);
    }
}
