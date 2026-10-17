<?php
/**
 * Record (or edit a draft/rejected) direct expense with its receipt.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/expenses.php';
require_institution_context();
require_permission('expenses.record');

$expenseId = get_int('id');
$expense = $expenseId ? require_found(find_expense($expenseId)) : null;
if ($expense && !in_array($expense['status'], ['draft', 'rejected'], true)) {
    render_error_page(403, 'Expense Locked', 'Submitted, approved and posted expenses cannot be edited.');
}

if (is_post()) {
    handle_post(function () use ($expenseId, $expense) {
        if (post_str('submit_now', 1) === '1' && !has_upload('receipt') && empty($expense['receipt_attachment_id'])) {
            fail_user('Attach the official receipt or invoice before submitting, or save as draft.', 'receipt');
        }
        $id = save_expense($expenseId, $_POST);
        if (post_str('submit_now', 1) === '1') {
            submit_expense($id);
            flash('success', 'Expense submitted for approval');
        } else {
            flash('success', 'Expense saved as draft');
        }
        return 'expenses/view.php?id=' . $id;
    });
}

$institutionId = (int) current_institution_id();
$invoice = '';
$description = $expense['description'] ?? old('description');
if (preg_match('/^Invoice\/receipt no\.: (.*)\R?/u', (string) $description, $match)) {
    $invoice = $match[1];
    $description = preg_replace('/^Invoice\/receipt no\.: .*\R?/u', '', (string) $description);
}
$field = static fn(string $key, $default = '') => $expense[$key] ?? old($key, $default);
$funds = options_from_rows(db_all("SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? AND status = 'active' ORDER BY fund_name", [$institutionId]), 'id', 'fund_name');
$accounts = options_from_rows(usable_financial_accounts(), 'id', static fn($row) => financial_account_label($row));

$pageScripts = ['js/categories.js'];
$pageTitle = $expense ? 'Edit Expense ' . $expense['expense_number'] : 'Record Expense';
$activeNav = 'expenses';
$breadcrumb = [auth_context()['label'], 'Expenses'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" enctype="multipart/form-data" class="card panel form-card" style="max-width: 960px" data-modal-body>
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="form-section">
            <h2 class="form-section-title">Expense</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label required" for="payee">Payee / supplier</label><input class="form-control" id="payee" name="payee" required maxlength="180" value="<?= e($field('payee')) ?>"></div>
                <div class="col-md-3"><label class="form-label required" for="amount">Amount</label><input class="form-control text-amount" id="amount" name="amount" required inputmode="decimal" value="<?= e($expense ? cents_to_decimal(to_cents($expense['amount'])) : old('amount')) ?>"></div>
                <div class="col-md-3"><label class="form-label required" for="expense_date">Transaction date</label><input class="form-control" type="date" id="expense_date" name="expense_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e($field('expense_date', date('Y-m-d'))) ?>"></div>
                <div class="col-md-6"><label class="form-label required" for="category">Category</label><?php $categorySelect = '<select class="form-select" id="category" name="category" required>' . options_html(array_combine(expense_categories(), expense_categories()), $field('category'), 'Select…') . '</select>'; ?><?php if (can('institution.settings.manage')): ?><div class="input-group"><?= $categorySelect ?><button class="btn btn-outline-primary" type="button" data-add-category="#category" data-url="<?= e(url('institutions/add_category.php')) ?>" title="Add a category"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>New</button></div><?php else: ?><?= $categorySelect ?><?php endif; ?></div>
                <div class="col-md-6"><label class="form-label" for="invoice_reference">Receipt / invoice no.</label><input class="form-control" id="invoice_reference" name="invoice_reference" maxlength="100" value="<?= e($invoice ?: old('invoice_reference')) ?>"></div>
                <div class="col-12"><label class="form-label required" for="description">Purpose</label><textarea class="form-control" id="description" name="description" rows="3" required minlength="5" maxlength="3000"><?= e($description) ?></textarea></div>
            </div>
        </div>
        <div class="form-section">
            <h2 class="form-section-title">Charging</h2>
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label required" for="fund_id">Fund</label><select class="form-select" id="fund_id" name="fund_id" required><?= options_html($funds, $field('fund_id'), 'Select…') ?></select></div>
                <div class="col-md-6"><label class="form-label" for="event_id">Related event</label><select class="form-select" id="event_id" name="event_id"><?= options_html(event_options(['approved', 'collecting', 'completed']), $field('event_id'), 'None') ?></select></div>
                <div class="col-md-6"><label class="form-label" for="payment_method">Payment method</label><select class="form-select" id="payment_method" name="payment_method"><?= options_html(EXPENSE_METHODS, $field('payment_method', 'cash')) ?></select></div>
                <div class="col-md-6"><label class="form-label" for="financial_account_id">Pay from <span class="text-muted-sm">(can be chosen at payment)</span></label><select class="form-select" id="financial_account_id" name="financial_account_id"><?= options_html($accounts, $field('financial_account_id'), 'Decide later') ?></select></div>
            </div>
        </div>
        <div class="form-section">
            <h2 class="form-section-title">Documents</h2>
            <div class="row g-3">
                <div class="col-md-6"><?= attachment_input('receipt', $expense && $expense['receipt_attachment_id'] ? 'Replace official receipt / invoice' : 'Official receipt / invoice', false, $expense && $expense['receipt_attachment_id'] ? 'optional' : 'needed to submit; optional for a draft') ?><div class="form-text">Required before submitting. The same document cannot support two expenses.</div></div>
                <div class="col-md-6"><?= attachment_input('attachment', 'Other supporting document') ?></div>
            </div>
            <?php if ($expense): ?><div class="mt-3"><?= attachment_list_html(attachments_for('expense', (int) $expense['id'])) ?></div><?php endif; ?>
        </div>
        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url($expense ? 'expenses/view.php?id=' . $expense['id'] : 'expenses/index.php')) ?>">Cancel</a>
            <button class="btn btn-outline-primary" type="submit" name="submit_now" value="0"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Draft</button>
            <button class="btn btn-primary" type="submit" name="submit_now" value="1"><i class="bi bi-send me-1" aria-hidden="true"></i>Save &amp; Submit for Approval</button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
