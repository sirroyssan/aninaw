<?php
/**
 * Printable refund slip for an overpayment given back to a payer (RF-…), for the payer to sign.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission(['collections.view', 'collections.record']);

$slip = require_found(find_adjustment(require_get_id()));
if ($slip['adjustment_type'] !== 'refund') {
    render_error_page(404, 'Not a Refund', 'Only refunds have a refund slip.');
}
$institution = db_one('SELECT * FROM institutions WHERE id = ?', [current_institution_id()]);
$currency = current_currency();
$amountCents = to_cents($slip['amount']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Refund Slip <?= e($slip['adjustment_number']) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/receipt.css')) ?>">
</head>
<body class="receipt-body">
<div class="receipt-toolbar no-print">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('events/overpayments.php?event=' . $slip['event_id'])) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back</a>
    <button class="btn btn-primary btn-sm" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
</div>
<main class="receipt">
    <header class="receipt-header">
        <div><div class="receipt-institution"><?= e($institution['institution_name']) ?></div><div class="receipt-address"><?= e($institution['address'] ?? '') ?></div></div>
        <div class="text-end"><div class="receipt-title">Refund Slip</div><div class="receipt-number">No. <?= e($slip['adjustment_number']) ?></div></div>
    </header>
    <section class="receipt-meta">
        <div><span>Date</span><strong><?= e(format_date($slip['adjustment_date'])) ?></strong></div>
        <div><span>Refunded to</span><strong><?= e(trim($slip['first_name'] . ' ' . ($slip['middle_name'] ? mb_substr($slip['middle_name'], 0, 1) . '. ' : '') . $slip['last_name'])) ?></strong><small><?= e($slip['participant_number']) ?></small></div>
        <div><span>Paid out from</span><strong><?= e($slip['account_name']) ?></strong><small><?= e($slip['fund_name']) ?></small></div>
    </section>
    <table class="receipt-lines">
        <thead><tr><th>Particulars</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
            <tr><td>Overpayment refunded — <?= e($slip['event_name']) ?>, <?= e($slip['from_rule']) ?><div class="receipt-includes">Original receipt(s): <?= e($slip['receipts'] ?: '—') ?> · <?= e($slip['reason']) ?></div></td><td class="text-end"><?= e(money($amountCents, $currency)) ?></td></tr>
        </tbody>
        <tfoot><tr><th>Total refunded</th><th class="text-end"><?= e(money($amountCents, $currency)) ?></th></tr></tfoot>
    </table>
    <p class="receipt-words"><span>Amount in words:</span> <?= e(amount_in_words($amountCents, $currency)) ?></p>
    <footer class="receipt-footer">
        <div class="receipt-sign"><div class="receipt-sign-line"><?= e($slip['created_by_name']) ?></div><span>Released by</span></div>
        <div class="receipt-sign"><div class="receipt-sign-line">&nbsp;</div><span>Received by (payer's signature)</span></div>
    </footer>
</main>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
