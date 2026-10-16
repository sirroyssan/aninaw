<?php
/**
 * Cash turnovers: a collector hands collected cash to the assigned cashier.
 *
 * Workflow
 *  1. Collector prepares the turnover: picks their posted, not-yet-turned-over payments
 *     for an event/group and declares the cash being surrendered       -> submitted
 *  2. Cashier counts the cash, records the amount actually received and the custody
 *     account it goes into (receiver acknowledgment)                    -> received_pending_verification
 *  3. Collector acknowledges the count (sender acknowledgment). Custody is posted:
 *     Dr Cash (cashier account) / Cr Cash (collector account) for the received amount.
 *     Equal amounts                                                     -> verified
 *     Shortage or excess is recorded as a discrepancy                   -> discrepancy
 *     (or the collector disputes the count                              -> back to submitted for recount)
 *  4. Discrepancies are resolved by a third person (reconciliation.manage) with a reason.
 *
 * A turnover moves custody of existing money: it is never income, so collected totals
 * do not change. Declared and received amounts are stored separately and never overwritten.
 */
declare(strict_types=1);

require_once __DIR__ . '/payments.php';

/** Event/group assignments where the current member is the collector. */
function my_collection_assignments(): array
{
    return db_all(
        "SELECT a.id, a.event_id, a.group_id, a.cashier_membership_id, e.event_name, e.status AS event_status, g.group_name,
                CONCAT(u.first_name, ' ', u.last_name) AS cashier_name
         FROM event_group_assignments a
         JOIN events e ON e.id = a.event_id AND e.institution_id = a.institution_id
         JOIN `groups` g ON g.id = a.group_id AND g.institution_id = a.institution_id
         JOIN institution_memberships m ON m.id = a.cashier_membership_id JOIN users u ON u.id = m.user_id
         WHERE a.institution_id = ? AND a.collector_membership_id = ? AND a.status = 'active'
           AND e.status IN ('collecting', 'completed')
         ORDER BY e.event_name, g.group_name",
        [current_institution_id(), current_membership_id()]
    );
}

/**
 * Posted payments received by the current member for an event that are not part of any
 * active or completed turnover, grouped by the custody account they sit in.
 */
function turnover_eligible_payments(int $eventId, int $groupId): array
{
    return db_all(
        "SELECT p.id, p.payment_number, p.payment_date, p.amount, p.financial_account_id, r.receipt_number,
                pt.participant_number, pt.first_name, pt.last_name, fa.account_name,
                (SELECT l.fund_id FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id AND l.institution_id = j.institution_id
                  WHERE j.institution_id = p.institution_id AND j.source_type = 'payment' AND j.source_id = p.id AND j.reversal_of_id IS NULL LIMIT 1) AS fund_id
         FROM payments p
         JOIN participants pt ON pt.id = p.payer_participant_id AND pt.institution_id = p.institution_id
         JOIN financial_accounts fa ON fa.id = p.financial_account_id AND fa.institution_id = p.institution_id
         LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id
         WHERE p.institution_id = ? AND p.status = 'posted' AND p.received_by_membership_id = ?
           AND EXISTS (SELECT 1 FROM payment_allocations pa JOIN event_obligations o ON o.id = pa.obligation_id AND o.institution_id = pa.institution_id
                        WHERE pa.institution_id = p.institution_id AND pa.payment_id = p.id AND o.event_id = ?)
           AND EXISTS (SELECT 1 FROM group_memberships gm WHERE gm.institution_id = p.institution_id AND gm.group_id = ? AND gm.participant_id = p.payer_participant_id AND gm.status = 'active')
           AND NOT EXISTS (SELECT 1 FROM cash_turnover_details d JOIN cash_turnovers t ON t.id = d.turnover_id AND t.institution_id = d.institution_id
                            WHERE d.institution_id = p.institution_id AND d.payment_id = p.id AND t.status <> 'cancelled')
         ORDER BY p.payment_date, p.id",
        [current_institution_id(), current_membership_id(), $eventId, $groupId]
    );
}

