<?php
/**
 * Serves an attachment after checking the institution and the permission for the
 * record it belongs to. Files are never reachable by a direct URL (uploads/ is blocked).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_institution_context();

$attachment = db_one('SELECT * FROM file_attachments WHERE institution_id = ? AND id = ?', [current_institution_id(), require_get_id()]);
if (!$attachment || !attachment_can_view($attachment)) {
    // Same response for "missing" and "not allowed" so IDs cannot be probed.
    render_error_page(404, 'Document Not Found', 'The document does not exist or you do not have access to it.');
}

$path = realpath(APP_ROOT . '/' . $attachment['storage_key']);
$uploads = realpath(APP_ROOT . '/uploads');
if ($path === false || $uploads === false || !str_starts_with($path, $uploads . DIRECTORY_SEPARATOR) || !is_file($path)) {
    log_error('Attachment file missing', ['attachment_id' => $attachment['id']]);
    render_error_page(404, 'Document Not Found', 'The stored file could not be found. Please contact your administrator.');
}

audit_log('attachment.viewed', (string) $attachment['entity_type'], (int) $attachment['entity_id'], current_institution_id(), (int) auth_user()['id'], ['attachment_id' => (int) $attachment['id']]);

$inline = get_str('download') === '' && in_array($attachment['mime_type'], array_keys(ATTACHMENT_TYPES), true);
header('Content-Type: ' . $attachment['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace(['"', "\r", "\n"], '', $attachment['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
readfile($path);
