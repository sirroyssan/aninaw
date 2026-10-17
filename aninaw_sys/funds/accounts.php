<?php
/**
 * Financial accounts: where money is physically held (cash custody, bank, e-wallet),
 * each with an accountable custodian and a balance from posted entries.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/funds.php';
require_institution_context();
require_permission(['funds.view_all', 'funds.manage', 'reconciliation.manage']);

$canManage = can('funds.manage');
if (is_post()) {
    require_permission('funds.manage');
    handle_post(function () {
        $id = post_int('account_id');
        $savedId = save_financial_account($id ?: null, $_POST);
        flash('success', $id ? 'Account updated' : 'Account created', $id ? '' : 'Map it to the funds whose money it may hold.');
        return $id ? 'funds/accounts.php' : 'funds/account.php?id=' . $savedId; // a new account goes on to its funds
    });
}

$institutionId = (int) current_institution_id();
$type = get_str('type', 20);
$where = 'fa.institution_id = ?';
$params = [$institutionId];
if (isset(FINANCIAL_ACCOUNT_TYPES[$type])) {
    $where .= ' AND fa.account_type = ?';
    $params[] = $type;
}
$accounts = db_all(
    "SELECT fa.*, CONCAT(u.first_name, ' ', u.last_name) AS custodian_name,
            (SELECT COALESCE(SUM(l.debit_amount - l.credit_amount), 0) FROM journal_lines l
               JOIN journal_entries j ON j.id = l.journal_entry_id AND j.institution_id = l.institution_id AND j.status = 'posted'
              WHERE l.institution_id = fa.institution_id AND l.financial_account_id = fa.id) AS balance,
            (SELECT COUNT(*) FROM fund_account_mappings fm WHERE fm.institution_id = fa.institution_id AND fm.financial_account_id = fa.id
              AND (fm.valid_until IS NULL OR fm.valid_until >= CURDATE())) AS funds
     FROM financial_accounts fa
     LEFT JOIN institution_memberships m ON m.id = fa.custodian_membership_id AND m.institution_id = fa.institution_id
     LEFT JOIN users u ON u.id = m.user_id
     WHERE {$where} ORDER BY fa.status = 'active' DESC, fa.account_type, fa.account_name",
    $params
);
$editing = null;
$failedId = old('form_name') === 'account' ? (int) old('account_id') : 0; // a failed save reopens its pop-up
if ($editId = (get_int('edit') ?? ($failedId ?: null))) {
    $editing = require_found(db_one('SELECT * FROM financial_accounts WHERE institution_id = ? AND id = ?', [$institutionId, $editId]));
}
$total = array_sum(array_map(static fn($row) => to_cents($row['balance']), $accounts));

$newFailed = old('form_name') === 'account' && !$failedId;
$pageScripts = ['js/modal_from.js'];
$pageTitle = 'Financial Accounts';
$activeNav = 'accounts';
$breadcrumb = [auth_context()['label'], 'Finance'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Where money is physically held. Every account has an accountable custodian and may hold only the funds mapped to it.',
    button_link('funds/index.php', 'Funds', 'bi-wallet', 'btn-outline-secondary')
    . ($canManage ? '<button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#new-account-modal"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New Account</button>' : '')) ?>

<div class="row g-3">
    <div class="col-12">
        <div class="card panel">
            <form class="filter-bar" method="get">
                <div class="filter-field"><label for="type">Type</label><select class="form-select" id="type" name="type" data-autosubmit><?= options_html(FINANCIAL_ACCOUNT_TYPES, $type, 'All types') ?></select></div>
                <div class="ms-auto text-end"><div class="text-muted-sm">Total held (posted)</div><div class="fw-semibold"><?= e(money($total, current_currency())) ?></div></div>
            </form>
            <?php if (!$accounts): ?>
                <div class="card-body"><?= empty_state('bi-bank', 'No financial accounts yet', 'Create the treasurer\'s cash box, each collector\'s custody and the bank account.') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Account</th><th>Custodian</th><th class="text-end">Funds</th><th class="text-end">Balance</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($accounts as $row): ?>
                            <tr>
                                <td><div class="fw-semibold"><?= e($row['account_name']) ?></div><div class="text-muted-sm"><?= e(FINANCIAL_ACCOUNT_TYPES[$row['account_type']] ?? $row['account_type']) ?><?= $row['account_reference'] ? ' · ' . e($row['account_reference']) : '' ?></div></td>
                                <td><?= e($row['custodian_name'] ?: '—') ?></td>
                                <td class="text-end"><?= e((string) $row['funds']) ?></td>
                                <td class="text-end fw-semibold"><?= e(m($row['balance'])) ?></td>
                                <td><?= status_badge($row['status']) ?></td>
                                <td class="actions">
                                    <a class="btn btn-outline-primary btn-xs" href="<?= e(url('funds/account.php?id=' . $row['id'])) ?>">Open</a>
                                    <?php if ($canManage && $row['status'] !== 'closed'): ?><a class="btn btn-outline-secondary btn-xs" href="<?= e(url('funds/accounts.php?edit=' . $row['id'])) ?>" data-modal-from="account-modal">Edit</a><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
// Add / Edit account pop-ups: $acc = the account being edited (null for a new one), $af = id prefix, $ao = value for a field.
$renderAccountForm = static function (?array $acc, string $af, callable $ao): void { ?>
    <form method="post" class="modal-content">
        <?= csrf_field() ?><?= idempotency_field() ?>
        <input type="hidden" name="form_name" value="account"><input type="hidden" name="account_id" value="<?= e($acc['id'] ?? '') ?>">
        <div class="modal-header"><h2 class="modal-title h5" id="<?= $af ?>title"><?= $acc ? 'Edit ' . e($acc['account_name']) : 'New account' ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label required" for="<?= $af ?>account_name">Name</label><input class="form-control" id="<?= $af ?>account_name" name="account_name" required maxlength="180" value="<?= e($ao('account_name')) ?>" placeholder="e.g. Treasurer Cash Box, BPI Savings, RAITE GCash"></div>
            <div class="mb-3"><label class="form-label required" for="<?= $af ?>account_type">Type</label><select class="form-select" id="<?= $af ?>account_type" name="account_type" required><?= options_html(FINANCIAL_ACCOUNT_TYPES, $ao('account_type', 'cash_custody')) ?></select><div class="form-text">Cash payments go into Cash custody, GCash into E-wallet, bank transfers into Bank.</div></div>
            <div class="mb-3"><label class="form-label" for="<?= $af ?>account_reference">Reference</label><input class="form-control" id="<?= $af ?>account_reference" name="account_reference" maxlength="255" value="<?= e($ao('account_reference')) ?>" placeholder="Bank / account no. (last digits), wallet number"><div class="form-text">Avoid full account numbers.</div></div>
            <div class="mb-0"><label class="form-label" for="<?= $af ?>custodian_membership_id">Custodian</label><select class="form-select" id="<?= $af ?>custodian_membership_id" name="custodian_membership_id"><?= options_html(member_options(), $ao('custodian_membership_id'), 'None') ?></select><div class="form-text">Required for cash custody: the person physically holding the cash. For GCash: whoever holds the phone / app.</div></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save</button>
        </div>
    </form>
<?php }; ?>
<?php if ($canManage): ?>
    <div class="modal fade" id="new-account-modal" tabindex="-1" aria-labelledby="na-title" aria-hidden="true"<?= $newFailed ? ' data-open-on-load' : '' ?>>
        <div class="modal-dialog modal-dialog-centered"><?php $renderAccountForm(null, 'na-', static fn(string $key, $default = '') => $newFailed ? old($key, $default) : $default); ?></div>
    </div>
    <?php if ($editing): ?>
        <div class="modal fade" id="account-modal" tabindex="-1" aria-labelledby="ea-title" aria-hidden="true" data-open-on-load>
            <div class="modal-dialog modal-dialog-centered"><?php $renderAccountForm($editing, 'ea-', static fn(string $key, $default = '') => $failedId ? old($key, $default) : ($editing[$key] ?? $default)); ?></div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
