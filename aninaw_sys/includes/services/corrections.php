<?php
/**
 * Correcting a collection amount after payments were received, and settling the overpayments.
 *
 * correct_rule_amount() lowers a locked rule (e.g. Registration Fee ₱1,750 → ₱1,500): every
 * charge changes to the new amount at once. Money already paid above it is no longer income but
 * owed back to the payer: Dr Collections / Cr Overpayments (2100), per fund.
 *
 * Each overpayment is then settled, as a numbered adjustment:
 *  - refund_overpayment(): cash goes back to the payer (Dr 2100 / Cr cash, cash-flow "refund"),
 *    with a printable refund slip (RF-…);
 *  - apply_overpayment(): the money pays another charge of the same person in the event
 *    (Dr 2100 / Cr Collections, ADJ-…).
 * Settling moves the payment's allocation off the corrected charge (to the other charge, or out
 * as refunded); payment_adjustment_parts record which payment each peso came from, so the
 * original receipt still adds up and stays valid. Payments with settlements cannot be reversed.
 */
declare(strict_types=1);

require_once __DIR__ . '/payments.php';

function corrections_ready(): bool
{
    static $ready = null;
    return $ready ??= (bool) db_value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'rule_corrections'");
}

function require_corrections_ready(): void
{
    if (!corrections_ready()) {
        fail_user('Correcting amounts needs database/migration_011_rule_corrections.sql. Ask the administrator to import it once (phpMyAdmin → Import).');
    }
}

/** Fund a posted payment was booked to (the event's first fund if the payment has no journal entry, e.g. imported data). */
function payment_fund_id(int $paymentId, int $eventId): int
{
    $fund = (int) db_value(
        "SELECT l.fund_id FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id AND l.institution_id = j.institution_id
         WHERE j.institution_id = ? AND j.source_type = 'payment' AND j.source_id = ? AND j.reversal_of_id IS NULL LIMIT 1",
        [current_institution_id(), $paymentId]
    );
    return $fund ?: ((int) (event_funds($eventId)[0]['fund_id'] ?? 0) ?: fail_user('The event has no fund to book the correction to.'));
}

/** Paid above what is now due on a charge, in cents (0 when not overpaid). */
function obligation_overpaid_cents(int $obligationId): int
{
    $row = db_one('SELECT amount_due, waived_amount, status FROM event_obligations WHERE institution_id = ? AND id = ?', [current_institution_id(), $obligationId]);
    if (!$row || $row['status'] === 'cancelled') {
        return 0;
    }
    return max(0, obligation_paid_cents($obligationId) - (to_cents($row['amount_due']) - to_cents($row['waived_amount'])));
}

/**
 * Lowers a rule's amount after payments. Returns what changed.
 * @return array{charges: int, overpaid_people: int, overpaid_total: int}
 */
