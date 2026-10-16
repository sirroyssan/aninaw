<?php
/**
 * Expense details and workflow: submit, approve/reject, pay (post), reverse, delete draft.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/expenses.php';
require_institution_context();
require_permission(['expenses.view', 'expenses.record', 'expenses.approve']);

$expenseId = require_get_id();
$expense = require_found(find_expense($expenseId));

if (is_post()) {
    $action = post_str('action', 20);
    handle_post(function () use ($action, $expenseId) {
        switch ($action) {
            case 'submit':
                submit_expense($expenseId);
                flash('success', 'Submitted for approval');
                break;
            case 'approve':
                decide_expense($expenseId, true, post_str('notes', 1000));
                flash('success', 'Expense approved');
                break;
            case 'reject':
                decide_expense($expenseId, false, post_str('reason', 1000));
                flash('success', 'Expense rejected');
                break;
            case 'pay':
                pay_expense($expenseId, $_POST);
                flash('success', 'Expense paid and posted');
                break;
            case 'reverse':
                reverse_expense($expenseId, post_str('reason', 1000));
                flash('success', 'Expense reversed');
                break;
            case 'delete':
                delete_draft_expense($expenseId);
                flash('success', 'Draft deleted');
                return 'expenses/index.php';
            default:
                fail_user('Unknown action.');
        }
        return 'expenses/view.php?id=' . $expenseId;
    }, $action === 'pay');
}

$isCreator = (int) $expense['created_by'] === (int) auth_user()['id'];
$history = db_all(
    "SELECT a.action, a.reason, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE a.institution_id = ? AND a.entity_type = 'expense' AND a.entity_id = ? ORDER BY a.id",
    [current_institution_id(), $expenseId]
);
$accounts = options_from_rows(usable_financial_accounts((int) $expense['fund_id']), 'id', static fn($row) => financial_account_label($row) . ' — ' . money(cash_balance((int) current_institution_id(), (int) $expense['fund_id'], (int) $row['id']), current_currency()) . ' available');

$pageTitle = 'Expense ' . $expense['expense_number'];
$activeNav = 'expenses';
$breadcrumb = [auth_context()['label'], 'Expenses'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions($expense['payee'] . ' · ' . $expense['category'], button_link('expenses/index.php', 'All Expenses', 'bi-arrow-left', 'btn-outline-secondary')
    . (can('expenses.record') && in_array($expense['status'], ['draft', 'rejected'], true) ? button_link('expenses/record_expense.php?id=' . $expenseId, 'Edit', 'bi-pencil', 'btn-outline-primary') : '')) ?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Details</h3><?= status_badge($expense['status']) ?></div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Amount</dt><dd class="amount-highlight"><?= e(m($expense['amount'])) ?></dd></div>
                    <div><dt>Transaction date</dt><dd><?= e(format_date($expense['expense_date'])) ?></dd></div>
                    <div><dt>Fund</dt><dd><a href="<?= e(url('funds/view.php?id=' . $expense['fund_id'])) ?>"><?= e($expense['fund_name']) ?></a></dd></div>
                    <div><dt>Event</dt><dd><?= e($expense['event_name'] ?: '—') ?></dd></div>
                    <div><dt>Payment method</dt><dd><?= e(EXPENSE_METHODS[$expense['payment_method']] ?? $expense['payment_method']) ?></dd></div>
                    <div><dt>Paid from</dt><dd><?= e($expense['account_name'] ?: '—') ?></dd></div>
                    <div><dt>Recorded by</dt><dd><?= e($expense['created_by_name']) ?></dd></div>
                    <div><dt>Approved by</dt><dd><?= e($expense['approved_by_name'] ? $expense['approved_by_name'] . ', ' . format_datetime($expense['approved_at']) : '—') ?></dd></div>
                    <div><dt>Paid by</dt><dd><?= e($expense['paid_by_name'] ? $expense['paid_by_name'] . ', ' . format_datetime($expense['paid_at']) : '—') ?></dd></div>
                </dl>
                <p class="mt-3 mb-0" style="white-space: pre-line"><span class="text-muted-sm">Purpose:</span> <?= e($expense['description']) ?></p>
            </div>
        </div>
        <div class="card panel">
            <div class="card-header"><h3>Receipt and documents</h3><?= $expense['receipt_attachment_id'] ? '<span class="badge badge-soft-success">Receipt attached</span>' : '<span class="badge badge-soft-danger">No receipt</span>' ?></div>
            <div class="card-body"><?= attachment_list_html(attachments_for('expense', $expenseId)) ?></div>
        </div>
    </div>
    <div class="col-xl-4">
        <?php if ($expense['status'] === 'draft' && can('expenses.record')): ?>
            <div class="card panel mb-3"><div class="card-body">
                <form method="post" class="mb-2" data-confirm="Submit this expense for approval?"><?= csrf_field() ?><input type="hidden" name="action" value="submit"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit for Approval</button></form>
                <form method="post" data-confirm="Delete this draft?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn btn-outline-danger w-100" type="submit">Delete Draft</button></form>
            </div></div>
        <?php endif; ?>
        <?php if ($expense['status'] === 'submitted' && can('expenses.approve')): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Approval</h3></div>
                <div class="card-body">
                    <?php if ($isCreator): ?>
                        <p class="text-muted-sm mb-0">You recorded this expense, so another approver must review it.</p>
                    <?php else: ?>
                        <p class="text-muted-sm">Check the receipt, amount, fund and purpose.</p>
                        <form method="post" class="mb-2" data-confirm="Approve this expense?"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input class="form-control mb-2" name="notes" maxlength="1000" placeholder="Notes (optional)" aria-label="Notes"><button class="btn btn-primary w-100" type="submit">Approve</button></form>
                        <form method="post" data-confirm="Reject this expense?" data-reason="Explain why it is rejected" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="reject"><button class="btn btn-outline-danger w-100" type="submit">Reject</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($expense['status'] === 'approved' && can('expenses.record')): ?>
            <form method="post" class="card panel form-card mb-3" data-confirm="Pay and post this expense?" data-confirm-text="Cash leaves the selected account and the expense is posted to the ledger.">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="pay">
                <div class="card-header"><h3>Disburse</h3></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label required" for="financial_account_id">Pay from</label><select class="form-select" id="financial_account_id" name="financial_account_id" required><?= options_html($accounts, $expense['financial_account_id'] ?: (count($accounts) === 1 ? array_key_first($accounts) : null), 'Select…') ?></select></div>
                    <div class="mb-2"><label class="form-label" for="paid_date">Paid on</label><input class="form-control" type="date" id="paid_date" name="paid_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>"></div>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-cash me-1" aria-hidden="true"></i>Pay &amp; Post</button>
                </div>
            </form>
        <?php endif; ?>
        <?php if ($expense['status'] === 'paid' && can('expenses.approve')): ?>
            <form method="post" class="card panel mb-3" data-confirm="Reverse this posted expense?" data-reason="Reason for the reversal" data-danger>
                <?= csrf_field() ?><input type="hidden" name="action" value="reverse">
                <div class="card-body"><p class="text-muted-sm">Use when the expense was posted in error or refunded. A reversing entry restores the fund; the original stays visible.</p><button class="btn btn-outline-danger w-100" type="submit">Reverse Expense</button></div>
            </form>
        <?php endif; ?>
        <div class="card panel">
            <div class="card-header"><h3>History</h3></div>
            <div class="card-body"><ul class="timeline">
                <?php foreach ($history as $row): ?>
                    <li><div class="fw-semibold"><?= e(humanize(str_replace('expense.', '', $row['action']))) ?></div><div class="text-muted-sm"><?= e($row['actor'] . ' · ' . format_datetime($row['created_at'])) ?></div><?php if ($row['reason']): ?><div class="text-muted-sm">“<?= e($row['reason']) ?>”</div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
