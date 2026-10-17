<?php
/**
 * Solicitations and donations: money (or a promise of money) from a donor to a fund, optionally
 * for an event. Each amount received is an ordinary payment with an official receipt, so cash
 * turnovers, reversals and receipt verification treat it like any collection. It is booked as
 * Donations & Solicitations income (4300) in the receiving fund.
 *
 * A donation either arrives outright ("received now") or starts as a pledge that is received in
 * one or more payments. Donors are kept in People as Customer / Other so receipts name the payer.
 */
declare(strict_types=1);

require_once __DIR__ . '/payments.php';

const DONOR_KINDS = ['individual' => 'Individual', 'company' => 'Company / business', 'alumni' => 'Alumni', 'parent' => 'Parent / guardian', 'organization' => 'Organization', 'other' => 'Other'];
const DONATION_STATUSES = ['pledged' => 'Pledged', 'partially_received' => 'Partly received', 'received' => 'Received', 'cancelled' => 'Cancelled'];

/** Migration 010 adds the donation tables; pages explain what to import until it is run. */
function donations_ready(): bool
{
    static $ready = null;
    return $ready ??= (bool) db_value("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'donations'");
}

function require_donations_ready(): void
{
    if (!donations_ready()) {
        fail_user('Solicitations need database/migration_010_solicitations.sql. Ask the administrator to import it once (phpMyAdmin → Import).');
    }
}

/** Funds a donation may go to: active funds (an event's funds first when one is given). */
function donation_fund_options(?int $eventId = null): array
{
    $eventFunds = $eventId ? array_map(static fn($r) => (int) $r['fund_id'], event_funds($eventId)) : [];
    $rows = db_all("SELECT id, fund_code, fund_name FROM fund_accounts WHERE institution_id = ? AND status = 'active' ORDER BY fund_name", [current_institution_id()]);
    usort($rows, static fn($a, $b) => (int) in_array((int) $b['id'], $eventFunds, true) <=> (int) in_array((int) $a['id'], $eventFunds, true));
    return options_from_rows($rows, 'id', static fn($r) => $r['fund_name'] . ' (' . $r['fund_code'] . ')');
}

/** Received so far for a donation (posted payments only), in cents. */
function donation_received_cents(int $donationId): int
{
    return to_cents(db_value(
        "SELECT COALESCE(SUM(dp.amount), 0) FROM donation_payments dp JOIN payments p ON p.id = dp.payment_id AND p.institution_id = dp.institution_id AND p.status = 'posted'
         WHERE dp.institution_id = ? AND dp.donation_id = ?",
        [current_institution_id(), $donationId]
    ));
}

function find_donation(int $donationId): ?array
{
    $donation = db_one(
        "SELECT d.*, p.participant_number, p.first_name, p.last_name, p.phone AS donor_phone, p.email AS donor_email,
                f.fund_name, f.fund_code, e.event_name, e.event_code,
                CONCAT(su.first_name, ' ', su.last_name) AS solicited_by_name, CONCAT(cu.first_name, ' ', cu.last_name) AS created_by_name
         FROM donations d
         JOIN participants p ON p.id = d.donor_participant_id AND p.institution_id = d.institution_id
         JOIN fund_accounts f ON f.id = d.fund_id AND f.institution_id = d.institution_id
         LEFT JOIN events e ON e.id = d.event_id AND e.institution_id = d.institution_id
         LEFT JOIN institution_memberships sm ON sm.id = d.solicited_by_membership_id AND sm.institution_id = d.institution_id
         LEFT JOIN users su ON su.id = sm.user_id
         JOIN users cu ON cu.id = d.created_by
         WHERE d.institution_id = ? AND d.id = ?",
        [current_institution_id(), $donationId]
    );
    if (!$donation) {
        return null;
    }
    $donation['donor_name'] = donor_display_name($donation);
    $donation['received_cents'] = donation_received_cents($donationId);
    $donation['outstanding_cents'] = $donation['pledged_amount'] !== null && $donation['status'] !== 'cancelled'
        ? max(0, to_cents($donation['pledged_amount']) - $donation['received_cents']) : 0;
    $donation['payments'] = db_all(
        "SELECT p.id, p.payment_number, p.payment_date, p.payment_method, p.amount, p.status, p.external_reference, r.receipt_number, r.receipt_status,
                CONCAT(u.first_name, ' ', u.last_name) AS received_by_name, fa.account_name
         FROM donation_payments dp
         JOIN payments p ON p.id = dp.payment_id AND p.institution_id = dp.institution_id
         LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id
         JOIN institution_memberships m ON m.id = p.received_by_membership_id AND m.institution_id = p.institution_id
         JOIN users u ON u.id = m.user_id
         LEFT JOIN financial_accounts fa ON fa.id = p.financial_account_id AND fa.institution_id = p.institution_id
         WHERE dp.institution_id = ? AND dp.donation_id = ? ORDER BY p.payment_date, p.id",
        [current_institution_id(), $donationId]
    );
    return $donation;
}

