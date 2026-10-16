<?php
/**
 * Institution profile (platform): details, edit, status, administrators and counts.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/institutions.php';

if (auth_context()['type'] !== 'platform') {
    render_error_page(403, 'Platform Only', 'This page is available only in the Platform Administration workspace.');
}
require_permission('platform.institutions.view');

$institutionId = require_get_id();
$institution = require_found(db_one('SELECT * FROM institutions WHERE id = ?', [$institutionId]));
$canManage = can('platform.institutions.manage');

if (is_post()) {
    require_permission('platform.institutions.manage');
    $action = post_str('action', 30);
    handle_post(function () use ($action, $institutionId) {
        switch ($action) {
            case 'update':
                update_institution($institutionId, $_POST);
                flash('success', 'Institution updated');
                break;
            case 'status':
                set_institution_status($institutionId, post_str('status', 20), post_str('reason', 1000));
                flash('success', 'Status changed');
                break;
            case 'add_admin':
                $link = db_transaction(fn() => add_institution_admin($institutionId, mb_strtolower(post_str('admin_email', 190)), $_POST));
                if ($link) {
                    remember_issued_link($link, post_str('admin_email', 190));
                }
                flash('success', 'Administrator assigned');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'institutions/view.php?id=' . $institutionId;
    }, $action !== 'status');
}

$summary = institution_summary($institutionId);
$history = db_all(
    "SELECT a.action, a.reason, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor
     FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE a.entity_type = 'institution' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 10",
    [$institutionId]
);

$pageTitle = $institution['institution_name'];
$activeNav = 'institutions';
$breadcrumb = ['Platform', 'Institutions'];
require APP_ROOT . '/includes/header.php';
?>
<?= issued_link_notice() ?>
<?= page_actions($institution['institution_code'] . ' · ' . ($institution['institution_type'] ?: 'Institution'), button_link('institutions/index.php', 'All Institutions', 'bi-arrow-left', 'btn-outline-secondary')) ?>

<div class="stat-grid">
    <?= stat_card('bi-flag', 'Status', humanize($institution['status']), 'Since ' . format_date($institution['updated_at']), $institution['status'] === 'active' ? 'success' : ($institution['status'] === 'pending' ? 'warning' : 'danger')) ?>
    <?= stat_card('bi-people', 'Active Members', number_format($summary['members'])) ?>
    <?= stat_card('bi-person-vcard', 'Participants / Groups', number_format($summary['participants']) . ' / ' . number_format($summary['groups'])) ?>
    <?= stat_card('bi-calendar-event', 'Events / Active Funds', number_format($summary['events']) . ' / ' . number_format($summary['funds']), 'Counts only', 'info') ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <form method="post" class="card panel form-card">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <input type="hidden" name="action" value="update">
            <div class="card-header"><h3>Profile</h3></div>
            <div class="card-body">
                <fieldset <?= $canManage ? '' : 'disabled' ?>>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label required" for="institution_code">Code</label><input class="form-control" id="institution_code" name="institution_code" required maxlength="50" value="<?= e($institution['institution_code']) ?>"></div>
                    <div class="col-md-8"><label class="form-label required" for="institution_name">Name</label><input class="form-control" id="institution_name" name="institution_name" required maxlength="200" value="<?= e($institution['institution_name']) ?>"></div>
                    <div class="col-md-4"><label class="form-label" for="institution_type">Type</label><input class="form-control" id="institution_type" name="institution_type" maxlength="80" value="<?= e($institution['institution_type']) ?>"></div>
                    <div class="col-md-4"><label class="form-label" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" maxlength="190" value="<?= e($institution['email']) ?>"></div>
                    <div class="col-md-4"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="50" value="<?= e($institution['phone']) ?>"></div>
                    <div class="col-12"><label class="form-label" for="address">Address</label><textarea class="form-control" id="address" name="address" rows="2" maxlength="1000"><?= e($institution['address']) ?></textarea></div>
                    <div class="col-md-6"><label class="form-label" for="timezone">Timezone</label><select class="form-select" id="timezone" name="timezone"><?= options_html(array_combine(INSTITUTION_TIMEZONES, INSTITUTION_TIMEZONES), $institution['timezone']) ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="currency_code">Currency</label><select class="form-select" id="currency_code" name="currency_code"><?= options_html(INSTITUTION_CURRENCIES, $institution['currency_code']) ?></select></div>
                </div>
                </fieldset>
                <?php if ($canManage): ?>
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Profile</button></div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <?php if ($canManage): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Change Status</h3></div>
                <div class="card-body">
                    <form method="post" data-confirm="Change institution status?" data-reason="Reason for the status change" data-confirm-text="Members of suspended or closed institutions cannot sign in to it.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="status">
                        <label class="form-label" for="new-status">New status</label>
                        <div class="d-flex gap-2">
                            <select class="form-select" id="new-status" name="status"><?= options_html(['pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended', 'closed' => 'Closed'], $institution['status']) ?></select>
                            <button class="btn btn-outline-primary" type="submit">Apply</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card panel mb-3">
            <div class="card-header"><h3>Administrators</h3></div>
            <?php if (!$summary['admins']): ?>
                <div class="card-body"><?= empty_state('bi-person-gear', 'No administrator yet') ?></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($summary['admins'] as $admin): ?>
                        <li><div><div class="fw-semibold"><?= e(person_name($admin)) ?></div><div class="text-muted-sm"><?= e($admin['email']) ?></div></div><?= status_badge($admin['membership_status']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <form method="post" class="card-body border-top">
                    <?= csrf_field() ?><?= idempotency_field() ?>
                    <input type="hidden" name="action" value="add_admin">
                    <label class="form-label" for="admin_email">Add administrator by e-mail</label>
                    <input class="form-control mb-2" type="email" id="admin_email" name="admin_email" required maxlength="190">
                    <details class="mb-2"><summary class="text-muted-sm">New account details</summary>
                        <input class="form-control mt-2" name="admin_username" placeholder="Username" maxlength="100">
                        <input class="form-control mt-2" name="admin_first_name" placeholder="First name" maxlength="100">
                        <input class="form-control mt-2" name="admin_last_name" placeholder="Last name" maxlength="100">
                    </details>
                    <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-person-plus me-1" aria-hidden="true"></i>Assign Administrator</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card panel">
            <div class="card-header"><h3>History</h3></div>
            <div class="card-body">
                <?php if (!$history): ?>
                    <p class="text-muted-sm mb-0">No recorded changes.</p>
                <?php else: ?>
                    <ul class="timeline">
                        <?php foreach ($history as $row): ?>
                            <li><div class="fw-semibold"><?= e(humanize($row['action'])) ?></div><div class="text-muted-sm"><?= e(($row['actor'] ?: 'System') . ' · ' . format_datetime($row['created_at'])) ?></div><?php if ($row['reason']): ?><div class="text-muted-sm">“<?= e($row['reason']) ?>”</div><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
