<?php
/**
 * Budget: approved vs actual (posted) vs pending by category, workflow actions,
 * authorised revisions and history.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/budgets.php';
require_institution_context();
require_permission(['budgets.view', 'budgets.manage', 'budgets.approve']);

$budgetId = require_get_id();
$budget = require_found(find_budget($budgetId));

if (is_post()) {
    $action = post_str('action', 20);
    handle_post(function () use ($action, $budgetId) {
        switch ($action) {
            case 'submit':
                submit_budget($budgetId);
                flash('success', 'Submitted for approval');
                break;
            case 'approve':
                decide_budget($budgetId, true, post_str('notes', 1000));
                flash('success', 'Budget approved');
                break;
            case 'return':
                decide_budget($budgetId, false, post_str('reason', 1000));
                flash('success', 'Returned to the preparer');
                break;
            case 'revise':
                revise_budget($budgetId, $_POST);
                flash('success', 'Revision recorded', 'The previous amounts are kept in the history.');
                break;
            case 'close':
                set_budget_status($budgetId, 'closed', post_str('reason', 1000));
                flash('success', 'Budget closed');
                break;
            case 'cancel':
                set_budget_status($budgetId, 'cancelled', post_str('reason', 1000));
                flash('success', 'Budget cancelled');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'budgets/view.php?id=' . $budgetId;
    }, $action === 'revise');
}

$report = budget_vs_actual($budget);
$totals = $report['totals'];
$currency = current_currency();
$isCreator = (int) $budget['created_by'] === (int) auth_user()['id'];
$history = db_all(
    "SELECT a.action, a.reason, a.before_data, a.after_data, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE a.institution_id = ? AND a.entity_type = 'budget' AND a.entity_id = ? ORDER BY a.id",
    [current_institution_id(), $budgetId]
);
$categories = expense_categories();

$pageTitle = 'Budget ' . $budget['budget_number'];
$activeNav = 'budgets';
$breadcrumb = [auth_context()['label'], 'Budgets'];
$pageScripts = ['js/finance.js'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Budget vs Actual · <?= e($budget['budget_name']) ?></h2><p><?= e(auth_context()['label']) ?> · <?= e($budget['fund_name'] . ($budget['event_name'] ? ' · ' . $budget['event_name'] : '')) ?> · <?= e(format_date($budget['start_date']) . ' – ' . format_date($budget['end_date'])) ?> · Generated <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions($budget['budget_name'] . ' · ' . $budget['fund_name'] . ($budget['event_name'] ? ' · ' . $budget['event_name'] : '') . ' · ' . format_date($budget['start_date']) . ' – ' . format_date($budget['end_date']),
    button_link('budgets/index.php', 'All Budgets', 'bi-arrow-left', 'btn-outline-secondary')
    . ($budget['status'] === 'draft' && can('budgets.manage') ? button_link('budgets/form.php?id=' . $budgetId, 'Edit', 'bi-pencil', 'btn-outline-primary') : '')
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>

<?php if (!$budget['in_force']): ?>
    <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div>This budget is <strong><?= e(strtolower(BUDGET_STATUS_LABELS[$budget['status']] ?? $budget['status'])) ?></strong>. Figures below show spending against the proposed amounts.</div></div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('bi-clipboard-check', 'Approved budget', money($totals['approved'], $currency), BUDGET_STATUS_LABELS[$budget['status']] ?? $budget['status']) ?>
    <?= stat_card('bi-receipt', 'Actual (posted)', money($totals['actual'], $currency), $totals['approved'] ? floor($totals['actual'] * 100 / $totals['approved']) . '% used' : 'Paid and verified', $totals['actual'] > $totals['approved'] ? 'danger' : 'info') ?>
    <?= stat_card('bi-hourglass-split', 'Pending (not posted)', money($totals['pending'], $currency), 'Submitted, approved or under review', 'warning') ?>
    <?= stat_card('bi-piggy-bank', 'Available', money($totals['available'], $currency), 'Remaining ' . money($totals['remaining'], $currency) . ' less pending', $totals['available'] < 0 ? 'danger' : 'success') ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>By category</h3><?= status_badge($budget['status']) ?></div>
            <div class="table-responsive">
                <table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Category</th><th class="text-end">Approved</th><th class="text-end">Actual</th><th class="text-end">Pending</th><th class="text-end">Remaining</th><th class="text-end">Available</th><th style="width:90px">Used</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['rows'] as $row): ?>
                        <tr<?= $row['over'] ? ' class="table-danger"' : '' ?>>
                            <td><div class="fw-semibold"><?= e($row['category']) ?></div><div class="text-muted-sm"><?= e($row['budgeted'] ? ($row['description'] ?: '') : 'Not in the budget') ?></div></td>
                            <td class="text-end"><?= e(money($row['approved'], $currency)) ?></td>
                            <td class="text-end"><?= e(money($row['actual'], $currency)) ?></td>
                            <td class="text-end text-muted-sm"><?= e(money($row['pending'], $currency)) ?></td>
                            <td class="text-end"><?= e(money($row['remaining'], $currency)) ?></td>
                            <td class="text-end fw-semibold<?= $row['available'] < 0 ? ' text-danger' : '' ?>"><?= e(money($row['available'], $currency)) ?></td>
                            <td><?= progress_bar(min(100, $row['used_percent']), $row['over'] ? 'danger' : ($row['at_risk'] ? 'warning' : 'success')) ?><div class="text-muted-sm"><?= e($row['used_percent'] . '%') ?><?= $row['over'] ? ' over' : ($row['at_risk'] ? ' at risk' : '') ?></div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end"><?= e(money($totals['approved'], $currency)) ?></td><td class="text-end"><?= e(money($totals['actual'], $currency)) ?></td><td class="text-end"><?= e(money($totals['pending'], $currency)) ?></td><td class="text-end"><?= e(money($totals['remaining'], $currency)) ?></td><td class="text-end"><?= e(money($totals['available'], $currency)) ?></td><td></td></tr></tfoot>
                </table>
            </div>
            <div class="card-body text-muted-sm">Actual = paid expenses and verified liquidation receipts of <?= e($budget['fund_name']) ?><?= $budget['event_name'] ? ' for ' . e($budget['event_name']) : '' ?> dated within the period. Pending items are not posted and are shown separately.</div>
        </div>
        <?php if ($budget['in_force'] && can('budgets.approve')): ?>
            <form method="post" class="card panel form-card d-print-none" data-row-editor data-confirm="Record this budget revision?">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="revise">
                <div class="card-header"><h3>Revise budget</h3></div>
                <div class="card-body">
                    <p class="text-muted-sm">Change the approved amounts or add categories. The revision and its reason are recorded with the previous figures.</p>
                    <div class="table-responsive" data-money-sum="#revised-total">
                        <table class="table table-clean align-middle mb-2">
                            <thead><tr><th>Category</th><th>Description</th><th style="width:160px">Amount</th><th></th></tr></thead>
                            <tbody data-row-body>
                            <?php $renderLine = static function (int|string $index, array $line) use ($categories): string {
                                $name = static fn(string $field) => 'lines[' . $index . '][' . $field . ']';
                                return '<tr><td><select class="form-select form-select-sm" name="' . $name('category') . '" aria-label="Category">' . options_html(array_combine($categories, $categories), $line['category'] ?? null, 'Category…') . '</select></td>'
                                    . '<td><input class="form-control form-control-sm" name="' . $name('description') . '" maxlength="255" aria-label="Description" value="' . e((string) ($line['description'] ?? '')) . '"></td>'
                                    . '<td><input class="form-control form-control-sm text-amount" name="' . $name('allocated_amount') . '" inputmode="decimal" aria-label="Amount" value="' . e(isset($line['allocated_amount']) ? cents_to_decimal(to_cents($line['allocated_amount'])) : '') . '"></td>'
                                    . '<td><button class="btn btn-outline-danger btn-xs" type="button" data-row-remove aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td></tr>';
                            }; ?>
                            <?php foreach ($budget['lines'] as $index => $line): ?><?= $renderLine($index, $line) ?><?php endforeach; ?>
                            </tbody>
                            <tfoot><tr class="fw-semibold"><td><button class="btn btn-outline-primary btn-sm" type="button" data-row-add><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add line</button></td><td class="text-end">New total</td><td id="revised-total" data-currency="<?= e($currency === 'PHP' ? '₱' : '') ?>"></td><td></td></tr></tfoot>
                        </table>
                    </div>
                    <template data-row-template><?= $renderLine('__INDEX__', []) ?></template>
                    <label class="form-label required" for="revise-reason">Reason for the revision</label>
                    <textarea class="form-control mb-2" id="revise-reason" name="reason" rows="2" required minlength="10" maxlength="1000"></textarea>
                    <button class="btn btn-primary" type="submit">Record Revision</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Details</h3></div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Number</dt><dd><?= e($budget['budget_number']) ?></dd></div>
                    <div><dt>Fund</dt><dd><a href="<?= e(url('funds/view.php?id=' . $budget['fund_id'])) ?>"><?= e($budget['fund_name']) ?></a></dd></div>
                    <div><dt>Event</dt><dd><?= $budget['event_id'] ? '<a href="' . e(url('events/view.php?id=' . $budget['event_id'])) . '">' . e($budget['event_name']) . '</a>' : 'Whole fund' ?></dd></div>
                    <div><dt>Prepared by</dt><dd><?= e($budget['created_by_name']) ?></dd></div>
                    <div><dt>Approved by</dt><dd><?= e($budget['approved_by_name'] ? $budget['approved_by_name'] . ' · ' . format_datetime($budget['approved_at']) : '—') ?></dd></div>
                </dl>
            </div>
        </div>
        <div class="d-print-none">
        <?php if ($budget['status'] === 'draft' && can('budgets.manage')): ?>
            <div class="card panel mb-3"><div class="card-body">
                <form method="post" class="mb-2" data-confirm="Submit this budget for approval?"><?= csrf_field() ?><input type="hidden" name="action" value="submit"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit for Approval</button></form>
                <form method="post" data-confirm="Cancel this budget?" data-reason="Reason" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger w-100" type="submit">Cancel Budget</button></form>
            </div></div>
        <?php endif; ?>
        <?php if ($budget['status'] === 'pending' && can('budgets.approve')): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Approval</h3></div>
                <div class="card-body">
                    <?php if ($isCreator): ?>
                        <p class="text-muted-sm mb-0">You prepared this budget, so another approver must decide on it.</p>
                    <?php else: ?>
                        <form method="post" class="mb-2" data-confirm="Approve this budget?"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input class="form-control mb-2" name="notes" maxlength="1000" placeholder="Notes (optional)" aria-label="Notes"><button class="btn btn-primary w-100" type="submit">Approve</button></form>
                        <form method="post" data-confirm="Return this budget to the preparer?" data-reason="What should be changed?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="return"><button class="btn btn-outline-danger w-100" type="submit">Return for Changes</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($budget['in_force'] && can_any(['budgets.manage', 'budgets.approve'])): ?>
            <form method="post" class="card panel mb-3" data-confirm="Close this budget?" data-reason="Reason (e.g. the period has ended)">
                <?= csrf_field() ?><input type="hidden" name="action" value="close">
                <div class="card-body"><p class="text-muted-sm">Closing keeps the figures for reporting; spending is no longer monitored against it.</p><button class="btn btn-outline-secondary w-100" type="submit">Close Budget</button></div>
            </form>
        <?php endif; ?>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>History</h3></div>
            <div class="card-body"><ul class="timeline">
                <?php foreach ($history as $row): $after = json_decode((string) $row['after_data'], true) ?: []; $before = json_decode((string) $row['before_data'], true) ?: []; ?>
                    <li><div class="fw-semibold"><?= e(humanize(str_replace('budget.', '', $row['action']))) ?></div><div class="text-muted-sm"><?= e(($row['actor'] ?: 'System') . ' · ' . format_datetime($row['created_at'])) ?></div>
                        <?php if (isset($after['total_amount'], $before['total_amount']) && $after['total_amount'] !== $before['total_amount']): ?><div class="text-muted-sm">Total <?= e(m($before['total_amount'])) ?> → <?= e(m($after['total_amount'])) ?></div><?php endif; ?>
                        <?php if ($row['reason']): ?><div class="text-muted-sm">“<?= e($row['reason']) ?>”</div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