/** "ABC HARDWARE" for a company stored with an empty first name, otherwise "JUAN DELA CRUZ". */
function donor_display_name(array $row): string
{
    return trim(($row['first_name'] === '-' ? '' : $row['first_name']) . ' ' . $row['last_name']);
}

/**
 * Donor for a new donation: an existing person (donor_participant_id), or a new donor registered in
 * People as Customer / Other with a DON- number. Companies give only a name (stored as the last name).
 */
function resolve_donor(array $input): int
{
    $institutionId = (int) current_institution_id();
    $existing = input_int($input, 'donor_participant_id');
    if ($existing) {
        $row = db_one("SELECT id FROM participants WHERE institution_id = ? AND id = ? AND status = 'active'", [$institutionId, $existing]) ?? fail_user('The selected donor was not found.');
        return (int) $row['id'];
    }
    $kind = input_enum($input, 'donor_kind', array_keys(DONOR_KINDS)) ?? 'individual';
    $isGroup = in_array($kind, ['company', 'organization'], true);
    $name = person_name_case(input_str($input, $isGroup ? 'donor_org_name' : 'donor_last_name', 100));
    $first = $isGroup ? '-' : person_name_case(input_str($input, 'donor_first_name', 100));
    if ($name === '' || $first === '') {
        fail_user($isGroup ? 'Enter the company or organization name, or pick an existing donor.' : 'Enter the donor\'s first and last name, or pick an existing donor.');
    }
    $phone = input_str($input, 'donor_phone', 50);
    if ($phone !== '' && !preg_match('/^[0-9+() .-]{6,50}$/', $phone)) {
        fail_user('Enter a valid contact number for the donor.');
    }
    $email = mb_strtolower(input_str($input, 'donor_email', 190));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail_user('Enter a valid e-mail for the donor, or leave it blank.');
    }
    $number = next_document_number($institutionId, 'donor', 'DON');
    db_query("INSERT INTO participants (institution_id, participant_number, first_name, last_name, phone, email, participant_type, status, no_middle_name) VALUES (?, ?, ?, ?, ?, ?, 'other', 'active', 1)",
        [$institutionId, $number, $first, $name, $phone ?: null, $email ?: null]);
    $id = (int) db()->lastInsertId();
    audit_log('participant.donor_added', 'participant', $id, $institutionId, (int) auth_user()['id'], ['participant_number' => $number, 'name' => trim(($isGroup ? '' : $first . ' ') . $name), 'kind' => $kind]);
    return $id;
}

/**
 * Records a solicitation. mode=received: the money is in hand (amount + payment details), a receipt
 * is issued now. mode=pledge: a promised amount to receive later with receive_donation().
 */
