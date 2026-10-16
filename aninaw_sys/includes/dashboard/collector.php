<?php
/** Class President / Collector panel. Uses $panelData, $panelFailed, $currency. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-collector">
    <div class="section-heading">
        <h2 id="panel-collector"><i class="bi bi-people" aria-hidden="true"></i>My Group Collections</h2>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: $d = $panelData; ?>
        <?php if ($d['officer_groups']): ?>
            <div class="chip-row mb-3">
                <?php foreach ($d['officer_groups'] as $group): ?>
                    <span class="info-chip"><i class="bi bi-diagram-3" aria-hidden="true"></i><?= e($group['group_name']) ?> <span class="text-muted-sm">· <?= e(humanize($group['officer_type'])) ?><?= $group['academic_year'] ? ' · ' . e($group['academic_year']) : '' ?></span></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Collection Requirements</h3></div>
                    <?php if (!$d['assignments']): ?>
                        <div class="card-body"><?= empty_state('bi-clipboard', 'No active collection assignments', 'When you are assigned as collector for an event, its requirements will appear here.') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Event · Group</th><th class="text-end">Expected</th><th class="text-end">Collected</th><th class="text-end">Remaining</th><th style="min-width:120px">Progress</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['assignments'] as $row): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($row['event_name']) ?></div><div class="text-muted-sm"><?= e($row['group_name'] . ' · Cashier: ' . $row['cashier']) ?><?= $row['collection_deadline'] ? ' · Due ' . e(format_date($row['collection_deadline'])) : '' ?></div></td>
                                        <td class="text-end"><?= e(format_money($row['expected'], $currency)) ?></td>
                                        <td class="text-end"><?= e(format_money($row['collected'], $currency)) ?></td>
                                        <td class="text-end fw-semibold"><?= e(format_money($row['remaining'], $currency)) ?></td>
                                        <td><?= progress_bar($row['percent']) ?><div class="text-muted-sm mt-1"><?= e((string) $row['percent']) ?>% · <?= e(format_money($row['turned_over'], $currency)) ?> turned over</div></td>
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
                    <div class="card-header"><h3>My Turnovers</h3></div>
                    <?php if (!$d['turnovers']): ?>
                        <div class="card-body"><?= empty_state('bi-arrow-left-right', 'No turnovers submitted yet') ?></div>
                    <?php else: ?>
                        <ul class="list-clean">
                            <?php foreach ($d['turnovers'] as $row): ?>
                                <li><div><div class="fw-semibold"><?= e($row['turnover_number']) ?></div><div class="text-muted-sm"><?= e($row['event_name'] . ' · ' . format_date($row['submitted_at'])) ?></div><div class="mt-1"><?= status_badge($row['status']) ?></div></div>
                                    <div class="text-end"><div class="fw-semibold"><?= e(format_money($row['declared_amount'], $currency)) ?></div><?php if ($row['received_amount'] !== null): ?><div class="text-muted-sm">Received <?= e(format_money($row['received_amount'], $currency)) ?></div><?php endif; ?></div></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
