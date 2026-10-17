<?php
/**
 * "Add a new person" modal: registers someone not yet in People (strict: students need a middle
 * name or "No middle name") and the page's action adds them where they were needed (a group, an event).
 * Expects $newPerson = ['action', 'title', 'intro', 'submit', 'type' (default type), 'hidden' (extra inputs HTML)].
 * Reopens with the typed values when the save came back with an error. member_picker.js prefills the name.
 */
$reopen = old('action') === $newPerson['action'];
?>
<div class="modal fade" id="new-member-modal" tabindex="-1" aria-labelledby="new-member-title" aria-hidden="true"<?= $reopen ? ' data-open-on-load' : '' ?>>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <form method="post" class="modal-content">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <input type="hidden" name="action" value="<?= e($newPerson['action']) ?>"><?= $newPerson['hidden'] ?? '' ?>
            <div class="modal-header">
                <h2 class="modal-title h5" id="new-member-title"><?= e($newPerson['title']) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted-sm"><?= e($newPerson['intro']) ?></p>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label required" for="nm-number">ID number</label><input class="form-control" id="nm-number" name="participant_number" required maxlength="100" value="<?= e($reopen ? old('participant_number') : '') ?>" placeholder="e.g. 26-00123"></div>
                    <div class="col-md-6"><label class="form-label" for="nm-type">Type</label><select class="form-select" id="nm-type" name="participant_type"><?= options_html(PARTICIPANT_TYPES, $reopen ? old('participant_type', 'student') : $newPerson['type']) ?></select></div>
                    <div class="col-md-6"><label class="form-label required" for="nm-first">First name</label><input class="form-control" id="nm-first" data-uppercase name="first_name" required maxlength="100" value="<?= e($reopen ? old('first_name') : '') ?>" data-member-new-first></div>
                    <div class="col-md-6"><label class="form-label required" for="nm-last">Last name</label><input class="form-control" id="nm-last" data-uppercase name="last_name" required maxlength="100" value="<?= e($reopen ? old('last_name') : '') ?>" data-member-new-last></div>
                    <div class="col-md-6"><label class="form-label" for="nm-middle">Middle name</label><input class="form-control" id="nm-middle" data-uppercase name="middle_name" maxlength="100" value="<?= e($reopen ? old('middle_name') : '') ?>">
                        <div class="form-check mt-1"><input type="hidden" name="no_middle_name" value="0"><input class="form-check-input" type="checkbox" id="nm-no-middle" name="no_middle_name" value="1" <?= $reopen && old('no_middle_name') === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="nm-no-middle">No middle name</label></div></div>
                    <div class="col-md-6"><label class="form-label" for="nm-program">Program</label><select class="form-select" id="nm-program" name="program_id"><?= options_html(program_options(), $reopen ? old('program_id') : null, '—') ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="nm-birth">Birthday</label><input class="form-control" type="date" id="nm-birth" name="birth_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($reopen ? old('birth_date') : '') ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="nm-phone">Phone</label><input class="form-control" id="nm-phone" name="phone" maxlength="50" value="<?= e($reopen ? old('phone') : '') ?>"></div>
                    <div class="col-12"><label class="form-label" for="nm-address">Address</label><input class="form-control" id="nm-address" name="address" maxlength="500" value="<?= e($reopen ? old('address') : '') ?>"></div>
                    <div class="col-md-7"><label class="form-label" for="nm-guardian">Parent / guardian</label><input class="form-control" id="nm-guardian" name="guardian_name" maxlength="180" value="<?= e($reopen ? old('guardian_name') : '') ?>"></div>
                    <div class="col-md-5"><label class="form-label" for="nm-guardian-phone">Guardian's contact</label><input class="form-control" id="nm-guardian-phone" name="guardian_phone" maxlength="50" value="<?= e($reopen ? old('guardian_phone') : '') ?>"></div>
                    <div class="col-12"><label class="form-label" for="nm-email">E-mail</label><input class="form-control" type="email" id="nm-email" name="email" maxlength="190" value="<?= e($reopen ? old('email') : '') ?>"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus me-1" aria-hidden="true"></i><?= e($newPerson['submit']) ?></button>
            </div>
        </form>
    </div>
</div>
