<?php
/**
 * Secure file attachments (file_attachments + uploads/).
 *
 * - Only PDF and common image types, checked by content (finfo), not by the filename.
 * - Files are stored under random server-side names in uploads/, which .htaccess blocks.
 * - Downloads go through attachments/download.php, which checks the institution and
 *   the permission for the record the file belongs to.
 */
declare(strict_types=1);

const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024;
const ATTACHMENT_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
];

/**
 * Which permissions may download attachments of each record type.
 * Owners (e.g. an advance recipient) are checked separately in attachment_can_view().
 */
const ATTACHMENT_ENTITY_PERMISSIONS = [
    'payment'         => ['collections.view', 'collections.record'],
    'turnover'        => ['turnovers.view'],
    'deposit'         => ['deposits.manage', 'cashflow.view', 'reconciliation.manage'],
    'fund_transfer'   => ['transfers.manage', 'transfers.approve', 'cashflow.view'],
    'expense'         => ['expenses.view', 'expenses.record', 'expenses.approve'],
    'advance_expense' => ['advances.view', 'liquidation.review'],
    'advance_return'  => ['advances.view', 'liquidation.review'],
    'budget'          => ['budgets.view'],
    'product'         => ['products.view'],
    'order_item'      => ['orders.view', 'orders.manage'],
];

/** True when a file was submitted in the given field. */
function has_upload(string $field): bool
{
    return isset($_FILES[$field]) && is_array($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/**
 * Validates and stores an uploaded file, then records it against an entity.
 * Must be called inside the transaction that creates/updates the entity.
 * Returns the file_attachments.id.
 */
function store_attachment(string $field, string $entityType, int $entityId, bool $rejectDuplicate = false): int
{
    if (!isset(ATTACHMENT_ENTITY_PERMISSIONS[$entityType])) {
        throw new InvalidArgumentException('Unknown attachment entity ' . $entityType);
    }
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || is_array($file['error'] ?? null)) {
        fail_user('Please choose one file to upload.');
    }

    $error = (int) $file['error'];
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) $file['size'] > ATTACHMENT_MAX_BYTES) {
        fail_user('The file is too large. The maximum size is 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        fail_user('The file could not be uploaded. Please try again.');
    }
    if ((int) $file['size'] === 0) {
        fail_user('The file is empty.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']) ?: '';
    if (!isset(ATTACHMENT_TYPES[$mime])) {
        fail_user('Only PDF, JPG, PNG or WEBP files are accepted.');
    }

    $institutionId = (int) current_institution_id();
    $checksum = hash_file('sha256', (string) $file['tmp_name']);

    if ($rejectDuplicate && ($used = find_duplicate_document($checksum))) {
        fail_user('This exact document has already been used to support another expense (' . humanize($used['entity_type']) . ' #' . $used['entity_id'] . ').');
    }

    $directory = 'uploads/' . (str_starts_with($entityType, 'payment') || $entityType === 'expense' || $entityType === 'advance_expense' ? 'receipts' : 'attachments')
        . '/' . $institutionId . '/' . date('Y/m');
    $absoluteDirectory = APP_ROOT . '/' . $directory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('Cannot create upload directory');
    }
    $storageKey = $directory . '/' . bin2hex(random_bytes(20)) . '.' . ATTACHMENT_TYPES[$mime];
    if (!move_uploaded_file((string) $file['tmp_name'], APP_ROOT . '/' . $storageKey)) {
        throw new RuntimeException('Cannot store uploaded file');
    }

    // Keep the user's filename only as display text, stripped of path and control characters.
    $originalName = preg_replace('/[^\pL\pN ._()-]+/u', '_', basename((string) $file['name'])) ?: 'document';

    try {
        db_query(
            'INSERT INTO file_attachments (institution_id, uploaded_by_membership_id, entity_type, entity_id, storage_key, original_filename, mime_type, file_size, checksum)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$institutionId, current_membership_id(), $entityType, $entityId, $storageKey, mb_substr($originalName, 0, 255), $mime, (int) $file['size'], $checksum]
        );
    } catch (Throwable $exception) {
        @unlink(APP_ROOT . '/' . $storageKey);
        throw $exception;
    }
    $attachmentId = (int) db()->lastInsertId();
    audit_log('attachment.uploaded', $entityType, $entityId, $institutionId, (int) auth_user()['id'], ['attachment_id' => $attachmentId, 'file' => $originalName, 'size' => (int) $file['size']]);
    return $attachmentId;
}

