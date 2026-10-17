<?php
/**
 * Register or edit a participant.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/organization.php';
require_institution_context();
require_permission('organization.manage');

$participantId = get_int('id');
$participant = $participantId ? require_found(find_participant($participantId)) : null;

if (is_post()) {
    handle_post(function () use ($participantId) {
        $id = save_participant($participantId, $_POST, true);
        $groupId = post_int('group_id');
        if (!$participantId && $groupId) {
            add_group_members($groupId, [$id], '', '');
        }
        flash('success', $participantId ? 'Person updated' : 'Person added');
        return 'participants/view.php?id=' . $id;
    });
}

$field = static fn(string $key, string $default = '') => $participant[$key] ?? old($key, $default);

$pageTitle = $participant ? 'Edit Person' : 'Add Person';
$activeNav = 'participants';
$breadcrumb = [auth_context()['label'], 'People'];
$pageScripts = ['js/participant_form.js'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" class="card panel form-card" style="max-width: 880px">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="form-section">
            <h2 class="form-section-title">Identity</h2>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label required" for="participant_number">ID number</label><input class="form-control" id="participant_number" name="participant_number" required maxlength="100" value="<?= e($field('participant_number')) ?>"></div>
                <div class="col-md-4"><label class="form-label" for="participant_type">Type</label><select class="form-select" id="participant_type" name="participant_type"><?= options_html(PARTICIPANT_TYPES, $field('participant_type', 'student')) ?></select></div>
                <div class="col-md-4"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['active' => 'Active', 'inactive' => 'Inactive', 'archived' => 'Archived'], $field('status', 'active')) ?></select></div>
                <div class="col-md-4"><label class="form-label required" for="first_name">First name</label><input class="form-control" id="first_name" data-uppercase name="first_name" required maxlength="100" value="<?= e($field('first_name')) ?>"></div>
                <div class="col-md-4"><label class="form-label" for="middle_name" data-middle-label>Middle name</label><input class="form-control" id="middle_name" data-uppercase name="middle_name" maxlength="100" value="<?= e($field('middle_name')) ?>" data-middle-name>
                    <div class="form-check mt-1"><input type="hidden" name="no_middle_name" value="0"><input class="form-check-input" type="checkbox" id="no_middle_name" name="no_middle_name" value="1" <?= (string) $field('no_middle_name', '0') === '1' ? 'checked' : '' ?> data-no-middle><label class="form-check-label small" for="no_middle_name">No middle name</label></div></div>
                <div class="col-md-4"><label class="form-label required" for="last_name">Last name</label><input class="form-control" id="last_name" data-uppercase name="last_name" required maxlength="100" value="<?= e($field('last_name')) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" maxlength="190" value="<?= e($field('email')) ?>"></div>
                <div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="50" value="<?= e($field('phone')) ?>"></div>
                <div class="col-md-4"><label class="form-label" for="birth_date">Birthday</label><input class="form-control" type="date" id="birth_date" name="birth_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e((string) $field('birth_date')) ?>"></div>
                <div class="col-md-8"><label class="form-label" for="program_id">Program <a class="text-muted-sm ms-1" href="<?= e(url('participants/programs.php')) ?>">Manage programs</a></label>
                    <select class="form-select" id="program_id" name="program_id" data-searchable><?= options_html(program_options(true, isset($participant['program_id']) ? (int) $participant['program_id'] : null), $field('program_id'), 'None / not applicable') ?></select></div>
                <div class="col-12"><label class="form-label" for="address">Address</label><input class="form-control" id="address" name="address" maxlength="500" value="<?= e((string) $field('address')) ?>" placeholder="House no., street, barangay, town / city, province"></div>
            </div>
        </div>
        <div class="form-section">
            <h2 class="form-section-title">Parent / guardian</h2>
            <div class="row g-3">
                <div class="col-md-5"><label class="form-label" for="guardian_name">Parent / guardian name</label><input class="form-control" id="guardian_name" name="guardian_name" maxlength="180" value="<?= e((string) $field('guardian_name')) ?>"></div>
                <div class="col-md-3"><label class="form-label" for="guardian_relationship">Relationship</label><select class="form-select" id="guardian_relationship" name="guardian_relationship"><?= options_html(GUARDIAN_RELATIONSHIPS, $field('guardian_relationship'), '—') ?></select></div>
                <div class="col-md-4"><label class="form-label" for="guardian_phone">Guardian's contact number</label><input class="form-control" id="guardian_phone" name="guardian_phone" maxlength="50" value="<?= e((string) $field('guardian_phone')) ?>" placeholder="09XX XXX XXXX"></div>
            </div>
        </div>
        <div class="form-section">
            <h2 class="form-section-title">Login link &amp; group</h2>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="link_membership_id">Linked login <span class="text-muted-sm">(optional)</span></label>
                    <select class="form-select" id="link_membership_id" name="link_membership_id" data-searchable><?= options_html(member_options(), $participant['linked_membership_id'] ?? old('link_membership_id'), 'Not linked') ?></select>
                    <div class="form-text">Linking lets the person see their own obligations and receipts on the dashboard. They need the Student / Member role.</div>
                </div>
                <?php if (!$participant): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="group_id">Add to group <span class="text-muted-sm">(optional)</span></label>
                        <select class="form-select" id="group_id" name="group_id"><?= options_html(group_options(), get_int('group') ?? old('group_id'), 'None') ?></select>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url($participant ? 'participants/view.php?id=' . $participant['id'] : 'participants/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i><?= $participant ? 'Save Changes' : 'Register' ?></button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
