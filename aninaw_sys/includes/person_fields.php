<?php
/**
 * Person detail fields (ID, type, names, program, birthday, contacts, address, guardian), shared by the
 * "Add a new person" and "Edit details" modals. Expects $pf (key => current value), $pfPrefix (id prefix)
 * and $pfType (default type).
 */
?>
<div class="row g-2">
    <div class="col-md-6"><label class="form-label required" for="<?= $pfPrefix ?>-number">ID number</label><input class="form-control" id="<?= $pfPrefix ?>-number" name="participant_number" required maxlength="100" value="<?= e($pf('participant_number')) ?>" placeholder="e.g. 26-00123"></div>
    <div class="col-md-6"><label class="form-label" for="<?= $pfPrefix ?>-type">Type</label><select class="form-select" id="<?= $pfPrefix ?>-type" name="participant_type"><?= options_html(PARTICIPANT_TYPES, $pf('participant_type', $pfType)) ?></select></div>
    <div class="col-md-6"><label class="form-label required" for="<?= $pfPrefix ?>-first">First name</label><input class="form-control" id="<?= $pfPrefix ?>-first" data-uppercase name="first_name" required maxlength="100" value="<?= e($pf('first_name')) ?>"<?= $pfPrefix === 'nm' ? ' data-member-new-first' : '' ?>></div>
    <div class="col-md-6"><label class="form-label required" for="<?= $pfPrefix ?>-last">Last name</label><input class="form-control" id="<?= $pfPrefix ?>-last" data-uppercase name="last_name" required maxlength="100" value="<?= e($pf('last_name')) ?>"<?= $pfPrefix === 'nm' ? ' data-member-new-last' : '' ?>></div>
    <div class="col-md-6"><label class="form-label" for="<?= $pfPrefix ?>-middle">Middle name</label><input class="form-control" id="<?= $pfPrefix ?>-middle" data-uppercase name="middle_name" maxlength="100" value="<?= e($pf('middle_name')) ?>">
        <div class="form-check mt-1"><input type="hidden" name="no_middle_name" value="0"><input class="form-check-input" type="checkbox" id="<?= $pfPrefix ?>-no-middle" name="no_middle_name" value="1" <?= (string) $pf('no_middle_name') === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="<?= $pfPrefix ?>-no-middle">No middle name</label></div></div>
    <div class="col-md-6"><label class="form-label" for="<?= $pfPrefix ?>-program">Program</label><select class="form-select" id="<?= $pfPrefix ?>-program" name="program_id"><?= options_html(program_options(), $pf('program_id'), '—') ?></select></div>
    <div class="col-md-6"><label class="form-label" for="<?= $pfPrefix ?>-birth">Birthday</label><input class="form-control" type="date" id="<?= $pfPrefix ?>-birth" name="birth_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($pf('birth_date')) ?>"></div>
    <div class="col-md-6"><label class="form-label" for="<?= $pfPrefix ?>-phone">Phone</label><input class="form-control" id="<?= $pfPrefix ?>-phone" name="phone" maxlength="50" value="<?= e($pf('phone')) ?>"></div>
    <div class="col-12"><label class="form-label" for="<?= $pfPrefix ?>-address">Address</label><input class="form-control" id="<?= $pfPrefix ?>-address" name="address" maxlength="500" value="<?= e($pf('address')) ?>"></div>
    <div class="col-md-7"><label class="form-label" for="<?= $pfPrefix ?>-guardian">Parent / guardian</label><input class="form-control" id="<?= $pfPrefix ?>-guardian" name="guardian_name" maxlength="180" value="<?= e($pf('guardian_name')) ?>"></div>
    <div class="col-md-5"><label class="form-label" for="<?= $pfPrefix ?>-guardian-phone">Guardian's contact</label><input class="form-control" id="<?= $pfPrefix ?>-guardian-phone" name="guardian_phone" maxlength="50" value="<?= e($pf('guardian_phone')) ?>"></div>
    <div class="col-12"><label class="form-label" for="<?= $pfPrefix ?>-email">E-mail</label><input class="form-control" type="email" id="<?= $pfPrefix ?>-email" name="email" maxlength="190" value="<?= e($pf('email')) ?>"></div>
</div>
