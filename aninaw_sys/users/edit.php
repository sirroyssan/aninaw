<?php
/**
 * Member details: profile, membership and account status, password link, activity.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/users.php';
require_institution_context();
require_permission('users.view');

$membershipId = require_get_id();
$member = require_found(find_membership_detail($membershipId));
$isSelf = (int) $member['user_id'] === (int) auth_user()['id'];
$editable = account_editable_here((int) $member['user_id']);

if (is_post()) {
    require_permission('users.manage');
    $action = post_str('action', 30);
    handle_post(function () use ($action, $membershipId, $member) {
        switch ($action) {
            case 'profile':
                update_member_profile($membershipId, $_POST);
                flash('success', 'Profile saved');
                break;
            case 'membership_status':
                set_membership_status($membershipId, post_str('status', 20), post_str('reason', 1000));
                flash('success', 'Membership updated');
                break;
            case 'account_status':
                set_account_status($membershipId, post_str('status', 20), post_str('reason', 1000));
                flash('success', 'Account updated');
                break;
            case 'reset_default':
                remember_issued_password(person_name($member), (string) $member['username'], reset_member_to_default_password($membershipId));
                flash('success', 'Password reset', 'Give them the default password shown below. They must change it when they sign in.');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'users/edit.php?id=' . $membershipId;
    }, in_array($action, ['profile', 'reset_default'], true));
}

$roles = db_all('SELECT r.role_name, r.description FROM membership_roles mr JOIN roles r ON r.id = mr.role_id WHERE mr.membership_id = ? ORDER BY r.id', [$membershipId]);
$otherInstitutions = (int) db_value("SELECT COUNT(*) FROM institution_memberships WHERE user_id = ? AND institution_id <> ? AND membership_status = 'active'", [$member['user_id'], current_institution_id()]);
$activity = db_all(
    "SELECT action, entity_type, entity_id, reason, created_at FROM audit_logs
     WHERE institution_id = ? AND (actor_user_id = ? OR (entity_type = 'institution_membership' AND entity_id = ?))
     ORDER BY id DESC LIMIT 15",
    [current_institution_id(), $member['user_id'], $membershipId]
);
$canManage = can('users.manage');

$pageTitle = person_name($member);
$activeNav = 'users';
$breadcrumb = [auth_context()['label'], 'Users'];
require APP_ROOT . '/includes/header.php';
?>
<?= issued_link_notice() ?><?= issued_password_notice() ?>
<?= page_actions('@' . $member['username'] . ' · ' . $member['email'],
    button_link('users/index.php', 'All Users', 'bi-arrow-left', 'btn-outline-secondary')
    . (can('roles.assign') && !$isSelf ? button_link('users/roles.php?id=' . $membershipId, 'Manage Roles', 'bi-shield-lock') : '')) ?>

<div class="stat-grid">
    <?= stat_card('bi-person-check', 'Membership', humanize($member['membership_status']), 'Joined ' . format_date($member['joined_at'] ?: $member['member_since']), $member['membership_status'] === 'active' ? 'success' : 'warning') ?>
    <?= stat_card('bi-person-lock', 'Account', humanize($member['account_status']), $otherInstitutions ? "Also active in {$otherInstitutions} other institution(s)" : 'This institution only', $member['account_status'] === 'active' ? 'success' : 'danger') ?>
    <?= stat_card('bi-clock-history', 'Last Sign-in', $member['last_login_at'] ? format_date($member['last_login_at']) : 'Never', $member['last_login_at'] ? format_datetime($member['last_login_at']) : '', 'info') ?>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <form method="post" class="card panel form-card mb-3">
            <?= csrf_field() ?><?= idempotency_field() ?>
            <input type="hidden" name="action" value="profile">
            <div class="card-header"><h3>Profile</h3></div>
            <div class="card-body">
                <?php if (!$editable): ?>
                    <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div>This account is also used in another institution or on the platform, so its personal details can only be changed by its owner or a platform administrator.</div></div>
                <?php endif; ?>
                <fieldset <?= $editable && $canManage ? '' : 'disabled' ?>>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label required" for="username">Username</label><input class="form-control" id="username" name="username" required maxlength="100" value="<?= e($member['username']) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="email">E-mail</label><input class="form-control" type="email" id="email" name="email" maxlength="190" value="<?= e($member['email']) ?>"></div>
                        <div class="col-md-4"><label class="form-label required" for="first_name">First name</label><input class="form-control" id="first_name" name="first_name" required maxlength="100" value="<?= e($member['first_name']) ?>"></div>
                        <div class="col-md-4"><label class="form-label" for="middle_name">Middle name</label><input class="form-control" id="middle_name" name="middle_name" maxlength="100" value="<?= e($member['middle_name']) ?>"></div>
                        <div class="col-md-4"><label class="form-label required" for="last_name">Last name</label><input class="form-control" id="last_name" name="last_name" required maxlength="100" value="<?= e($member['last_name']) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="phone">Phone</label><input class="form-control" id="phone" name="phone" maxlength="50" value="<?= e($member['phone']) ?>"></div>
                    </div>
                </fieldset>
                <?php if ($editable && $canManage): ?>
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save Profile</button></div>
                <?php endif; ?>
            </div>
        </form>

        <div class="card panel">
            <div class="card-header"><h3>Activity in this institution</h3></div>
            <?php if (!$activity): ?>
                <div class="card-body"><?= empty_state('bi-clock-history', 'No recorded activity') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Action</th><th>Record</th><th>When</th></tr></thead>
                        <tbody>
                        <?php foreach ($activity as $row): ?>
                            <tr>
                                <td><div class="fw-semibold"><?= e(humanize($row['action'])) ?></div><?php if ($row['reason']): ?><div class="text-muted-sm">“<?= e($row['reason']) ?>”</div><?php endif; ?></td>
                                <td class="text-muted-sm"><?= e(humanize($row['entity_type'])) ?><?= $row['entity_id'] ? ' #' . e((string) $row['entity_id']) : '' ?></td>
                                <td class="text-muted-sm"><?= e(format_datetime($row['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Roles</h3><?php if (can('roles.assign') && !$isSelf): ?><a class="btn btn-outline-primary btn-xs" href="<?= e(url('users/roles.php?id=' . $membershipId)) ?>">Change</a><?php endif; ?></div>
            <?php if (!$roles): ?>
                <div class="card-body"><?= empty_state('bi-shield-lock', 'No roles assigned', 'Without a role this person cannot open this institution.') ?></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($roles as $role): ?>
                        <li><div><div class="fw-semibold"><?= e($role['role_name']) ?></div><div class="text-muted-sm"><?= e($role['description']) ?></div></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if ($canManage && !$isSelf): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Access</h3></div>
                <div class="card-body">
                    <form method="post" class="mb-3" data-confirm="Change membership status?" data-reason="Reason for the change">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="membership_status">
                        <label class="form-label" for="membership-status">Membership in this institution</label>
                        <div class="d-flex gap-2">
                            <select class="form-select" id="membership-status" name="status"><?= options_html(['active' => 'Active', 'suspended' => 'Suspended', 'ended' => 'Ended'], $member['membership_status']) ?></select>
                            <button class="btn btn-outline-primary" type="submit">Apply</button>
                        </div>
                    </form>
                    <?php if ($editable): ?>
                        <form method="post" class="mb-3" data-confirm="Change account status?" data-reason="Reason for the change" data-confirm-text="A disabled account cannot sign in anywhere, and its open sessions end immediately." data-danger>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="account_status">
                            <label class="form-label" for="account-status">Account</label>
                            <div class="d-flex gap-2">
                                <select class="form-select" id="account-status" name="status"><?= options_html(['active' => 'Active', 'disabled' => 'Disabled'], $member['account_status'] === 'active' ? 'active' : 'disabled') ?></select>
                                <button class="btn btn-outline-primary" type="submit">Apply</button>
                            </div>
                        </form>
                        <form method="post" data-confirm="Reset to a default password?" data-confirm-text="Their current password stops working and they are signed out. They must change the new default password when they sign in.">
                            <?= csrf_field() ?><?= idempotency_field() ?>
                            <input type="hidden" name="action" value="reset_default">
                            <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-key me-1" aria-hidden="true"></i>Reset to Default Password</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($isSelf): ?>
            <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div>This is your own membership. Another administrator must change your roles or access. Change your password under <a href="<?= e(url('account.php')) ?>">My Account</a>.</div></div>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
