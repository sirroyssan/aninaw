<?php
/** Cash Advance Recipient panel (own advances only). Uses $panelData, $panelFailed, $currency. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-advances">
    <div class="section-heading">
        <h2 id="panel-advances"><i class="bi bi-wallet2" aria-hidden="true"></i>My Cash Advances</h2>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: $d = $panelData; ?>
        <?php if (!$d['advances']): ?>
            <div class="card panel"><div class="card-body"><?= empty_state('bi-wallet2', 'No cash advances assigned to you') ?></div></div>
        <?php else: ?>
            <div class="stat-grid">
                <?= stat_card('bi-wallet2', 'Outstanding to Liquidate', format_money($d['outstanding'], $currency), 'Released − verified expenses − confirmed returns', $d['outstanding'] > 0 ? 'warning' : 'success') ?>
                <?= stat_card('bi-paperclip', 'Missing Receipts', number_format(array_sum(array_map('intval', array_column($d['advances'], 'missing_receipts')))), 'Expenses without an attachment', 'danger') ?>
            </div>
            <div class="card panel">
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Advance</th><th class="text-end">Released</th><th class="text-end">Expenses (verified / submitted)</th><th class="text-end">Returned</th><th class="text-end">Outstanding</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($d['advances'] as $row): ?>
                            <?php $overdue = $row['liquidation_deadline'] && $row['liquidation_deadline'] < date('Y-m-d') && $row['status'] !== 'liquidated'; ?>
                            <tr>
                                <td><div class="fw-semibold"><?= e($row['advance_number']) ?></div>
                                    <div class="text-muted-sm text-truncate" style="max-width:280px" title="<?= e($row['purpose']) ?>"><?= e($row['purpose']) ?></div>
                                    <div class="text-muted-sm<?= $overdue ? ' text-danger' : '' ?>"><?= $row['liquidation_deadline'] ? 'Liquidate by ' . e(format_date($row['liquidation_deadline'])) : 'No deadline set' ?><?= (int) $row['missing_receipts'] ? ' · ' . e($row['missing_receipts']) . ' receipt(s) missing' : '' ?><?= (int) $row['needs_correction'] ? ' · ' . e($row['needs_correction']) . ' need correction' : '' ?></div></td>
                                <td class="text-end"><?= e(format_money($row['released_amount'], $currency)) ?></td>
                                <td class="text-end"><?= e(format_money($row['verified_expenses'], $currency)) ?> <span class="text-muted-sm">/ <?= e(format_money($row['submitted_expenses'], $currency)) ?></span></td>
                                <td class="text-end"><?= e(format_money($row['returned'], $currency)) ?><?= (int) $row['pending_returns'] ? '<div class="text-muted-sm">' . e($row['pending_returns']) . ' pending</div>' : '' ?></td>
                                <td class="text-end fw-semibold"><?= e(format_money($row['outstanding'], $currency)) ?></td>
                                <td><?= status_badge($overdue && $row['status'] !== 'overdue' ? 'overdue' : $row['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
