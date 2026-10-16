<?php
/**
 * Reconciliation: ledger vs register per account, physical counts per custodian,
 * open turnover discrepancies, pending movements and outstanding cash advances.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/movements.php';
require_institution_context();
require_permission(['reconciliation.manage', 'ledger.view']);

$institutionId = (int) current_institution_id();
if (is_post()) {
    require_permission('reconciliation.manage');
    handle_post(function () {
        $result = record_account_count(post_int('account_id') ?? 0, $_POST);
        $difference = to_cents($result['difference']);
        if ($difference === 0) {
            flash('success', 'Count matches the ledger');
        } else {
            flash('warning', 'Difference recorded', 'Counted ' . money(to_cents($result['counted']), current_currency()) . ' vs ledger ' . money(to_cents($result['ledger']), current_currency())
                . '. Investigate, then correct it with an authorised adjusting entry in the Ledger.');
        }
        return 'reconciliation/index.php';
    });
}

$accounts = reconciliation_accounts();
$discrepancies = db_all(
    "SELECT d.*, t.id AS turnover_id, t.turnover_number, g.group_name FROM turnover_discrepancies d
     JOIN cash_turnovers t ON t.id = d.turnover_id AND t.institution_id = d.institution_id
     JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
     WHERE d.institution_id = ? AND d.status IN ('open', 'under_review') ORDER BY d.reported_at",
    [$institutionId]
);
$pending = [
    'turnovers' => (int) db_value("SELECT COUNT(*) FROM cash_turnovers WHERE institution_id = ? AND status IN ('submitted', 'received_pending_verification')", [$institutionId]),
    'deposits'  => (int) db_value("SELECT COUNT(*) FROM deposits WHERE institution_id = ? AND status IN ('recorded', 'pending_confirmation')", [$institutionId]),
    'transfers' => (int) db_value("SELECT COUNT(*) FROM fund_transfers WHERE institution_id = ? AND status IN ('submitted', 'approved')", [$institutionId]),
];
$advances = db_all(
    "SELECT a.id, a.advance_number, a.status, a.released_amount, a.liquidation_deadline, CONCAT(u.first_name, ' ', u.last_name) AS recipient,
            (SELECT COALESCE(SUM(x.amount), 0) FROM cash_advance_expenses x WHERE x.institution_id = a.institution_id AND x.cash_advance_id = a.id AND x.verification_status = 'verified') AS verified,
            (SELECT COALESCE(SUM(r.returned_amount), 0) FROM cash_advance_returns r WHERE r.institution_id = a.institution_id AND r.cash_advance_id = a.id AND r.status = 'confirmed') AS returned
     FROM cash_advances a JOIN institution_memberships m ON m.id = a.recipient_membership_id JOIN users u ON u.id = m.user_id
     WHERE a.institution_id = ? AND a.status IN ('released', 'partially_liquidated', 'submitted_for_review', 'returned_for_correction', 'overdue')
     ORDER BY a.liquidation_deadline",
    [$institutionId]
);
$advancesLedger = ledger_key_balance($institutionId, 'advances');
$advancesOperational = array_sum(array_map(static fn($a) => to_cents($a['released_amount']) - to_cents($a['verified']) - to_cents($a['returned']), $advances));
$currency = current_currency();
$canManage = can('reconciliation.manage');

$pageTitle = 'Reconciliation';
$activeNav = 'reconciliation';
$breadcrumb = [auth_context()['label'], 'Finance'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Reconciliation</h2><p><?= e(auth_context()['label']) ?> · <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions('The ledger must agree with the cash register, physical counts and outstanding advances. Differences are documented here and corrected only through authorised entries.',
    '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>

<div class="stat-grid">
    <?= stat_card('bi-exclamation-diamond', 'Open Discrepancies', (string) count($discrepancies), 'Turnovers to resolve', $discrepancies ? 'danger' : 'success') ?>
    <?= stat_card('bi-hourglass-split', 'Pending Movements', (string) array_sum($pending), $pending['turnovers'] . ' turnovers · ' . $pending['deposits'] . ' deposits · ' . $pending['transfers'] . ' transfers', array_sum($pending) ? 'warning' : 'success') ?>
    <?= stat_card('bi-wallet2', 'Advances Outstanding', money($advancesLedger, $currency), $advancesLedger === $advancesOperational ? 'Ledger agrees with advance records' : 'Records show ' . money($advancesOperational, $currency) . ' — investigate', $advancesLedger === $advancesOperational ? 'success' : 'danger') ?>
</div>

<div class="card panel mb-3">
    <div class="card-header"><h3>Accounts: ledger vs register vs count</h3></div>
    <?php if (!$accounts): ?>
        <div class="card-body"><?= empty_state('bi-bank', 'No financial accounts') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr><th>Account · Custodian</th><th class="text-end">Ledger</th><th class="text-end">Register</th><th>Agreement</th><th class="text-end">Pending</th><th>Last count</th><?php if ($canManage): ?><th style="min-width:260px">Record count</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($accounts as $row): $ledger = to_cents($row['ledger_balance']); $register = to_cents($row['register_balance']); $count = $row['last_count']['data'] ?? null; ?>
                    <tr>
                        <td><a class="fw-semibold" href="<?= e(url('funds/account.php?id=' . $row['id'])) ?>"><?= e($row['account_name']) ?></a><div class="text-muted-sm"><?= e(humanize($row['account_type']) . ' · ' . ($row['custodian_name'] ?: 'no custodian')) ?></div></td>
                        <td class="text-end fw-semibold"><?= e(money($ledger, $currency)) ?></td>
                        <td class="text-end"><?= e(money($register, $currency)) ?></td>
                        <td><?= $ledger === $register ? '<span class="badge badge-soft-success">Agrees</span>' : '<span class="badge badge-soft-danger">Off by ' . e(money($register - $ledger, $currency)) . '</span>' ?></td>
                        <td class="text-end text-muted-sm"><?= to_cents($row['pending_net']) ? e(money(to_cents($row['pending_net']), $currency)) : '—' ?></td>
                        <td class="text-muted-sm"><?php if ($count): ?>
                            <?= e(format_date($count['as_of'])) ?>: <?= e(money(to_cents($count['counted']), $currency)) ?>
                            <?= to_cents($count['difference']) === 0 ? '<span class="badge badge-soft-success">match</span>' : '<span class="badge badge-soft-danger">' . e(money(to_cents($count['difference']), $currency)) . '</span>' ?>
                            <div><?= e($row['last_count']['actor']) ?></div>
                        <?php else: ?>Never<?php endif; ?></td>
                        <?php if ($canManage): ?>
                            <td>
                                <form method="post" class="d-flex gap-1 flex-wrap">
                                    <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="account_id" value="<?= e($row['id']) ?>">
                                    <input class="form-control form-control-sm text-amount" style="width:100px" name="counted_amount" placeholder="Counted" required inputmode="decimal" aria-label="Counted amount">
                                    <input class="form-control form-control-sm" style="width:130px" type="date" name="as_of" value="<?= e(date('Y-m-d')) ?>" aria-label="As of">
                                    <input class="form-control form-control-sm" name="notes" placeholder="Notes" maxlength="1000" aria-label="Notes" style="flex:1;min-width:120px">
                                    <button class="btn btn-outline-primary btn-xs" type="submit">Save</button>
                                </form>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-xl-6">
        <div class="card panel h-100">
            <div class="card-header"><h3>Open turnover discrepancies</h3></div>
            <?php if (!$discrepancies): ?>
                <div class="card-body"><?= empty_state('bi-check2-circle', 'None open') ?></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($discrepancies as $row): ?>
                        <li><div><a class="fw-semibold" href="<?= e(url('turnovers/view.php?id=' . $row['turnover_id'])) ?>"><?= e($row['turnover_number']) ?></a><div class="text-muted-sm"><?= e($row['group_name'] . ' · reported ' . format_date($row['reported_at'])) ?></div></div><span class="fw-semibold text-danger"><?= e(m($row['difference_amount'])) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card panel h-100">
            <div class="card-header"><h3>Outstanding cash advances</h3></div>
            <?php if (!$advances): ?>
                <div class="card-body"><?= empty_state('bi-wallet2', 'No outstanding advances') ?></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($advances as $row): $outstanding = to_cents($row['released_amount']) - to_cents($row['verified']) - to_cents($row['returned']); ?>
                        <li><div><a class="fw-semibold" href="<?= e(url('advances/view.php?id=' . $row['id'])) ?>"><?= e($row['advance_number']) ?></a><div class="text-muted-sm"><?= e($row['recipient'] . ' · due ' . format_date($row['liquidation_deadline'])) ?> <?= status_badge($row['status']) ?></div></div><span class="fw-semibold"><?= e(money($outstanding, $currency)) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
