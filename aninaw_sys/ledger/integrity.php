<?php
/**
 * Ledger integrity: balanced entries and funds, cash register vs ledger, every
 * completed operational record posted exactly once, advances receivable vs the
 * advance records, and stale prepared entries.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/ledger.php';
require_institution_context();
require_permission(['ledger.view', 'ledger.post', 'reconciliation.manage']);

$checks = ledger_integrity_checks();
$failed = array_filter($checks, static fn($check) => $check['rows']);

$pageTitle = 'Ledger Integrity';
$activeNav = 'ledger';
$breadcrumb = [auth_context()['label'], 'General Ledger'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Ledger Integrity Checks</h2><p><?= e(auth_context()['label']) ?> · Run <?= e(format_datetime(date('Y-m-d H:i:s'))) ?></p></div>
<?= page_actions('Compares the ledger with the operational records it was posted from. A failed check names the records to investigate; fix them through their module or an authorised adjusting entry.',
    button_link('ledger/index.php', 'Journal', 'bi-arrow-left', 'btn-outline-secondary')
    . button_link('reconciliation/index.php', 'Account Reconciliation', 'bi-check2-square', 'btn-outline-primary')
    . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>') ?>

<div class="stat-grid">
    <?= stat_card('bi-shield-check', 'Checks passed', (count($checks) - count($failed)) . ' of ' . count($checks), $failed ? 'Some checks need attention' : 'Ledger agrees with the records', $failed ? 'warning' : 'success') ?>
    <?= stat_card('bi-exclamation-triangle', 'Items to investigate', number_format(array_sum(array_map(static fn($c) => count($c['rows']), $checks))), 'Across failed checks', $failed ? 'danger' : 'success') ?>
</div>

<div class="card panel">
    <ul class="list-group list-group-flush">
        <?php foreach ($checks as $key => $check): ?>
            <li class="list-group-item py-3">
                <div class="d-flex align-items-start gap-3">
                    <i class="bi <?= $check['rows'] ? 'bi-x-circle-fill text-danger' : 'bi-check-circle-fill text-success' ?> fs-5" aria-hidden="true"></i>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= e($check['title']) ?> <span class="visually-hidden"><?= $check['rows'] ? 'failed' : 'passed' ?></span></div>
                        <div class="text-muted-sm"><?= e($check['help']) ?></div>
                        <?php if ($check['rows']): ?>
                            <ul class="mt-2 mb-0 small">
                                <?php foreach (array_slice($check['rows'], 0, 50) as $row): ?>
                                    <li><a href="<?= e(url($check['link'] . $row['id'])) ?>"><?= e($row['reference']) ?></a> — <?= e($row['detail']) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($check['rows']) > 50): ?><li class="text-muted-sm">and <?= e(number_format(count($check['rows']) - 50)) ?> more</li><?php endif; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
