<?php
/**
 * Fund accounts with their posted positions (cash held + outstanding advances).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/funds.php';
require_institution_context();
require_permission('funds.view');

$search = get_str('q', 100);
$status = get_str('status', 20);
[$visibility, $params] = fund_visibility('f');
$where = ['f.institution_id = ?', $visibility];
array_unshift($params, current_institution_id());
if ($search !== '') {
    $where[] = '(f.fund_name LIKE ? OR f.fund_code LIKE ? OR f.fund_category LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
}
if (in_array($status, ['draft', 'pending', 'active', 'suspended', 'closed'], true)) {
    $where[] = 'f.status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);
$institutionId = (int) current_institution_id();
$advancesCode = ledger_account($institutionId, 'advances')['code'];

[$orderBy, $sortKey, $sortDir] = sort_clause(['name' => 'f.fund_name', 'code' => 'f.fund_code', 'cash' => 'cash', 'status' => 'f.status'], 'name');
$pagination = paginate((int) db_value("SELECT COUNT(*) FROM fund_accounts f WHERE {$whereSql}", $params));
$funds = db_all(
    "SELECT f.id, f.fund_code, f.fund_name, f.fund_category, f.status, f.opening_balance, CONCAT(u.first_name, ' ', u.last_name) AS custodian_name,
            COALESCE(b.cash, 0) AS cash, COALESCE(b.advances, 0) AS advances
     FROM fund_accounts f
     JOIN institution_memberships m ON m.id = f.custodian_membership_id AND m.institution_id = f.institution_id
     JOIN users u ON u.id = m.user_id
     LEFT JOIN (SELECT l.fund_id,
                       SUM(CASE WHEN l.financial_account_id IS NOT NULL THEN l.debit_amount - l.credit_amount ELSE 0 END) AS cash,
                       SUM(CASE WHEN l.account_code = ? THEN l.debit_amount - l.credit_amount ELSE 0 END) AS advances
                FROM journal_lines l JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
                WHERE l.institution_id = ? GROUP BY l.fund_id) b ON b.fund_id = f.id
     WHERE {$whereSql} ORDER BY {$orderBy}, f.id LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    array_merge([$advancesCode, $institutionId], $params)
);

$pageScripts = ['js/modal_from.js'];
$pageTitle = 'Funds';
$activeNav = 'funds';
$breadcrumb = [auth_context()['label'], 'Finance'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Each fund is a purpose money is collected for. Balances come from posted journal entries.',
    (can_any(['funds.manage', 'funds.view_all']) ? button_link('funds/accounts.php', 'Financial Accounts', 'bi-bank', 'btn-outline-primary') : '')
    . (can('funds.manage') ? '<a class="btn btn-primary" href="' . e(url('funds/create.php')) . '" data-bs-toggle="modal" data-bs-target="#new-fund-modal"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Fund</a>' : '')) ?>

<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Name, code or category"></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(['draft' => 'Draft', 'pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended', 'closed' => 'Closed'], $status, 'All') ?></select></div>
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
    </form>
    <?php if (!$funds): ?>
        <div class="card-body"><?= empty_state('bi-wallet', 'No funds found', can('funds.manage') ? 'Create a fund such as "Graduation Fund" or "RAITE Fund 2026".' : 'No funds are assigned to you.') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr>
                    <th><?= sort_link('Fund', 'name', $sortKey, $sortDir) ?></th>
                    <th>Custodian</th>
                    <th class="text-end"><?= sort_link('Cash held', 'cash', $sortKey, $sortDir) ?></th>
                    <th class="text-end">Advances out</th>
                    <th class="text-end">Fund total</th>
                    <th><?= sort_link('Status', 'status', $sortKey, $sortDir) ?></th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($funds as $row): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($row['fund_name']) ?></div><div class="text-muted-sm"><?= e($row['fund_code'] . ($row['fund_category'] ? ' · ' . $row['fund_category'] : '')) ?></div></td>
                        <td><?= e($row['custodian_name']) ?></td>
                        <td class="text-end"><?= e(m($row['cash'])) ?></td>
                        <td class="text-end"><?= e(m($row['advances'])) ?></td>
                        <td class="text-end fw-semibold"><?= e(money(to_cents($row['cash']) + to_cents($row['advances']), current_currency())) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('funds/view.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php if (can('funds.manage')): $nf = old('form_name') === 'fund'; $fv = static fn(string $key, string $default = '') => $nf ? old($key, $default) : $default; ?>
<div class="modal fade" id="new-fund-modal" tabindex="-1" aria-labelledby="new-fund-title" aria-hidden="true"<?= $nf ? ' data-open-on-load' : '' ?>>
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="post" action="<?= e(url('funds/create.php')) ?>" class="modal-content">
            <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="form_name" value="fund">
            <div class="modal-header"><h2 class="modal-title h5" id="new-fund-title">New fund</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <p class="text-muted-sm">A <strong>fund</strong> is what the money is for (e.g. RAITE Fund 2026). Where it is physically held (a cash box, GCash, a bank account) is a <strong>financial account</strong>, linked to the fund on the next page.</p>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label required" for="nf-fund_code">Code</label><input class="form-control" id="nf-fund_code" name="fund_code" required maxlength="60" value="<?= e($fv('fund_code')) ?>" placeholder="RAITE26"></div>
                    <div class="col-md-8"><label class="form-label required" for="nf-fund_name">Name</label><input class="form-control" id="nf-fund_name" name="fund_name" required maxlength="180" value="<?= e($fv('fund_name')) ?>" placeholder="RAITE Fund 2026"></div>
                    <div class="col-md-6"><label class="form-label" for="nf-fund_category">Classification</label>
                        <input class="form-control" id="nf-fund_category" name="fund_category" maxlength="100" value="<?= e($fv('fund_category')) ?>" list="nf-fund-categories" placeholder="e.g. Restricted, Event, Class">
                        <datalist id="nf-fund-categories"><option value="Event fund"><option value="Class fund"><option value="Restricted / Donation"><option value="Dues"><option value="Emergency"><option value="General"></datalist></div>
                    <div class="col-md-6"><label class="form-label required" for="nf-custodian_membership_id">Accountable custodian</label><select class="form-select" id="nf-custodian_membership_id" name="custodian_membership_id" required><?= options_html(member_options(), $fv('custodian_membership_id'), 'Select…') ?></select></div>
                    <div class="col-12"><label class="form-label" for="nf-purpose">Purpose and restrictions</label><textarea class="form-control" id="nf-purpose" name="purpose" rows="3" maxlength="2000" placeholder="What this money may and may not be used for"><?= e($fv('purpose')) ?></textarea></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Create Fund</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
