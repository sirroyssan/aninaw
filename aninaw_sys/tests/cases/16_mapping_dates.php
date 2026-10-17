<?php
/** Changing the authorised period of a fund-account mapping. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/funds.php';

section('Funds: authorised period dates');

test('a mapping period can be changed but never so that recorded money falls outside it', function () {
    $fx = $GLOBALS['fx'];
    act_as('treasurer', 'TST');
    $mapping = (int) db_value('SELECT id FROM fund_account_mappings WHERE fund_id = ? AND financial_account_id = ? AND valid_until IS NULL', [$fx['fund'], $fx['cashier_box']]);
    $first = db_value('SELECT MIN(j.journal_date) FROM journal_lines l JOIN journal_entries j ON j.id = l.journal_entry_id WHERE l.fund_id = ? AND l.financial_account_id = ?', [$fx['fund'], $fx['cashier_box']]);
    assert_true($first !== null, 'the cashier box holds money of this fund');

    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-30 days')), 'valid_until' => date('Y-m-d', strtotime($first . ' -1 day'))]), 'Transactions of this fund were recorded');
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime($first . ' +1 day'))]), 'must still include');
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d'), 'valid_until' => date('Y-m-d', strtotime('-1 day'))]), 'before the start');

    update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-60 days')), 'valid_until' => date('Y-m-d', strtotime('+30 days'))]);
    assert_same([date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('+30 days'))], array_values(db_one('SELECT valid_from, valid_until FROM fund_account_mappings WHERE id = ?', [$mapping])));
    require_fund_account_mapping($fx['fund'], $fx['cashier_box'], date('Y-m-d', strtotime('-45 days')));

    // A later period of the same account may not be overlapped.
    add_fund_mapping($fx['fund'], ['financial_account_id' => (string) $fx['cashier_box'], 'valid_from' => date('Y-m-d', strtotime('+40 days'))]);
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-60 days')), 'valid_until' => '']), 'overlap');
    update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-60 days')), 'valid_until' => date('Y-m-d', strtotime('+39 days'))]);
    assert_true((bool) db_value("SELECT 1 FROM audit_logs WHERE action = 'fund.mapping_dates_changed' AND before_data LIKE '%valid_from%'"), 'old and new dates are audited');

    act_as('cashier', 'TST');
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-61 days'))]), 'permission');
    act_as('other_treasurer', 'OTH');
    assert_rejected(fn() => update_fund_mapping_dates($mapping, ['valid_from' => date('Y-m-d', strtotime('-61 days'))]), 'not found');
});
