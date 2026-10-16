<?php
/**
 * Record event documents (waivers, medical certificates, IDs).
 *  ?event=&person=       one person's papers: status, remarks and scanned copy per document
 *  ?event=&requirement=  one document for many people: tick who handed it in or was checked
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/event_requirements.php';
require_institution_context();

$eventId = (int) (get_int('event') ?? 0);
$event = require_found(find_event($eventId));
if (!can_check_event_documents($event)) {
    render_error_page(403, 'Not Allowed', 'Only the event\'s organizers or document checkers can see and record documents.');
}
$personId = get_int('person');
$requirementId = get_int('requirement');
$back = 'events/view.php?id=' . $eventId . '&tab=requirements';

if (is_post()) {
    handle_post(function () use ($eventId, $personId, $requirementId) {
        if (post_str('mode', 10) === 'person') {
            $count = record_event_documents($eventId, post_int('requirement_id') ?? 0, [post_int('participant_id') ?? 0], post_str('status', 12), post_str('remarks', 500), 'document');
            flash('success', 'Document recorded');
            return 'events/documents.php?event=' . $eventId . '&person=' . (post_int('participant_id') ?? 0);
        }
        $ids = array_filter(array_map('intval', is_array($_POST['participant_ids'] ?? null) ? $_POST['participant_ids'] : []));
        $count = record_event_documents($eventId, post_int('requirement_id') ?? 0, $ids, post_str('status', 12), post_str('remarks', 500));
        flash('success', $count . ' recorded');
        return 'events/documents.php?event=' . $eventId . '&requirement=' . (post_int('requirement_id') ?? 0);
    }, false);
}

$checklist = event_document_checklist($eventId);
$recordStatuses = array_diff_key(REQUIREMENT_STATUSES, ['missing' => true]);
$locked = in_array($event['status'], ['closed', 'cancelled'], true);

if ($personId) {
    $person = $checklist['people'][$personId] ?? require_found(null);
    $pageTitle = $person['first_name'] . ' ' . $person['last_name'] . ' · Documents';
} else {
    $requirement = null;
    foreach ($checklist['requirements'] as $row) {
        if ((int) $row['id'] === $requirementId) {
            $requirement = $row;
        }
    }
    $requirement = require_found($requirement);
    $pageTitle = $requirement['requirement_name'];
    $onlyOpen = get_str('open', 1) !== '0';
    $rows = array_filter($checklist['people'], static fn($p) => $p['cells'][(int) $requirement['id']] !== null
        && (!$onlyOpen || !in_array($p['cells'][(int) $requirement['id']]['status'], ['approved', 'waived'], true)));
}

$activeNav = 'events';
$breadcrumb = [auth_context()['label'], 'Events', $event['event_name']];
require APP_ROOT . '/includes/header.php';
$badge = static fn(string $status) => '<span class="badge ' . match ($status) { 'approved' => 'badge-soft-success', 'waived' => 'badge-soft-secondary', 'submitted' => 'badge-soft-info', 'rejected' => 'badge-soft-danger', default => 'badge-soft-warning' } . '">' . e(REQUIREMENT_STATUSES[$status]) . '</span>';
?>
<?= page_actions($personId ? 'Record each paper as it is handed in, then approve it or return it with the reason.' : 'Tick everyone who handed in this document (or whose paper you checked), then record them together.',
    button_link($back, 'Checklist', 'bi-arrow-left', 'btn-outline-secondary')) ?>

<?php if ($personId): ?>
    <div class="card panel mb-3"><div class="card-body">
        <dl class="detail-grid mb-0">
            <div><dt>Person</dt><dd><?= e($person['last_name'] . ', ' . $person['first_name']) ?><div class="text-muted-sm"><?= e($person['participant_number'] . ' · ' . (PARTICIPANT_TYPES[$person['participant_type']] ?? '')) ?></div></dd></div>
            <div><dt>Groups</dt><dd><?= e($person['group_names'] ?: '—') ?></dd></div>
            <div><dt>Status</dt><dd><?= $person['cleared'] ? '<span class="badge badge-soft-success">Cleared</span>' : e($person['done'] . ' of ' . $person['required'] . ' required papers done') ?></dd></div>
        </dl>
    </div></div>
    <?php if (!$checklist['requirements']): ?>
        <div class="card panel"><div class="card-body"><?= empty_state('bi-file-earmark-text', 'This event asks for no documents') ?></div></div>
    <?php endif; ?>
    <div class="row g-3">
    <?php foreach ($checklist['requirements'] as $req): $cell = $person['cells'][(int) $req['id']]; ?>
        <div class="col-lg-6">
            <div class="card panel h-100">
                <div class="card-header"><h3><?= e($req['requirement_name']) ?><?= $req['is_required'] ? '' : ' <span class="text-muted-sm">(optional)</span>' ?></h3><?= $cell === null ? '<span class="text-muted-sm">Does not apply</span>' : $badge($cell['status']) ?></div>
                <?php if ($cell !== null): $sub = $cell['submission']; ?>
                    <div class="card-body">
                        <?php if ($req['description'] || $req['due_date']): ?><p class="text-muted-sm"><?= e(trim($req['description'] . ($req['due_date'] ? ' · due ' . format_date($req['due_date']) : ''), ' ·')) ?></p><?php endif; ?>
                        <?php if ($sub): ?>
                            <p class="text-muted-sm mb-2">Received <?= e(format_datetime($sub['received_at'])) ?><?= $sub['reviewed_at'] ? ' · checked ' . e(format_datetime($sub['reviewed_at'])) : '' ?><?= $sub['remarks'] ? '<br>Remarks: ' . e($sub['remarks']) : '' ?></p>
                            <?= attachment_list_html(attachments_for('event_document', (int) $sub['id'])) ?>
                        <?php endif; ?>
                        <?php if (!$locked): ?>
                            <form method="post" enctype="multipart/form-data" class="mt-3">
                                <?= csrf_field() ?><input type="hidden" name="mode" value="person"><input type="hidden" name="participant_id" value="<?= (int) $personId ?>"><input type="hidden" name="requirement_id" value="<?= (int) $req['id'] ?>">
                                <div class="row g-2">
                                    <div class="col-md-5"><select class="form-select form-select-sm" name="status" aria-label="Status"><?= options_html($recordStatuses, $cell['status'] === 'missing' ? 'submitted' : ($cell['status'] === 'submitted' ? 'approved' : $cell['status'])) ?></select></div>
                                    <div class="col-md-7"><input class="form-control form-control-sm" name="remarks" maxlength="500" placeholder="Remarks (required if returned or not applicable)" aria-label="Remarks"></div>
                                    <div class="col-12"><input class="form-control form-control-sm" type="file" name="document" accept="application/pdf,image/jpeg,image/png,image/webp" aria-label="Scanned copy"><div class="form-text">Optional scanned copy (PDF or photo, 5 MB). Only document checkers can open it.</div></div>
                                </div>
                                <button class="btn btn-primary btn-sm mt-2" type="submit">Save</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

<?php else: ?>
    <div class="card panel mb-3"><div class="card-body">
        <dl class="detail-grid mb-0">
            <div><dt>Document</dt><dd><?= e($requirement['requirement_name']) ?><div class="text-muted-sm"><?= e(($requirement['is_required'] ? 'Required' : 'Optional') . ' · ' . REQUIREMENT_APPLIES[$requirement['applies_to_type']]) ?></div></dd></div>
            <div><dt>Approved · to check · returned</dt><dd><?= (int) $requirement['done_count'] ?> · <?= (int) $requirement['submitted_count'] ?> · <?= (int) $requirement['rejected_count'] ?></dd></div>
            <div><dt>Due</dt><dd><?= e($requirement['due_date'] ? format_date($requirement['due_date']) : '—') ?></dd></div>
        </dl>
    </div></div>
    <form method="post" class="card panel" data-confirm="Record for the ticked people?">
        <?= csrf_field() ?><input type="hidden" name="mode" value="bulk"><input type="hidden" name="requirement_id" value="<?= (int) $requirement['id'] ?>">
        <div class="card-header"><h3>People</h3>
            <a class="text-muted-sm" href="<?= e(query_url(['open' => $onlyOpen ? '0' : null])) ?>"><?= $onlyOpen ? 'Show everyone' : 'Show only those not yet done' ?></a>
        </div>
        <?php if (!$rows): ?>
            <div class="card-body"><?= empty_state('bi-check2-all', $onlyOpen ? 'Everyone is done for this document' : 'No one in this event needs this document') ?></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                <thead><tr><th></th><th>Person</th><th>Status</th><th>Remarks</th><th></th></tr></thead>
                <tbody><?php foreach ($rows as $pid => $row): $cell = $row['cells'][(int) $requirement['id']]; ?>
                    <tr>
                        <td><input class="form-check-input" type="checkbox" name="participant_ids[]" value="<?= (int) $pid ?>" id="pp-<?= (int) $pid ?>" aria-label="Select <?= e($row['first_name'] . ' ' . $row['last_name']) ?>"></td>
                        <td><label for="pp-<?= (int) $pid ?>"><span class="fw-semibold"><?= e($row['last_name'] . ', ' . $row['first_name']) ?></span><div class="text-muted-sm"><?= e($row['participant_number'] . ($row['group_names'] ? ' · ' . $row['group_names'] : '')) ?></div></label></td>
                        <td><?= $badge($cell['status']) ?></td>
                        <td class="text-muted-sm"><?= e($cell['submission']['remarks'] ?? '') ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('events/documents.php?event=' . $eventId . '&person=' . $pid)) ?>">Papers</a></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php if (!$locked): ?>
                <div class="card-body border-top">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3"><label class="form-label" for="bulk-status">Mark ticked people as</label><select class="form-select" id="bulk-status" name="status"><?= options_html($recordStatuses, 'submitted') ?></select></div>
                        <div class="col-md-6"><label class="form-label" for="bulk-remarks">Remarks</label><input class="form-control" id="bulk-remarks" name="remarks" maxlength="500" placeholder="Required if returned or not applicable"></div>
                        <div class="col-md-3"><button class="btn btn-primary w-100" type="submit">Record</button></div>
                    </div>
                    <div class="form-text">To attach a scanned copy, open the person's papers.</div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </form>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
