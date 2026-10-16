<?php
/**
 * Institution settings (institution administrators): profile, receipts, categories, ledger accounts.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/institutions.php';
require_institution_context();
require_permission('institution.settings.manage');

$institutionId = (int) current_institution_id();

if (is_post()) {
    $action = post_str('action', 20);
    handle_post(function () use ($action, $institutionId) {
        if ($action === 'profile') {
            // Institution administrators keep the code and currency set at onboarding.
            $current = db_one('SELECT institution_code, currency_code FROM institutions WHERE id = ?', [$institutionId]);
            update_institution($institutionId, array_merge($_POST, $current));
            flash('success', 'Profile saved');
        } elseif ($action === 'settings') {
            save_institution_settings($_POST);
            flash('success', 'Settings saved');
        } else {
            fail_user('Unknown action.');
        }
        return 'institutions/settings.php' . ($action === 'settings' ? '#settings' : '');
    });
}

$institution = db_one('SELECT * FROM institutions WHERE id = ?', [$institutionId]);
$settings = institution_settings_values($institutionId);
$ledgerLocked = institution_has_transactions($institutionId);

$pageTitle = 'Institution Settings';
$activeNav = 'settings';
$breadcrumb = [$institution['institution_name'], 'Administration'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Profile and preferences for ' . $institution['institution_name'] . '. Changes are recorded in the audit trail.') ?>

<div class="row g-3">
    <div class="col-xl-6">
        <form method="post" class="card panel form-card h-100">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <input type="hidden" name="action" value="profile">
            <div class="card-header"><h3>Profile</h3><span class="text-muted-sm">Code <?= e($institution['institution_code']) ?> · <?= e($institution['currency_code']) ?></span></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label required" for="institution_name">Name</label><input class="form-control" id="institution_name" name="institution_name" required maxlength="200" value="<?= e($institution['institution_name']) ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="institution_type">Type</label><input class="form-control" id="institution_type" name="institution_type" maxlength="80" value="<?= e($institution['institution_type']) ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="timezone">Timezone</label><select class="form-select" id="timezone" name="timezone"><?= options_html(array_combine(INSTITUTION_TIMEZONES, INSTITUTION_TIMEZONES), $institution['timezone']) ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" maxlength="190" value="<?= e($institution['email']) ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="50" value="<?= e($institution['phone']) ?>"></div>
                    <div class="col-12"><label class="form-label" for="address">Address</label><textarea class="form-control" id="address" name="address" rows="2" maxlength="1000"><?= e($institution['address']) ?></textarea></div>
                </div>
                <p class="text-muted-sm mt-3 mb-0">The institution code and currency are set by the platform administrator.</p>
                <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Profile</button></div>
            </div>
        </form>
    </div>

    <div class="col-xl-6">
        <form method="post" class="card panel form-card h-100" id="settings">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <input type="hidden" name="action" value="settings">
            <div class="card-header"><h3>Receipts &amp; Workflow</h3></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label required" for="receipt_title">Receipt title</label>
                    <input class="form-control" id="receipt_title" name="receipt_title" required maxlength="80" value="<?= e($settings['receipt.title']) ?>">
                    <div class="form-text">Printed at the top of every payment receipt. Use "Official Receipt" only if your institution is authorised to issue one.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="receipt_footer">Receipt footer note</label>
                    <textarea class="form-control" id="receipt_footer" name="receipt_footer" rows="2" maxlength="500"><?= e($settings['receipt.footer']) ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label required" for="expense_categories">Expense &amp; budget categories</label>
                    <textarea class="form-control" id="expense_categories" name="expense_categories" rows="6" required><?= e($settings['expense.categories']) ?></textarea>
                    <div class="form-text">One per line. Expenses and budget lines use the same list so budget-versus-actual can be compared.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="advance_default_days">Default liquidation period for cash advances (days)</label>
                    <input class="form-control" type="number" min="1" max="180" id="advance_default_days" name="advance_default_days" value="<?= e($settings['advance.default_days']) ?>">
                </div>

                <h2 class="form-section-title mt-4">Ledger accounts</h2>
                <?php if ($ledgerLocked): ?>
                    <div class="notice notice-info"><i class="bi bi-lock" aria-hidden="true"></i><div>Transactions have been posted, so account codes are locked. Names can still be changed.</div></div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Purpose</th><th style="width:110px">Code</th><th>Name</th></tr></thead>
                        <tbody>
                        <?php foreach (LEDGER_ACCOUNTS as $key => [$defaultCode, $defaultName, $class]): ?>
                            <tr>
                                <td class="text-muted-sm"><?= e(humanize($key)) ?> <span class="badge badge-soft-neutral"><?= e($class) ?></span></td>
                                <td><input class="form-control form-control-sm" name="ledger_<?= e($key) ?>_code" value="<?= e($settings["ledger.{$key}.code"]) ?>" maxlength="60" required <?= $ledgerLocked ? 'readonly' : '' ?> aria-label="<?= e(humanize($key)) ?> code"></td>
                                <td><input class="form-control form-control-sm" name="ledger_<?= e($key) ?>_name" value="<?= e($settings["ledger.{$key}.name"]) ?>" maxlength="180" required aria-label="<?= e(humanize($key)) ?> name"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Settings</button></div>
            </div>
        </form>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
