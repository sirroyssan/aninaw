<?php
/** Institution audit activity panel. Uses $panelData, $panelFailed. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-activity">
    <div class="section-heading">
        <h2 id="panel-activity"><i class="bi bi-clipboard-check" aria-hidden="true"></i>Recent Activity</h2>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: ?>
        <div class="card panel">
            <?php if (!$panelData['entries']): ?>
                <div class="card-body"><?= empty_state('bi-clipboard', 'No activity recorded yet') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Action</th><th>Record</th><th>By</th><th>When</th></tr></thead>
                        <tbody>
                        <?php foreach ($panelData['entries'] as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= e(humanize($row['action'])) ?></td>
                                <td><?= e(humanize($row['entity_type'])) ?><?= $row['entity_id'] ? ' #' . e((string) $row['entity_id']) : '' ?></td>
                                <td><?= e($row['actor'] ?: 'System') ?></td>
                                <td class="text-muted-sm"><?= e(format_datetime($row['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