/** Creates and submits a turnover. Input: assignment_id, payment_ids[], declared_amount, notes, optional attachment. */
function submit_turnover(array $input): int
{
    if (!can('turnovers.submit')) {
        fail_user('You do not have permission to submit turnovers.');
    }
    $institutionId = (int) current_institution_id();
    $assignmentId = input_int($input, 'assignment_id') ?? 0;
    $paymentIds = input_ids($input, 'payment_ids');
    $declared = parse_money(input_str($input, 'declared_amount', 20)) ?? fail_user('Enter the total cash you are handing over.');
    $notes = input_str($input, 'notes', 1000);
    if (!$paymentIds) {
        fail_user('Select the payments included in this turnover.');
    }

    $assignment = null;
    foreach (my_collection_assignments() as $row) {
        if ((int) $row['id'] === $assignmentId) {
            $assignment = $row;
        }
    }
    if (!$assignment) {
        fail_user('Select one of your collection assignments.');
    }

    return db_transaction(function () use ($institutionId, $assignment, $paymentIds, $declared, $notes) {
        // Lock the payments so two turnovers cannot claim the same payment.
        $placeholders = implode(',', array_fill(0, count($paymentIds), '?'));
        db_query("SELECT id FROM payments WHERE institution_id = ? AND id IN ({$placeholders}) FOR UPDATE", array_merge([$institutionId], $paymentIds));
        $eligible = [];
        foreach (turnover_eligible_payments((int) $assignment['event_id'], (int) $assignment['group_id']) as $row) {
            $eligible[(int) $row['id']] = $row;
        }
        $total = 0;
        $accountId = null;
        $fundId = null;
        foreach ($paymentIds as $paymentId) {
            $payment = $eligible[$paymentId] ?? fail_user('A selected payment is not yours, is not posted, or is already in another turnover.');
            if ($accountId !== null && (int) $payment['financial_account_id'] !== $accountId) {
                fail_user('All payments in one turnover must come from the same custody account.');
            }
            if ($fundId !== null && (int) $payment['fund_id'] !== $fundId) {
                fail_user('All payments in one turnover must belong to the same fund.');
            }
            $accountId = (int) $payment['financial_account_id'];
            $fundId = (int) $payment['fund_id'];
            $total += to_cents($payment['amount']);
        }

        $number = next_document_number($institutionId, 'turnover', 'TO');
        db_query(
            "INSERT INTO cash_turnovers (institution_id, turnover_number, event_id, fund_id, group_id, submitted_by_membership_id, received_by_membership_id,
                                         declared_amount, submitted_at, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'submitted', ?)",
            [$institutionId, $number, $assignment['event_id'], $fundId, $assignment['group_id'], current_membership_id(), $assignment['cashier_membership_id'],
             cents_to_decimal($declared), $notes ?: null]
        );
        $turnoverId = (int) db()->lastInsertId();
        foreach ($paymentIds as $paymentId) {
            db_query('INSERT INTO cash_turnover_details (institution_id, turnover_id, payment_id, amount_included) VALUES (?, ?, ?, ?)',
                [$institutionId, $turnoverId, $paymentId, $eligible[$paymentId]['amount']]);
        }
        if (has_upload('attachment')) {
            store_attachment('attachment', 'turnover', $turnoverId);
        }
        audit_log('turnover.submitted', 'turnover', $turnoverId, $institutionId, (int) auth_user()['id'], [
            'turnover_number' => $number, 'payments' => $paymentIds, 'payments_total' => cents_to_decimal($total),
            'declared_amount' => cents_to_decimal($declared), 'source_account_id' => $accountId,
        ]);
        notify_membership($institutionId, (int) $assignment['cashier_membership_id'], 'turnover', 'Turnover awaiting receipt',
            $number . ': ' . auth_user()['full_name'] . ' is handing over ' . money($declared, current_currency()) . ' for ' . $assignment['group_name'] . ' (' . $assignment['event_name'] . ').');
        return $turnoverId;
    });
}

