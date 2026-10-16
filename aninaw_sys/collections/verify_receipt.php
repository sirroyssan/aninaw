<?php
/**
 * Receipt verification: confirms whether a receipt number was issued by this
 * institution and whether it is still valid or was voided.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission(['collections.view', 'collections.record']);

$number = strtoupper(get_str('number', 80));
$receipt = null;
if ($number !== '') {
    $receipt = db_one(
        "SELECT r.receipt_number, r.receipt_status, r.issued_at, p.id AS payment_id, p.amount, p.payment_date, p.status AS payment_status,
                pt.first_name, pt.last_name, pt.participant_number, CONCAT(u.first_name, ' ', u.last_name) AS issued_by
         FROM receipts r JOIN payments p ON p.id = r.payment_id AND p.institution_id = r.institution_id
         JOIN participants pt ON pt.id = p.payer_participant_id AND pt.institution_id = p.institution_id
         JOIN institution_memberships m ON m.id = r.issued_by_membership_id JOIN users u ON u.id = m.user_id
         WHERE r.institution_id = ? AND r.receipt_number = ?",
        [current_institution_id(), $number]
    );
    audit_log('receipt.verified', 'receipt', null, current_institution_id(), (int) auth_user()['id'], ['receipt_number' => $number, 'found' => (bool) $receipt]);
}

$pageTitle = 'Verify Receipt';
$activeNav = 'collections';
$breadcrumb = [auth_context()['label'], 'Collections'];
require APP_ROOT . '/includes/header.php';
?>
<div style="max-width: 720px">
    <form class="card panel mb-3" method="get">
        <div class="card-body d-flex gap-2 align-items-end">
            <div class="flex-grow-1"><label class="form-label" for="number">Receipt number</label><input class="form-control text-uppercase" id="number" name="number" value="<?= e($number) ?>" placeholder="AR-2026-000001" required autofocus></div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-patch-check me-1" aria-hidden="true"></i>Verify</button>
        </div>
    </form>
    <?php if ($number !== '' && !$receipt): ?>
        <div class="notice notice-danger"><i class="bi bi-x-octagon" aria-hidden="true"></i><div><strong>Not found.</strong> No receipt <?= e($number) ?> was issued by <?= e(auth_context()['label']) ?>.</div></div>
    <?php elseif ($receipt): ?>
        <div class="notice <?= $receipt['receipt_status'] === 'issued' ? 'notice-info' : 'notice-danger' ?>">
            <i class="bi <?= $receipt['receipt_status'] === 'issued' ? 'bi-patch-check' : 'bi-x-octagon' ?>" aria-hidden="true"></i>
            <div><strong><?= $receipt['receipt_status'] === 'issued' ? 'Valid receipt.' : 'This receipt was VOIDED.' ?></strong>
                <?= e($receipt['receipt_number']) ?> for <?= e(m($receipt['amount'])) ?>, received from <?= e($receipt['first_name'] . ' ' . $receipt['last_name'] . ' (' . $receipt['participant_number'] . ')') ?>
                on <?= e(format_datetime($receipt['payment_date'])) ?>, issued by <?= e($receipt['issued_by']) ?>.
                <a href="<?= e(url('collections/payment.php?id=' . $receipt['payment_id'])) ?>">Open payment</a>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
