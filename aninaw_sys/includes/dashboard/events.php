<?php
/** Event Organizer panel. Uses $panelData, $panelFailed, $currency. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-events">
    <div class="section-heading">
        <h2 id="panel-events"><i class="bi bi-calendar-event" aria-hidden="true"></i>My Events</h2>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: $d = $panelData; ?>
        <div class="row g-3">
            <div class="col-xl-8">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Collection Progress</h3></div>
                    <?php if (!$d['events']): ?>
                        <div class="card-body"><?= empty_state('bi-calendar-plus', 'No open events', 'Events you create will appear here with their collection progress.') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Event</th><th class="text-end">Expected</th><th class="text-end">Collected</th><th style="min-width:140px">Progress</th><?php if ($d['show_expenses']): ?><th class="text-end">Paid expenses</th><?php endif; ?></tr></thead>
                                <tbody>
                                <?php foreach ($d['events'] as $event): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($event['event_name']) ?></div><div class="text-muted-sm"><?= e($event['event_code']) ?> · <?= status_badge($event['status']) ?><?= $event['collection_deadline'] ? ' · Due ' . e(format_date($event['collection_deadline'])) : '' ?></div></td>
                                        <td class="text-end"><?= e(format_money($event['expected'], $currency)) ?></td>
                                        <td class="text-end"><?= e(format_money($event['collected'], $currency)) ?></td>
                                        <td><?= progress_bar($event['percent']) ?><div class="text-muted-sm mt-1"><?= e((string) $event['percent']) ?>% · <?= e(format_money($event['remaining'], $currency)) ?> left</div></td>
                                        <?php if ($d['show_expenses']): ?><td class="text-end"><?= e(format_money($event['expenses_paid'], $currency)) ?></td><?php endif; ?>
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
                    <div class="card-header"><h3>Pending Tasks</h3></div>
                    <div class="card-body">
                        <?php if (!$d['tasks']): ?>
                            <?= empty_state('bi-check2-circle', 'Nothing pending', 'Your events have no outstanding setup or collection issues.') ?>
                        <?php else: ?>
                            <ul class="task-list">
                                <?php foreach ($d['tasks'] as $task): ?>
                                    <li><i class="bi <?= e($task['icon']) ?>" aria-hidden="true"></i><span><?= e($task['text']) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
