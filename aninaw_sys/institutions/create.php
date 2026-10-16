<?php
/**
 * Add an institution profile (platform), optionally with its first administrator.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/institutions.php';

if (auth_context()['type'] !== 'platform') {
    render_error_page(403, 'Platform Only', 'This page is available only in the Platform Administration workspace.');
}
require_permission('platform.institutions.manage');

if (is_post()) {
    handle_post(function () {
        $result = create_institution($_POST);
        if ($result['reset_link']) {
            remember_issued_link($result['reset_link'], post_str('admin_email', 190));
        }
        flash('success', 'Institution created');
        return 'institutions/view.php?id=' . $result['institution_id'];
    });
}

$pageTitle = 'Add Institution';
$activeNav = 'institutions';
$breadcrumb = ['Platform', 'Institutions'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" class="card panel form-card" style="max-width: 880px">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="form-section">
            <h2 class="form-section-title">Institution profile</h2>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label required" for="institution_code">Code</label>
                    <input class="form-control text-uppercase" id="institution_code" name="institution_code" maxlength="50" required value="<?= e(old('institution_code')) ?>" placeholder="e.g. PCB">
                </div>
                <div class="col-md-8">
                    <label class="form-label required" for="institution_name">Name</label>
                    <input class="form-control" id="institution_name" name="institution_name" maxlength="200" required value="<?= e(old('institution_name')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="institution_type">Type</label>
                    <input class="form-control" id="institution_type" name="institution_type" maxlength="80" value="<?= e(old('institution_type')) ?>" placeholder="School, College, Organization…" list="institution-types">
                    <datalist id="institution-types"><option value="School"><option value="College"><option value="University"><option value="Organization"><option value="Association"></datalist>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="email">E-mail</label>
                    <input class="form-control" type="email" id="email" name="email" maxlength="190" value="<?= e(old('email')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="phone">Phone</label>
                    <input class="form-control" id="phone" name="phone" maxlength="50" value="<?= e(old('phone')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="address">Address</label>
                    <textarea class="form-control" id="address" name="address" rows="2" maxlength="1000"><?= e(old('address')) ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="timezone">Timezone</label>
                    <select class="form-select" id="timezone" name="timezone"><?= options_html(array_combine(INSTITUTION_TIMEZONES, INSTITUTION_TIMEZONES), old('timezone', 'Asia/Manila')) ?></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="currency_code">Currency</label>
                    <select class="form-select" id="currency_code" name="currency_code"><?= options_html(INSTITUTION_CURRENCIES, old('currency_code', 'PHP')) ?></select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="status">Initial status</label>
                    <select class="form-select" id="status" name="status"><?= options_html(['pending' => 'Pending (members cannot sign in yet)', 'active' => 'Active'], old('status', 'pending')) ?></select>
                </div>
            </div>
        </div>

        <div class="form-section">
            <h2 class="form-section-title">First administrator <span class="text-muted-sm text-lowercase">(optional)</span></h2>
            <p class="text-muted-sm">An existing account with this e-mail is added as Institution Administrator. For a new e-mail, an account is created and you receive a one-time link for the person to set their password.</p>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="admin_email">Administrator e-mail</label>
                    <input class="form-control" type="email" id="admin_email" name="admin_email" maxlength="190" value="<?= e(old('admin_email')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="admin_username">Username <span class="text-muted-sm">(new accounts)</span></label>
                    <input class="form-control" id="admin_username" name="admin_username" maxlength="100" value="<?= e(old('admin_username')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="admin_first_name">First name <span class="text-muted-sm">(new accounts)</span></label>
                    <input class="form-control" id="admin_first_name" name="admin_first_name" maxlength="100" value="<?= e(old('admin_first_name')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="admin_last_name">Last name <span class="text-muted-sm">(new accounts)</span></label>
                    <input class="form-control" id="admin_last_name" name="admin_last_name" maxlength="100" value="<?= e(old('admin_last_name')) ?>">
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url('institutions/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Create Institution</button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
