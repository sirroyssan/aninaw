<?php
/**
 * Payment processing: recording full or partial payments against obligations,
 * official/acknowledgment receipts, reprint history, receipt verification and
 * authorised reversal (void) of incorrect payments.
 *
 * Posting: Dr Cash (receiving financial account) / Cr Collections, per fund.
 * A posted payment is never deleted: it is reversed with a reason, its receipt is
 * voided, and a mirrored journal entry restores the balances.
 */
declare(strict_types=1);

require_once __DIR__ . '/events.php';

const PAYMENT_METHODS = ['cash' => 'Cash', 'gcash' => 'GCash / e-wallet', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'];

/** Which financial account types may receive each payment method. */
const PAYMENT_METHOD_ACCOUNT_TYPES = [
    'cash'          => ['cash_custody'],
    'gcash'         => ['e_wallet'],
    'bank_transfer' => ['bank'],
    'other'         => ['cash_custody', 'bank', 'e_wallet', 'other'],
];

/** Open obligations (unpaid or partially paid) of a participant, with balances in centavos. */
function open_obligations(int $participantId, ?int $eventId = null): array
{
    $sql = "SELECT o.id, o.event_id, o.amount_due, o.waived_amount, o.due_date, o.status, e.event_name, e.event_code, e.status AS event_status,
                   r.rule_name, " . rule_inclusions_sql('r') . ", r.allow_partial_payment,
                   (SELECT COALESCE(SUM(pa.allocated_amount), 0) FROM payment_allocations pa
                      JOIN payments p ON p.id = pa.payment_id AND p.institution_id = pa.institution_id AND p.status = 'posted'
                     WHERE pa.institution_id = o.institution_id AND pa.obligation_id = o.id) AS paid
            FROM event_obligations o
            JOIN events e ON e.id = o.event_id AND e.institution_id = o.institution_id
            JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
            WHERE o.institution_id = ? AND o.participant_id = ? AND o.status IN ('unpaid', 'partially_paid')";
    $params = [current_institution_id(), $participantId];
    if ($eventId) {
        $sql .= ' AND o.event_id = ?';
        $params[] = $eventId;
    }
    $rows = db_all($sql . ' ORDER BY e.event_name, o.due_date, o.id', $params);
    foreach ($rows as &$row) {
        $row['balance'] = max(0, to_cents($row['amount_due']) - to_cents($row['waived_amount']) - to_cents($row['paid']));
    }
    unset($row);
    return array_values(array_filter($rows, static fn($row) => $row['balance'] > 0));
}

/**
 * Records a payment. Input keys: participant_id, event_id, fund_id, financial_account_id,
 * payment_date (Y-m-d), payment_time (H:i), payment_method, external_reference, notes,
 * alloc[obligation_id] = amount. Optional upload field "attachment".
 *
 * @return int payment ID
 */
function record_payment(array $input): int
{
    if (!can('collections.record')) {
        fail_user('You do not have permission to record payments.');
    }
    $institutionId = (int) current_institution_id();
    $participantId = input_int($input, 'participant_id') ?? 0;
    $eventId = input_int($input, 'event_id') ?? 0;
    $method = input_enum($input, 'payment_method', array_keys(PAYMENT_METHODS)) ?? fail_user('Select the payment method.');
    $reference = input_str($input, 'external_reference', 150);
    $notes = input_str($input, 'notes', 1000);
    $date = input_date($input, 'payment_date') ?? fail_user('Enter the actual payment date.');
    $time = preg_match('/^\d{2}:\d{2}$/', input_str($input, 'payment_time', 5)) ? input_str($input, 'payment_time', 5) : date('H:i');
    $paymentDate = $date . ' ' . $time . ':00';
    if (strtotime($paymentDate) > time() + 300) {
        fail_user('The payment date cannot be in the future.');
    }
    if ($method !== 'cash' && $reference === '') {
        fail_user('Enter the transaction reference number for ' . PAYMENT_METHODS[$method] . ' payments.');
    }

    $participant = find_participant($participantId) ?? fail_user('Participant not found or not in your groups.');
    if ($participant['status'] !== 'active') {
        fail_user('Payments can only be recorded for active participants.');
    }
    $event = find_event($eventId) ?? fail_user('Select the event being paid.');
    if ($event['status'] !== 'collecting') {
        fail_user('Payments are accepted only while the event is open for collection.');
    }

    // Fund: one of the event's receiving funds.
    $eventFundIds = array_map(static fn($row) => (int) $row['fund_id'], event_funds($eventId));
    $fundId = count($eventFundIds) === 1 ? $eventFundIds[0] : (input_int($input, 'fund_id') ?? 0);
    if (!in_array($fundId, $eventFundIds, true)) {
        fail_user('Select which of the event\'s funds receives this payment.');
    }

    $allocationsInput = is_array($input['alloc'] ?? null) ? $input['alloc'] : [];
    $requested = [];
    foreach ($allocationsInput as $obligationId => $amount) {
        $cents = trim((string) $amount) === '' ? 0 : parse_money((string) $amount, true);
        if ($cents === null) {
            fail_user('Enter valid amounts (e.g. 50 or 50.00).');
        }
        if ($cents > 0 && ctype_digit((string) $obligationId)) {
            $requested[(int) $obligationId] = $cents;
        }
    }
    if (!$requested) {
        fail_user('Enter the amount paid for at least one obligation.');
    }

    $paymentId = db_transaction(function () use ($institutionId, $participant, $eventId, $fundId, $input, $method, $reference, $notes, $paymentDate, $date, $requested, $event) {
        $fund = find_fund($fundId);
        $account = find_financial_account(input_int($input, 'financial_account_id') ?? 0);
        require_usable_account($account);
        if (!in_array($account['account_type'], PAYMENT_METHOD_ACCOUNT_TYPES[$method], true)) {
            fail_user(PAYMENT_METHODS[$method] . ' payments must be received into a ' . implode(' or ', array_map('humanize', PAYMENT_METHOD_ACCOUNT_TYPES[$method])) . ' account.');
        }
        require_fund_account_mapping($fundId, (int) $account['id'], $date);

        // Lock the obligations so concurrent payments cannot overpay them.
        $total = 0;
        foreach ($requested as $obligationId => $cents) {
            $obligation = db_one(
                "SELECT o.*, r.allow_partial_payment, r.rule_name FROM event_obligations o
                 JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
                 WHERE o.institution_id = ? AND o.id = ? FOR UPDATE",
                [$institutionId, $obligationId]
            );
            if (!$obligation || (int) $obligation['participant_id'] !== (int) $participant['id'] || (int) $obligation['event_id'] !== $eventId) {
                fail_user('One of the selected obligations does not belong to this participant and event.');
            }
            if (!in_array($obligation['status'], ['unpaid', 'partially_paid'], true)) {
                fail_user('"' . $obligation['rule_name'] . '" is already ' . humanize($obligation['status']) . '.');
            }
            $balance = to_cents($obligation['amount_due']) - to_cents($obligation['waived_amount']) - obligation_paid_cents($obligationId);
            if ($cents > $balance) {
                fail_user('Overpayment is not allowed: the balance of "' . $obligation['rule_name'] . '" is ' . money($balance, current_currency()) . '.');
            }
            if (!$obligation['allow_partial_payment'] && $cents !== $balance) {
                fail_user('"' . $obligation['rule_name'] . '" must be paid in full (' . money($balance, current_currency()) . ').');
            }
            $total += $cents;
        }

        $paymentNumber = next_document_number($institutionId, 'payment', 'PAY');
        db_query(
            "INSERT INTO payments (institution_id, payment_number, payer_participant_id, received_by_membership_id, financial_account_id, payment_date, payment_method, amount, external_reference, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?)",
            [$institutionId, $paymentNumber, $participant['id'], current_membership_id(), $account['id'], $paymentDate, $method, cents_to_decimal($total), $reference ?: null, $notes ?: null]
        );
        $paymentId = (int) db()->lastInsertId();
        foreach ($requested as $obligationId => $cents) {
            db_query('INSERT INTO payment_allocations (institution_id, payment_id, obligation_id, allocated_amount) VALUES (?, ?, ?, ?)',
                [$institutionId, $paymentId, $obligationId, cents_to_decimal($cents)]);
        }

        $receiptNumber = next_document_number($institutionId, 'receipt', 'AR');
        db_query(
            "INSERT INTO receipts (institution_id, receipt_number, payment_id, issued_by_membership_id, issued_at, receipt_status) VALUES (?, ?, ?, ?, NOW(), 'issued')",
            [$institutionId, $receiptNumber, $paymentId, current_membership_id()]
        );

        post_journal([
            'institution_id' => $institutionId,
            'date' => $date,
            'description' => 'Payment ' . $paymentNumber . ' (receipt ' . $receiptNumber . ') from ' . person_name($participant) . ' for ' . $event['event_name'],
            'source_type' => 'payment',
            'source_id' => $paymentId,
            'cash_flow_type' => 'collection',
            'lines' => [
                ['fund_id' => $fundId, 'account' => cash_ledger_key($account['account_type']), 'financial_account_id' => (int) $account['id'], 'debit' => $total],
                ['fund_id' => $fundId, 'account' => 'collections', 'credit' => $total, 'description' => $event['event_name']],
            ],
        ]);

        foreach (array_keys($requested) as $obligationId) {
            refresh_obligation_status($obligationId);
        }
        if (has_upload('attachment')) {
            store_attachment('attachment', 'payment', $paymentId);
        }
        audit_log('payment.recorded', 'payment', $paymentId, $institutionId, (int) auth_user()['id'], [
            'payment_number' => $paymentNumber, 'receipt_number' => $receiptNumber, 'amount' => cents_to_decimal($total),
            'participant_id' => (int) $participant['id'], 'fund_id' => $fundId, 'financial_account_id' => (int) $account['id'], 'method' => $method,
        ]);
        if ($participant['user_id']) {
            notify_user($institutionId, (int) $participant['user_id'], 'payment', 'Payment received',
                'We received ' . money($total, current_currency()) . ' for ' . $event['event_name'] . '. Receipt ' . $receiptNumber . '.');
        }
        return $paymentId;
    });

    return $paymentId;
}

/** Payment with receipt, payer and recorder, scoped to the institution and participant visibility. */
function find_payment(int $paymentId): ?array
{
    [$visibility, $params] = participant_visibility('pt');
    $payment = db_one(
        "SELECT p.*, r.id AS receipt_id, r.receipt_number, r.receipt_status, r.issued_at, r.issued_by_membership_id,
                pt.id AS participant_id, pt.participant_number, pt.first_name, pt.middle_name, pt.last_name, pt.user_id AS participant_user_id,
                CONCAT(u.first_name, ' ', u.last_name) AS received_by_name,
                fa.account_name, fa.account_type
         FROM payments p
         JOIN participants pt ON pt.id = p.payer_participant_id AND pt.institution_id = p.institution_id
         JOIN institution_memberships m ON m.id = p.received_by_membership_id AND m.institution_id = p.institution_id
         JOIN users u ON u.id = m.user_id
         LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id
         LEFT JOIN financial_accounts fa ON fa.id = p.financial_account_id AND fa.institution_id = p.institution_id
         WHERE p.institution_id = ? AND p.id = ? AND ({$visibility} OR p.received_by_membership_id = ?)",
        array_merge([current_institution_id(), $paymentId], $params, [current_membership_id()])
    );
    if ($payment) {
        $payment['allocations'] = db_all(
            "SELECT pa.allocated_amount, o.id AS obligation_id, o.amount_due, o.waived_amount, o.status AS obligation_status, r.rule_name, " . rule_inclusions_sql('r') . ", e.event_name, e.id AS event_id
             FROM payment_allocations pa
             JOIN event_obligations o ON o.id = pa.obligation_id AND o.institution_id = pa.institution_id
             JOIN collection_rules r ON r.id = o.collection_rule_id AND r.institution_id = o.institution_id
             JOIN events e ON e.id = o.event_id AND e.institution_id = o.institution_id
             WHERE pa.institution_id = ? AND pa.payment_id = ? ORDER BY pa.id",
            [current_institution_id(), $paymentId]
        );
        $payment['order'] = db_one(
            'SELECT o.id, o.order_number, o.total_amount FROM sales_order_payments sop JOIN sales_orders o ON o.id = sop.order_id AND o.institution_id = sop.institution_id
             WHERE sop.institution_id = ? AND sop.payment_id = ?',
            [current_institution_id(), $paymentId]
        );
        $payment['order_items'] = $payment['order'] ? db_all(
            'SELECT description, quantity, unit_price, additional_charge, line_total FROM sales_order_items WHERE institution_id = ? AND order_id = ? ORDER BY id',
            [current_institution_id(), $payment['order']['id']]
        ) : [];
        $payment['fund'] = db_one(
            "SELECT f.id, f.fund_name FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id AND l.institution_id = j.institution_id
             JOIN fund_accounts f ON f.id = l.fund_id AND f.institution_id = l.institution_id
             WHERE j.institution_id = ? AND j.source_type = 'payment' AND j.source_id = ? AND j.reversal_of_id IS NULL LIMIT 1",
            [current_institution_id(), $paymentId]
        );
    }
    return $payment;
}

/** Turnover (not cancelled) that already includes a payment, if any. */
function payment_turnover(int $paymentId): ?array
{
    return db_one(
        "SELECT t.id, t.turnover_number, t.status FROM cash_turnover_details d
         JOIN cash_turnovers t ON t.id = d.turnover_id AND t.institution_id = d.institution_id
         WHERE d.institution_id = ? AND d.payment_id = ? AND t.status <> 'cancelled' LIMIT 1",
        [current_institution_id(), $paymentId]
    );
}

/**
 * Reverses a posted payment entered incorrectly: voids the receipt, posts a mirrored
 * journal entry and restores the obligations. Requires payments.reverse and a reason.
 * Payments already handed over in a turnover must be handled through that turnover first.
 */
function reverse_payment(int $paymentId, string $reason): void
{
    if (!can('payments.reverse')) {
        fail_user('You do not have permission to reverse payments.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('A reason is required to reverse a payment.');
    }
    $institutionId = (int) current_institution_id();

    db_transaction(function () use ($institutionId, $paymentId, $reason) {
        $payment = db_one('SELECT * FROM payments WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $paymentId]) ?? fail_user('Payment not found.');
        if ($payment['status'] !== 'posted') {
            fail_user('Only posted payments can be reversed. This payment is ' . $payment['status'] . '.');
        }
        if ($turnover = payment_turnover($paymentId)) {
            fail_user('This payment is part of turnover ' . $turnover['turnover_number'] . ' (' . humanize($turnover['status']) . '). Cancel that turnover first, or record a refund adjustment through the ledger if the cash was already handed over.');
        }
        $fundId = (int) db_value(
            "SELECT l.fund_id FROM journal_entries j JOIN journal_lines l ON l.journal_entry_id = j.id AND l.institution_id = j.institution_id
             WHERE j.institution_id = ? AND j.source_type = 'payment' AND j.source_id = ? AND j.reversal_of_id IS NULL LIMIT 1",
            [$institutionId, $paymentId]
        );
        require_available_cash($institutionId, $fundId, (int) $payment['financial_account_id'], to_cents($payment['amount']));

        reverse_journal($institutionId, 'payment', $paymentId, $reason);
        db_query("UPDATE payments SET status = 'reversed' WHERE id = ?", [$paymentId]);
        db_query("UPDATE receipts SET receipt_status = 'voided' WHERE institution_id = ? AND payment_id = ?", [$institutionId, $paymentId]);
        foreach (db_query('SELECT obligation_id FROM payment_allocations WHERE institution_id = ? AND payment_id = ?', [$institutionId, $paymentId])->fetchAll(PDO::FETCH_COLUMN) as $obligationId) {
            refresh_obligation_status((int) $obligationId);
        }
        refresh_orders_for_payment($paymentId);
        audit_log('payment.reversed', 'payment', $paymentId, $institutionId, (int) auth_user()['id'], ['status' => 'reversed', 'receipt_status' => 'voided'], ['status' => 'posted'], $reason);
        notify_membership($institutionId, (int) $payment['received_by_membership_id'], 'payment', 'Payment reversed',
            'Payment ' . $payment['payment_number'] . ' was reversed: ' . $reason);
    });
}

// ---------------------------------------------------------------------------
// Receipts
// ---------------------------------------------------------------------------

/** Original print or reprint. Reprints need receipts.issue (or being the issuer) and are logged. */
function register_receipt_print(array $payment): bool
{
    $institutionId = (int) current_institution_id();
    $printed = (int) db_value(
        "SELECT COUNT(*) FROM audit_logs WHERE institution_id = ? AND entity_type = 'receipt' AND entity_id = ? AND action IN ('receipt.printed', 'receipt.reprinted')",
        [$institutionId, $payment['receipt_id']]
    );
    $isReprint = $printed > 0;
    if ($isReprint && !can('receipts.issue') && (int) $payment['issued_by_membership_id'] !== current_membership_id()) {
        render_error_page(403, 'Reprint Not Allowed', 'This receipt has already been printed. Reprints are limited to cashiers or the person who issued it.');
    }
    audit_log($isReprint ? 'receipt.reprinted' : 'receipt.printed', 'receipt', (int) $payment['receipt_id'], $institutionId, (int) auth_user()['id'],
        ['receipt_number' => $payment['receipt_number'], 'copy' => $printed + 1]);
    return $isReprint;
}

function receipt_print_history(int $receiptId): array
{
    return db_all(
        "SELECT a.action, a.created_at, CONCAT(u.first_name, ' ', u.last_name) AS actor
         FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
         WHERE a.institution_id = ? AND a.entity_type = 'receipt' AND a.entity_id = ? AND a.action IN ('receipt.printed', 'receipt.reprinted')
         ORDER BY a.id",
        [current_institution_id(), $receiptId]
    );
}

/** Spells an amount for receipts, e.g. "One Hundred Fifty Pesos and 50/100". */
function amount_in_words(int $cents, string $currency = 'PHP'): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $chunk = static function (int $n) use ($ones, $tens): string {
        $words = [];
        if ($n >= 100) {
            $words[] = $ones[intdiv($n, 100)] . ' Hundred';
            $n %= 100;
        }
        if ($n >= 20) {
            $words[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        } elseif ($n > 0) {
            $words[] = $ones[$n];
        }
        return implode(' ', $words);
    };
    $whole = intdiv($cents, 100);
    $parts = [];
    foreach ([1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand', 1 => ''] as $scale => $label) {
        if ($whole >= $scale) {
            $parts[] = trim($chunk(intdiv($whole, $scale)) . ' ' . $label);
            $whole %= $scale;
        }
    }
    $unit = ['PHP' => 'Pesos', 'USD' => 'Dollars', 'EUR' => 'Euros'][$currency] ?? $currency;
    return ($parts ? implode(' ', $parts) : 'Zero') . ' ' . $unit . ' and ' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT) . '/100';
}

// Product/service orders settle through these payments (loaded last: orders.php builds on this file).
require_once __DIR__ . '/orders.php';
