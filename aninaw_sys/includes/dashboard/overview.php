<?php
/** Institution Administrator panel. Uses $panelData, $panelFailed. */
declare(strict_types=1);
?>
<section class="dashboard-section" aria-labelledby="panel-overview">
    <div class="section-heading">
        <h2 id="panel-overview"><i class="bi bi-building" aria-hidden="true"></i>Institution Overview</h2>
    </div>
    <?php if ($panelFailed): ?>
        <?= panel_error() ?>
    <?php else: $d = $panelData; ?>
        <div class="stat-grid">
            <?= stat_card('bi-people', 'Active Members', number_format((int) ($d['memberships']['active'] ?? 0)), number_format((int) ($d['memberships']['pending'] ?? 0)) . ' pending approval') ?>
            <?= stat_card('bi-calendar-event', 'Active Events', number_format($d['active_events']), 'Approved or collecting') ?>
            <?= stat_card('bi-wallet', 'Active Funds', number_format($d['active_funds'])) ?>
            <?= stat_card('bi-diagram-3', 'Groups / Participants', number_format($d['active_groups']) . ' / ' . number_format($d['active_participants']), 'Active records', 'info') ?>
        </div>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Profile</h3><?= status_badge($d['institution']['status'] ?? '') ?></div>
                    <div class="card-body">
                        <dl class="detail-list">
                            <dt>Name</dt><dd><?= e($d['institution']['institution_name'] ?? '—') ?></dd>
                            <dt>Code</dt><dd><?= e($d['institution']['institution_code'] ?? '—') ?></dd>
                            <dt>Type</dt><dd><?= e($d['institution']['institution_type'] ?: '—') ?></dd>
                            <dt>E-mail</dt><dd><?= e($d['institution']['email'] ?: '—') ?></dd>
                            <dt>Phone</dt><dd><?= e($d['institution']['phone'] ?: '—') ?></dd>
                            <dt>Currency</dt><dd><?= e($d['institution']['currency_code'] ?? '—') ?> · <?= e($d['institution']['timezone'] ?? '') ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card panel h-100">
                    <div class="card-header"><h3>Members by Role</h3></div>
                    <div class="card-body">
                        <?php if (!$d['roles']): ?>
                            <?= empty_state('bi-shield-lock', 'No roles assigned yet') ?>
                        <?php else: ?>
                            <ul class="metric-list">
                                <?php foreach ($d['roles'] as $row): ?>
                                    <li><span><?= e($row['role_name']) ?></span><span class="fw-semibold"><?= e(number_format((int) $row['members'])) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
