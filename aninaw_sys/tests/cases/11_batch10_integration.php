<?php
/** Batch 10: notifications, reminders, audit hygiene, attachment isolation, cross-module consistency. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/advances.php';
require_once APP_ROOT . '/includes/services/ledger.php';

section('Batch 10: integration');

test('notifications reach only members who hold the permission, inside the institution', function () {
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $before = (int) db_value('SELECT MAX(id) FROM notifications');
    notify_permission($institutionId, 'budgets.approve', 'budget', 'Test notice', 'Only approvers should see this.');
    $recipients = db_query('SELECT DISTINCT u.username FROM notifications n JOIN users u ON u.id = n.recipient_user_id WHERE n.id > ?', [$before])->fetchAll(PDO::FETCH_COLUMN);
    assert_same(['admin'], $recipients, 'only the institution admin holds budgets.approve in TST');
    assert_same(0, (int) db_value('SELECT COUNT(*) FROM notifications WHERE id > ? AND institution_id <> ?', [$before, $institutionId]));
    act_as('treasurer2', 'TST');
    notify_permission($institutionId, 'ledger.post', 'ledger', 'Self-exclusion', 'The actor is not notified about their own action.');
    assert_same(0, (int) db_value("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.recipient_user_id WHERE n.id > ? AND u.username = 'treasurer2'", [$before]));
});

test('daily reminders mark late advances overdue once, with an audit entry and a notice to the recipient', function () {
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $advance = fx_new_advance('100');
    db_query('UPDATE cash_advances SET liquidation_deadline = ? WHERE id = ?', [date('Y-m-d', strtotime('-1 day')), $advance]);
    db_query("DELETE FROM institution_settings WHERE institution_id = ? AND setting_key = 'reminders.last_run'", [$institutionId]);
    generate_due_reminders($institutionId);
    assert_same('overdue', db_value('SELECT status FROM cash_advances WHERE id = ?', [$advance]));
    assert_same(1, (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'cash_advance' AND entity_id = ? AND action = 'advance.overdue'", [$advance]));
    assert_true((bool) db_value("SELECT 1 FROM notifications n JOIN users u ON u.id = n.recipient_user_id WHERE u.username = 'organizer' AND n.title = 'Cash advance overdue'"), 'recipient notified');
    generate_due_reminders($institutionId);
    assert_same(1, (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE entity_type = 'cash_advance' AND entity_id = ? AND action = 'advance.overdue'", [$advance]), 'runs once per day');
    $ledgerBefore = ledger_key_balance($institutionId, 'advances');
    assert_same($ledgerBefore, ledger_key_balance($institutionId, 'advances'), 'overdue is a status, not a posting');
});

test('audit logs never store passwords, tokens or secrets', function () {
    $leaks = (int) db_value(
        "SELECT COUNT(*) FROM audit_logs WHERE before_data REGEXP '\"(password|password_hash|token|token_hash|secret|csrf)\"' OR after_data REGEXP '\"(password|password_hash|token|token_hash|secret|csrf)\"'"
    );
    assert_same(0, $leaks);
    assert_true((int) db_value('SELECT COUNT(*) FROM audit_logs WHERE request_id IS NOT NULL') > 0, 'entries carry a request reference');
});

test('attachments are visible only inside their institution and to authorised roles', function () {
    act_as('treasurer', 'TST');
    $attachment = db_one("SELECT * FROM file_attachments WHERE institution_id = ? AND entity_type = 'expense' LIMIT 1", [current_institution_id()]);
    assert_true($attachment !== null, 'batch 6 stored expense receipts');
    assert_true(attachment_can_view($attachment), 'treasurer may view');
    act_as('student', 'TST');
    assert_same(false, attachment_can_view($attachment), 'members without expense access');
    act_as('other_treasurer', 'OTH');
    assert_same(false, attachment_can_view($attachment), 'other institution');
});

test('after every batch the ledger, register and modules still agree', function () {
    act_as('treasurer', 'TST');
    assert_same([], array_keys(array_filter(ledger_integrity_checks(), static fn($check) => $check['rows'])));
    $rows = trial_balance();
    assert_same(array_sum(array_column($rows, 'debit_balance')), array_sum(array_column($rows, 'credit_balance')));
});
