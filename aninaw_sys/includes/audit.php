<?php
/**
 * Audit trail writer for the `audit_logs` table.
 */
declare(strict_types=1);

/**
 * Records an audit event. Failures are logged privately and never interrupt the request,
 * so a logging problem cannot lock users out.
 */
function audit_log(
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?int $institutionId = null,
    ?int $actorUserId = null,
    ?array $afterData = null,
    ?array $beforeData = null,
    ?string $reason = null
): void {
    $afterData = array_merge($afterData ?? [], ['ip' => client_ip(), 'user_agent' => client_user_agent()]);

    try {
        db_query(
            'INSERT INTO audit_logs (institution_id, actor_user_id, action, entity_type, entity_id, before_data, after_data, reason, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $institutionId,
                $actorUserId,
                mb_substr($action, 0, 100),
                mb_substr($entityType, 0, 100),
                $entityId,
                $beforeData === null ? null : json_encode($beforeData, JSON_UNESCAPED_UNICODE),
                json_encode($afterData, JSON_UNESCAPED_UNICODE),
                $reason,
                request_id(),
            ]
        );
    } catch (Throwable $exception) {
        log_error('Audit log write failed', ['action' => $action, 'message' => $exception->getMessage()]);
    }
}