function create_donation(array $input): array
{
    if (!can('collections.record')) {
        fail_user('You do not have permission to record solicitations.');
    }
    require_donations_ready();
    $institutionId = (int) current_institution_id();
    $mode = input_enum($input, 'mode', ['received', 'pledge']) ?? 'received';
    $amount = parse_money(input_str($input, 'amount', 20)) ?? fail_user($mode === 'pledge' ? 'Enter the amount pledged.' : 'Enter the amount received.');
    $eventId = input_int($input, 'event_id');
    if ($eventId) {
        $event = find_event($eventId) ?? fail_user('Event not found.');
        if (in_array($event['status'], ['cancelled', 'closed'], true)) {
            fail_user('Solicitations cannot be added to a ' . $event['status'] . ' event.');
        }
    }
    $fund = find_fund(input_int($input, 'fund_id') ?? 0);
    $solicitedBy = input_int($input, 'solicited_by_membership_id');
    if ($solicitedBy && !db_value("SELECT 1 FROM institution_memberships WHERE institution_id = ? AND id = ? AND membership_status = 'active'", [$institutionId, $solicitedBy])) {
        fail_user('Select who solicited it from the list.');
    }
    $expected = input_date($input, 'expected_date');

    return db_transaction(function () use ($input, $institutionId, $mode, $amount, $eventId, $fund, $solicitedBy, $expected) {
        $donorId = resolve_donor($input);
        $number = next_document_number($institutionId, 'donation', 'SOL');
        db_query(
            "INSERT INTO donations (institution_id, donation_number, donor_participant_id, donor_kind, fund_id, event_id, purpose, pledged_amount, pledge_date, expected_date, solicited_by_membership_id, status, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pledged', ?, ?)",
            [$institutionId, $number, $donorId, input_enum($input, 'donor_kind', array_keys(DONOR_KINDS)) ?? 'individual', $fund['id'], $eventId ?: null,
             input_str($input, 'purpose', 255) ?: null, $mode === 'pledge' ? cents_to_decimal($amount) : null, $mode === 'pledge' ? date('Y-m-d') : null,
             $mode === 'pledge' ? $expected : null, $solicitedBy ?: null, input_str($input, 'notes', 2000) ?: null, (int) auth_user()['id']]
        );
        $donationId = (int) db()->lastInsertId();
        audit_log('donation.created', 'donation', $donationId, $institutionId, (int) auth_user()['id'],
            ['donation_number' => $number, 'mode' => $mode, 'amount' => cents_to_decimal($amount), 'fund_id' => (int) $fund['id'], 'event_id' => $eventId ?: null, 'donor_participant_id' => $donorId]);
        $paymentId = $mode === 'received' ? receive_donation($donationId, $input + ['amount' => cents_to_decimal($amount)]) : null;
        if ($mode === 'received') {
            db_query("UPDATE donations SET status = 'received' WHERE id = ?", [$donationId]);
        }
        return ['donation_id' => $donationId, 'payment_id' => $paymentId];
    });
}

