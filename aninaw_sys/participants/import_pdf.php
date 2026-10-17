<?php
/**
 * Import students from a PDF class list (e.g. the enrollment system's printed section list).
 *
 * Step 1 (upload): the PDF's text is read on the server; nothing is saved and the file is
 * not kept. Step 2 (review): the extracted students are shown for correction, with ID
 * numbers already registered marked. Step 3 (import): the confirmed rows are validated
 * again and registered in one transaction, optionally into a section group.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/class_list.php';
require_institution_context();
require_permission('organization.manage');

$institutionId = (int) current_institution_id();
$review = null;   // ['meta', 'rows', 'warnings', 'source']
$error = null;
$groupInput = [];

/** Rows for the review table with the registered participant (if any) for each ID number. */
$withExisting = static function (array $rows) use ($institutionId): array {
    $numbers = array_values(array_unique(array_filter(array_column($rows, 'participant_number'), 'is_string')));
    $existing = [];
    foreach (array_chunk($numbers, 500) as $chunk) {
        $found = db_all('SELECT id, participant_number, first_name, middle_name, last_name FROM participants WHERE institution_id = ? AND participant_number IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', [$institutionId, ...$chunk]);
        foreach ($found as $row) {
            $existing[$row['participant_number']] = $row;
        }
    }
    foreach ($rows as &$row) {
        $row['existing'] = $existing[$row['participant_number']] ?? null;
    }
    return $rows;
};

/** Short, plain header values carried from the review form back to the page. */
$cleanMeta = static function (mixed $meta): array {
    $clean = [];
    foreach (is_array($meta) ? $meta : [] as $key => $value) {
        if (is_string($key) && in_array($key, CLASS_LIST_LABELS, true) && is_scalar($value)) {
            $clean[$key] = mb_substr(trim((string) $value), 0, 120);
        }
    }
    return $clean;
};