function find_turnover(int $turnoverId): ?array
{
    $turnover = db_one(
        "SELECT t.*, e.event_name, g.group_name, f.fund_name,
                CONCAT(su.first_name, ' ', su.last_name) AS submitted_by_name, su.id AS submitted_by_user_id,
                CONCAT(ru.first_name, ' ', ru.last_name) AS received_by_name, ru.id AS received_by_user_id
         FROM cash_turnovers t
         JOIN events e ON e.id = t.event_id AND e.institution_id = t.institution_id
         JOIN `groups` g ON g.id = t.group_id AND g.institution_id = t.institution_id
         JOIN fund_accounts f ON f.id = t.fund_id AND f.institution_id = t.institution_id
         JOIN institution_memberships sm ON sm.id = t.submitted_by_membership_id JOIN users su ON su.id = sm.user_id
         JOIN institution_memberships rm ON rm.id = t.received_by_membership_id JOIN users ru ON ru.id = rm.user_id
         WHERE t.institution_id = ? AND t.id = ?",
        [current_institution_id(), $turnoverId]
    );
    if (!$turnover) {
        return null;
    }
    $isParty = in_array(current_membership_id(), [(int) $turnover['submitted_by_membership_id'], (int) $turnover['received_by_membership_id']], true);
    if (!$isParty && !can_any(['turnovers.view', 'reconciliation.manage'])) {
        return null;
    }
    // Collectors and cashiers only see their own turnovers unless they have institution-wide finance access.
    if (!$isParty && !can_any(['funds.view_all', 'reconciliation.manage', 'audit.view']) && !in_array((int) $turnover['group_id'], my_group_ids(), true)) {
        return null;
    }
    $turnover['payments_total'] = to_cents(db_value('SELECT COALESCE(SUM(amount_included), 0) FROM cash_turnover_details WHERE institution_id = ? AND turnover_id = ?', [current_institution_id(), $turnoverId]));
    $turnover['source_account_id'] = (int) db_value(
        'SELECT p.financial_account_id FROM cash_turnover_details d JOIN payments p ON p.id = d.payment_id AND p.institution_id = d.institution_id
         WHERE d.institution_id = ? AND d.turnover_id = ? LIMIT 1',
        [current_institution_id(), $turnoverId]
    );
    $turnover['is_sender'] = (int) $turnover['submitted_by_membership_id'] === current_membership_id();
    $turnover['is_receiver'] = (int) $turnover['received_by_membership_id'] === current_membership_id();
    return $turnover;
}

function lock_turnover(int $turnoverId): array
{
    $row = db_one('SELECT * FROM cash_turnovers WHERE institution_id = ? AND id = ? FOR UPDATE', [current_institution_id(), $turnoverId]);
    if (!$row) {
        fail_user('Turnover not found.');
    }
    return find_turnover($turnoverId) ?? fail_user('Turnover not found.');
}

/**
 * Receiver acknowledgment: the assigned cashier records the counted cash and the
 * custody account it is placed in. Pending register rows record the intended move.
 */
