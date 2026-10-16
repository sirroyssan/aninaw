<?php
/**
 * Prepare (or edit) an adjusting journal entry. Prepared entries change nothing
 * until a second officer with ledger.post reviews and posts them.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/ledger.php';
require_institution_context();
require_permission('ledger.post');

$institutionId = (int) current_institution_id();
$entryId = get_int('id');
$entry = null;
if ($entryId) {
    $entry = require_found(find_journal_entry($entryId));
    if ($entry['status'] !== 'draft' || $entry['source_type'] !== 'adjustment' || (int) $entry['created_by'] !== (int) auth_user()['id']) {
        flash('warning', 'Cannot edit', 'Only the preparer can edit an adjusting entry, and only before it is posted.');
        redirect('ledger/entry.php?id=' . $entryId);
    }
}

if (is_post()) {
    handle_post(function () use ($entryId) {
        $id = save_adjusting_draft($_POST, $entryId);
        flash('success', $entryId ? 'Prepared entry updated' : 'Adjusting entry prepared', 'Another officer with posting authority must review and post it.');
        return 'ledger/entry.php?id=' . $id;
    });
}

$chart = ledger_chart($institutionId);
$accountOptions = [];
foreach (ADJUSTABLE_LEDGER_KEYS as $key) {
    $accountOptions[$key] = $chart[$key]['code'] . ' · ' . $chart[$key]['name'];
}
$cashKeys = ['cash_custody', 'bank', 'e_wallet', 'other'];
$funds = options_from_rows(db_all("SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? AND status IN ('active', 'suspended') ORDER BY fund_name", [$institutionId]), 'id', 'fund_name');
$cashAccounts = db_all("SELECT id, account_name, account_type FROM financial_accounts WHERE institution_id = ? AND status <> 'closed' ORDER BY account_name", [$institutionId]);

// Rows to show: submitted input after an error, the draft being edited, or two blank rows.
$lines = old('lines');
if (!is_array($lines) || !$lines) {
    $lines = [];
    if ($entry) {
        $keyByCode = array_flip(array_map(static fn($a) => $a['code'], $chart));
        foreach ($entry['lines'] as $line) {
            $lines[] = [
                'fund_id' => $line['fund_id'], 'account' => $keyByCode[$line['account_code']] ?? '', 'financial_account_id' => $line['financial_account_id'],
                'debit' => to_cents($line['debit_amount']) ? cents_to_decimal(to_cents($line['debit_amount'])) : '',
                'credit' => to_cents($line['credit_amount']) ? cents_to_decimal(to_cents($line['credit_amount'])) : '',
                'description' => $line['description'],
            ];
        }
    }
    while (count($lines) < 2) {
        $lines[] = [];
    }
}

$renderRow = static function (int|string $index, array $line) use ($funds, $accountOptions, $cashAccounts, $cashKeys): string {
    $name = static fn(string $field) => 'lines[' . $index . '][' . $field . ']';
    $isCash = in_array($line['account'] ?? '', $cashKeys, true);
    $cashOptions = '<option value="">—</option>';
    foreach ($cashAccounts as $account) {
        $cashOptions .= '<option value="' . (int) $account['id'] . '" data-key="' . e(cash_ledger_key($account['account_type'])) . '"'
            . ((string) ($line['financial_account_id'] ?? '') === (string) $account['id'] ? ' selected' : '') . '>' . e($account['account_name']) . '</option>';
    }
    return '<tr data-line>'
        . '<td><select class="form-select form-select-sm" name="' . $name('fund_id') . '" aria-label="Fund">' . options_html($funds, $line['fund_id'] ?? null, 'Fund…') . '</select></td>'
        . '<td><select class="form-select form-select-sm" name="' . $name('account') . '" aria-label="Ledger account" data-line-account>' . options_html($accountOptions, $line['account'] ?? null, 'Account…') . '</select></td>'
        . '<td><select class="form-select form-select-sm" name="' . $name('financial_account_id') . '" aria-label="Cash account" data-line-cash' . ($isCash ? '' : ' disabled') . '>' . $cashOptions . '</select></td>'
        . '<td><input class="form-control form-control-sm text-amount" name="' . $name('debit') . '" inputmode="decimal" aria-label="Debit" data-line-debit value="' . e((string) ($line['debit'] ?? '')) . '"></td>'
        . '<td><input class="form-control form-control-sm text-amount" name="' . $name('credit') . '" inputmode="decimal" aria-label="Credit" data-line-credit value="' . e((string) ($line['credit'] ?? '')) . '"></td>'
        . '<td><input class="form-control form-control-sm" name="' . $name('description') . '" maxlength="255" aria-label="Line note" value="' . e((string) ($line['description'] ?? '')) . '"></td>'
        . '<td><button class="btn btn-outline-danger btn-xs" type="button" data-row-remove aria-label="Remove line"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>'
        . '</tr>';
};

$pageTitle = $entry ? 'Edit ' . $entry['journal_number'] : 'Prepare Adjusting Entry';
$activeNav = 'ledger';
$breadcrumb = [auth_context()['label'], 'General Ledger'];
$pageScripts = ['js/finance.js'];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" class="card panel form-card" data-row-editor data-min-rows="2" data-journal-lines data-currency="<?= e(current_currency() === 'PHP' ? '₱' : '') ?>">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <div class="card-body">
        <div class="notice notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div>
            Use adjusting entries for corrections that no module covers, e.g. reclassifying an expense or recording a counted cash difference.
            Each fund must balance on its own; cash lines must name the account holding the money; Cash Advances Receivable is settled only through liquidation.
            The entry is <strong>prepared</strong> now and <strong>posted</strong> by a different officer.
        </div></div>
        <div class="row g-3 mb-3">
            <div class="col-md-3"><label class="form-label required" for="journal_date">Entry date</label><input class="form-control" type="date" id="journal_date" name="journal_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('journal_date', $entry['journal_date'] ?? date('Y-m-d'))) ?>"></div>
            <div class="col-md-9"><label class="form-label required" for="description">Reason and supporting reference</label><textarea class="form-control" id="description" name="description" rows="2" required minlength="10" maxlength="2000"><?= e(old('description', $entry['description'] ?? '')) ?></textarea></div>
        </div>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-2">
                <thead><tr><th style="min-width:160px">Fund</th><th style="min-width:200px">Ledger account</th><th style="min-width:160px">Cash account</th><th style="width:130px">Debit</th><th style="width:130px">Credit</th><th style="min-width:160px">Note</th><th></th></tr></thead>
                <tbody data-row-body>
                <?php foreach (array_values($lines) as $index => $line): ?><?= $renderRow($index, is_array($line) ? $line : []) ?><?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold"><td colspan="3"><button class="btn btn-outline-primary btn-sm" type="button" data-row-add><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Add line</button></td>
                    <td class="text-end" data-total-debit>0.00</td><td class="text-end" data-total-credit>0.00</td><td colspan="2" data-total-status class="text-muted-sm"></td></tr></tfoot>
            </table>
        </div>
        <template data-row-template><?= $renderRow('__INDEX__', []) ?></template>
        <div class="form-actions">
            <a class="btn btn-outline-secondary" href="<?= e(url($entry ? 'ledger/entry.php?id=' . $entryId : 'ledger/index.php')) ?>">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i><?= $entry ? 'Save Changes' : 'Prepare Entry' ?></button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
