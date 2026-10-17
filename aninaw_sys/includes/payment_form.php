<?php
/**
 * Payment form for one payer and one event: an amount per open collection, method, account,
 * date and proof, plus a Waive button per line for the event's organizers.
 * Used by collections/record_payment.php, as a page and as the Collections "Pay" modal.
 * Expects $participant, $eventId, $events, $obligations, $funds, $accounts, $currency, $canWaive, $inModal.
 */
$balanceTotal = array_sum(array_column($obligations, 'balance'));
?>
<form method="post" action="<?= e(url('collections/record_payment.php')) ?>" enctype="multipart/form-data" class="card panel <?= $inModal ? 'border-0 rounded-0 shadow-none' : 'form-card' ?>"<?= $inModal ? '' : ' style="max-width: 980px"' ?> data-confirm="Record this payment?" data-confirm-text="The payment is posted and a receipt is issued. Corrections require an authorised reversal.">
    <?= csrf_field() ?><?= idempotency_field() ?>
    <input type="hidden" name="participant_id" value="<?= e($participant['id']) ?>">
    <input type="hidden" name="event_id" value="<?= e($eventId) ?>">
    <?php if ($inModal): ?><input type="hidden" name="back" value="referer"><?php endif; ?>
    <div class="card-header"><h3><?= $inModal ? 'Collections' : 'Amounts for ' . e($events[$eventId]) ?></h3>
    <?php if (count($obligations) > 1): ?><button class="btn btn-outline-primary btn-sm" type="button" data-fill-all>Pay all · <?= e(money($balanceTotal, $currency)) ?></button><?php endif; ?></div>
    <div class="table-responsive">
        <table class="table table-clean align-middle mb-0" data-money-sum="#payment-total">
            <thead><tr><th>Collection</th><th class="text-end">Balance</th><th style="width:220px">Amount paid now</th><?php if ($canWaive): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($obligations as $row): ?>
                <tr>
                    <td><div class="fw-semibold"><?= e($row['rule_name']) ?></div><?= inclusions_html($row['inclusions']) ?><div class="text-muted-sm"><?= $row['allow_partial_payment'] ? 'Partial payment allowed' : 'Must be paid in full' ?><?= $row['due_date'] ? ' · due ' . e(format_date($row['due_date'])) : '' ?></div></td>
                    <td class="text-end fw-semibold"><?= e(money($row['balance'], $currency)) ?></td>
                    <td>
                        <div class="input-group input-group-sm">
                            <input class="form-control text-amount" name="alloc[<?= e($row['id']) ?>]" inputmode="decimal" placeholder="0.00" data-max="<?= e(cents_to_decimal($row['balance'])) ?>" aria-label="Amount for <?= e($row['rule_name']) ?>" value="<?= e(old('alloc')[$row['id']] ?? '') ?>">
                            <button class="btn btn-outline-primary" type="button" data-fill-max>Full</button>
                        </div>
                    </td>
                    <?php if ($canWaive): ?><td class="actions"><button class="btn btn-outline-secondary btn-xs" type="submit" form="waive-<?= e($row['id']) ?>" title="Waive this balance (e.g. already insured)">Waive</button></td><?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><th colspan="2" class="text-end">Total received</th><th<?= $canWaive ? ' colspan="2"' : '' ?>><span id="payment-total" class="amount-highlight" data-currency="<?= e($currency === 'PHP' ? '₱' : $currency . ' ') ?>">0.00</span></th></tr></tfoot>
        </table>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php if (count($funds) > 1): ?>
                <div class="col-md-6"><label class="form-label required" for="fund_id">Receiving fund</label><select class="form-select" id="fund_id" name="fund_id" required><?= options_html(options_from_rows($funds, 'fund_id', 'fund_name'), old('fund_id'), 'Select…') ?></select></div>
            <?php endif; ?>
            <div class="col-md-6">
                <label class="form-label required" for="payment_method">Method</label>
                <select class="form-select" id="payment_method" name="payment_method" required data-method-select><?= options_html(PAYMENT_METHODS, old('payment_method', 'cash')) ?></select>
            </div>
            <div class="col-md-6">
                <label class="form-label required" for="financial_account_id">Received into</label>
                <select class="form-select" id="financial_account_id" name="financial_account_id" required<?= method_account_select_attrs() ?>><?= account_options_by_type($accounts, old('financial_account_id')) ?></select>
                <div class="form-text text-danger d-none" data-method-none>No account for this method is linked to the event's fund. Ask the treasurer: Financial Accounts → New Account (e.g. type E-wallet for GCash), then link it to the fund.</div>
                <?php if (!$accounts): ?><div class="form-text text-danger">You are not the custodian of any account authorised for this event's fund. Ask the treasurer to set one up.</div><?php else: ?><div class="form-text">Follows the method: Cash → cash box, GCash → e-wallet, Bank transfer → bank account.</div><?php endif; ?>
            </div>
            <div class="col-md-3"><label class="form-label required" for="payment_date">Payment date</label><input class="form-control" type="date" id="payment_date" name="payment_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(old('payment_date', date('Y-m-d'))) ?>"></div>
            <div class="col-md-3"><label class="form-label" for="payment_time">Time</label><input class="form-control" type="time" id="payment_time" name="payment_time" value="<?= e(old('payment_time', date('H:i'))) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="external_reference">Reference no. <span class="text-muted-sm">(required for GCash/bank)</span></label><input class="form-control" id="external_reference" name="external_reference" maxlength="150" value="<?= e(old('external_reference')) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="notes">Remarks</label><input class="form-control" id="notes" name="notes" maxlength="1000" value="<?= e(old('notes')) ?>"></div>
            <div class="col-md-6"><?= attachment_input('attachment', 'Proof of payment') ?></div>
        </div>
        <div class="form-actions">
            <?php if ($inModal): ?><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><?php else: ?><a class="btn btn-outline-secondary" href="<?= e(url('collections/index.php')) ?>">Cancel</a><?php endif; ?>
            <button class="btn btn-primary" type="submit" <?= $accounts ? '' : 'disabled' ?>><i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Record Payment &amp; Issue Receipt</button>
        </div>
    </div>
</form>
<?php if ($canWaive): foreach ($obligations as $row): ?>
    <form method="post" action="<?= e(url('collections/record_payment.php')) ?>"<?= $inModal ? ' data-ajax' : '' ?> id="waive-<?= e($row['id']) ?>" class="d-none" data-confirm="Waive <?= e(money($row['balance'], $currency)) ?> of <?= e($row['rule_name']) ?>?" data-confirm-text="Nothing is collected for it and the event's expected total goes down. The reason is recorded." data-reason="Who approved this and why? (e.g. already insured until Dec 2026, policy no.)">
        <?= csrf_field() ?><input type="hidden" name="action" value="waive"><?= $inModal ? '<input type="hidden" name="back" value="referer">' : '' ?><input type="hidden" name="obligation_id" value="<?= e($row['id']) ?>"><input type="hidden" name="amount" value="<?= e(cents_to_decimal($row['balance'])) ?>">
    </form>
<?php endforeach; endif; ?>
