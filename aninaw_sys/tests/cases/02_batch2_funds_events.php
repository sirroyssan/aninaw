<?php
/** Batch 2: funds, financial accounts, mappings, opening balances, events, rules, obligations. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/funds.php';
require_once APP_ROOT . '/includes/services/events.php';

section('Batch 2: funds and financial accounts');

test('treasurer creates funds and custody accounts; cash custody needs a custodian', function () use ($members) {
    act_as('treasurer', 'TST');
    $fund = save_fund(null, ['fund_code' => 'icsfest', 'fund_name' => 'ICS Fest Fund', 'custodian_membership_id' => (string) $members['treasurer']]);
    $general = save_fund(null, ['fund_code' => 'GEN', 'fund_name' => 'General Fund', 'custodian_membership_id' => (string) $members['treasurer']]);
    assert_same('draft', db_value('SELECT status FROM fund_accounts WHERE id = ?', [$fund]));
    assert_rejected(fn() => save_financial_account(null, ['account_name' => 'Box', 'account_type' => 'cash_custody']), 'custodian');
    $GLOBALS['fx'] += [
        'fund' => $fund,
        'general' => $general,
        'treasurer_box' => save_financial_account(null, ['account_name' => 'Treasurer Cash Box', 'account_type' => 'cash_custody', 'custodian_membership_id' => (string) $members['treasurer']]),
        'collector_box' => save_financial_account(null, ['account_name' => 'Cora Collection Pouch', 'account_type' => 'cash_custody', 'custodian_membership_id' => (string) $members['collector']]),
        'cashier_box'   => save_financial_account(null, ['account_name' => 'Cashier Vault', 'account_type' => 'cash_custody', 'custodian_membership_id' => (string) $members['cashier']]),
        'bank'          => save_financial_account(null, ['account_name' => 'Bank Savings', 'account_type' => 'bank', 'account_reference' => '****1234']),
    ];
});

test('opening balances need a mapping, post balanced entries and cannot be posted twice', function () {
    act_as('treasurer', 'TST');
    $fx = $GLOBALS['fx'];
    $input = ['financial_account_id' => (string) $fx['treasurer_box'], 'amount' => '1,000.00', 'opening_date' => date('Y-m-d'), 'note' => 'Cash count sheet'];
    assert_rejected(fn() => post_opening_balance($fx['fund'], $input), 'Map the account');
    foreach (['treasurer_box', 'collector_box', 'cashier_box', 'bank'] as $account) {
        add_fund_mapping($fx['fund'], ['financial_account_id' => (string) $fx[$account], 'valid_from' => date('Y-m-d', strtotime('-30 days'))]);
        add_fund_mapping($fx['general'], ['financial_account_id' => (string) $fx[$account], 'valid_from' => date('Y-m-d', strtotime('-30 days'))]);
    }
    assert_rejected(fn() => add_fund_mapping($fx['fund'], ['financial_account_id' => (string) $fx['bank']]), 'overlapping');
    post_opening_balance($fx['fund'], $input);
    assert_same(100000, cash_balance(current_institution_id(), $fx['fund'], $fx['treasurer_box']));
    assert_same('1000.00', db_value('SELECT opening_balance FROM fund_accounts WHERE id = ?', [$fx['fund']]));
    assert_rejected(fn() => post_opening_balance($fx['fund'], $input), 'already been posted');
    $entry = db_one("SELECT SUM(debit_amount) d, SUM(credit_amount) c FROM journal_lines WHERE journal_entry_id = (SELECT MAX(id) FROM journal_entries)");
    assert_same($entry['d'], $entry['c'], 'debits equal credits');
    assert_same(1, (int) db_value("SELECT COUNT(*) FROM cash_flow_transactions WHERE transaction_type = 'opening_balance' AND status = 'posted'"));
});

test('a mistaken opening balance is reversed with a reason and can then be re-posted', function () {
    act_as('treasurer', 'TST');
    $fx = $GLOBALS['fx'];
    $mapping = (int) db_value('SELECT id FROM fund_account_mappings WHERE fund_id = ? AND financial_account_id = ?', [$fx['fund'], $fx['treasurer_box']]);
    reverse_opening_balance($mapping, 'Count sheet was wrong');
    assert_same(0, cash_balance(current_institution_id(), $fx['fund'], null));
    assert_same(2, (int) db_value("SELECT COUNT(*) FROM journal_entries WHERE source_type = 'opening_balance'"), 'original kept, reversal added');
    assert_rejected(fn() => reverse_opening_balance($mapping, 'Twice is not allowed'), 'already been reversed');
    post_opening_balance($fx['fund'], ['financial_account_id' => (string) $fx['treasurer_box'], 'amount' => '500', 'opening_date' => date('Y-m-d'), 'note' => 'Corrected count']);
    assert_same(50000, cash_balance(current_institution_id(), $fx['fund'], null));
    assert_same('500.00', db_value('SELECT opening_balance FROM fund_accounts WHERE id = ?', [$fx['fund']]));
});

test('funds activate, and cannot close while holding money', function () {
    act_as('treasurer', 'TST');
    $fx = $GLOBALS['fx'];
    set_fund_status($fx['fund'], 'active', '');
    set_fund_status($fx['general'], 'active', '');
    assert_rejected(fn() => set_fund_status($fx['fund'], 'closed', 'Event is over'), 'only be closed');
    assert_rejected(fn() => set_fund_status($fx['fund'], 'draft', ''), 'cannot be changed');
    assert_true(db_value('SELECT approved_by FROM fund_accounts WHERE id = ?', [$fx['fund']]) !== null, 'approval recorded');
});

test('fund visibility: without view-all, members see only funds assigned to them', function () use ($members) {
    act_as('organizer', 'TST');
    assert_forbidden('funds.view');
    act_as('treasurer', 'TST');
    grant_fund_access($GLOBALS['fx']['general'], ['membership_id' => (string) $members['auditor'], 'permission_level' => 'view']);
    act_as('auditor', 'TST');
    assert_true(find_visible_fund($GLOBALS['fx']['fund']) !== null, 'auditor has view-all');
    assert_forbidden('funds.manage');
});

test('fund summary answers where the money is and who is accountable', function () {
    act_as('treasurer', 'TST');
    $summary = fund_summary($GLOBALS['fx']['fund']);
    assert_same(50000, $summary['cash']);
    assert_same(50000, $summary['total']);
    assert_same('Treasurer Cash Box', $summary['holdings'][0]['account_name']);
    assert_same('Tess Reyes', $summary['holdings'][0]['custodian_name']);
});

section('Batch 2: events and collection rules');

test('organizer creates an event; groups need a real collector and a different cashier', function () use ($members) {
    act_as('organizer', 'TST');
    $eventId = save_event(null, ['event_code' => 'icsfest26', 'event_name' => 'ICS Fest 2026', 'collection_deadline' => date('Y-m-d', strtotime('+20 days'))]);
    add_event_fund($eventId, ['fund_id' => (string) $GLOBALS['fx']['fund']]);
    assert_rejected(fn() => assign_event_group($eventId, ['group_id' => (string) $GLOBALS['fx']['groupId'], 'collector_membership_id' => (string) $members['student'], 'cashier_membership_id' => (string) $members['cashier']]), 'collect');
    assert_rejected(fn() => assign_event_group($eventId, ['group_id' => (string) $GLOBALS['fx']['groupId'], 'collector_membership_id' => (string) $members['collector'], 'cashier_membership_id' => (string) $members['student']]), 'receive turnovers');
    assign_event_group($eventId, ['group_id' => (string) $GLOBALS['fx']['groupId'], 'collector_membership_id' => (string) $members['collector'], 'cashier_membership_id' => (string) $members['cashier']]);
    assert_rejected(fn() => set_event_status($eventId, 'collecting', ''), 'cannot move');
    set_event_status($eventId, 'approved', '');
    assert_rejected(fn() => set_event_status($eventId, 'collecting', ''), 'collection rule');
    $GLOBALS['fx']['event'] = $eventId;
});

test('only the creator or assigned organizers manage an event', function () {
    act_as('treasurer', 'TST');
    assert_same(false, find_event($GLOBALS['fx']['event'])['can_manage']);
    assert_rejected(fn() => save_collection_rule($GLOBALS['fx']['event'], null, ['rule_name' => 'X', 'amount_due' => '10']), 'creator or its assigned organizers');
});

test('obligations are generated only for eligible participants and generation is repeatable', function () {
    act_as('organizer', 'TST');
    $fx = $GLOBALS['fx'];
    $rule = save_collection_rule($fx['event'], null, ['rule_name' => 'Registration fee', 'amount_due' => '150', 'applies_to_type' => 'all', 'allow_partial_payment' => '1']);
    $groupMembers = (int) db_value("SELECT COUNT(*) FROM group_memberships WHERE group_id = ? AND status = 'active'", [$fx['groupId']]);
    assert_same($groupMembers, (int) db_value('SELECT COUNT(*) FROM event_obligations WHERE collection_rule_id = ?', [$rule]), 'saving the rule charges each active member of the participating group');
    assert_same(0, (int) db_value('SELECT COUNT(*) FROM event_obligations WHERE event_id = ? AND participant_id = ?', [$fx['event'], $fx['p4']]), 'non-participating group excluded');
    assert_same(0, generate_obligations($fx['event']), 'Generate Obligations finds nothing missing');
    $special = save_collection_rule($fx['event'], null, ['rule_name' => 'Late registration', 'amount_due' => '50', 'applies_to_type' => 'participant', 'applies_to_participant' => (string) $fx['p2']]);
    assert_same(1, (int) db_value('SELECT COUNT(*) FROM event_obligations WHERE collection_rule_id = ?', [$special]), 'a rule for one person charges that person');
    assert_same(0, generate_obligations($fx['event']));
    set_event_status($fx['event'], 'collecting', '');
    $GLOBALS['fx'] += ['rule' => $rule, 'special_rule' => $special];
    $stats = event_collection_stats($fx['event']);
    assert_same($groupMembers * 15000 + 5000, $stats['expected']);
    assert_same(0, $stats['received']);
    assert_same($groupMembers, $stats['unpaid_count']);
});

test('waivers need a reason, cannot exceed the balance and update the status', function () {
    act_as('organizer', 'TST');
    $fx = $GLOBALS['fx'];
    $obligation = (int) db_value('SELECT id FROM event_obligations WHERE event_id = ? AND participant_id = ? AND collection_rule_id = ?', [$fx['event'], $fx['p3'], $fx['rule']]);
    assert_rejected(fn() => waive_obligation($obligation, '200', 'Too much waived'), 'at most');
    assert_rejected(fn() => waive_obligation($obligation, '50', ''), 'reason');
    waive_obligation($obligation, '50', 'Scholar discount approved by dean');
    assert_same('unpaid', db_value('SELECT status FROM event_obligations WHERE id = ?', [$obligation]));
    waive_obligation($obligation, '100', 'Full scholarship approved by dean');
    assert_same('waived', db_value('SELECT status FROM event_obligations WHERE id = ?', [$obligation]));
    assert_same('obligation.waived', db_value("SELECT action FROM audit_logs WHERE entity_type = 'event_obligation' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$obligation]));
    $GLOBALS['fx']['waived_obligation'] = $obligation;
});

test('rule amounts can change before any payment and update unpaid obligations', function () {
    act_as('organizer', 'TST');
    $fx = $GLOBALS['fx'];
    save_collection_rule($fx['event'], $fx['special_rule'], ['rule_name' => 'Late registration', 'amount_due' => '60', 'applies_to_type' => 'participant', 'applies_to_participant' => (string) $fx['p2']]);
    assert_same('60.00', db_value('SELECT amount_due FROM event_obligations WHERE collection_rule_id = ?', [$fx['special_rule']]));
});

test('another institution cannot see this event', function () {
    act_as('other_treasurer', 'OTH');
    assert_same(null, find_event($GLOBALS['fx']['event']));
});

test('a collection rule lists what it includes, shown to payers', function () {
    act_as('organizer', 'TST');
    $fx = $GLOBALS['fx'];
    save_collection_rule($fx['event'], $fx['rule'], ['rule_name' => 'Registration fee', 'amount_due' => '150', 'applies_to_type' => 'all', 'allow_partial_payment' => '1',
        'inclusions' => " T-shirt \r\nLanyard\n\nAM snacks; Lunch\n"]);
    assert_same("T-shirt\nLanyard\nAM snacks\nLunch", db_value('SELECT inclusions FROM collection_rules WHERE id = ?', [$fx['rule']]), 'trimmed, one per line');
    assert_same('<div class="text-muted-sm"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Includes: T-shirt, Lanyard, AM snacks, Lunch</div>', inclusions_html("T-shirt\nLanyard\nAM snacks\nLunch"));
    assert_same('', inclusions_html(null));
    assert_same('<div class="text-muted-sm"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Includes: &lt;b&gt;</div>', inclusions_html('<b>'), 'escaped');
});
