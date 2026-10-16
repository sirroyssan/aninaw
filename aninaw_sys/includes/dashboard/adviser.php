<?php
/** Adviser panel. Uses $panelData, $panelFailed, $currency. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-adviser">
    <div class="section-heading">
        <h2 id="panel-adviser"><i class="bi bi-mortarboard" aria-hidden="true"></i>Advised Groups</h2>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: $d = $panelData; ?>
        <?php if (!$d['groups']): ?>
            <div class="card panel"><div class="card-body"><?= empty_state('bi-diagram-3', 'No advised groups', 'Groups where you are the active adviser will appear here.') ?></div></div>
        <?php else: ?>
            <div class="chip-row mb-3">
                <?php foreach ($d['groups'] as $group): ?>
                    <span class="info-chip"><i class="bi bi-diagram-3" aria-hidden="true"></i><?= e($group['group_name']) ?> <span class="text-muted-sm">· <?= e(number_format((int) $group['members'])) ?> members</span></span>
                <?php endforeach; ?>
            </div>
            <div class="row g-3">
                <div class="col-xl-8">
                    <div class="card panel h-100">
                        <div class="card-header"><h3>Collection &amp; Turnover Status</h3></div>
                        <?php if (!$d['progress']): ?>
                            <div class="card-body"><?= empty_state('bi-calendar-event', 'No active events for your groups') ?></div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-clean align-middle mb-0">
                                    <thead><tr><th>Group · Event</th><th class="text-end">Collected / Expected</th><th style="min-width:120px">Progress</th><th>Turnovers</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($d['progress'] as $row): ?>
                                        <tr>
                                            <td><div class="fw-semibold"><?= e($row['group_name']) ?></div><div class="text-muted-sm"><?= e($row['event_name']) ?></div></td>
                                            <td class="text-end"><?= e(format_money($row['collected'], $currency)) ?> <span class="text-muted-sm">/ <?= e(format_money($row['expected'], $currency)) ?></span></td>
                                            <td><?= progress_bar($row['percent']) ?><div class="text-muted-sm mt-1"><?= e((string) $row['percent']) ?>%</div></td>
                                            <td>
                                                <?php if (!$row['turnover_status']): ?><span class="text-muted-sm">None yet</span><?php endif; ?>
                                                <?php foreach ($row['turnover_status'] as $status => $count): ?>
                                                    <span class="badge <?= status_badge_class($status) ?>"><?= e(humanize($status)) ?>: <?= e((string) $count) ?></span>
                                                <?php endforeach; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card panel h-100">
                        <div class="card-header"><h3>Open Discrepancies</h3></div>
                        <?php if (!$d['discrepancies']): ?>
                            <div class="card-body"><?= empty_state('bi-check2-circle', 'No open discrepancies') ?></div>
                        <?php else: ?>
                            <ul class="list-clean">
                                <?php foreach ($d['discrepancies'] as $row): ?>
                                    <li><div><div class="fw-semibold"><?= e($row['turnover_number']) ?></div><div class="text-muted-sm"><?= e($row['group_name']) ?> · <?= status_badge($row['status']) ?></div></div><span class="fw-semibold text-danger"><?= e(format_money($row['difference_amount'], $currency)) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
