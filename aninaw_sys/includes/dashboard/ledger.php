<?php
/** Ledger & reconciliation panel (Auditor, Treasurer). Read-only. Uses $panelData, $panelFailed, $currency. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-ledger">
    <div class="section-heading">
        <h2 id="panel-ledger"><i class="bi bi-journal-text" aria-hidden="true"></i>Ledger &amp; Reconciliation</h2>
        <span class="section-note">Read-only</span>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: $d = $panelData; ?>
        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Recent Posted Journal Entries</h3></div>
                    <?php if (!$d['journal_entries']): ?>
                        <div class="card-body"><?= empty_state('bi-journal', 'No posted journal entries', 'Entries appear here once financial transactions are posted to the ledger.') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Entry</th><th class="text-end">Debits</th><th class="text-end">Credits</th><th>Balance</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['journal_entries'] as $row): ?>
                                    <?php $balanced = abs((float) $row['debits'] - (float) $row['credits']) < 0.005; ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($row['journal_number']) ?></div><div class="text-muted-sm"><?= e(format_date($row['journal_date']) . ' · ' . humanize($row['source_type'])) ?></div></td>
                                        <td class="text-end"><?= e(format_money($row['debits'], $currency)) ?></td>
                                        <td class="text-end"><?= e(format_money($row['credits'], $currency)) ?></td>
                                        <td><?= $balanced ? '<span class="badge badge-soft-success">Balanced</span>' : '<span class="badge badge-soft-danger">Unbalanced</span>' ?></td>
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
                    <div class="card-header"><h3>Reconciliation Status</h3></div>
                    <div class="card-body">
                        <div class="subheading">Cash turnovers</div>
                        <?php if (!$d['turnover_status']): ?>
                            <p class="text-muted-sm">No turnovers recorded.</p>
                        <?php else: ?>
                            <ul class="metric-list mb-3">
                                <?php foreach ($d['turnover_status'] as $status => $count): ?>
                                    <li><span><?= status_badge($status) ?></span><span class="fw-semibold"><?= e((string) $count) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="subheading">Deposits</div>
                        <?php if (!$d['deposit_status']): ?>
                            <p class="text-muted-sm mb-0">No deposits recorded.</p>
                        <?php else: ?>
                            <ul class="metric-list mb-0">
                                <?php foreach ($d['deposit_status'] as $status => $count): ?>
                                    <li><span><?= status_badge($status) ?></span><span class="fw-semibold"><?= e((string) $count) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card panel">
                    <div class="card-header"><h3>Unresolved Discrepancies</h3></div>
                    <?php if (!$d['discrepancies']): ?>
                        <div class="card-body"><?= empty_state('bi-check2-circle', 'No unresolved discrepancies') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Turnover</th><th>Group</th><th class="text-end">Expected</th><th class="text-end">Actual</th><th class="text-end">Difference</th><th>Status</th><th>Reported</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['discrepancies'] as $row): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= e($row['turnover_number']) ?></td>
                                        <td><?= e($row['group_name']) ?></td>
                                        <td class="text-end"><?= e(format_money($row['expected_amount'], $currency)) ?></td>
                                        <td class="text-end"><?= e(format_money($row['actual_amount'], $currency)) ?></td>
                                        <td class="text-end fw-semibold text-danger"><?= e(format_money($row['difference_amount'], $currency)) ?></td>
                                        <td><?= status_badge($row['status']) ?></td>
                                        <td class="text-muted-sm"><?= e(format_date($row['reported_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
