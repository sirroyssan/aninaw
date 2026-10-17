<?php
/**
 * Solicitations: donations and pledges by event / fund, with received and still-pledged totals.
 * Printable and as CSV (the solicitation list for liquidation or thank-you letters).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission(['collections.view', 'collections.record']);

$ready = donations_ready();
$institutionId = (int) current_institution_id();
$eventId = get_int('event');
$fundId = get_int('fund');
$status = isset(DONATION_STATUSES[get_str('status', 20)]) ? get_str('status', 20) : (get_str('status', 20) === 'open' ? 'open' : '');
$search = get_str('q', 100);
$currency = current_currency();

$rows = [];
$totals = ['count' => 0, 'received' => 0, 'outstanding' => 0];
if ($ready) {
    $where = ['d.institution_id = ?'];
    $params = [$institutionId];
    if ($eventId) {
        $where[] = 'd.event_id = ?';
        $params[] = $eventId;
    }
    if ($fundId) {
        $where[] = 'd.fund_id = ?';
        $params[] = $fundId;
    }
    if ($status === 'open') {
        $where[] = "d.status IN ('pledged', 'partially_received')";
    } elseif ($status !== '') {
        $where[] = 'd.status = ?';
        $params[] = $status;
    }
    if ($search !== '') {
        $where[] = '(dn.first_name LIKE ? OR dn.last_name LIKE ? OR d.donation_number LIKE ? OR d.purpose LIKE ?)';
        array_push($params, ...array_fill(0, 4, "%{$search}%"));
    }
    $whereSql = implode(' AND ', $where);
    $totals = donation_totals($whereSql, $params);
    $csv = get_str('export', 10) === 'csv';
    $pagination = paginate($totals['count']);
    $rows = db_all(
        "SELECT d.id, d.donation_number, d.donor_kind, d.purpose, d.pledged_amount, d.expected_date, d.status, d.created_at, dn.first_name, dn.last_name, dn.participant_number,
                f.fund_name, e.event_name, e.event_code,
                (SELECT COALESCE(SUM(dp.amount), 0) FROM donation_payments dp JOIN payments p ON p.id = dp.payment_id AND p.institution_id = dp.institution_id AND p.status = 'posted'
                  WHERE dp.institution_id = d.institution_id AND dp.donation_id = d.id) AS received,
                (SELECT MAX(r.receipt_number) FROM donation_payments dp JOIN receipts r ON r.payment_id = dp.payment_id AND r.institution_id = dp.institution_id AND r.receipt_status = 'issued'
                  WHERE dp.institution_id = d.institution_id AND dp.donation_id = d.id) AS last_receipt,
                (SELECT MAX(p.payment_date) FROM donation_payments dp JOIN payments p ON p.id = dp.payment_id AND p.institution_id = dp.institution_id AND p.status = 'posted'
                  WHERE dp.institution_id = d.institution_id AND dp.donation_id = d.id) AS last_received
         FROM donations d
         JOIN participants dn ON dn.id = d.donor_participant_id AND dn.institution_id = d.institution_id
         JOIN fund_accounts f ON f.id = d.fund_id AND f.institution_id = d.institution_id
         LEFT JOIN events e ON e.id = d.event_id AND e.institution_id = d.institution_id
         WHERE {$whereSql} ORDER BY d.created_at DESC, d.id DESC" . ($csv ? '' : " LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}"),
        $params
    );

    if ($csv) {
        audit_log('report.exported', 'donation', null, $institutionId, (int) auth_user()['id'], ['report' => 'solicitations', 'rows' => count($rows)]);
        send_csv('solicitations_' . date('Ymd') . '.csv',
            ['Reference', 'Donor', 'Donor type', 'Event', 'Fund', 'Purpose', 'Pledged', 'Received', 'Still pledged', 'Status', 'Last received', 'Receipt'],
            array_map(static fn($r) => [$r['donation_number'], donor_display_name($r), DONOR_KINDS[$r['donor_kind']] ?? '', (string) $r['event_name'], $r['fund_name'], (string) $r['purpose'],
                $r['pledged_amount'] ?? '', $r['received'], $r['pledged_amount'] !== null && in_array($r['status'], ['pledged', 'partially_received'], true) ? cents_to_decimal(max(0, to_cents($r['pledged_amount']) - to_cents($r['received']))) : '0.00',
                DONATION_STATUSES[$r['status']] ?? $r['status'], (string) $r['last_received'], (string) $r['last_receipt']], $rows),
            [['Solicitations', $eventId ? (event_options(['draft', 'pending', 'approved', 'collecting', 'completed', 'closed'])[$eventId] ?? '') : 'All events'], ['Generated', date('Y-m-d H:i') . ' by ' . auth_user()['username']]]);
    }
}

$pageTitle = 'Solicitations';
$activeNav = 'donations';
$breadcrumb = [auth_context()['label'], 'Solicitations'];
require APP_ROOT . '/includes/header.php';
?>
<div class="report-header"><h2>Solicitations<?= $eventId ? ' · ' . e(event_options(['draft', 'pending', 'approved', 'collecting', 'completed', 'closed'])[$eventId] ?? '') : '' ?></h2><p><?= e(auth_context()['label']) ?> · Printed <?= e(format_datetime(date('Y-m-d H:i:s'))) ?> by <?= e(auth_user()['username']) ?></p></div>
<?= page_actions('Donations and sponsorships received for a fund or event, and pledges still to collect. Each amount received gets an official receipt and goes to the fund as Donations & Solicitations income.',
    ($ready ? '<a class="btn btn-outline-primary" href="' . e(query_url(['export' => 'csv'])) . '"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>CSV</a>'
        . '<button class="btn btn-outline-primary" type="button" data-print><i class="bi bi-printer me-1" aria-hidden="true"></i>Print</button>' : '')
    . ($ready && can('collections.record') ? button_link('donations/create.php' . ($eventId ? '?event=' . $eventId : ''), 'Record Solicitation', 'bi-gift') : '')) ?>

<?php if (!$ready): ?>
    <div class="notice notice-danger"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>Import <code>database/migration_010_solicitations.sql</code> once (phpMyAdmin → Import) to start recording solicitations.</div></div>
<?php else: ?>
    <div class="stat-grid">
        <?= stat_card('bi-gift', 'Received', money($totals['received'], $currency), number_format($totals['count']) . ' solicitation(s) listed', 'success') ?>
        <?= stat_card('bi-hourglass-split', 'Still pledged', money($totals['outstanding'], $currency), 'Promised, not yet received', 'warning') ?>
    </div>
    <div class="card panel">
        <form class="filter-bar" method="get">
            <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Donor, reference or purpose"></div>
            <div class="filter-field"><label for="event">Event</label><select class="form-select" id="event" name="event" data-autosubmit><?= options_html(event_options(['draft', 'pending', 'approved', 'collecting', 'completed', 'closed']), $eventId, 'All events') ?></select></div>
            <div class="filter-field"><label for="fund">Fund</label><select class="form-select" id="fund" name="fund" data-autosubmit><?= options_html(options_from_rows(db_all('SELECT id, fund_name FROM fund_accounts WHERE institution_id = ? ORDER BY fund_name', [$institutionId]), 'id', 'fund_name'), $fundId, 'All funds') ?></select></div>
            <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status" data-autosubmit><?= options_html(['open' => 'Open pledges'] + DONATION_STATUSES, $status, 'All') ?></select></div>
            <button class="btn btn-outline-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Filter</span></button>
        </form>
        <?php if (!$rows): ?>
            <div class="card-body"><?= empty_state('bi-gift', 'No solicitations yet', 'Record money from sponsors, alumni, parents or companies with Record Solicitation. It gets an official receipt and goes to the fund you choose.') ?></div>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                <thead><tr><th>Donor</th><th>For</th><th class="text-end">Pledged</th><th class="text-end">Received</th><th>Status</th><th>Receipt</th></tr></thead>
                <tbody><?php foreach ($rows as $r): ?>
                    <tr>
                        <td><a class="fw-semibold" href="<?= e(url('donations/view.php?id=' . $r['id'])) ?>"><?= e(donor_display_name($r)) ?></a><div class="text-muted-sm"><?= e($r['donation_number'] . ' · ' . (DONOR_KINDS[$r['donor_kind']] ?? '')) ?></div></td>
                        <td><?= e($r['event_name'] ?: $r['fund_name']) ?><div class="text-muted-sm"><?= e(trim(($r['event_name'] ? $r['fund_name'] : '') . ($r['purpose'] ? ($r['event_name'] ? ' · ' : '') . $r['purpose'] : ''))) ?></div></td>
                        <td class="text-end"><?= $r['pledged_amount'] !== null ? e(m($r['pledged_amount'])) : '<span class="text-muted-sm">—</span>' ?></td>
                        <td class="text-end fw-semibold"><?= e(m($r['received'])) ?><?= $r['last_received'] ? '<div class="text-muted-sm">' . e(format_date($r['last_received'])) . '</div>' : '' ?></td>
                        <td><?= status_badge($r['status']) ?><?= $r['expected_date'] && in_array($r['status'], ['pledged', 'partially_received'], true) ? '<div class="text-muted-sm">expected ' . e(format_date($r['expected_date'])) . '</div>' : '' ?></td>
                        <td class="text-muted-sm"><?= e($r['last_receipt'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
        <?= pagination_links($pagination) ?>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
