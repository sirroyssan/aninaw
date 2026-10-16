<?php
/**
 * Institutions (platform): search, filter and open institution profiles.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';

if (auth_context()['type'] !== 'platform') {
    render_error_page(403, 'Platform Only', 'This page is available only in the Platform Administration workspace.');
}
require_permission('platform.institutions.view');

$search = get_str('q', 100);
$status = get_str('status', 20);

$where = ['1 = 1'];
$params = [];
if ($search !== '') {
    $where[] = '(i.institution_name LIKE ? OR i.institution_code LIKE ? OR i.email LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
}
if (in_array($status, ['pending', 'active', 'suspended', 'closed'], true)) {
    $where[] = 'i.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

[$orderBy, $sortKey, $sortDir] = sort_clause([
    'name' => 'i.institution_name', 'code' => 'i.institution_code', 'status' => 'i.status', 'created' => 'i.created_at', 'members' => 'active_members',
], 'name');

$pagination = paginate((int) db_value("SELECT COUNT(*) FROM institutions i WHERE {$whereSql}", $params));
$institutions = db_all(
    "SELECT i.id, i.institution_code, i.institution_name, i.institution_type, i.status, i.created_at,
            (SELECT COUNT(*) FROM institution_memberships m WHERE m.institution_id = i.id AND m.membership_status = 'active') AS active_members
     FROM institutions i WHERE {$whereSql}
     ORDER BY {$orderBy}, i.id LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Institutions';
$activeNav = 'institutions';
$breadcrumb = ['Platform'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Institutions on the platform. Financial records stay private to each institution.',
    can('platform.institutions.manage') ? button_link('institutions/create.php', 'Add Institution', 'bi-building-add') : '') ?>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field grow">
            <label for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Name, code or e-mail">
        </div>
        <div class="filter-field">
            <label for="status">Status</label>
            <select class="form-select" id="status" name="status" data-autosubmit>
                <?= options_html(['pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended', 'closed' => 'Closed'], $status, 'All statuses') ?>
            </select>
        </div>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search me-1" aria-hidden="true"></i>Filter</button>
    </form>

    <?php if (!$institutions): ?>
        <div class="card-body"><?= empty_state('bi-building', 'No institutions found', $search || $status ? 'Try a different search or filter.' : 'Add the first institution to begin onboarding.') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr>
                    <th><?= sort_link('Institution', 'name', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Code', 'code', $sortKey, $sortDir) ?></th>
                    <th>Type</th>
                    <th class="text-end"><?= sort_link('Active members', 'members', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Status', 'status', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Created', 'created', $sortKey, $sortDir) ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($institutions as $row): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($row['institution_name']) ?></td>
                        <td><?= e($row['institution_code']) ?></td>
                        <td><?= e($row['institution_type'] ?: '—') ?></td>
                        <td class="text-end"><?= e(number_format((int) $row['active_members'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="text-muted-sm"><?= e(format_date($row['created_at'])) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('institutions/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
