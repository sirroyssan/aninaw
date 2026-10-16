<?php
/** Faculty Cashier / Collection Officer panel. Uses $panelData, $panelFailed, $currency. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-cashier">
    <div class="section-heading">
        <h2 id="panel-cashier"><i class="bi bi-cash-coin" aria-hidden="true"></i>Cashier Desk</h2>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: $d = $panelData; ?>
        <div class="stat-grid">
            <?= stat_card('bi-cash-coin', 'Collections I Received', format_money($d['collected_total'], $currency), number_format($d['collected_count']) . ' posted payments') ?>
            <?= stat_card('bi-arrow-left-right', 'Turnovers Awaiting Me', number_format(count($d['pending_turnovers'])), 'Submitted or pending verification', count($d['pending_turnovers']) ? 'warning' : 'success') ?>
            <?= stat_card('bi-exclamation-diamond', 'Open Discrepancies', number_format(count($d['open_discrepancies'])), 'On turnovers I receive', count($d['open_discrepancies']) ? 'danger' : 'success') ?>
            <?= stat_card('bi-diagram-3', 'Assigned Groups', number_format($d['assigned_groups']), 'Active event assignments', 'info') ?>
        </div>
        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Turnovers to Receive / Verify</h3></div>
                    <?php if (!$d['pending_turnovers']): ?>
                        <div class="card-body"><?= empty_state('bi-inbox', 'No pending turnovers', 'Turnovers submitted to you will appear here.') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Turnover</th><th class="text-end">Declared</th><th class="text-end">Received</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['pending_turnovers'] as $row): ?>
                                    <?php $mismatch = $row['received_amount'] !== null && (float) $row['received_amount'] !== (float) $row['declared_amount']; ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($row['turnover_number']) ?></div><div class="text-muted-sm"><?= e($row['group_name'] . ' · ' . $row['event_name'] . ' · by ' . $row['submitted_by']) ?></div></td>
                                        <td class="text-end"><?= e(format_money($row['declared_amount'], $currency)) ?></td>
                                        <td class="text-end<?= $mismatch ? ' text-danger fw-semibold' : '' ?>"><?= $row['received_amount'] === null ? '<span class="text-muted-sm">Not counted</span>' : e(format_money($row['received_amount'], $currency)) ?></td>
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
                <div class="card panel mb-3">
                    <div class="card-header"><h3>Recent Receipts I Issued</h3></div>
                    <?php if (!$d['recent_receipts']): ?>
                        <div class="card-body"><?= empty_state('bi-receipt', 'No receipts issued yet') ?></div>
                    <?php else: ?>
                        <ul class="list-clean">
                            <?php foreach ($d['recent_receipts'] as $row): ?>
                                <li><div><div class="fw-semibold"><?= e($row['receipt_number']) ?> <?= $row['receipt_status'] === 'voided' ? status_badge('voided') : '' ?></div><div class="text-muted-sm"><?= e($row['payer'] . ' · ' . format_datetime($row['issued_at'])) ?></div></div><span class="fw-semibold"><?= e(format_money($row['amount'], $currency)) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <?php if ($d['open_discrepancies']): ?>
                    <div class="card panel">
                        <div class="card-header"><h3>Discrepancies Requiring Attention</h3></div>
                        <ul class="list-clean">
                            <?php foreach ($d['open_discrepancies'] as $row): ?>
                                <li><div><div class="fw-semibold"><?= e($row['turnover_number']) ?></div><div class="text-muted-sm">Expected <?= e(format_money($row['expected_amount'], $currency)) ?> · Counted <?= e(format_money($row['actual_amount'], $currency)) ?></div></div><span class="fw-semibold text-danger"><?= e(format_money($row['difference_amount'], $currency)) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
