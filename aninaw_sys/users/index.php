<?php
/**
 * Users.
 * - Institution workspace: members of this institution with their roles (users.view).
 * - Platform workspace: every account on the platform, read-only (platform.users.view).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';

$isPlatform = auth_context()['type'] === 'platform';
require_permission($isPlatform ? 'platform.users.view' : 'users.view');

$search = get_str('q', 100);
$status = get_str('status', 20);
$roleId = get_int('role');
$params = [];
$where = [];

if ($search !== '') {
    $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR CONCAT(u.first_name, " ", u.last_name) LIKE ?)';
    array_push($params, ...array_fill(0, 5, "%{$search}%"));
}

if ($isPlatform) {
    if (in_array($status, ['pending', 'active', 'locked', 'disabled'], true)) {
        $where[] = 'u.status = ?';
        $params[] = $status;
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    [$orderBy, $sortKey, $sortDir] = sort_clause(['name' => 'u.last_name', 'email' => 'u.email', 'status' => 'u.status', 'login' => 'u.last_login_at'], 'name');
    $pagination = paginate((int) db_value("SELECT COUNT(*) FROM users u {$whereSql}", $params));
    $rows = db_all(
        "SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.status, u.last_login_at,
                (SELECT COUNT(*) FROM institution_memberships m WHERE m.user_id = u.id AND m.membership_status = 'active') AS memberships,
                (SELECT GROUP_CONCAT(r.role_name SEPARATOR ', ') FROM platform_user_roles pr JOIN roles r ON r.id = pr.role_id WHERE pr.user_id = u.id) AS platform_roles
         FROM users u {$whereSql} ORDER BY {$orderBy}, u.id LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
        $params
    );
} else {
    require_institution_context();
    $where[] = 'm.institution_id = ?';
    $params[] = current_institution_id();
    if (in_array($status, ['pending', 'active', 'suspended', 'ended'], true)) {
        $where[] = 'm.membership_status = ?';
        $params[] = $status;
    }
    if ($roleId) {
        $where[] = 'EXISTS (SELECT 1 FROM membership_roles fr WHERE fr.membership_id = m.id AND fr.role_id = ?)';
        $params[] = $roleId;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    [$orderBy, $sortKey, $sortDir] = sort_clause(['name' => 'u.last_name', 'email' => 'u.email', 'status' => 'm.membership_status', 'login' => 'u.last_login_at', 'joined' => 'm.joined_at'], 'name');
    $pagination = paginate((int) db_value("SELECT COUNT(*) FROM institution_memberships m JOIN users u ON u.id = m.user_id {$whereSql}", $params));
    $rows = db_all(
        "SELECT m.id AS membership_id, m.membership_status, m.joined_at, u.id AS user_id, u.username, u.email, u.first_name, u.last_name,
                u.status AS account_status, u.last_login_at,
                (SELECT GROUP_CONCAT(r.role_name ORDER BY r.id SEPARATOR ', ') FROM membership_roles mr JOIN roles r ON r.id = mr.role_id WHERE mr.membership_id = m.id) AS roles
         FROM institution_memberships m JOIN users u ON u.id = m.user_id
         {$whereSql} ORDER BY {$orderBy}, m.id LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
        $params
    );
    $roleOptions = options_from_rows(db_all("SELECT id, role_name FROM roles WHERE scope_type = 'institution' ORDER BY id"), 'id', 'role_name');
}

$pageTitle = 'Users';
$activeNav = 'users';
$breadcrumb = [auth_context()['label'], $isPlatform ? 'Platform' : 'Administration'];
$pageScripts = array_merge($pageScripts ?? [], ['js/page_modal.js']);
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions(
    $isPlatform ? 'All accounts on the platform. Access to each institution is managed by that institution\'s administrators.' : 'Members of this institution, their roles and access status.',
    !$isPlatform && can('users.manage') ? button_link('users/create.php', 'Add Member', 'bi-person-plus', 'btn-primary', 'lg') : ''
) ?>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Name, e-mail or username">
        </div>
        <?php if (!$isPlatform): ?>
            <div class="filter-field">
                <label for="role">Role</label>
                <select class="form-select" id="role" name="role" data-autosubmit><?= options_html($roleOptions, $roleId, 'All roles') ?></select>
            </div>
        <?php endif; ?>
        <div class="filter-field">
            <label for="status"><?= $isPlatform ? 'Account status' : 'Membership' ?></label>
            <select class="form-select" id="status" name="status" data-autosubmit>
                <?= options_html($isPlatform
                    ? ['pending' => 'Pending', 'active' => 'Active', 'locked' => 'Locked', 'disabled' => 'Disabled']
                    : ['active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended', 'ended' => 'Ended'], $status, 'All') ?>
            </select>
        </div>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search me-1" aria-hidden="true"></i>Filter</button>
    </form>

    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-people', 'No users found', 'Try a different search or filter.') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr>
                    <th><?= sort_link('Name', 'name', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('E-mail', 'email', $sortKey, $sortDir) ?></th>
                    <th><?= $isPlatform ? 'Platform roles' : 'Roles' ?></th>
                    <?php if ($isPlatform): ?><th class="text-end">Institutions</th><?php endif; ?>
                    <th><?= sort_link('Status', 'status', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Last sign-in', 'login', $sortKey, $sortDir) ?></th>
                    <?php if (!$isPlatform): ?><th></th><?php endif; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e(person_name($row)) ?></div><div class="text-muted-sm">@<?= e($row['username']) ?></div></td>
                        <td><?= e($row['email']) ?></td>
                        <?php if ($isPlatform): ?>
                            <td><?= e($row['platform_roles'] ?: '—') ?></td>
                            <td class="text-end"><?= e((string) $row['memberships']) ?></td>
                            <td><?= status_badge($row['status']) ?></td>
                        <?php else: ?>
                            <td class="text-muted-sm" style="max-width:280px"><?= e($row['roles'] ?: 'No roles') ?></td>
                            <td><?= status_badge($row['membership_status']) ?><?= $row['account_status'] !== 'active' ? ' ' . status_badge('account ' . $row['account_status']) : '' ?></td>
                        <?php endif; ?>
                        <td class="text-muted-sm"><?= e($row['last_login_at'] ? format_datetime($row['last_login_at']) : 'Never') ?></td>
                        <?php if (!$isPlatform): ?>
                            <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('users/edit.php?id=' . $row['membership_id'])) ?>">Open</a></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