/**
 * The record already supported by a document with this checksum, if any.
 * Used to stop the same receipt from being liquidated or expensed twice.
 */
function find_duplicate_document(string $checksum): ?array
{
    return db_one(
        "SELECT entity_type, entity_id FROM file_attachments WHERE institution_id = ? AND checksum = ? AND entity_type IN ('expense', 'advance_expense') LIMIT 1",
        [current_institution_id(), $checksum]
    );
}

/** Attachments recorded for an entity in the current institution. */
function attachments_for(string $entityType, int $entityId): array
{
    return db_all(
        "SELECT a.id, a.original_filename, a.mime_type, a.file_size, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by
         FROM file_attachments a
         JOIN institution_memberships m ON m.id = a.uploaded_by_membership_id AND m.institution_id = a.institution_id
         JOIN users u ON u.id = m.user_id
         WHERE a.institution_id = ? AND a.entity_type = ? AND a.entity_id = ?
         ORDER BY a.id",
        [current_institution_id(), $entityType, $entityId]
    );
}

/** Whether the current user may open an attachment row. */
function attachment_can_view(array $attachment): bool
{
    if ((int) $attachment['institution_id'] !== current_institution_id()) {
        return false;
    }
    $type = (string) $attachment['entity_type'];
    if (can_any(ATTACHMENT_ENTITY_PERMISSIONS[$type] ?? [])) {
        return true;
    }
    if ((int) $attachment['uploaded_by_membership_id'] === current_membership_id()) {
        return true;
    }
    // Advance recipients may see the documents of their own advances.
    if (in_array($type, ['advance_expense', 'advance_return'], true)) {
        $table = $type === 'advance_expense' ? 'cash_advance_expenses' : 'cash_advance_returns';
        return (bool) db_value(
            "SELECT 1 FROM {$table} x JOIN cash_advances a ON a.id = x.cash_advance_id AND a.institution_id = x.institution_id
             WHERE x.institution_id = ? AND x.id = ? AND a.recipient_membership_id = ?",
            [current_institution_id(), $attachment['entity_id'], current_membership_id()]
        );
    }
    return false;
}

/** HTML list of attachment links for a record. */
function attachment_list_html(array $attachments): string
{
    if (!$attachments) {
        return '<p class="text-muted-sm mb-0">No documents attached.</p>';
    }
    $html = '<ul class="attachment-list">';
    foreach ($attachments as $attachment) {
        $icon = $attachment['mime_type'] === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image';
        $html .= '<li><i class="bi ' . $icon . '" aria-hidden="true"></i>'
            . '<a href="' . e(url('attachments/download.php?id=' . $attachment['id'])) . '" target="_blank" rel="noopener">' . e($attachment['original_filename']) . '</a>'
            . '<span class="text-muted-sm">' . e(number_format($attachment['file_size'] / 1024, 0)) . ' KB · ' . e($attachment['uploaded_by']) . ' · ' . e(format_date($attachment['created_at'])) . '</span></li>';
    }
    return $html . '</ul>';
}

/** File input with the accepted types. */
function attachment_input(string $name = 'attachment', string $label = 'Supporting document', bool $required = false): string
{
    return '<label class="form-label" for="' . e($name) . '">' . e($label) . ($required ? '' : ' <span class="text-muted-sm">(optional)</span>') . '</label>'
        . '<input class="form-control" type="file" id="' . e($name) . '" name="' . e($name) . '" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"' . ($required ? ' required' : '') . '>'
        . '<div class="form-text">PDF, JPG, PNG or WEBP, up to 5 MB.</div>';
}
