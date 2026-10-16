<?php
/** Batch 9: report definitions run, agree with the services and stay inside the institution. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/reports.php';

section('Batch 9: reports');

/** Runs a report definition with filters; returns all rows (computed columns included). */
function fx_report(string $key, array $filters = []): array
{
    $definition = report_definitions()[$key];
    $f = array_merge(['institution_id' => current_institution_id(), 'from' => null, 'to' => null, 'fund' => null, 'event' => null, 'group' => null,
        'department' => null, 'account' => null, 'product' => null, 'category' => null, 'variant' => null, 'status' => '', 'q' => ''], $filters);
    [$sql, $params] = $definition['build']($f);
    $rows = db_all("SELECT * FROM ({$sql}) r ORDER BY {$definition['order']}", $params);
    foreach ($rows as &$row) {
        foreach ($definition['columns'] as $alias => $column) {
            if (isset($column[3]) && is_callable($column[3])) {
                $row[$alias] = $column[3]($row);
            }
        }
    }
    return $rows;
}

function fx_sum(array $rows, string $column): int
{
    return array_sum(array_map(static fn($row) => to_cents($row[$column]), $rows));
}

test('every tabular report runs with and without each of its filters', function () {
    act_as('treasurer', 'TST');
    $fx = $GLOBALS['fx'];
    $values = ['from' => date('Y-01-01'), 'to' => date('Y-m-d'), 'fund' => $fx['general'], 'event' => $fx['event'], 'group' => $fx['groupId'],
        'department' => (int) db_value('SELECT id FROM departments WHERE institution_id = ? LIMIT 1', [current_institution_id()]) ?: 1, 'account' => $fx['treasurer_box'], 'q' => 'a',
        'product' => 1, 'category' => 1, 'variant' => 1];
    foreach (report_definitions() as $key => $definition) {
        fx_report($key);
        foreach ($definition['filters'] as $filter) {
            $value = $filter === 'status' ? array_key_first($definition['statuses']) : $values[$filter];
            fx_report($key, [$filter => $value]);
        }
    }
    assert_same(30, array_sum(array_map('count', report_catalog())), 'the hub lists the twenty financial and ten store reports');
});

test('report totals agree with the ledger and the services', function () {
    act_as('treasurer', 'TST');
    $institutionId = (int) current_institution_id();
    $fx = $GLOBALS['fx'];

    $custody = fx_report('cash_custody');
    assert_same(cash_balance($institutionId, null, null), fx_sum($custody, 'balance'), 'custody report = ledger cash');
    assert_same(cash_balance($institutionId, $fx['general'], null), fx_sum(fx_report('cash_custody', ['fund' => $fx['general']]), 'balance'));

    $summary = fx_report('collection_summary', ['event' => $fx['event']]);
    $stats = event_collection_stats($fx['event']);
    assert_same($stats['expected'], fx_sum($summary, 'expected') - fx_sum($summary, 'waived'));
    assert_same((int) db_value("SELECT COALESCE(SUM(pa.allocated_amount), 0) * 100 FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id AND p.status = 'posted' JOIN event_obligations o ON o.id = pa.obligation_id WHERE o.event_id = ?", [$fx['event']]),
        fx_sum($summary, 'collected'), 'collected = posted allocations only');

    $posted = fx_report('payment_history', ['status' => 'posted']);
    $reversed = fx_report('payment_history', ['status' => 'reversed']);
    assert_true(count($reversed) > 0, 'batch 3 reversed a payment');
    assert_same([], array_intersect(array_column($posted, 'id'), array_column($reversed, 'id')), 'reversed payments never appear in posted totals');

    $liquidation = fx_report('liquidation_report');
    assert_same(ledger_key_balance($institutionId, 'advances'), fx_sum($liquidation, 'released_amount') - fx_sum($liquidation, 'verified') - fx_sum($liquidation, 'returned'),
        'unliquidated advances = Cash Advances Receivable');

    require_once APP_ROOT . '/includes/services/budgets.php';
    $budget = find_budget($fx['budget']);
    $service = budget_vs_actual($budget)['rows'];
    $report = array_values(array_filter(fx_report('budget_vs_actual'), static fn($r) => (int) $r['id'] === $fx['budget']));
    foreach ($report as $row) {
        $match = array_values(array_filter($service, static fn($s) => $s['category'] === $row['category']))[0];
        assert_same($match['actual'], to_cents($row['actual']), $row['category'] . ' actual');
        assert_same($match['pending'], to_cents($row['pending']), $row['category'] . ' pending');
    }
    assert_same(count($budget['lines']), count($report), 'one report row per budget line');
});

test('reports never include another institution\'s records', function () {
    act_as('other_treasurer', 'OTH');
    foreach (report_definitions() as $key => $definition) {
        assert_same([], fx_report($key), $key . ' is empty for an institution with no activity');
    }
    act_as('auditor', 'TST');
    $foreign = (int) db_value("SELECT COUNT(*) FROM audit_logs WHERE institution_id <> ?", [current_institution_id()]);
    assert_true($foreign > 0, 'other institutions have audit entries');
    $ids = array_column(fx_report('audit_trail'), 'id');
    assert_same(0, (int) db_value('SELECT COUNT(*) FROM audit_logs WHERE id IN (' . (implode(',', array_map('intval', $ids)) ?: '0') . ') AND (institution_id IS NULL OR institution_id <> ?)', [current_institution_id()]));
});

test('audit change summaries omit network details', function () {
    $text = audit_changes_text('{"status":"draft"}', '{"status":"approved","ip":"10.0.0.1","user_agent":"Browser"}');
    assert_same('status: draft → approved', $text);
});