function correct_rule_amount(int $eventId, int $ruleId, string $amountInput, string $reason): array
{
    require_corrections_ready();
    $institutionId = (int) current_institution_id();
    $event = require_event_manager($eventId);
    if (in_array($event['status'], ['closed', 'cancelled'], true)) {
        fail_user('Amounts cannot change on a ' . $event['status'] . ' event.');
    }
    if (mb_strlen(trim($reason)) < 5) {
        fail_user('Give the reason for the correction (e.g. "Registration is ₱1,500, entered ₱1,750 by mistake").');
    }
    $new = parse_money($amountInput) ?? fail_user('Enter the correct amount.');

    return db_transaction(function () use ($institutionId, $eventId, $ruleId, $new, $reason) {
        $rule = db_one('SELECT * FROM collection_rules WHERE institution_id = ? AND event_id = ? AND id = ? FOR UPDATE', [$institutionId, $eventId, $ruleId]) ?? fail_user('Collection rule not found.');
        $old = to_cents($rule['amount_due']);
        if ($new <= 0 || $new >= $old) {
            fail_user('Enter an amount lower than ' . money($old, current_currency()) . '. To charge more, add a separate collection for the difference.');
        }
        $obligations = db_all("SELECT id, amount_due, waived_amount FROM event_obligations WHERE institution_id = ? AND collection_rule_id = ? AND status <> 'cancelled' FOR UPDATE", [$institutionId, $ruleId]);
        $byFund = [];
        $people = 0;
        $total = 0;
        foreach ($obligations as $o) {
            $id = (int) $o['id'];
            $paid = obligation_paid_cents($id);
            $waived = to_cents($o['waived_amount']);
            $newWaived = min($waived, $new);
            $before = max(0, $paid - (to_cents($o['amount_due']) - $waived));
            $after = max(0, $paid - ($new - $newWaived));
            db_query('UPDATE event_obligations SET amount_due = ?, waived_amount = ? WHERE id = ?', [cents_to_decimal($new), cents_to_decimal($newWaived), $id]);
            refresh_obligation_status($id);
            if ($after > $before) {
                // Book the newly overpaid money against the fund that received it (newest payments first).
                $left = $after - $before;
                foreach (obligation_allocations($id) as $alloc) {
                    $take = min($left, to_cents($alloc['allocated_amount']));
                    $fund = payment_fund_id((int) $alloc['payment_id'], $eventId);
                    $byFund[$fund] = ($byFund[$fund] ?? 0) + $take;
                    $left -= $take;
                    if ($left <= 0) {
                        break;
                    }
                }
                $people++;
                $total += $after - $before;
            }
        }
        db_query('UPDATE collection_rules SET amount_due = ? WHERE id = ?', [cents_to_decimal($new), $ruleId]);
        db_query('INSERT INTO rule_corrections (institution_id, collection_rule_id, event_id, old_amount, new_amount, charges_updated, overpaid_total, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$institutionId, $ruleId, $eventId, cents_to_decimal($old), cents_to_decimal($new), count($obligations), cents_to_decimal($total), $reason, (int) auth_user()['id']]);
        $correctionId = (int) db()->lastInsertId();
        if ($byFund) {
            $lines = [];
            foreach ($byFund as $fundId => $cents) {
                $lines[] = ['fund_id' => $fundId, 'account' => 'collections', 'debit' => $cents, 'description' => $rule['rule_name'] . ' corrected'];
                $lines[] = ['fund_id' => $fundId, 'account' => 'overpayments', 'credit' => $cents, 'description' => 'Paid above the corrected amount'];
            }
            post_journal(['institution_id' => $institutionId, 'date' => date('Y-m-d'), 'source_type' => 'rule_correction', 'source_id' => $correctionId, 'cash_flow_type' => 'adjustment',
                'description' => $rule['rule_name'] . ' corrected from ' . money($old, current_currency()) . ' to ' . money($new, current_currency()) . ': overpayments owed back to payers', 'lines' => $lines]);
        }
        audit_log('collection_rule.amount_corrected', 'collection_rule', $ruleId, $institutionId, (int) auth_user()['id'],
            ['amount_due' => cents_to_decimal($new), 'charges_updated' => count($obligations), 'overpaid_people' => $people, 'overpaid_total' => cents_to_decimal($total)],
            ['amount_due' => cents_to_decimal($old)], $reason);
        return ['charges' => count($obligations), 'overpaid_people' => $people, 'overpaid_total' => $total];
    });
}

/** Allocations of posted payments to a charge, newest payment first (where settlements take money from). */
function obligation_allocations(int $obligationId): array
{
    return db_all(
        "SELECT pa.id, pa.payment_id, pa.allocated_amount FROM payment_allocations pa
         JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
         WHERE pa.institution_id = ? AND pa.obligation_id = ? AND pa.allocated_amount > 0 ORDER BY p.payment_date DESC, p.id DESC FOR UPDATE",
        [current_institution_id(), $obligationId]
    );
}

/** Overpaid charges of an event, with the payer's other open charges (for "apply"). */
function event_overpayments(int $eventId): array
{
    if (!corrections_ready()) {
        return [];
    }
    $institutionId = (int) current_institution_id();
    $rows = db_all(
        "SELECT o.id, o.participant_id, o.amount_due, o.waived_amount, r.rule_name, p.participant_number, p.first_name, p.last_name,
                (SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa JOIN payments pp ON pp.id = pa.payment_id AND pp.institution_id = pa.institution_id AND pp.status = 'posted'
                  WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id) AS paid
         FROM event_obligations o
         JOIN participants p ON p.id = o.participant_id AND p.institution_id = o.institution_id
         JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
         WHERE o.institution_id = ? AND o.event_id = ? AND o.status <> 'cancelled'
         HAVING paid > amount_due - waived_amount ORDER BY p.last_name, p.first_name, o.id",
        [$institutionId, $eventId]
    );
    foreach ($rows as &$row) {
        $row['overpaid'] = to_cents($row['paid']) - (to_cents($row['amount_due']) - to_cents($row['waived_amount']));
        $row['open_charges'] = array_values(array_filter(open_obligations((int) $row['participant_id'], $eventId), static fn($o) => (int) $o['id'] !== (int) $row['id']));
    }
    unset($row);
    return $rows;
}

/** Takes $cents of a charge's allocations (newest payments first). @return list<array{payment_id: int, amount: int, fund_id: int, allocation_id: int}> */
function take_from_allocations(int $obligationId, int $cents): array
{
    $eventId = (int) db_value('SELECT event_id FROM event_obligations WHERE institution_id = ? AND id = ?', [current_institution_id(), $obligationId]);
    $parts = [];
    foreach (obligation_allocations($obligationId) as $alloc) {
        $take = min($cents, to_cents($alloc['allocated_amount']));
        $parts[] = ['payment_id' => (int) $alloc['payment_id'], 'amount' => $take, 'fund_id' => payment_fund_id((int) $alloc['payment_id'], $eventId), 'allocation_id' => (int) $alloc['id']];
        db_query('UPDATE payment_allocations SET allocated_amount = allocated_amount - ? WHERE id = ?', [cents_to_decimal($take), $alloc['id']]);
        $cents -= $take;
        if ($cents <= 0) {
            break;
        }
    }
    if ($cents > 0) {
        fail_user('Not enough was paid on this charge to settle that amount.');
    }
    return $parts;
}

/** Shared checks for settling an overpayment: returns [obligation, amount]. */
function lock_overpayment(int $obligationId, array $input): array
{
    require_corrections_ready();
    $institutionId = (int) current_institution_id();
    $obligation = db_one('SELECT * FROM event_obligations WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $obligationId]) ?? fail_user('Charge not found.');
    $overpaid = obligation_overpaid_cents($obligationId);
    if ($overpaid <= 0) {
        fail_user('This charge is not overpaid.');
    }
    $amount = parse_money(input_str($input, 'amount', 20)) ?? fail_user('Enter the amount.');
    if ($amount > $overpaid) {
        fail_user('Only ' . money($overpaid, current_currency()) . ' was overpaid on this charge.');
    }
    return [$obligation, $amount];
}

function insert_adjustment(string $type, array $obligation, ?int $toObligationId, ?int $accountId, int $amount, string $date, string $reason, array $parts): array
{
    $institutionId = (int) current_institution_id();
    $number = next_document_number($institutionId, $type === 'refund' ? 'refund' : 'adjustment', $type === 'refund' ? 'RF' : 'ADJ');
    db_query(
        'INSERT INTO payment_adjustments (institution_id, adjustment_number, adjustment_type, participant_id, event_id, fund_id, from_obligation_id, to_obligation_id, financial_account_id, amount, adjustment_date, reason, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$institutionId, $number, $type, $obligation['participant_id'], $obligation['event_id'], $parts[0]['fund_id'], $obligation['id'], $toObligationId, $accountId,
         cents_to_decimal($amount), $date, $reason, (int) auth_user()['id']]
    );
    $id = (int) db()->lastInsertId();
    foreach ($parts as $part) {
        db_query('INSERT INTO payment_adjustment_parts (institution_id, adjustment_id, payment_id, amount) VALUES (?, ?, ?, ?)', [$institutionId, $id, $part['payment_id'], cents_to_decimal($part['amount'])]);
    }
    return ['id' => $id, 'number' => $number];
}

/** Gives overpaid money back in cash (or GCash/bank). Needs payments.reverse; the account's custodian pays it out. */
function refund_overpayment(int $obligationId, array $input): int
{
    if (!can('payments.reverse')) {
        fail_user('Only people who may reverse payments (e.g. the treasurer) can record refunds.');
    }
    $reason = input_str($input, 'reason', 1000);
    if (mb_strlen($reason) < 5) {
        fail_user('Give the reason for the refund.');
    }
    $date = input_date($input, 'refund_date') ?? date('Y-m-d');
    if ($date > date('Y-m-d')) {
        fail_user('The refund date cannot be in the future.');
    }
    return db_transaction(function () use ($obligationId, $input, $reason, $date) {
        $institutionId = (int) current_institution_id();
        [$obligation, $amount] = lock_overpayment($obligationId, $input);
        $account = find_financial_account(input_int($input, 'financial_account_id') ?? 0);
        require_usable_account($account);
        $parts = take_from_allocations($obligationId, $amount);
        $lines = [];
        foreach ($parts as $part) {
            require_fund_account_mapping($part['fund_id'], (int) $account['id'], $date);
            require_available_cash($institutionId, $part['fund_id'], (int) $account['id'], $part['amount']);
            $lines[] = ['fund_id' => $part['fund_id'], 'account' => 'overpayments', 'debit' => $part['amount'], 'description' => 'Overpayment refunded'];
            $lines[] = ['fund_id' => $part['fund_id'], 'account' => cash_ledger_key($account['account_type']), 'financial_account_id' => (int) $account['id'], 'credit' => $part['amount']];
        }
        $adjustment = insert_adjustment('refund', $obligation, null, (int) $account['id'], $amount, $date, $reason, $parts);
        $payer = db_one('SELECT first_name, last_name FROM participants WHERE institution_id = ? AND id = ?', [$institutionId, $obligation['participant_id']]);
        post_journal(['institution_id' => $institutionId, 'date' => $date, 'source_type' => 'payment_adjustment', 'source_id' => $adjustment['id'], 'cash_flow_type' => 'refund',
            'description' => 'Refund ' . $adjustment['number'] . ' to ' . trim($payer['first_name'] . ' ' . $payer['last_name']) . ' (overpayment)', 'lines' => $lines]);
        refresh_obligation_status($obligationId);
        audit_log('payment.overpayment_refunded', 'payment_adjustment', $adjustment['id'], $institutionId, (int) auth_user()['id'],
            ['number' => $adjustment['number'], 'amount' => cents_to_decimal($amount), 'obligation_id' => $obligationId, 'financial_account_id' => (int) $account['id'],
             'payments' => array_map(static fn($p) => ['payment_id' => $p['payment_id'], 'amount' => cents_to_decimal($p['amount'])], $parts)], null, $reason);
        return $adjustment['id'];
    });
}

/** Uses overpaid money to pay another charge of the same person in the same event (no cash moves). */
function apply_overpayment(int $obligationId, array $input): int
{
    if (!can('collections.record')) {
        fail_user('You do not have permission to apply payments.');
    }
    $reason = input_str($input, 'reason', 1000) ?: 'Overpayment applied to another charge';
    return db_transaction(function () use ($obligationId, $input, $reason) {
        $institutionId = (int) current_institution_id();
        [$obligation, $amount] = lock_overpayment($obligationId, $input);
        $targetId = input_int($input, 'to_obligation_id') ?? 0;
        $target = db_one("SELECT o.*, r.allow_partial_payment, r.rule_name FROM event_obligations o JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
            WHERE o.institution_id = ? AND o.id = ? AND o.participant_id = ? AND o.event_id = ? AND o.id <> ? AND o.status IN ('unpaid', 'partially_paid') FOR UPDATE",
            [$institutionId, $targetId, $obligation['participant_id'], $obligation['event_id'], $obligationId]) ?? fail_user('Choose one of this person\'s unpaid charges in the same event.');
        $balance = to_cents($target['amount_due']) - to_cents($target['waived_amount']) - obligation_paid_cents($targetId);
        if ($amount > $balance) {
            fail_user('"' . $target['rule_name'] . '" only has ' . money($balance, current_currency()) . ' left to pay.');
        }
        if ($amount < $balance && !$target['allow_partial_payment']) {
            fail_user('"' . $target['rule_name'] . '" must be paid in full (' . money($balance, current_currency()) . '); apply less only to charges that allow partial payment.');
        }
        $parts = take_from_allocations($obligationId, $amount);
        $lines = [];
        foreach ($parts as $part) {
            db_query('INSERT INTO payment_allocations (institution_id, payment_id, obligation_id, allocated_amount) VALUES (?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE allocated_amount = allocated_amount + VALUES(allocated_amount)', [$institutionId, $part['payment_id'], $targetId, cents_to_decimal($part['amount'])]);
            $lines[] = ['fund_id' => $part['fund_id'], 'account' => 'overpayments', 'debit' => $part['amount'], 'description' => 'Overpayment applied'];
            $lines[] = ['fund_id' => $part['fund_id'], 'account' => 'collections', 'credit' => $part['amount'], 'description' => $target['rule_name']];
        }
        $adjustment = insert_adjustment('apply', $obligation, $targetId, null, $amount, date('Y-m-d'), $reason, $parts);
        post_journal(['institution_id' => $institutionId, 'date' => date('Y-m-d'), 'source_type' => 'payment_adjustment', 'source_id' => $adjustment['id'], 'cash_flow_type' => 'adjustment',
            'description' => 'Overpayment ' . $adjustment['number'] . ' applied to ' . $target['rule_name'], 'lines' => $lines]);
        refresh_obligation_status($obligationId);
        refresh_obligation_status($targetId);
        audit_log('payment.overpayment_applied', 'payment_adjustment', $adjustment['id'], $institutionId, (int) auth_user()['id'],
            ['number' => $adjustment['number'], 'amount' => cents_to_decimal($amount), 'from_obligation_id' => $obligationId, 'to_obligation_id' => $targetId,
             'payments' => array_map(static fn($p) => ['payment_id' => $p['payment_id'], 'amount' => cents_to_decimal($p['amount'])], $parts)], null, $reason);
        return $adjustment['id'];
    });
}

/** Settlements that drew on a payment (shown on its receipt and page). */
function payment_adjustment_parts(int $paymentId): array
{
    if (!corrections_ready()) {
        return [];
    }
    return db_all(
        "SELECT a.id, a.adjustment_number, a.adjustment_type, a.adjustment_date, ap.amount, r.rule_name AS to_rule, fr.rule_name AS from_rule
         FROM payment_adjustment_parts ap JOIN payment_adjustments a ON a.id = ap.adjustment_id AND a.institution_id = ap.institution_id
         JOIN event_obligations fo ON fo.id = a.from_obligation_id AND fo.institution_id = a.institution_id
         JOIN collection_rules fr ON fr.id = fo.collection_rule_id AND fr.institution_id = fo.institution_id
         LEFT JOIN event_obligations o ON o.id = a.to_obligation_id AND o.institution_id = a.institution_id
         LEFT JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
         WHERE ap.institution_id = ? AND ap.payment_id = ? ORDER BY a.id",
        [current_institution_id(), $paymentId]
    );
}

function find_adjustment(int $adjustmentId): ?array
{
    if (!corrections_ready()) {
        return null;
    }
    return db_one(
        "SELECT a.*, p.participant_number, p.first_name, p.middle_name, p.last_name, e.event_name, f.fund_name, fa.account_name, fa.account_type,
                fr.rule_name AS from_rule, tr.rule_name AS to_rule, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name,
                (SELECT GROUP_CONCAT(DISTINCT rc.receipt_number ORDER BY rc.receipt_number SEPARATOR ', ') FROM payment_adjustment_parts ap
                   JOIN receipts rc ON rc.payment_id = ap.payment_id AND rc.institution_id = ap.institution_id WHERE ap.institution_id = a.institution_id AND ap.adjustment_id = a.id) AS receipts
         FROM payment_adjustments a
         JOIN participants p ON p.id = a.participant_id AND p.institution_id = a.institution_id
         JOIN events e ON e.id = a.event_id AND e.institution_id = a.institution_id
         JOIN fund_accounts f ON f.id = a.fund_id AND f.institution_id = a.institution_id
         LEFT JOIN financial_accounts fa ON fa.id = a.financial_account_id AND fa.institution_id = a.institution_id
         JOIN event_obligations fo ON fo.id = a.from_obligation_id AND fo.institution_id = a.institution_id
         JOIN collection_rules fr ON fr.id = fo.collection_rule_id AND fr.institution_id = fo.institution_id
         LEFT JOIN event_obligations tob ON tob.id = a.to_obligation_id AND tob.institution_id = a.institution_id
         LEFT JOIN collection_rules tr ON tr.id = tob.collection_rule_id AND tr.institution_id = tob.institution_id
         JOIN users u ON u.id = a.created_by
         WHERE a.institution_id = ? AND a.id = ?",
        [current_institution_id(), $adjustmentId]
    );
}
