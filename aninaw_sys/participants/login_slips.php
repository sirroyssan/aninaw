<?php
/**
 * Sign-in slips for logins just created in bulk: name, ID number, username and default password,
 * to print and hand out. The passwords live only in this session (never in the database) and are
 * cleared with "Done" or after 30 minutes.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/users.php';
require_institution_context();
require_permission('users.manage');

const LOGIN_SLIPS_MINUTES = 30;

$issued = $_SESSION['issued_logins'] ?? null;
if (is_array($issued) && time() - (int) $issued['at'] > LOGIN_SLIPS_MINUTES * 60) {
    unset($_SESSION['issued_logins']);
    $issued = null;
}

if (is_post()) {
    handle_post(function () {
        unset($_SESSION['issued_logins']);
        flash('success', 'Slips cleared', 'The default passwords are no longer shown anywhere. Students who lose theirs can be reset from Users.');
        return 'participants/logins.php';
    }, false);
}

if (is_array($issued) && $issued['created'] && get_str('export', 10) === 'csv') {
    audit_log('report.exported', 'user', null, current_institution_id(), (int) auth_user()['id'], ['report' => 'login_slips', 'rows' => count($issued['created'])]);
    send_csv('logins_' . date('Ymd_His') . '.csv', ['Name', 'ID number', 'Username', 'Default password'],
        array_map(static fn($row) => [$row['name'], $row['number'], $row['username'], $row['password']], $issued['created']),
        [['Sign in at', absolute_url('login.php')], ['Note', 'Change the default password at first sign-in. Delete this file after handing out the passwords.']]);
}

$signIn = absolute_url('login.php');
$institution = auth_context()['label'];

$pageTitle = 'Login Slips';
$activeNav = 'participants';
$breadcrumb = [auth_context()['label'], 'People', 'Login Slips'];
require APP_ROOT . '/includes/header.php';
?>
<?php if (!is_array($issued)): ?>
    <?= page_actions('Slips are shown once, right after the logins are created.', button_link('participants/logins.php', 'Create Logins', 'bi-arrow-left', 'btn-outline-secondary')) ?>
    <div class="card panel"><div class="card-body"><?= empty_state('bi-shield-lock', 'No slips to show', 'Default passwords are never stored, so slips cannot be shown again. To give someone a new one, open them in Users and use Reset to Default Password.') ?></div></div>
<?php else: ?>
    <?= page_actions('Print and hand out privately. Each student must change the password at first sign-in. Shown for ' . LOGIN_SLIPS_MINUTES . ' minutes or until you click Done.',
        button_link('participants/logins.php', 'Create More', 'bi-arrow-left', 'btn-outline-secondary')
        . ($issued['created'] ? '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv'])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
            . '<button class="btn btn-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print Slips</button>' : '')) ?>

    <?php if ($issued['skipped']): ?>
        <div class="card panel mb-3 d-print-none">
            <div class="card-header"><h3>Skipped <span class="text-muted-sm">(<?= count($issued['skipped']) ?>)</span></h3></div>
            <div class="table-responsive"><table class="table table-clean table-sm mb-0">
                <thead><tr><th>Name</th><th>ID number</th><th>Why</th></tr></thead>
                <tbody><?php foreach ($issued['skipped'] as $row): ?><tr><td><?= e($row['name']) ?></td><td><?= e($row['number']) ?></td><td class="text-muted-sm"><?= e($row['reason']) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    <?php endif; ?>

    <?php if ($issued['created']): ?>
        <div class="login-slips">
            <?php foreach ($issued['created'] as $row): ?>
                <div class="login-slip">
                    <div class="login-slip-head"><strong><?= e(APP_NAME) ?></strong> · <?= e($institution) ?></div>
                    <div class="login-slip-name"><?= e($row['name']) ?> <span class="text-muted-sm"><?= e($row['number']) ?></span></div>
                    <dl>
                        <dt>Sign in at</dt><dd><?= e($signIn) ?></dd>
                        <dt>Username</dt><dd class="font-monospace"><?= e($row['username']) ?></dd>
                        <dt>Password</dt><dd class="font-monospace"><?= e($row['password']) ?></dd>
                    </dl>
                    <div class="login-slip-note">You will be asked to choose your own password the first time you sign in. Keep this slip private.</div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="mt-3 d-print-none" data-confirm="Done handing out the slips?" data-confirm-text="The default passwords will no longer be shown anywhere.">
        <?= csrf_field() ?>
        <button class="btn btn-outline-danger" type="submit"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Done: Clear Passwords</button>
    </form>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