if (is_post()) {
    require_csrf();
    $action = post_str('action', 10);

    if ($action === 'read') {
        try {
            $file = $_FILES['pdf'] ?? null;
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
                fail_user(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'The PDF is too large.' : 'Choose the class list PDF to upload.');
            }
            if ((int) $file['size'] > CLASS_LIST_MAX_BYTES) {
                fail_user('The PDF is larger than 10 MB.');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
            if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'pdf' || $mime !== 'application/pdf') {
                fail_user('Only PDF files are accepted here. For Excel lists, save as CSV and use Import CSV.');
            }
            $parsed = read_class_list_pdf((string) $file['tmp_name']);
            $rows = array_map(static fn($s) => [
                'no' => $s['no'], 'sex' => $s['sex'], 'participant_number' => $s['participant_number'], 'raw_name' => $s['raw_name'], 'review' => $s['review'],
                'last_name' => person_name_case($s['last_name']),
                'first_name' => person_name_case($s['first_name']),
                'middle_name' => person_name_case($s['middle_name']),
                'include' => true,
            ], $parsed['students']);
            $review = ['meta' => $parsed['meta'], 'rows' => $rows, 'warnings' => $parsed['warnings'], 'source' => mb_substr(basename((string) $file['name']), 0, 180)];
            audit_log('participant.class_list_read', 'participant', null, $institutionId, (int) auth_user()['id'], ['source' => $review['source'], 'students' => count($rows), 'pages' => $parsed['pages']]);
        } catch (UserFacingException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($action === 'import') {
        require_idempotency();
        $meta = $cleanMeta(json_decode(post_str('meta_json', 4000), true));
        $source = post_str('source', 180);
        $groupInput = [
            'mode' => post_str('group_mode', 10), 'group_id' => post_str('group_id', 20), 'group_code' => post_str('group_code', 80), 'group_name' => post_str('group_name', 180),
            'academic_year' => post_str('academic_year', 30), 'term' => post_str('term', 40), 'department_id' => post_str('department_id', 20),
        ];
        // Rows arrive as one JSON field (browser script) or as form fields (no script).
        $posted = [];
        $complete = true;
        $json = (string) ($_POST['rows_json'] ?? '');
        if ($json !== '') {
            $decoded = json_decode($json, true, 4);
            $complete = is_array($decoded) && array_is_list($decoded) && count($decoded) === (int) post_str('row_total', 6);
            $posted = $complete ? $decoded : [];
        } else {
            $posted = is_array($_POST['rows'] ?? null) ? array_values($_POST['rows']) : [];
            $complete = count($posted) === (int) post_str('row_total', 6);
        }
        $rows = [];
        foreach (array_slice($posted, 0, CLASS_LIST_MAX_ROWS) as $row) {
            if (!is_array($row)) {
                $complete = false;
                continue;
            }
            $value = static fn(string $key, int $max) => mb_substr(trim(is_string($row[$key] ?? null) ? $row[$key] : ''), 0, $max);
            $rows[] = [
                'no' => (int) ($row['no'] ?? 0), 'sex' => in_array($row['sex'] ?? '', ['male', 'female'], true) ? $row['sex'] : null,
                'participant_number' => $value('participant_number', 100), 'last_name' => $value('last_name', 100), 'first_name' => $value('first_name', 100),
                'middle_name' => $value('middle_name', 100), 'raw_name' => $value('raw_name', 300), 'review' => false,
                'include' => !empty($row['include']) && $row['include'] !== '0',
            ];
        }
        try {
            if (!$complete) {
                fail_user('The list was not received completely (the form may be too large). Nothing was imported. Reload the PDF and try again, or split the list.');
            }
            $result = import_class_list(array_values(array_filter($rows, static fn($r) => $r['include'])), $groupInput, $source);
            flash('success', 'Class list imported',
                "{$result['created']} student(s) registered" . ($result['existing'] ? ", {$result['existing']} already registered (left unchanged)" : '')
                . ($result['group_id'] ? ", {$result['grouped']} added to the group." : '.'));
            redirect($result['group_id'] ? 'groups/view.php?id=' . $result['group_id'] : 'participants/index.php');
        } catch (UserFacingException $exception) {
            $error = $exception->getMessage();
            $review = ['meta' => $meta, 'rows' => $rows, 'warnings' => [], 'source' => $source];
        }
    }
}

if ($review) {
    $review['rows'] = $withExisting($review['rows']);
    $suggestedCode = class_list_group_code((string) ($review['meta']['section'] ?? ''));
    $groups = db_all("SELECT id, group_code, group_name, academic_year, term FROM `groups` WHERE institution_id = ? AND status = 'active' ORDER BY group_name", [$institutionId]);
    $matching = $suggestedCode !== '' ? array_values(array_filter($groups, static fn($g) => strcasecmp($g['group_code'], $suggestedCode) === 0)) : [];
    $groupDefaults = [
        'mode' => $groupInput['mode'] ?? ($matching ? 'existing' : ($suggestedCode !== '' ? 'new' : 'none')),
        'group_id' => $groupInput['group_id'] ?? ($matching[0]['id'] ?? ''),
        'group_code' => $groupInput['group_code'] ?? $suggestedCode,
        'group_name' => $groupInput['group_name'] ?? (string) ($review['meta']['section'] ?? ''),
        'academic_year' => $groupInput['academic_year'] ?? (string) ($review['meta']['academic_year'] ?? ''),
        'term' => $groupInput['term'] ?? (string) ($review['meta']['term'] ?? ''),
        'department_id' => $groupInput['department_id'] ?? '',
    ];
    $counts = ['total' => count($review['rows']), 'new' => count(array_filter($review['rows'], static fn($r) => !$r['existing'])), 'existing' => count(array_filter($review['rows'], static fn($r) => (bool) $r['existing']))];
}

$pageTitle = 'Import Class List';
$activeNav = 'participants';
$breadcrumb = [auth_context()['label'], 'People'];
$pageScripts = ['js/class_list.js'];
require APP_ROOT . '/includes/header.php';
$sexLabel = ['male' => 'Male', 'female' => 'Female'];
?>
<?php if ($error): ?>
    <div class="notice notice-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><?= nl2br(e($error)) ?></div></div>
<?php endif; ?>

<?php if (!$review): ?>
<?= page_actions('Upload the class list PDF printed from the enrollment system. Students are read from the PDF and shown for review before anything is saved.',
    button_link('participants/import.php', 'Import CSV instead', 'bi-filetype-csv', 'btn-outline-secondary')) ?>
<div class="row g-3" style="max-width: 1100px">
    <div class="col-lg-7">
        <form method="post" enctype="multipart/form-data" class="card panel form-card">
            <?= csrf_field() ?><input type="hidden" name="action" value="read">
            <div class="card-header"><h3>Upload class list</h3></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label required" for="pdf">Class list (PDF)</label>
                    <input class="form-control" type="file" id="pdf" name="pdf" accept="application/pdf,.pdf" required>
                    <div class="form-text">Up to 10 MB. The PDF must contain text (a list saved or printed to PDF from the enrollment system). Scanned pages and photos cannot be read.</div>
                </div>
                <p class="text-muted-sm mb-3">Names are saved in capital letters (e.g. DEL CASTILLO, DAN RAFAEL).</p>
                <div class="notice notice-info"><i class="bi bi-shield-check" aria-hidden="true"></i><div>Reading the PDF saves nothing and the file is not kept. You review every student before importing.</div></div>
                <div class="form-actions">
                    <a class="btn btn-outline-secondary" href="<?= e(url('participants/index.php')) ?>">Cancel</a>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Read PDF</button>
                </div>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card panel">
            <div class="card-header"><h3>What is read</h3></div>
            <ul class="metric-list card-body">
                <li><span>Student number</span><span class="text-muted-sm">becomes the ID number</span></li>
                <li><span>Name ("LAST, FIRST MIDDLE")</span><span class="text-muted-sm">split into last, first and middle name</span></li>
                <li><span>Section, school year, semester</span><span class="text-muted-sm">suggested as the group</span></li>
                <li><span>Male / female headings and totals</span><span class="text-muted-sm">used to check nothing was missed</span></li>
            </ul>
            <div class="card-footer-note">Students already registered (same ID number) are not changed; they can still be added to the group.</div>
        </div>
    </div>
</div>

<?php else: $meta = $review['meta']; ?>
<?= page_actions('Check the students read from the PDF. Correct any name, untick anyone who should not be imported, choose the group, then import.',
    button_link('participants/import_pdf.php', 'Upload a different PDF', 'bi-arrow-left', 'btn-outline-secondary')) ?>

<div class="card panel mb-3"><div class="card-body">
    <dl class="detail-grid mb-0">
        <div><dt>File</dt><dd><?= e($review['source'] ?: '—') ?></dd></div>
        <div><dt>Section</dt><dd><?= e($meta['section'] ?? '—') ?></dd></div>
        <div><dt>School year · semester</dt><dd><?= e(trim(($meta['academic_year'] ?? '') . ' · ' . ($meta['term'] ?? ''), ' ·') ?: '—') ?></dd></div>
        <div><dt>Subject · instructor</dt><dd><?= e(trim(($meta['course_code'] ?? '') . ' · ' . ($meta['instructor'] ?? ''), ' ·') ?: '—') ?></dd></div>
        <div><dt>Students read</dt><dd><?= number_format($counts['total']) ?><?= isset($meta['total_students']) ? ' <span class="text-muted-sm">(PDF total ' . e($meta['total_students']) . ')</span>' : '' ?></dd></div>
        <div><dt>New · already registered</dt><dd><?= number_format($counts['new']) ?> · <?= number_format($counts['existing']) ?></dd></div>
    </dl>
</div></div>

<?php foreach ($review['warnings'] as $warning): ?>
    <div class="notice notice-warning mb-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><?= e($warning) ?></div></div>
<?php endforeach; ?>

<form method="post" data-class-list data-confirm="Import the selected students?" data-confirm-text="New students are registered; students already registered are only added to the group.">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <input type="hidden" name="action" value="import">
    <input type="hidden" name="source" value="<?= e($review['source']) ?>">
    <input type="hidden" name="meta_json" value="<?= e((string) json_encode($meta, JSON_UNESCAPED_UNICODE)) ?>">
    <input type="hidden" name="rows_json" value="" data-rows-json>
    <input type="hidden" name="row_total" value="<?= count($review['rows']) ?>" data-row-total>

    <div class="card panel mb-3">
        <div class="card-header"><h3>Students</h3><span class="text-muted-sm"><span data-selected-count><?= count(array_filter($review['rows'], static fn($r) => $r['include'])) ?></span> selected</span></div>
        <div class="table-responsive"><table class="table table-clean align-middle mb-0">
            <thead><tr>
                <th><input class="form-check-input" type="checkbox" data-select-all checked aria-label="Select all"></th>
                <th>No.</th><th>Student no.</th><th>Last name</th><th>First name</th><th>Middle name</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($review['rows'] as $i => $row): $name = static fn(string $field) => 'rows[' . $i . '][' . $field . ']'; ?>
                <tr data-row>
                    <td><input type="hidden" name="<?= $name('include') ?>" value="0"><input class="form-check-input" type="checkbox" name="<?= $name('include') ?>" value="1" <?= $row['include'] ? 'checked' : '' ?> data-field="include" aria-label="Import <?= e($row['participant_number']) ?>"></td>
                    <td class="text-muted-sm"><?= (int) $row['no'] ?><div><?= e($sexLabel[$row['sex']] ?? '') ?></div>
                        <input type="hidden" name="<?= $name('no') ?>" value="<?= (int) $row['no'] ?>" data-field="no"><input type="hidden" name="<?= $name('sex') ?>" value="<?= e((string) $row['sex']) ?>" data-field="sex"><input type="hidden" name="<?= $name('raw_name') ?>" value="<?= e($row['raw_name']) ?>" data-field="raw_name"></td>
                    <td><input class="form-control form-control-sm" name="<?= $name('participant_number') ?>" value="<?= e($row['participant_number']) ?>" maxlength="100" required data-field="participant_number" aria-label="Student number" style="min-width:110px"></td>
                    <td><input class="form-control form-control-sm" name="<?= $name('last_name') ?>" value="<?= e($row['last_name']) ?>" maxlength="100" required data-field="last_name" aria-label="Last name" data-uppercase></td>
                    <td><input class="form-control form-control-sm" name="<?= $name('first_name') ?>" value="<?= e($row['first_name']) ?>" maxlength="100" required data-field="first_name" aria-label="First name" data-uppercase></td>
                    <td><input class="form-control form-control-sm" name="<?= $name('middle_name') ?>" value="<?= e($row['middle_name']) ?>" maxlength="100" data-field="middle_name" aria-label="Middle name" data-uppercase></td>
                    <td class="text-muted-sm">
                        <?php if ($row['existing']): $onFile = trim($row['existing']['first_name'] . ' ' . ($row['existing']['middle_name'] ?? '') . ' ' . $row['existing']['last_name']); ?>
                            <span class="badge badge-soft-info">Already registered</span>
                            <div title="Name on file"><?= e($onFile) ?></div>
                            <?php if (mb_strtolower($row['existing']['last_name']) !== mb_strtolower($row['last_name'])): ?><div class="text-danger">Last name differs from the PDF</div><?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-soft-success">New</span>
                        <?php endif; ?>
                        <?php if ($row['review']): ?><div class="text-warning">Check how the name was split</div><?php endif; ?>
                        <?php if ($row['raw_name'] !== ''): ?><div class="text-truncate" style="max-width:220px" title="As printed">PDF: <?= e($row['raw_name']) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="card-footer-note">The middle name is taken from the last word(s) of the given names (e.g. "DAN RAFAEL DELOS SANTOS" → first name Dan Rafael, middle name Delos Santos). Correct any that are wrong before importing. Male/female is shown for checking only; it is not stored.</div>
    </div>

    <div class="card panel form-card mb-3">
        <div class="card-header"><h3>Group</h3></div>
        <div class="card-body">
            <div class="mb-3">
                <?php foreach (['existing' => 'Add to an existing group', 'new' => 'Create a new group for this section', 'none' => 'Do not add to a group'] as $mode => $label): ?>
                    <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="group_mode" id="mode-<?= $mode ?>" value="<?= $mode ?>" <?= $groupDefaults['mode'] === $mode ? 'checked' : '' ?> data-group-mode><label class="form-check-label" for="mode-<?= $mode ?>"><?= e($label) ?></label></div>
                <?php endforeach; ?>
            </div>
            <div class="row g-3" data-group-panel="existing">
                <div class="col-md-6"><label class="form-label" for="group_id">Group</label>
                    <select class="form-select" id="group_id" name="group_id"><?= options_html(options_from_rows($groups, 'id', static fn($g) => $g['group_name'] . ' (' . $g['group_code'] . ')' . ($g['academic_year'] ? ' · ' . $g['academic_year'] : '')), $groupDefaults['group_id'], 'Choose…') ?></select></div>
            </div>
            <div class="row g-3" data-group-panel="new">
                <div class="col-md-3"><label class="form-label" for="group_code">Group code</label><input class="form-control" id="group_code" name="group_code" maxlength="80" value="<?= e($groupDefaults['group_code']) ?>"></div>
                <div class="col-md-3"><label class="form-label" for="group_name">Group name</label><input class="form-control" id="group_name" name="group_name" maxlength="180" value="<?= e($groupDefaults['group_name']) ?>"></div>
                <div class="col-md-2"><label class="form-label" for="academic_year">School year</label><input class="form-control" id="academic_year" name="academic_year" maxlength="30" value="<?= e($groupDefaults['academic_year']) ?>"></div>
                <div class="col-md-2"><label class="form-label" for="term">Semester</label><input class="form-control" id="term" name="term" maxlength="40" value="<?= e($groupDefaults['term']) ?>"></div>
                <div class="col-md-2"><label class="form-label" for="department_id">Department</label><select class="form-select" id="department_id" name="department_id"><?= options_html(department_options(), $groupDefaults['department_id'], 'None') ?></select></div>
            </div>
            <div class="form-actions">
                <a class="btn btn-outline-secondary" href="<?= e(url('participants/import_pdf.php')) ?>">Cancel</a>
                <button class="btn btn-primary" type="submit"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Import Students</button>
            </div>
        </div>
    </div>
</form>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
