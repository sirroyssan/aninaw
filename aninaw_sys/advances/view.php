<?php
/**
 * Cash advance: figures, approval, release, liquidation items (expenses and returns)
 * and review, with the full history. Actions shown depend on the viewer's role.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/advances.php';
require_institution_context();

$advanceId = require_get_id();
$advance = require_found(find_advance($advanceId));
$institutionId = (int) current_institution_id();

if (is_post()) {
    $action = post_str('action', 30);
    handle_post(function () use ($action, $advanceId) {
        switch ($action) {
            case 'approve':
                decide_advance($advanceId, true, $_POST);
                flash('success', 'Advance approved');
                break;
            case 'reject':
                decide_advance($advanceId, false, $_POST);
                flash('success', 'Request rejected');
                break;
            case 'cancel':
                cancel_advance($advanceId, post_str('reason', 1000));
                flash('success', 'Request cancelled');
                break;
            case 'release':
                release_advance($advanceId, $_POST);
                flash('success', 'Advance released');
                break;
            case 'add_expense':
                save_advance_expense($advanceId, post_int('expense_id') ?: null, $_POST);
                flash('success', 'Expense saved');
                break;
            case 'delete_expense':
                delete_advance_expense($advanceId, post_int('expense_id') ?? 0);
                flash('success', 'Expense removed');
                break;
            case 'add_return':
                record_advance_return($advanceId, $_POST);
                flash('success', 'Return recorded', 'It counts once the receiving custodian confirms it.');
                break;
            case 'submit':
                submit_liquidation($advanceId);
                flash('success', 'Liquidation submitted for review');
                break;
            case 'review_expense':
                review_advance_expense(post_int('expense_id') ?? 0, post_str('decision', 20), post_str('remarks', 1000) ?: post_str('reason', 1000));
                flash('success', 'Item reviewed');
                break;
            case 'confirm_return':
                confirm_advance_return(post_int('return_id') ?? 0, true, '');
                flash('success', 'Return confirmed');
                break;
            case 'reject_return':
                confirm_advance_return(post_int('return_id') ?? 0, false, post_str('reason', 1000));
                flash('success', 'Return not confirmed');
                break;
            case 'return_for_correction':
                return_liquidation($advanceId, post_str('reason', 1000));
                flash('success', 'Returned for correction');
                break;
            case 'complete':
                $status = complete_liquidation_review($advanceId);
                flash('success', 'Review completed', 'Status: ' . humanize($status) . '.');
                break;
            default:
                fail_user('Unknown action.');
        }
        return 'advances/view.php?id=' . $advanceId;
    }, in_array($action, ['add_expense', 'add_return', 'release'], true));
}

$expenses = db_all(
    "SELECT x.*, CONCAT(u.first_name, ' ', u.last_name) AS verified_by_name FROM cash_advance_expenses x LEFT JOIN users u ON u.id = x.verified_by
     WHERE x.institution_id = ? AND x.cash_advance_id = ? ORDER BY x.expense_date, x.id",
    [$institutionId, $advanceId]
);
$returns = db_all(
    "SELECT r.*, fa.account_name, CONCAT(u.first_name, ' ', u.last_name) AS receiver_name FROM cash_advance_returns r
     JOIN financial_accounts fa ON fa.id = r.financial_account_id JOIN institution_memberships m ON m.id = r.received_by_membership_id JOIN users u ON u.id = m.user_id
     WHERE r.institution_id = ? AND r.cash_advance_id = ? ORDER BY r.id",
    [$institutionId, $advanceId]
);
$history = db_all(
    "SELECT a.action, a.reason, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
     WHERE a.institution_id = ? AND a.entity_type = 'cash_advance' AND a.entity_id = ? ORDER BY a.id",
    [$institutionId, $advanceId]
);
$currency = current_currency();
$recipientCanEdit = $advance['is_recipient'] && in_array($advance['status'], ['released', 'partially_liquidated', 'returned_for_correction', 'overdue'], true);
$canReview = can('liquidation.review') && !$advance['is_recipient'] && in_array($advance['status'], ['submitted_for_review', 'released', 'partially_liquidated', 'returned_for_correction', 'overdue'], true);
$returnAccounts = options_from_rows(array_filter(
    db_all("SELECT fa.*, CONCAT(u.first_name, ' ', u.last_name) AS custodian_name FROM financial_accounts fa
            JOIN institution_memberships m ON m.id = fa.custodian_membership_id JOIN users u ON u.id = m.user_id
            WHERE fa.institution_id = ? AND fa.status = 'active'", [$institutionId]),
    static fn($row) => (int) $row['custodian_membership_id'] !== current_membership_id() && fund_account_is_mapped($institutionId, (int) $advance['fund_id'], (int) $row['id'], date('Y-m-d'))
), 'id', static fn($row) => financial_account_label($row));

$pageTitle = 'Cash Advance ' . $advance['advance_number'];
$activeNav = 'advances';
$breadcrumb = [auth_context()['label'], 'Cash Advances'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Cash Advance <?= e($advance['advance_number']) ?> — Liquidation</h2><p><?= e(auth_context()['label']) ?> · <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions($advance['recipient_name'] . ' · ' . $advance['fund_name'] . ($advance['event_name'] ? ' · ' . $advance['event_name'] : ''),
    button_link('advances/index.php', 'All Advances', 'bi-arrow-left', 'btn-outline-secondary') . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>

<?php if ($advance['status'] === 'returned_for_correction' && $advance['is_recipient']): ?>
    <div class="notice notice-warning"><i class="bi bi-arrow-return-left" aria-hidden="true"></i><div>Your liquidation was returned for correction. Check the remarks on each item and the history, fix them, then submit again.</div></div>
<?php endif; ?>

<div class="stat-grid">
    <?= stat_card('bi-box-arrow-up-right', 'Released', money($advance['released_cents'], $currency), $advance['released_at'] ? 'on ' . format_date($advance['released_at']) . ' from ' . $advance['released_from'] : 'Requested ' . m($advance['requested_amount'])) ?>
    <?= stat_card('bi-receipt', 'Verified Expenses', money($advance['verified_cents'], $currency), $advance['pending_expense_cents'] ? money($advance['pending_expense_cents'], $currency) . ' awaiting verification' : 'No pending items', 'info') ?>
    <?= stat_card('bi-arrow-return-left', 'Confirmed Returns', money($advance['returned_cents'], $currency), $advance['pending_return_cents'] ? money($advance['pending_return_cents'], $currency) . ' awaiting confirmation' : '', 'info') ?>
    <?= stat_card('bi-hourglass-split', 'Unliquidated Balance', money($advance['outstanding_cents'], $currency), $advance['liquidation_deadline'] ? 'Due ' . format_date($advance['liquidation_deadline']) : '', $advance['outstanding_cents'] > 0 ? 'warning' : 'success') ?>
    <?php if ($advance['excess_cents']): ?><?= stat_card('bi-exclamation-diamond', 'Excess Expenses', money($advance['excess_cents'], $currency), 'Subject to reimbursement review — not paid automatically', 'danger') ?><?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <div class="card panel mb-3">
            <div class="card-header"><h3>Request</h3><?= status_badge($advance['status']) ?></div>
            <div class="card-body">
                <dl class="detail-grid">
                    <div><dt>Type</dt><dd><?= e(ADVANCE_TYPES[$advance['advance_type']] ?? $advance['advance_type']) ?></dd></div>
                    <div><dt>Requested</dt><dd><?= e(m($advance['requested_amount']) . ' · ' . format_date($advance['request_date'])) ?></dd></div>
                    <div><dt>Approved</dt><dd><?= e($advance['approved_amount'] !== null ? m($advance['approved_amount']) . ' by ' . $advance['approved_by_name'] : '—') ?></dd></div>
                    <div><dt>Released by</dt><dd><?= e($advance['released_by_name'] ?: '—') ?></dd></div>
                </dl>
                <p class="mt-3 mb-0"><span class="text-muted-sm">Purpose:</span> <?= e($advance['purpose']) ?></p>
            </div>
        </div>

        <div class="card panel mb-3" id="liquidation">
            <div class="card-header"><h3>Expenses</h3></div>
            <?php if (!$expenses): ?>
                <div class="card-body"><?= empty_state('bi-receipt', 'No expenses submitted yet') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Date · Item</th><th>Receipt</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($expenses as $row): $open = in_array($row['verification_status'], ['pending', 'needs_correction'], true); ?>
                            <tr>
                                <td><div class="fw-semibold"><?= e($row['description']) ?></div><div class="text-muted-sm"><?= e(format_date($row['expense_date']) . ' · ' . $row['category'] . ($row['vendor_name'] ? ' · ' . $row['vendor_name'] : '')) ?></div>
                                    <?php if ($row['remarks']): ?><div class="text-muted-sm text-danger">Reviewer: <?= e($row['remarks']) ?></div><?php endif; ?></td>
                                <td><?= attachment_list_html(attachments_for('advance_expense', (int) $row['id'])) ?></td>
                                <td class="text-end fw-semibold"><?= e(m($row['amount'])) ?></td>
                                <td><?= status_badge($row['verification_status']) ?><?php if ($row['verified_by_name']): ?><div class="text-muted-sm"><?= e($row['verified_by_name']) ?></div><?php endif; ?></td>
                                <td class="actions">
                                    <?php if ($canReview && $open): ?>
                                        <form method="post" class="d-inline" data-confirm="Verify this expense?" data-confirm-text="It is posted as a liquidated expense of the fund."><?= csrf_field() ?><input type="hidden" name="action" value="review_expense"><input type="hidden" name="expense_id" value="<?= e($row['id']) ?>"><input type="hidden" name="decision" value="verified"><button class="btn btn-primary btn-xs" type="submit">Verify</button></form>
                                        <form method="post" class="d-inline" data-confirm="Needs correction?" data-reason="What must be corrected?"><?= csrf_field() ?><input type="hidden" name="action" value="review_expense"><input type="hidden" name="expense_id" value="<?= e($row['id']) ?>"><input type="hidden" name="decision" value="needs_correction"><button class="btn btn-outline-secondary btn-xs" type="submit">Correct</button></form>
                                        <form method="post" class="d-inline" data-confirm="Reject this expense?" data-reason="Reason for rejecting" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="review_expense"><input type="hidden" name="expense_id" value="<?= e($row['id']) ?>"><input type="hidden" name="decision" value="rejected"><button class="btn btn-outline-danger btn-xs" type="submit">Reject</button></form>
                                    <?php elseif ($recipientCanEdit && $open): ?>
                                        <form method="post" class="d-inline" data-confirm="Remove this expense line?" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="expense_id" value="<?= e($row['id']) ?>"><button class="btn btn-outline-danger btn-xs" type="submit">Remove</button></form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card panel mb-3">
            <div class="card-header"><h3>Returned cash</h3></div>
            <?php if (!$returns): ?>
                <div class="card-body"><?= empty_state('bi-arrow-return-left', 'No returns recorded') ?></div>
            <?php else: ?>
                <ul class="list-clean">
                    <?php foreach ($returns as $row): $canConfirm = $row['status'] === 'pending_verification' && !$advance['is_recipient'] && ((int) $row['received_by_membership_id'] === current_membership_id() || can('liquidation.review')); ?>
                        <li><div><div class="fw-semibold"><?= e($row['return_number']) ?> · <?= e(m($row['returned_amount'])) ?></div>
                            <div class="text-muted-sm"><?= e(format_datetime($row['returned_at']) . ' · to ' . $row['account_name'] . ' (' . $row['receiver_name'] . ')' . ($row['acknowledgment_number'] ? ' · ' . $row['acknowledgment_number'] : '')) ?></div></div>
                            <div class="d-flex gap-1 align-items-center"><?= status_badge($row['status']) ?>
                                <?php if ($canConfirm): ?>
                                    <form method="post" data-confirm="Confirm you received <?= e(m($row['returned_amount'])) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="confirm_return"><input type="hidden" name="return_id" value="<?= e($row['id']) ?>"><button class="btn btn-primary btn-xs" type="submit">Confirm</button></form>
                                    <form method="post" data-confirm="Not received?" data-reason="Reason" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="reject_return"><input type="hidden" name="return_id" value="<?= e($row['id']) ?>"><button class="btn btn-outline-danger btn-xs" type="submit">Not received</button></form>
                                <?php endif; ?></div></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-4">
        <?php if ($advance['status'] === 'pending_approval' && can('advances.approve') && !$advance['is_recipient']): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Approval</h3></div>
                <div class="card-body">
                    <form method="post" class="mb-2" data-confirm="Approve this advance?"><?= csrf_field() ?><input type="hidden" name="action" value="approve">
                        <label class="form-label" for="approved_amount">Approved amount</label><input class="form-control text-amount mb-2" id="approved_amount" name="approved_amount" value="<?= e(cents_to_decimal(to_cents($advance['requested_amount']))) ?>">
                        <button class="btn btn-primary w-100" type="submit">Approve</button></form>
                    <form method="post" data-confirm="Reject this request?" data-reason="Reason for rejecting" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="reject"><button class="btn btn-outline-danger w-100" type="submit">Reject</button></form>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($advance['status'] === 'approved' && can('advances.release') && !$advance['is_recipient']): ?>
            <?php $releaseAccounts = options_from_rows(usable_financial_accounts((int) $advance['fund_id']), 'id', static fn($row) => financial_account_label($row) . ' — ' . money(cash_balance($institutionId, (int) $advance['fund_id'], (int) $row['id']), $currency)); ?>
            <form method="post" class="card panel form-card mb-3" data-confirm="Release <?= e(m($advance['approved_amount'])) ?> to <?= e($advance['recipient_name']) ?>?" data-confirm-text="Hand over the cash only after confirming. It is recorded as an advance the recipient must liquidate.">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="release">
                <div class="card-header"><h3>Release</h3></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label required" for="rel-account">Pay out from</label><select class="form-select" id="rel-account" name="financial_account_id" required><?= options_html($releaseAccounts, count($releaseAccounts) === 1 ? array_key_first($releaseAccounts) : null, 'Select…') ?></select></div>
                    <div class="mb-2"><label class="form-label" for="liquidation_deadline">Liquidation deadline</label><input class="form-control" type="date" id="liquidation_deadline" name="liquidation_deadline" min="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d', strtotime('+' . (int) institution_setting($institutionId, 'advance.default_days', '15') . ' days'))) ?>"></div>
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-cash me-1" aria-hidden="true"></i>Release Advance</button>
                </div>
            </form>
        <?php endif; ?>
        <?php if (in_array($advance['status'], ['pending_approval', 'approved'], true) && ($advance['is_recipient'] || can('advances.approve'))): ?>
            <form method="post" class="card panel mb-3" data-confirm="Cancel this request?" data-reason="Reason" data-danger><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><div class="card-body"><button class="btn btn-outline-danger w-100" type="submit">Cancel Request</button></div></form>
        <?php endif; ?>

        <?php if ($recipientCanEdit): ?>
            <form method="post" enctype="multipart/form-data" class="card panel form-card mb-3">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="add_expense">
                <div class="card-header"><h3>Add expense</h3></div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label required" for="ax-date">Date</label><input class="form-control" type="date" id="ax-date" name="expense_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
                        <div class="col-6"><label class="form-label required" for="ax-amount">Amount</label><input class="form-control text-amount" id="ax-amount" name="amount" required inputmode="decimal"></div>
                        <div class="col-12"><label class="form-label required" for="ax-category">Category</label><select class="form-select" id="ax-category" name="category" required><?= options_html(array_combine(expense_categories(), expense_categories()), null, 'Select…') ?></select></div>
                        <div class="col-12"><label class="form-label" for="ax-vendor">Vendor</label><input class="form-control" id="ax-vendor" name="vendor_name" maxlength="180"></div>
                        <div class="col-12"><label class="form-label required" for="ax-desc">Description</label><input class="form-control" id="ax-desc" name="description" required maxlength="2000"></div>
                        <div class="col-12"><?= attachment_input('receipt', 'Receipt', true) ?></div>
                    </div>
                    <button class="btn btn-primary w-100 mt-2" type="submit">Add Expense</button>
                </div>
            </form>
            <form method="post" enctype="multipart/form-data" class="card panel form-card mb-3">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="add_return">
                <div class="card-header"><h3>Return unused cash</h3></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label required" for="rt-amount">Amount</label><input class="form-control text-amount" id="rt-amount" name="returned_amount" required inputmode="decimal"></div>
                    <div class="mb-2"><label class="form-label required" for="rt-account">Returned to</label><select class="form-select" id="rt-account" name="financial_account_id" required><?= options_html($returnAccounts, null, 'Select custodian account…') ?></select></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><select class="form-select" name="payment_method" aria-label="Method"><?= options_html(EXPENSE_METHODS, 'cash') ?></select></div>
                        <div class="col-6"><input class="form-control" name="reference_number" maxlength="150" placeholder="Reference" aria-label="Reference"></div>
                    </div>
                    <button class="btn btn-outline-primary w-100" type="submit">Record Return</button>
                </div>
            </form>
            <form method="post" class="card panel mb-3" data-confirm="Submit your liquidation for review?" data-confirm-text="You cannot add items while it is under review.">
                <?= csrf_field() ?><input type="hidden" name="action" value="submit">
                <div class="card-body"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-send me-1" aria-hidden="true"></i>Submit Liquidation</button></div>
            </form>
        <?php endif; ?>

        <?php if ($canReview && $advance['status'] === 'submitted_for_review'): ?>
            <div class="card panel mb-3">
                <div class="card-header"><h3>Review</h3></div>
                <div class="card-body">
                    <p class="text-muted-sm">Verify each receipt and confirm returned cash. The advance is liquidated only when no balance remains.</p>
                    <form method="post" class="mb-2" data-confirm="Complete the review?"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><button class="btn btn-primary w-100" type="submit">Complete Review</button></form>
                    <form method="post" data-confirm="Return for correction?" data-reason="What must the recipient correct?"><?= csrf_field() ?><input type="hidden" name="action" value="return_for_correction"><button class="btn btn-outline-secondary w-100" type="submit">Return for Correction</button></form>
                </div>
            </div>
        <?php endif; ?>

        <div class="card panel">
            <div class="card-header"><h3>History</h3></div>
            <div class="card-body"><ul class="timeline">
                <?php foreach ($history as $row): ?>
                    <li><div class="fw-semibold"><?= e(humanize(str_replace('advance.', '', $row['action']))) ?></div><div class="text-muted-sm"><?= e($row['actor'] . ' · ' . format_datetime($row['created_at'])) ?></div><?php if ($row['reason']): ?><div class="text-muted-sm">“<?= e($row['reason']) ?>”</div><?php endif; ?></li>
                <?php endforeach; ?>
            </ul></div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
