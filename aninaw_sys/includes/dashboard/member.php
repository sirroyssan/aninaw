<?php
/** Student / Member panel (own records only). Uses $panelData, $panelFailed, $currency. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-member">
    <div class="section-heading">
        <h2 id="panel-member"><i class="bi bi-person-badge" aria-hidden="true"></i>My Obligations &amp; Payments</h2>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php elseif (!$panelData['participant']): ?>
        <div class="card panel"><div class="card-body"><?= empty_state('bi-person-exclamation', 'Your account is not linked to a member record yet', 'Ask your institution administrator to link your login to your student or member record so you can see your obligations and receipts.') ?></div></div>
    <?php else: $d = $panelData; ?>
        <div class="stat-grid">
            <?= stat_card('bi-card-checklist', 'Total Due', format_money($d['total_due'], $currency), count($d['obligations']) . ' obligations') ?>
            <?= stat_card('bi-check2-circle', 'Paid', format_money($d['total_paid'], $currency), 'Posted payments') ?>
            <?= stat_card('bi-hourglass-split', 'Outstanding Balance', format_money($d['balance'], $currency), $d['balance'] > 0 ? 'Remaining to pay' : 'Fully settled', $d['balance'] > 0 ? 'warning' : 'success') ?>
            <?= stat_card('bi-person-vcard', 'Member No.', $d['participant']['participant_number'], humanize($d['participant']['participant_type']), 'info') ?>
        </div>
        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Obligations</h3></div>
                    <?php if (!$d['obligations']): ?>
                        <div class="card-body"><?= empty_state('bi-emoji-smile', 'No obligations', 'You have no event payments due.') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Event</th><th class="text-end">Due</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['obligations'] as $row): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($row['event_name']) ?></div><div class="text-muted-sm"><?= e($row['rule_name']) ?><?= $row['due_date'] ? ' · Due ' . e(format_date($row['due_date'])) : '' ?></div><?= inclusions_html($row['inclusions']) ?></td>
                                        <td class="text-end"><?= e(format_money((float) $row['amount_due'] - (float) $row['waived_amount'], $currency)) ?></td>
                                        <td class="text-end"><?= e(format_money($row['paid'], $currency)) ?></td>
                                        <td class="text-end fw-semibold"><?= e(format_money($row['balance'], $currency)) ?></td>
                                        <td><?= status_badge($row['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Payment History &amp; Receipts</h3></div>
                    <?php if (!$d['payments']): ?>
                        <div class="card-body"><?= empty_state('bi-receipt', 'No payments yet') ?></div>
                    <?php else: ?>
                        <ul class="list-clean">
                            <?php foreach ($d['payments'] as $row): ?>
                                <li><div><div class="fw-semibold"><?= e($row['receipt_number'] ? 'OR ' . $row['receipt_number'] : $row['payment_number']) ?></div><div class="text-muted-sm"><?= e(format_date($row['payment_date']) . ' · ' . humanize($row['payment_method'])) ?><?= $row['status'] === 'refunded' ? ' · ' . status_badge('refunded') : '' ?></div></div><span class="text-end"><span class="fw-semibold d-block"><?= e(format_money($row['amount'], $currency)) ?></span><?php if ($row['receipt_number']): ?><a class="btn btn-outline-primary btn-xs mt-1" href="<?= e(url('collections/print_receipt.php?id=' . $row['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-receipt me-1" aria-hidden="true"></i>View receipt</a><?php endif; ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