function record_turnover_count(int $turnoverId, array $input): void
{
    $institutionId = (int) current_institution_id();
    $received = parse_money(input_str($input, 'received_amount', 20), true);
    if ($received === null) {
        fail_user('Enter the amount you counted (0 if nothing was received).');
    }
    $notes = input_str($input, 'count_notes', 1000);

    db_transaction(function () use ($institutionId, $turnoverId, $received, $notes, $input) {
        $turnover = lock_turnover($turnoverId);
        if (!$turnover['is_receiver'] || !can('turnovers.receive')) {
            fail_user('Only the assigned receiving cashier can count this turnover.');
        }
        if ($turnover['status'] !== 'submitted') {
            fail_user('This turnover is ' . humanize($turnover['status']) . ' and cannot be counted now.');
        }
        $destination = find_financial_account(input_int($input, 'destination_account_id') ?? 0);
        if ((int) $destination['custodian_membership_id'] !== current_membership_id()) {
            fail_user('Place the cash in a custody account you hold.');
        }
        if ((int) $destination['id'] === $turnover['source_account_id']) {
            fail_user('The destination must differ from the collector\'s account.');
        }
        require_fund_account_mapping((int) $turnover['fund_id'], (int) $destination['id'], date('Y-m-d'));
        if ($received !== to_cents($turnover['declared_amount']) && mb_strlen($notes) < 5) {
            fail_user('The counted amount differs from the declared amount. Describe the difference in the count notes.');
        }

        db_query("UPDATE cash_turnovers SET received_amount = ?, received_at = NOW(), status = 'received_pending_verification' WHERE id = ?",
            [cents_to_decimal($received), $turnoverId]);
        db_query("INSERT INTO turnover_acknowledgments (institution_id, turnover_id, acknowledged_by_membership_id, acknowledged_amount, acknowledgment_type, notes, acknowledged_at)
                  VALUES (?, ?, ?, ?, 'received', ?, NOW())", [$institutionId, $turnoverId, current_membership_id(), cents_to_decimal($received), $notes ?: null]);
        // Intended custody move, pending until the sender acknowledges.
        cancel_pending_cash_flows($institutionId, 'turnover', $turnoverId);
        if ($received > 0) {
            record_cash_flows($institutionId, 'turnover', $turnoverId, 'transfer', date('Y-m-d'), [
                ['fund_id' => (int) $turnover['fund_id'], 'financial_account_id' => $turnover['source_account_id'], 'direction' => 'outflow', 'amount' => $received],
                ['fund_id' => (int) $turnover['fund_id'], 'financial_account_id' => (int) $destination['id'], 'direction' => 'inflow', 'amount' => $received],
            ], 'pending');
        }
        audit_log('turnover.counted', 'turnover', $turnoverId, $institutionId, (int) auth_user()['id'],
            ['received_amount' => cents_to_decimal($received), 'destination_account_id' => (int) $destination['id']], null, $notes ?: null);
        notify_membership($institutionId, (int) $turnover['submitted_by_membership_id'], 'turnover', 'Turnover counted',
            $turnover['turnover_number'] . ': the cashier counted ' . money($received, current_currency()) . '. Please acknowledge or dispute the count.');
    });
}

/**
 * Sender acknowledgment. Accepting posts the custody move for the received amount and,
 * when amounts differ, records the discrepancy. Disputing sends it back for recount.
 */
function acknowledge_turnover(int $turnoverId, bool $accept, string $notes): void
{
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $turnoverId, $accept, $notes) {
        $turnover = lock_turnover($turnoverId);
        if (!$turnover['is_sender']) {
            fail_user('Only the collector who submitted this turnover can acknowledge the count.');
        }
        if ($turnover['status'] !== 'received_pending_verification') {
            fail_user('This turnover is not waiting for your acknowledgment.');
        }
        $received = to_cents($turnover['received_amount']);

        if (!$accept) {
            if (mb_strlen($notes) < 5) {
                fail_user('Explain why you dispute the count.');
            }
            cancel_pending_cash_flows($institutionId, 'turnover', $turnoverId);
            db_query("UPDATE cash_turnovers SET status = 'submitted', received_amount = NULL, received_at = NULL WHERE id = ?", [$turnoverId]);
            db_query("INSERT INTO turnover_acknowledgments (institution_id, turnover_id, acknowledged_by_membership_id, acknowledged_amount, acknowledgment_type, notes, acknowledged_at)
                      VALUES (?, ?, ?, ?, 'adjustment', ?, NOW())", [$institutionId, $turnoverId, current_membership_id(), cents_to_decimal($received), 'Count disputed: ' . $notes]);
            audit_log('turnover.count_disputed', 'turnover', $turnoverId, $institutionId, (int) auth_user()['id'], ['disputed_amount' => cents_to_decimal($received)], null, $notes);
            notify_membership($institutionId, (int) $turnover['received_by_membership_id'], 'turnover', 'Count disputed',
                $turnover['turnover_number'] . ': the collector disputes the count. Please recount together.');
            return;
        }

        $destinationId = (int) db_value(
            "SELECT financial_account_id FROM cash_flow_transactions WHERE institution_id = ? AND source_type = 'turnover' AND source_id = ? AND status = 'pending' AND direction = 'inflow'",
            [$institutionId, $turnoverId]
        );
        if ($received > 0) {
            if (!$destinationId) {
                fail_user('The receiving account is missing. Ask the cashier to recount.');
            }
            $source = find_financial_account($turnover['source_account_id'], false);
            $destination = find_financial_account($destinationId);
            post_journal([
                'institution_id' => $institutionId,
                'date' => date('Y-m-d'),
                'description' => 'Turnover ' . $turnover['turnover_number'] . ': custody of ' . $turnover['event_name'] . ' collections from ' . $turnover['submitted_by_name'] . ' to ' . $turnover['received_by_name'],
                'source_type' => 'turnover',
                'source_id' => $turnoverId,
                'cash_flow_type' => 'transfer',
                'lines' => [
                    ['fund_id' => (int) $turnover['fund_id'], 'account' => cash_ledger_key($destination['account_type']), 'financial_account_id' => $destinationId, 'debit' => $received],
                    ['fund_id' => (int) $turnover['fund_id'], 'account' => cash_ledger_key($source['account_type']), 'financial_account_id' => (int) $source['id'], 'credit' => $received],
                ],
            ]);
        }

        $expected = $turnover['payments_total'];
        $declared = to_cents($turnover['declared_amount']);
        $balanced = $received === $expected && $received === $declared;
        db_query('UPDATE cash_turnovers SET status = ?, verified_at = NOW(), discrepancy_reason = ? WHERE id = ?',
            [$balanced ? 'verified' : 'discrepancy', $balanced ? null : turnover_difference_text($expected, $declared, $received), $turnoverId]);
        db_query("INSERT INTO turnover_acknowledgments (institution_id, turnover_id, acknowledged_by_membership_id, acknowledged_amount, acknowledgment_type, notes, acknowledged_at)
                  VALUES (?, ?, ?, ?, 'verified', ?, NOW())", [$institutionId, $turnoverId, current_membership_id(), cents_to_decimal($received), $notes ?: 'Count acknowledged by sender']);

        if (!$balanced) {
            $countNote = (string) db_value("SELECT notes FROM turnover_acknowledgments WHERE institution_id = ? AND turnover_id = ? AND acknowledgment_type = 'received' ORDER BY id DESC LIMIT 1", [$institutionId, $turnoverId]);
            db_query(
                "INSERT INTO turnover_discrepancies (institution_id, turnover_id, expected_amount, actual_amount, difference_amount, reason, reported_by_membership_id, reported_at, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'open')",
                [$institutionId, $turnoverId, cents_to_decimal($expected), cents_to_decimal($received), cents_to_decimal($received - $expected),
                 trim(turnover_difference_text($expected, $declared, $received) . ' ' . $countNote . ' ' . $notes), $turnover['received_by_membership_id']]
            );
            notify_permission($institutionId, 'reconciliation.manage', 'discrepancy', 'Turnover discrepancy',
                $turnover['turnover_number'] . ': ' . turnover_difference_text($expected, $declared, $received));
        }
        audit_log('turnover.acknowledged', 'turnover', $turnoverId, $institutionId, (int) auth_user()['id'],
            ['status' => $balanced ? 'verified' : 'discrepancy', 'expected' => cents_to_decimal($expected), 'declared' => cents_to_decimal($declared), 'received' => cents_to_decimal($received)], null, $notes ?: null);
        notify_membership($institutionId, (int) $turnover['received_by_membership_id'], 'turnover', 'Turnover acknowledged',
            $turnover['turnover_number'] . ' was acknowledged by the collector' . ($balanced ? '.' : ' with a discrepancy.'));
    });
}

function turnover_difference_text(int $expected, int $declared, int $received): string
{
    $currency = current_currency();
    $parts = ['Payments ' . money($expected, $currency), 'declared ' . money($declared, $currency), 'received ' . money($received, $currency)];
    $difference = $received - $expected;
    return implode(', ', $parts) . ' (' . ($difference < 0 ? 'shortage ' : ($difference > 0 ? 'excess ' : 'no difference ')) . money(abs($difference), $currency) . ').';
}

/** Cancels a submitted turnover (before it is counted). The payments become available again. */
function cancel_turnover(int $turnoverId, string $reason): void
{
    $institutionId = (int) current_institution_id();
    if (mb_strlen($reason) < 5) {
        fail_user('A reason is required.');
    }
    db_transaction(function () use ($institutionId, $turnoverId, $reason) {
        $turnover = lock_turnover($turnoverId);
        if (!$turnover['is_sender'] && !$turnover['is_receiver']) {
            fail_user('Only the collector or the receiving cashier can cancel this turnover.');
        }
        if (!in_array($turnover['status'], ['draft', 'submitted'], true)) {
            fail_user('Only turnovers that have not been counted can be cancelled.');
        }
        cancel_pending_cash_flows($institutionId, 'turnover', $turnoverId);
        db_query("UPDATE cash_turnovers SET status = 'cancelled' WHERE id = ?", [$turnoverId]);
        audit_log('turnover.cancelled', 'turnover', $turnoverId, $institutionId, (int) auth_user()['id'], ['status' => 'cancelled'], ['status' => $turnover['status']], $reason);
    });
}

/**
 * Resolves a discrepancy (reconciliation.manage, by someone other than the two parties):
 *  - settled:  the missing cash was handed over later (shortage) or the excess returned to the
 *              collector (excess). Posts a further custody move for the difference.
 *  - write_off: accepted exception. A shortage is expensed (Cash Shortages); an excess is
 *              recognised as Other Income. Posts against the collector's account.
 */
function resolve_discrepancy(int $discrepancyId, string $method, string $notes): void
{
    $institutionId = (int) current_institution_id();
    if (!can('reconciliation.manage')) {
        fail_user('You do not have permission to resolve discrepancies.');
    }
    if (!in_array($method, ['settled', 'write_off'], true)) {
        fail_user('Choose how the discrepancy was resolved.');
    }
    if (mb_strlen($notes) < 10) {
        fail_user('Explain the resolution (at least 10 characters).');
    }
    db_transaction(function () use ($institutionId, $discrepancyId, $method, $notes) {
        $discrepancy = db_one('SELECT * FROM turnover_discrepancies WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $discrepancyId]) ?? fail_user('Discrepancy not found.');
        if (!in_array($discrepancy['status'], ['open', 'under_review'], true)) {
            fail_user('This discrepancy is already ' . humanize($discrepancy['status']) . '.');
        }
        $turnover = find_turnover((int) $discrepancy['turnover_id']) ?? fail_user('Turnover not found.');
        if ($turnover['is_sender'] || $turnover['is_receiver']) {
            fail_user('A discrepancy must be resolved by someone other than the collector and the cashier involved.');
        }
        $difference = to_cents($discrepancy['difference_amount']); // received − expected
        $amount = abs($difference);
        $fundId = (int) $turnover['fund_id'];
        $source = find_financial_account($turnover['source_account_id'], false);
        $destinationId = (int) db_value("SELECT financial_account_id FROM cash_flow_transactions WHERE institution_id = ? AND source_type = 'turnover' AND source_id = ? AND direction = 'inflow' AND status = 'posted' LIMIT 1", [$institutionId, $turnover['id']]);
        $sourceKey = cash_ledger_key($source['account_type']);

        if ($amount > 0) {
            if ($method === 'settled') {
                $destination = $destinationId ? find_financial_account($destinationId, false) : fail_user('The original receiving account was not found.');
                $destKey = cash_ledger_key($destination['account_type']);
                $lines = $difference < 0
                    ? [['fund_id' => $fundId, 'account' => $destKey, 'financial_account_id' => $destinationId, 'debit' => $amount],
                       ['fund_id' => $fundId, 'account' => $sourceKey, 'financial_account_id' => (int) $source['id'], 'credit' => $amount]]
                    : [['fund_id' => $fundId, 'account' => $sourceKey, 'financial_account_id' => (int) $source['id'], 'debit' => $amount],
                       ['fund_id' => $fundId, 'account' => $destKey, 'financial_account_id' => $destinationId, 'credit' => $amount]];
                $type = 'transfer';
            } else {
                $lines = $difference < 0
                    ? [['fund_id' => $fundId, 'account' => 'cash_shortage', 'debit' => $amount],
                       ['fund_id' => $fundId, 'account' => $sourceKey, 'financial_account_id' => (int) $source['id'], 'credit' => $amount]]
                    : [['fund_id' => $fundId, 'account' => $sourceKey, 'financial_account_id' => (int) $source['id'], 'debit' => $amount],
                       ['fund_id' => $fundId, 'account' => 'other_income', 'credit' => $amount]];
                $type = 'adjustment';
            }
            post_journal([
                'institution_id' => $institutionId,
                'date' => date('Y-m-d'),
                'description' => 'Discrepancy on ' . $turnover['turnover_number'] . ' ' . ($method === 'settled' ? 'settled' : 'written off') . ': ' . mb_substr($notes, 0, 300),
                'source_type' => 'turnover_discrepancy',
                'source_id' => $discrepancyId,
                'cash_flow_type' => $type,
                'lines' => $lines,
            ]);
        }

        $status = $method === 'settled' ? 'resolved' : 'accepted_exception';
        db_query('UPDATE turnover_discrepancies SET status = ?, resolution_notes = ?, resolved_by_membership_id = ?, resolved_at = NOW() WHERE id = ?',
            [$status, $notes, current_membership_id(), $discrepancyId]);
        db_query("INSERT INTO turnover_acknowledgments (institution_id, turnover_id, acknowledged_by_membership_id, acknowledged_amount, acknowledgment_type, notes, acknowledged_at)
                  VALUES (?, ?, ?, ?, 'adjustment', ?, NOW())", [$institutionId, $turnover['id'], current_membership_id(), cents_to_decimal($amount), humanize($status) . ': ' . $notes]);
        audit_log('turnover.discrepancy_resolved', 'turnover_discrepancy', $discrepancyId, $institutionId, (int) auth_user()['id'],
            ['status' => $status, 'method' => $method, 'amount' => cents_to_decimal($amount)], ['status' => $discrepancy['status']], $notes);
        foreach ([(int) $turnover['submitted_by_membership_id'], (int) $turnover['received_by_membership_id']] as $membershipId) {
            notify_membership($institutionId, $membershipId, 'discrepancy', 'Discrepancy resolved', $turnover['turnover_number'] . ': ' . humanize($status) . '.');
        }
    });
}
