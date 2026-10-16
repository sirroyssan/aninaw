<?php
/**
 * Create or edit a fund account (e.g. RAITE Fund 2026). New funds start as draft;
 * opening balances are recorded separately through the controlled opening-balance entry.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/funds.php';
require_institution_context();
require_permission('funds.manage');

$fundId = get_int('id');
$fund = $fundId ? require_found(find_visible_fund($fundId)) : null;

if (is_post()) {
    handle_post(function () use ($fundId) {
        $id = save_fund($fundId, $_POST);
        flash('success', $fundId ? 'Fund updated' : 'Fund created', $fundId ? '' : 'Next: map the financial accounts that may hold this fund\'s money, then activate it.');
        return 'funds/view.php?id=' . $id;
    });
}

$field = static fn(string $key, string $default = '') => $fund[$key] ?? old($key, $default);

$pageTitle = $fund ? 'Edit Fund' : 'New Fund';
$activeNav = 'funds';
$breadcrumb = [auth_context()['label'], 'Funds'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" class="card panel form-card" style="max-width: 880px">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>A <strong>fund</strong> is what the money is for (e.g. Graduation Fund). Where the money is physically held (a cash box, a bank account) is a <strong>financial account</strong>, mapped to the fund on the next page.</div>
        </div>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label required" for="fund_code">Code</label><input class="form-control" id="fund_code" name="fund_code" required maxlength="60" value="<?= e($field('fund_code')) ?>" placeholder="RAITE26"></div>
            <div class="col-md-8"><label class="form-label required" for="fund_name">Name</label><input class="form-control" id="fund_name" name="fund_name" required maxlength="180" value="<?= e($field('fund_name')) ?>" placeholder="RAITE Fund 2026"></div>
            <div class="col-md-6">
                <label class="form-label" for="fund_category">Classification</label>
                <input class="form-control" id="fund_category" name="fund_category" maxlength="100" value="<?= e($field('fund_category')) ?>" list="fund-categories" placeholder="e.g. Restricted, Event, Class">
                <datalist id="fund-categories"><option value="Event fund"><option value="Class fund"><option value="Restricted / Donation"><option value="Dues"><option value="Emergency"><option value="General"></datalist>
            </div>
            <div class="col-md-6"><label class="form-label required" for="custodian_membership_id">Accountable custodian</label><select class="form-select" id="custodian_membership_id" name="custodian_membership_id" required><?= options_html(member_options(), $field('custodian_membership_id'), 'Select…') ?></select></div>
            <div class="col-12"><label class="form-label" for="purpose">Purpose and restrictions</label><textarea class="form-control" id="purpose" name="purpose" rows="3" maxlength="2000" placeholder="What this money may and may not be used for"><?= e($field('purpose')) ?></textarea></div>
        </div>
        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url($fund ? 'funds/view.php?id=' . $fund['id'] : 'funds/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i><?= $fund ? 'Save Changes' : 'Create Fund' ?></button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
