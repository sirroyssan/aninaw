<?php
/**
 * Journal entry: lines, source document, reversal links and history. Prepared
 * adjusting entries are posted or declined here by a second officer; posted
 * adjusting entries can be reversed with a reason.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/ledger.php';
require_institution_context();
require_permission(['ledger.view', 'ledger.post']);

$entryId = require_get_id();
$entry = require_found(find_journal_entry($entryId));

if (is_post()) {
    require_permission('ledger.post');
    $action = post_str('action', 20);
    handle_post(function () use ($action, $entryId) {
        switch ($action) {
            case 'post':
                post_adjusting_entry($entryId);
                flash('success', 'Entry posted', 'Balances and the cash register now include this adjustment.');
                return 'ledger/entry.php?id=' . $entryId;
            case 'discard':
                discard_adjusting_draft($entryId, post_str('reason', 1000));
                flash('success', 'Prepared entry removed', 'Nothing was posted. The removal is kept in the audit trail.');
                return 'ledger/index.php?status=draft';
            case 'reverse':
                $reversalId = reverse_adjusting_entry($entryId, post_str('reason', 1000));
                flash('success', 'Entry reversed', 'A reversing entry was posted; the original stays on record.');
                return 'ledger/entry.php?id=' . $reversalId;
            default:
                fail_user('Unknown action.');
        }
    }, in_array($action, ['post', 'reverse'], true));
}

$institutionId = (int) current_institution_id();
$userId = (int) auth_user()['id'];
$isPreparer = (int) $entry['created_by'] === $userId;
$sourcePath = journal_source_path($entry['source_type'], (int) $entry['source_id']);
$sourceUrl = $sourcePath ? url($sourcePath) : null;
$history = db_all(
    "SELECT a.action, a.reason, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE a.institution_id = ? AND a.entity_type = 'journal_entry' AND a.entity_id = ? ORDER BY a.id",
    [$institutionId, $entryId]
);
$cashFlows = db_all(
    "SELECT c.transaction_number, c.direction, c.amount, c.status, fa.account_name FROM cash_flow_transactions c
     JOIN financial_accounts fa ON fa.id = c.financial_account_id AND fa.institution_id = c.institution_id
     WHERE c.institution_id = ? AND c.source_type = ? AND c.source_id = ? ORDER BY c.id",
    [$institutionId, $entry['source_type'], $entry['source_id']]
);
$currency = current_currency();
$balanced = $entry['debit_cents'] === $entry['credit_cents'];

$pageTitle = 'Journal Entry ' . $entry['journal_number'];
$activeNav = 'ledger';
$breadcrumb = [auth_context()['label'], 'General Ledger'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Journal Entry <?= e($entry['journal_number']) ?></h2><p><?= e(auth_context()['label']) ?> · Printed <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions(journal_source_label($entry['source_type']) . ' · ' . format_date($entry['journal_date']),
    button_link('ledger/index.php', 'Journal', 'bi-arrow-left', 'btn-outline-secondary')
    . ($sourceUrl && $entry['source_type'] !== 'adjustment' ? button_link($sourcePath, 'Source Document', 'bi-box-arrow-up-right', 'btn-outline-primary') : '')
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>

<?php if ($entry['status'] === 'draft'): ?>
    <div class="notice notice-warning"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div>This adjusting entry is <strong>prepared but not posted</strong>. It does not affect any balance until a second officer with posting authority reviews and posts it.</div></div>
<?php elseif ($entry['reversed_by_id']): ?>
    <div class="notice notice-danger"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><div>Reversed by <a href="<?= e(url('ledger/entry.php?id=' . $entry['reversed_by_id'])) ?>"><?= e($entry['reversed_by_number']) ?></a>. Both entries stay posted and cancel each other out.</div></div>
<?php elseif ($entry['reversal_of_id']): ?>
    <div class="notice notice-info"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><div>This entry reverses <a href="<?= e(url('ledger/entry.php?id=' . $entry['reversal_of_id'])) ?>"><?= e($entry['reversal_of_number']) ?></a>.</div></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Entry</h3><?= $entry['status'] === 'draft' ? '<span class="badge badge-soft-warning">Prepared</span>' : ($entry['reversed_by_id'] ? '<span class="badge badge-soft-danger">Reversed</span>' : status_badge('posted')) ?></div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Entry date</dt><dd><?= e(format_date($entry['journal_date'])) ?></dd></div>
                    <div><dt>Source</dt><dd><?= $sourceUrl && $entry['source_type'] !== 'adjustment' ? '<a href="' . e($sourceUrl) . '">' . e(journal_source_label($entry['source_type'])) . '</a>' : e(journal_source_label($entry['source_type'])) ?></dd></div>
                    <div><dt>Total</dt><dd class="amount-highlight"><?= e(money($entry['debit_cents'], $currency)) ?></dd></div>
                    <div><dt>Prepared by</dt><dd><?= e($entry['created_by_name'] . ' · ' . format_datetime($entry['created_at'])) ?></dd></div>
                    <div><dt>Posted by</dt><dd><?= e($entry['posted_by_name'] ? $entry['posted_by_name'] . ' · ' . format_datetime($entry['posted_at']) : '—') ?></dd></div>
                </dl>
                <p class="mt-3 mb-0" style="white-space: pre-line"><span class="text-muted-sm">Description:</span> <?= e($entry['description']) ?></p>
            </div>
        </div>
        <div class="card panel mb-3">
            <div class="card-header"><h3>Lines</h3><?= $balanced ? '<span class="badge badge-soft-success">Balanced</span>' : '<span class="badge badge-soft-danger">Out of balance</span>' ?></div>
            <div class="table-responsive">
                <table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Account</th><th>Fund</th><th>Cash account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                    <tbody>
                    <?php foreach ($entry['lines'] as $line): ?>
                        <tr>
                            <td><a href="<?= e(url('ledger/account.php?code=' . rawurlencode($line['account_code']) . '&fund=' . $line['fund_id'])) ?>"><?= e($line['account_code']) ?></a> <?= e($line['account_name']) ?><?php if ($line['description']): ?><div class="text-muted-sm"><?= e($line['description']) ?></div><?php endif; ?></td>
                            <td><?= e($line['fund_name']) ?></td>
                            <td class="text-muted-sm"><?= e($line['financial_account_name'] ?: '—') ?></td>
                            <td class="text-end"><?= to_cents($line['debit_amount']) ? e(m($line['debit_amount'])) : '' ?></td>
                            <td class="text-end"><?= to_cents($line['credit_amount']) ? e(m($line['credit_amount'])) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr class="fw-semibold"><td colspan="3" class="text-end">Totals</td><td class="text-end"><?= e(money($entry['debit_cents'], $currency)) ?></td><td class="text-end"><?= e(money($entry['credit_cents'], $currency)) ?></td></tr></tfoot>
                </table>
            </div>
        </div>
        <?php if ($cashFlows): ?>
            <div class="card panel">
                <div class="card-header"><h3>Cash register rows</h3></div>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Number</th><th>Account</th><th class="text-end">In</th><th class="text-end">Out</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($cashFlows as $flow): ?>
                            <tr><td><?= e($flow['transaction_number']) ?></td><td><?= e($flow['account_name']) ?></td>
                                <td class="text-end text-success"><?= $flow['direction'] === 'inflow' ? e(m($flow['amount'])) : '' ?></td>
                                <td class="text-end text-danger"><?= $flow['direction'] === 'outflow' ? e(m($flow['amount'])) : '' ?></td>
                                <td><?= status_badge($flow['status']) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <div class="col-xl-4 d-print-none">
        <?php if ($entry['status'] === 'draft' && $entry['source_type'] === 'adjustment' && can('ledger.post')): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Review</h3></div>
                <div class="card-body">
                    <?php if ($isPreparer): ?>
                        <p class="text-muted-sm">You prepared this entry, so another officer with posting authority must review and post it.</p>
                        <a class="btn btn-outline-primary w-100 mb-2" href="<?= e(url('ledger/adjust.php?id=' . $entryId)) ?>"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Edit</a>
                        <form method="post" data-confirm="Discard this prepared entry?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="discard"><button class="btn btn-outline-danger w-100" type="submit">Discard</button></form>
                    <?php else: ?>
                        <p class="text-muted-sm">Check that the reason is documented, each fund balances and the accounts are right. Posting is final; mistakes are corrected with a reversal.</p>
                        <form method="post" class="mb-2" data-confirm="Post this adjusting entry?" data-confirm-text="Balances change immediately. A posted entry can only be reversed, not edited.">
                            <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="post">
                            <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Post Entry</button>
                        </form>
                        <form method="post" data-confirm="Decline this entry?" data-reason="Tell the preparer why it was not posted" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="discard"><button class="btn btn-outline-danger w-100" type="submit">Decline</button></form>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($entry['is_adjustment'] && $entry['status'] === 'posted' && !$entry['reversed_by_id'] && can('ledger.post')): ?>
            <form method="post" class="card panel mb-3" data-confirm="Reverse this adjusting entry?" data-reason="Reason for the reversal" data-danger>
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="reverse">
                <div class="card-body"><p class="text-muted-sm">Posts a mirrored entry that cancels this one. The original stays visible.</p><button class="btn btn-outline-danger w-100" type="submit">Reverse Entry</button></div>
            </form>
        <?php elseif ($entry['status'] === 'posted' && $entry['source_type'] !== 'adjustment' && !$entry['reversal_of_id'] && !$entry['reversed_by_id']): ?>
            <div class="card panel mb-3"><div class="card-body text-muted-sm">This entry was created by its source document. To correct it, reverse the <?= $sourceUrl ? '<a href="' . e($sourceUrl) . '">source document</a>' : 'source document' ?> (the module posts the reversal) or record an adjusting entry.</div></div>
        <?php endif; ?>
        <?php if ($history): ?>
            <div class="card panel">
                <div class="card-header"><h3>History</h3></div>
                <div class="card-body"><ul class="timeline">
                    <?php foreach ($history as $row): ?>
                        <li><div class="fw-semibold"><?= e(humanize(str_replace('journal.', '', $row['action']))) ?></div><div class="text-muted-sm"><?= e(($row['actor'] ?: 'System') . ' · ' . format_datetime($row['created_at'])) ?></div><?php if ($row['reason']): ?><div class="text-muted-sm">“<?= e($row['reason']) ?>”</div><?php endif; ?></li>
                    <?php endforeach; ?>
                </ul></div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
