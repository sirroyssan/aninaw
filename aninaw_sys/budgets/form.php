<?php
/**
 * Create or edit a draft budget: fund, optional event, period and category lines.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/budgets.php';
require_institution_context();
require_permission('budgets.manage');

$budgetId = get_int('id');
$budget = $budgetId ? require_found(find_budget($budgetId)) : null;
if ($budget && $budget['status'] !== 'draft') {
    flash('warning', 'Cannot edit', 'Only draft budgets can be edited. Approved budgets are changed through a revision.');
    redirect('budgets/view.php?id=' . $budgetId);
}

if (is_post()) {
    handle_post(function () use ($budgetId) {
        $id = save_budget($budgetId, $_POST);
        flash('success', $budgetId ? 'Budget saved' : 'Budget created', 'Submit it for approval when the lines are complete.');
        return 'budgets/view.php?id=' . $id;
    });
}

$categories = expense_categories();
$lines = old('lines');
if (!is_array($lines) || !$lines) {
    $lines = $budget ? array_map(static fn($l) => ['category' => $l['category'], 'description' => $l['description'], 'allocated_amount' => cents_to_decimal(to_cents($l['allocated_amount']))], $budget['lines']) : [[]];
}
$events = event_options(['draft', 'pending', 'approved', 'collecting', 'completed']);
$renderLine = static function (int|string $index, array $line) use ($categories): string {
    $name = static fn(string $field) => 'lines[' . $index . '][' . $field . ']';
    return '<tr>'
        . '<td><select class="form-select form-select-sm" name="' . $name('category') . '" aria-label="Category">' . options_html(array_combine($categories, $categories), $line['category'] ?? null, 'Category…') . '</select></td>'
        . '<td><input class="form-control form-control-sm" name="' . $name('description') . '" maxlength="255" aria-label="Description" value="' . e((string) ($line['description'] ?? '')) . '"></td>'
        . '<td><input class="form-control form-control-sm text-amount" name="' . $name('allocated_amount') . '" inputmode="decimal" aria-label="Amount" value="' . e((string) ($line['allocated_amount'] ?? '')) . '"></td>'
        . '<td><button class="btn btn-outline-danger btn-xs" type="button" data-row-remove aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>'
        . '</tr>';
};

$pageTitle = $budget ? 'Edit ' . $budget['budget_number'] : 'New Budget';
$activeNav = 'budgets';
$breadcrumb = [auth_context()['label'], 'Budgets'];
$pageScripts = ['js/finance.js'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" class="card panel form-card" style="max-width: 960px" data-row-editor>
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label required" for="budget_name">Budget name</label><input class="form-control" id="budget_name" name="budget_name" required maxlength="180" value="<?= e(old('budget_name', $budget['budget_name'] ?? '')) ?>"></div>
            <div class="col-md-6"><label class="form-label required" for="fund_id">Fund</label><select class="form-select" id="fund_id" name="fund_id" required><?= options_html(budget_fund_options(), old('fund_id', $budget['fund_id'] ?? null), 'Select…') ?></select></div>
            <div class="col-md-6"><label class="form-label" for="event_id">Event</label><select class="form-select" id="event_id" name="event_id"><?= options_html($events, old('event_id', $budget['event_id'] ?? null), 'Whole fund (no event)') ?></select><div class="form-text">For an event budget, the fund must be linked to the event.</div></div>
            <div class="col-md-3"><label class="form-label required" for="start_date">Period start</label><input class="form-control" type="date" id="start_date" name="start_date" required value="<?= e(old('start_date', $budget['start_date'] ?? '')) ?>"></div>
            <div class="col-md-3"><label class="form-label required" for="end_date">Period end</label><input class="form-control" type="date" id="end_date" name="end_date" required value="<?= e(old('end_date', $budget['end_date'] ?? '')) ?>"></div>
        </div>
        <h3 class="h6 mb-2">Budget lines</h3>
        <p class="text-muted-sm">One line per expense category. Expenses and liquidations are compared against these categories.</p>
        <div class="table-responsive" data-money-sum="#budget-total">
            <table class="table table-clean align-middle mb-2">
                <thead><tr><th style="min-width:200px">Category</th><th style="min-width:220px">Description</th><th style="width:160px">Approved amount</th><th></th></tr></thead>
                <tbody data-row-body>
                <?php foreach (array_values($lines) as $index => $line): ?><?= $renderLine($index, is_array($line) ? $line : []) ?><?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold"><td><button class="btn btn-outline-primary btn-sm" type="button" data-row-add><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add line</button></td><td class="text-end">Total</td><td id="budget-total" data-currency="<?= e(current_currency() === 'PHP' ? '₱' : '') ?>">0.00</td><td></td></tr></tfoot>
            </table>
        </div>
        <template data-row-template><?= $renderLine('__INDEX__', []) ?></template>
        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url($budget ? 'budgets/view.php?id=' . $budgetId : 'budgets/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Draft</button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
