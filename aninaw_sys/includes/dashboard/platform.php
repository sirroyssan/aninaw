<?php
/** Platform Super Administrator panel. Uses $panelData, $panelFailed. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-platform">
    <div class="section-heading">
        <h2 id="panel-platform"><i class="bi bi-globe2" aria-hidden="true"></i>Platform Overview</h2>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: $d = $panelData; ?>
        <div class="stat-grid">
            <?= stat_card('bi-building', 'Institutions', number_format($d['institutions_total']), number_format((int) ($d['institution_status']['active'] ?? 0)) . ' active') ?>
            <?= stat_card('bi-hourglass-split', 'Pending Onboarding', number_format((int) ($d['institution_status']['pending'] ?? 0)), 'Awaiting activation', 'warning') ?>
            <?= stat_card('bi-people', 'Registered Users', number_format($d['users_total']), number_format((int) ($d['user_status']['active'] ?? 0)) . ' active') ?>
            <?= stat_card('bi-slash-circle', 'Suspended Institutions', number_format((int) ($d['institution_status']['suspended'] ?? 0)), 'Access temporarily blocked', 'danger') ?>
        </div>

        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Recent Institutions</h3></div>
                    <?php if (!$d['recent_institutions']): ?>
                        <div class="card-body"><?= empty_state('bi-building-add', 'No institutions yet', 'Institutions you onboard will appear here.') ?></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-clean align-middle mb-0">
                                <thead><tr><th>Institution</th><th>Type</th><th class="text-end">Active members</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($d['recent_institutions'] as $row): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= e($row['institution_name']) ?></div><div class="text-muted-sm"><?= e($row['institution_code']) ?></div></td>
                                        <td><?= e($row['institution_type'] ?: '—') ?></td>
                                        <td class="text-end"><?= e(number_format((int) $row['active_members'])) ?></td>
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
                    <div class="card-header"><h3>Platform Activity</h3></div>
                    <div class="card-body">
                        <?php if (!$d['recent_activity']): ?>
                            <?= empty_state('bi-clipboard-check', 'No platform activity recorded') ?>
                        <?php else: ?>
                            <ul class="activity-list">
                                <?php foreach ($d['recent_activity'] as $row): ?>
                                    <li><span class="activity-dot"></span><div><div class="activity-text"><?= e(humanize($row['action'])) ?><?= $row['actor'] ? ' · ' . e($row['actor']) : '' ?></div><div class="text-muted-sm"><?= e(format_datetime($row['created_at'])) ?></div></div></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <p class="panel-note"><i class="bi bi-shield-lock" aria-hidden="true"></i>Institution financial records are private to each institution and are not shown at platform level.</p>
    <?php endif; ?>
</section>
