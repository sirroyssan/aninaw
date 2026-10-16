<?php
/** Accounting foundation: money, numbering, balanced posting, reversals. */
declare(strict_types=1);

section('Foundation: money and numbering');

test('money parsing is exact and rejects invalid input', function () {
    assert_same(15000, parse_money('150'));
    assert_same(125050, parse_money('1,250.50'));
    assert_same(5, parse_money('0.05'));
    assert_same(null, parse_money('-5'));
    assert_same(null, parse_money('1.234'));
    assert_same(null, parse_money('abc'));
    assert_same(null, parse_money('0'));
    assert_same(0, parse_money('0', true));
    assert_same(1999, to_cents('19.99'));
    assert_same(-1050, to_cents('-10.50'));
    assert_same('1250.05', cents_to_decimal(125005));
    assert_same('₱1,250.05', money(125005));
    // 0.1 + 0.2 style float errors cannot occur.
    assert_same(30, parse_money('0.10') + parse_money('0.20'));
});

test('document numbers are sequential and unique per institution', function () {
    act_as('treasurer', 'TST');
    $first = db_transaction(fn() => next_document_number(current_institution_id(), 'test_doc', 'TD'));
    $second = db_transaction(fn() => next_document_number(current_institution_id(), 'test_doc', 'TD'));
    assert_same('TD-' . date('Y') . '-000001', $first);
    assert_same('TD-' . date('Y') . '-000002', $second);
});

section('Foundation: posting');

test('unbalanced journal entries are refused', function () {
    act_as('treasurer', 'TST');
    $threw = false;
    try {
        db_transaction(fn() => post_journal([
            'institution_id' => current_institution_id(), 'date' => date('Y-m-d'), 'description' => 'bad',
            'source_type' => 'test', 'source_id' => 1, 'cash_flow_type' => 'adjustment',
            'lines' => [['fund_id' => 1, 'account' => 'expenses', 'debit' => 100], ['fund_id' => 1, 'account' => 'fund_balance', 'credit' => 99]],
        ]));
    } catch (LogicException) {
        $threw = true;
    }
    assert_true($threw, 'Unbalanced entry should throw');
    assert_same(0, (int) db_value('SELECT COUNT(*) FROM journal_entries'));
});
