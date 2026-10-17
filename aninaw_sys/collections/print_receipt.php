<?php
/**
 * Printable receipt. The first print is the original; later prints are marked REPRINT,
 * need authorisation and are logged. Voided receipts are marked VOID.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission(['collections.view', 'collections.record']);

$payment = require_found(find_payment(require_get_id()));
if (!$payment['receipt_id']) {
    render_error_page(404, 'No Receipt', 'This payment has no receipt.');
}
$isReprint = register_receipt_print($payment);
$institution = db_one('SELECT * FROM institutions WHERE id = ?', [current_institution_id()]);
$title = institution_setting((int) current_institution_id(), 'receipt.title', 'Acknowledgment Receipt');
$footer = institution_setting((int) current_institution_id(), 'receipt.footer', '');
$currency = current_currency();
$amountCents = to_cents($payment['amount']);
$voided = $payment['receipt_status'] === 'voided';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($payment['receipt_number'] . ' · ' . $title) ?></title>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/receipt.css')) ?>">
</head>
<body class="receipt-body" data-base-url="<?= e(app_base_path()) ?>" data-flash="[]">
<div class="receipt-toolbar no-print">
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('collections/payment.php?id=' . $payment['id'])) ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back</a>
    <button class="btn btn-primary btn-sm" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>
</div>
<main class="receipt<?= $voided ? ' is-void' : '' ?>">
    <?php if ($voided): ?><div class="receipt-watermark">VOID</div><?php elseif ($isReprint): ?><div class="receipt-watermark receipt-watermark-soft">REPRINT</div><?php endif; ?>
    <header class="receipt-header">
        <div>
            <div class="receipt-institution"><?= e($institution['institution_name']) ?></div>
            <div class="receipt-address"><?= e(trim(($institution['address'] ?? '') . ' ' . ($institution['phone'] ?? ''))) ?></div>
        </div>
        <div class="text-end">
            <div class="receipt-title"><?= e($title) ?></div>
            <div class="receipt-number">No. <?= e($payment['receipt_number']) ?></div>
        </div>
    </header>
    <section class="receipt-meta">
        <div><span>Date</span><strong><?= e(format_datetime($payment['payment_date'])) ?></strong></div>
        <div><span>Received from</span><strong><?= e(trim($payment['first_name'] . ' ' . ($payment['middle_name'] ? mb_substr($payment['middle_name'], 0, 1) . '. ' : '') . $payment['last_name'])) ?></strong><small><?= e($payment['participant_number']) ?></small></div>
        <div><span>Payment</span><strong><?= e(PAYMENT_METHODS[$payment['payment_method']] ?? $payment['payment_method']) ?></strong><small><?= e($payment['external_reference'] ? 'Ref. ' . $payment['external_reference'] : $payment['payment_number']) ?></small></div>
    </section>
    <table class="receipt-lines">
        <thead><tr><th>Particulars</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($payment['allocations'] as $row): ?>
            <tr><td><?= e($row['event_name']) ?> — <?= e($row['rule_name']) ?><?= inclusions_html($row['inclusions'], 'receipt-includes') ?></td><td class="text-end"><?= e(m($row['allocated_amount'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($payment['order']): ?>
            <tr><td>Order <?= e($payment['order']['order_number']) ?> — <?= e(implode(', ', array_map(static fn($i) => $i['description'] . ' × ' . $i['quantity'], $payment['order_items']))) ?><?= to_cents($payment['amount']) < to_cents($payment['order']['total_amount']) ? ' (payment toward ' . e(m($payment['order']['total_amount'])) . ')' : '' ?></td><td class="text-end"><?= e(m($payment['amount'])) ?></td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot><tr><th>Total</th><th class="text-end"><?= e(money($amountCents, $currency)) ?></th></tr></tfoot>
    </table>
    <p class="receipt-words"><span>Amount in words:</span> <?= e(amount_in_words($amountCents, $currency)) ?></p>
    <footer class="receipt-footer">
        <div class="receipt-sign"><div class="receipt-sign-line"><?= e($payment['received_by_name']) ?></div><span>Received by</span></div>
        <div class="receipt-note"><?= e($footer) ?><br>Issued <?= e(format_datetime($payment['issued_at'])) ?><?= $isReprint ? ' · Reprinted ' . e(format_datetime(date('Y-m-d H:i:s'))) : '' ?></div>
    </footer>
</main>
<script src="<?= e(asset('vendor/sweetalert2/sweetalert2.all.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