/** Receives money for a donation: payment + receipt, Dr cash / Cr Donations & Solicitations in its fund. */
function receive_donation(int $donationId, array $input): int
{
    if (!can('collections.record')) {
        fail_user('You do not have permission to receive payments.');
    }
    $institutionId = (int) current_institution_id();
    $method = input_enum($input, 'payment_method', array_keys(PAYMENT_METHODS)) ?? fail_user('Select the payment method.');
    $reference = input_str($input, 'external_reference', 150);
    if ($method !== 'cash' && $reference === '') {
        fail_user('Enter the transaction reference number for ' . PAYMENT_METHODS[$method] . ' payments.');
    }
    $date = input_date($input, 'payment_date') ?? fail_user('Enter the date the money was received.');
    $time = preg_match('/^\d{2}:\d{2}$/', input_str($input, 'payment_time', 5)) ? input_str($input, 'payment_time', 5) : ($date === date('Y-m-d') ? date('H:i') : '12:00');
    $paymentDate = $date . ' ' . $time . ':00';
    if (strtotime($paymentDate) > time() + 300) {
        fail_user('The date received cannot be in the future.');
    }
    $amount = parse_money(input_str($input, 'amount', 20)) ?? fail_user('Enter the amount received.');

    return db_transaction(function () use ($institutionId, $donationId, $input, $method, $reference, $paymentDate, $date, $amount) {
        $donation = db_one('SELECT * FROM donations WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $donationId]) ?? fail_user('Solicitation not found.');
        if ($donation['status'] === 'cancelled') {
            fail_user('This pledge was cancelled.');
        }
        if ($donation['pledged_amount'] !== null) {
            $outstanding = to_cents($donation['pledged_amount']) - donation_received_cents($donationId);
            if ($outstanding <= 0) {
                fail_user('This pledge is already fully received.');
            }
            if ($amount > $outstanding) {
                fail_user('That is more than the ' . money($outstanding, current_currency()) . ' still pledged. Record the extra as a new solicitation.');
            }
        }
        $fundId = (int) $donation['fund_id'];
        find_fund($fundId);
        $account = find_financial_account(input_int($input, 'financial_account_id') ?? 0);
        require_usable_account($account);
        if (!in_array($account['account_type'], PAYMENT_METHOD_ACCOUNT_TYPES[$method], true)) {
            fail_user(PAYMENT_METHODS[$method] . ' payments must be received into a ' . implode(' or ', array_map('humanize', PAYMENT_METHOD_ACCOUNT_TYPES[$method])) . ' account.');
        }
        require_fund_account_mapping($fundId, (int) $account['id'], $date);
        $donor = db_one('SELECT first_name, last_name FROM participants WHERE institution_id = ? AND id = ?', [$institutionId, $donation['donor_participant_id']]);

        $paymentNumber = next_document_number($institutionId, 'payment', 'PAY');
        db_query(
            "INSERT INTO payments (institution_id, payment_number, payer_participant_id, received_by_membership_id, financial_account_id, payment_date, payment_method, amount, external_reference, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?)",
            [$institutionId, $paymentNumber, $donation['donor_participant_id'], current_membership_id(), $account['id'], $paymentDate, $method, cents_to_decimal($amount), $reference ?: null,
             trim('Solicitation ' . $donation['donation_number'] . ' ' . input_str($input, 'notes', 500))]
        );
        $paymentId = (int) db()->lastInsertId();
        db_query('INSERT INTO donation_payments (institution_id, donation_id, payment_id, amount) VALUES (?, ?, ?, ?)', [$institutionId, $donationId, $paymentId, cents_to_decimal($amount)]);
        $receiptNumber = next_document_number($institutionId, 'receipt', 'AR');
        db_query("INSERT INTO receipts (institution_id, receipt_number, payment_id, issued_by_membership_id, issued_at, receipt_status) VALUES (?, ?, ?, ?, NOW(), 'issued')",
            [$institutionId, $receiptNumber, $paymentId, current_membership_id()]);
        post_journal([
            'institution_id' => $institutionId,
            'date' => $date,
            'description' => 'Solicitation ' . $donation['donation_number'] . ' from ' . donor_display_name($donor) . ' (receipt ' . $receiptNumber . ')',
            'source_type' => 'payment',
            'source_id' => $paymentId,
            'cash_flow_type' => 'collection',
            'lines' => [
                ['fund_id' => $fundId, 'account' => cash_ledger_key($account['account_type']), 'financial_account_id' => (int) $account['id'], 'debit' => $amount],
                ['fund_id' => $fundId, 'account' => 'donations', 'credit' => $amount, 'description' => 'Solicitation ' . $donation['donation_number']],
            ],
        ]);
        if (has_upload('attachment')) {
            store_attachment('attachment', 'payment', $paymentId);
        }
        refresh_donation_status($donationId);
        audit_log('payment.recorded', 'payment', $paymentId, $institutionId, (int) auth_user()['id'], [
            'payment_number' => $paymentNumber, 'receipt_number' => $receiptNumber, 'amount' => cents_to_decimal($amount), 'donation_id' => $donationId,
            'participant_id' => (int) $donation['donor_participant_id'], 'fund_id' => $fundId, 'financial_account_id' => (int) $account['id'], 'method' => $method,
        ]);
        return $paymentId;
    });
}

/** Status from what was pledged and received (a reversal brings a pledge back to open). */
function refresh_donation_status(int $donationId): void
{
    $donation = db_one('SELECT pledged_amount, status FROM donations WHERE institution_id = ? AND id = ?', [current_institution_id(), $donationId]);
    if (!$donation || $donation['status'] === 'cancelled') {
        return;
    }
    $received = donation_received_cents($donationId);
    $status = $donation['pledged_amount'] === null
        ? ($received > 0 ? 'received' : 'pledged')
        : ($received >= to_cents($donation['pledged_amount']) ? 'received' : ($received > 0 ? 'partially_received' : 'pledged'));
    db_query('UPDATE donations SET status = ? WHERE id = ?', [$status, $donationId]);
}

/** After a payment is reversed: re-open the donation it belonged to. */
function refresh_donations_for_payment(int $paymentId): void
{
    if (!donations_ready()) {
        return;
    }
    foreach (db_query('SELECT donation_id FROM donation_payments WHERE institution_id = ? AND payment_id = ?', [current_institution_id(), $paymentId])->fetchAll(PDO::FETCH_COLUMN) as $donationId) {
        refresh_donation_status((int) $donationId);
    }
}

/** Cancels a pledge nobody paid (or the unpaid rest of one, which keeps what was received). */
function cancel_donation(int $donationId, string $reason): void
{
    if (!can('collections.record')) {
        fail_user('You do not have permission to change solicitations.');
    }
    if (mb_strlen($reason) < 5) {
        fail_user('A reason is required.');
    }
    $institutionId = (int) current_institution_id();
    db_transaction(function () use ($institutionId, $donationId, $reason) {
        $donation = db_one('SELECT * FROM donations WHERE institution_id = ? AND id = ? FOR UPDATE', [$institutionId, $donationId]) ?? fail_user('Solicitation not found.');
        if (!in_array($donation['status'], ['pledged', 'partially_received'], true)) {
            fail_user('Only open pledges can be cancelled. Received money is corrected by reversing its payment.');
        }
        $received = donation_received_cents($donationId);
        if ($received > 0) {
            // Keep what came in; the pledge shrinks to it.
            db_query("UPDATE donations SET pledged_amount = ?, status = 'received' WHERE id = ?", [cents_to_decimal($received), $donationId]);
        } else {
            db_query("UPDATE donations SET status = 'cancelled' WHERE id = ?", [$donationId]);
        }
        audit_log('donation.cancelled', 'donation', $donationId, $institutionId, (int) auth_user()['id'],
            ['status' => $received > 0 ? 'received' : 'cancelled', 'pledged_amount' => $received > 0 ? cents_to_decimal($received) : $donation['pledged_amount']],
            ['status' => $donation['status'], 'pledged_amount' => $donation['pledged_amount']], $reason);
    });
}

/** Totals for a list filter: pledged (open), received, outstanding. */
function donation_totals(string $whereSql, array $params): array
{
    $row = db_one(
        "SELECT COUNT(*) AS n,
                COALESCE(SUM(CASE WHEN d.status <> 'cancelled' THEN COALESCE(d.pledged_amount, 0) END), 0) AS pledged,
                COALESCE(SUM((SELECT COALESCE(SUM(dp.amount), 0) FROM donation_payments dp JOIN payments p ON p.id = dp.payment_id AND p.institution_id = dp.institution_id AND p.status = 'posted'
                               WHERE dp.institution_id = d.institution_id AND dp.donation_id = d.id)), 0) AS received
         FROM donations d JOIN participants dn ON dn.id = d.donor_participant_id AND dn.institution_id = d.institution_id WHERE {$whereSql}",
        $params
    );
    $outstanding = to_cents(db_value(
        "SELECT COALESCE(SUM(GREATEST(d.pledged_amount - (SELECT COALESCE(SUM(dp.amount), 0) FROM donation_payments dp JOIN payments p ON p.id = dp.payment_id AND p.institution_id = dp.institution_id AND p.status = 'posted'
                               WHERE dp.institution_id = d.institution_id AND dp.donation_id = d.id), 0)), 0)
         FROM donations d JOIN participants dn ON dn.id = d.donor_participant_id AND dn.institution_id = d.institution_id
         WHERE {$whereSql} AND d.status IN ('pledged', 'partially_received') AND d.pledged_amount IS NOT NULL",
        $params
    ));
    return ['count' => (int) $row['n'], 'received' => to_cents($row['received']), 'outstanding' => $outstanding];
}
