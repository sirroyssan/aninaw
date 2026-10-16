<?php
/**
 * Import participants from a CSV file (all-or-nothing). ?template=1 downloads a template.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission('organization.manage');

if (get_str('template') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="aninaw_participants_template.csv"');
    echo "participant_number,first_name,middle_name,last_name,email,phone,participant_type,group_code\n";
    echo "2026-0001,Juan,Santos,Dela Cruz,juan@example.com,09171234567,student,BSCS-3A\n";
    exit;
}

if (is_post()) {
    handle_post(function () {
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            fail_user('Choose a CSV file to upload.');
        }
        if ((int) $file['size'] > 2 * 1024 * 1024) {
            fail_user('The CSV file is larger than 2 MB. Split it into smaller files.');
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        if ($extension !== 'csv' || !in_array($mime, ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'], true)) {
            fail_user('Only .csv files are accepted. In Excel use File → Save As → CSV UTF-8.');
        }
        $result = import_participants_csv((string) $file['tmp_name'], post_str('skip_existing', 1) === '1');
        flash('success', 'Import complete', "{$result['created']} registered, {$result['skipped']} already existed, {$result['grouped']} added to groups.");
        return 'participants/index.php';
    });
}

$pageTitle = 'Import People';
$activeNav = 'participants';
$breadcrumb = [auth_context()['label'], 'People'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Import participants from a CSV file. Have a class list PDF from the enrollment system? Import it directly.',
    button_link('participants/import_pdf.php', 'Import Class List (PDF)', 'bi-file-earmark-pdf', 'btn-outline-primary')) ?>
<div class="row g-3" style="max-width: 1100px">
    <div class="col-lg-7">
        <form method="post" enctype="multipart/form-data" class="card panel form-card">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <div class="card-header"><h3>Upload CSV</h3></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label required" for="csv">CSV file</label>
                    <input class="form-control" type="file" id="csv" name="csv" accept=".csv,text/csv" required>
                    <div class="form-text">UTF-8 CSV, up to 2 MB and 5,000 rows.</div>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="skip_existing" name="skip_existing" value="1" checked>
                    <label class="form-check-label" for="skip_existing">Skip ID numbers that are already registered (they can still be added to the group in the file)</label>
                </div>
                <div class="notice notice-info"><i class="bi bi-shield-check" aria-hidden="true"></i><div>Every row is checked first. If any row has a problem nothing is imported, and you get the list of lines to fix.</div></div>
                <div class="form-actions">
                    <a class="btn btn-outline-secondary" href="<?= e(url('participants/index.php')) ?>">Cancel</a>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-upload me-1" aria-hidden="true"></i>Import</button>
                </div>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card panel">
            <div class="card-header"><h3>Columns</h3><a class="btn btn-outline-primary btn-xs" href="<?= e(url('participants/import.php?template=1')) ?>"><i class="bi bi-download me-1" aria-hidden="true"></i>Template</a></div>
            <ul class="metric-list card-body">
                <li><span><code>participant_number</code></span><span class="badge badge-soft-danger">required</span></li>
                <li><span><code>first_name</code></span><span class="badge badge-soft-danger">required</span></li>
                <li><span><code>last_name</code></span><span class="badge badge-soft-danger">required</span></li>
                <li><span><code>middle_name</code>, <code>email</code>, <code>phone</code></span><span class="text-muted-sm">optional</span></li>
                <li><span><code>participant_type</code></span><span class="text-muted-sm">student, employee, member, other</span></li>
                <li><span><code>group_code</code></span><span class="text-muted-sm">adds to an existing group</span></li>
            </ul>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
