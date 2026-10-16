<?php
/** Treasurer / finance panel (read-only figures). Uses $panelData, $panelFailed, $currency. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-finance">
    <div class="section-heading">
        <h2 id="panel-finance"><i class="bi bi-wallet" aria-hidden="true"></i>Financial Summary</h2>
        <span class="section-note">Posted and confirmed records only</span>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: $d = $panelData; ?>
        <div class="stat-grid">
            <?= stat_card('bi-cash-coin', 'Total Collections', format_money($d['collections_total'], $currency), number_format($d['collections_count']) . ' posted payments') ?>
            <?= stat_card('bi-receipt', 'Paid Expenses', format_money($d['expenses_paid'], $currency), number_format($d['expenses_awaiting']) . ' awaiting approval', 'info') ?>
            <?= stat_card('bi-wallet2', 'Outstanding Advances', format_money($d['advances_outstanding'], $currency), number_format($d['advances_open']) . ' open · ' . number_format($d['advances_overdue']) . ' overdue', $d['advances_overdue'] ? 'danger' : 'warning') ?>
            <?= stat_card('bi-check2-square', 'Pending Reconciliation', number_format(array_sum($d['pending'])), 'Items needing review', array_sum($d['pending']) ? 'warning' : 'success') ?>
        </div>

        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Fund Positions</h3></div>
                    <?php if (!$d['funds']): ?>
                        <div class="card-body"><?= empty_state('bi-wallet', 'No active funds', 'Fund accounts will appear here once they are created and activated.') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Fund</th><th class="text-end">Opening (recorded)</th><th class="text-end">Posted position</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['funds'] as $fund): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($fund['fund_name']) ?></div><div class="text-muted-sm"><?= e($fund['fund_code']) ?></div></td>
                                        <td class="text-end"><?= e(format_money($fund['opening_balance'], $currency)) ?></td>
                                        <td class="text-end fw-semibold">
                                            <?php if ((int) $fund['posted_entries'] === 0): ?>
                                                <span class="text-muted-sm">No posted entries</span>
                                            <?php else: ?>
                                                <?= e(format_money($fund['posted_position'], $currency)) ?>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= status_badge($fund['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                    <div class="card-footer-note">Posted position = posted inflows − outflows in the cash-flow register, including posted opening balances.</div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Needs Attention</h3></div>
                    <div class="card-body">
                        <ul class="metric-list">
                            <li><span><i class="bi bi-arrow-left-right me-2 text-success" aria-hidden="true"></i>Turnovers to verify</span><span class="fw-semibold"><?= e((string) $d['pending']['turnovers_to_verify']) ?></span></li>
                            <li><span><i class="bi bi-exclamation-diamond me-2 text-danger" aria-hidden="true"></i>Open discrepancies</span><span class="fw-semibold"><?= e((string) $d['pending']['open_discrepancies']) ?></span></li>
                            <li><span><i class="bi bi-bank me-2 text-success" aria-hidden="true"></i>Unconfirmed deposits</span><span class="fw-semibold"><?= e((string) $d['pending']['deposits_unconfirmed']) ?></span></li>
                            <li><span><i class="bi bi-journal-check me-2 text-success" aria-hidden="true"></i>Liquidations to review</span><span class="fw-semibold"><?= e((string) $d['pending']['liquidations_to_review']) ?></span></li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-xl-6">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Recent Cash Turnovers</h3></div>
                    <?php if (!$d['recent_turnovers']): ?>
                        <div class="card-body"><?= empty_state('bi-arrow-left-right', 'No turnovers yet') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Turnover</th><th class="text-end">Declared</th><th class="text-end">Received</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['recent_turnovers'] as $row): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($row['turnover_number']) ?></div><div class="text-muted-sm"><?= e($row['group_name'] . ' · ' . $row['event_name']) ?></div></td>
                                        <td class="text-end"><?= e(format_money($row['declared_amount'], $currency)) ?></td>
                                        <td class="text-end"><?= $row['received_amount'] === null ? '<span class="text-muted-sm">—</span>' : e(format_money($row['received_amount'], $currency)) ?></td>
                                        <td><?= status_badge($row['status']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Recent Posted Transactions</h3></div>
                    <?php if (!$d['recent_transactions']): ?>
                        <div class="card-body"><?= empty_state('bi-activity', 'No posted transactions yet') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Transaction</th><th>Type</th><th class="text-end">Amount</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['recent_transactions'] as $row): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($row['transaction_number']) ?></div><div class="text-muted-sm"><?= e($row['fund_name'] . ' · ' . $row['account_name'] . ' · ' . format_date($row['transaction_date'])) ?></div></td>
                                        <td><?= e(humanize($row['transaction_type'])) ?></td>
                                        <td class="text-end fw-semibold <?= $row['direction'] === 'inflow' ? 'text-success' : 'text-danger' ?>"><?= $row['direction'] === 'inflow' ? '+' : '−' ?><?= e(format_money($row['amount'], $currency)) ?></td>
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
