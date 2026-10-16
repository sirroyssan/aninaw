<?php
/**
 * Payment history (all statuses clearly labelled).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/payments.php';
require_institution_context();
require_permission(['collections.view', 'collections.record']);

$institutionId = (int) current_institution_id();
$search = get_str('q', 100);
$status = get_str('status', 20);
$method = get_str('method', 20);
$from = input_date($_GET, 'from');
$to = input_date($_GET, 'to');
$mine = get_str('mine', 1) === '1';

[$visibility, $params] = participant_visibility('pt');
$where = ['p.institution_id = ?', "({$visibility} OR p.received_by_membership_id = ?)"];
$params = array_merge([$institutionId], $params, [current_membership_id()]);
if ($search !== '') {
    $where[] = '(p.payment_number LIKE ? OR r.receipt_number LIKE ? OR pt.participant_number LIKE ? OR pt.last_name LIKE ? OR pt.first_name LIKE ?)';
    array_push($params, ...array_fill(0, 5, "%{$search}%"));
}
if (in_array($status, ['pending', 'posted', 'reversed', 'refunded'], true)) {
    $where[] = 'p.status = ?';
    $params[] = $status;
}
if (isset(PAYMENT_METHODS[$method])) {
    $where[] = 'p.payment_method = ?';
    $params[] = $method;
}
if ($from) {
    $where[] = 'p.payment_date >= ?';
    $params[] = $from . ' 00:00:00';
}
if ($to) {
    $where[] = 'p.payment_date <= ?';
    $params[] = $to . ' 23:59:59';
}
if ($mine) {
    $where[] = 'p.received_by_membership_id = ?';
    $params[] = current_membership_id();
}
$whereSql = implode(' AND ', $where);
$fromSql = "FROM payments p
            JOIN participants pt ON pt.id = p.payer_participant_id AND pt.institution_id = p.institution_id
            JOIN institution_memberships m ON m.id = p.received_by_membership_id JOIN users u ON u.id = m.user_id
            LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id
            WHERE {$whereSql}";
$totals = db_one("SELECT COALESCE(SUM(CASE WHEN p.status = 'posted' THEN p.amount END), 0) AS posted, COALESCE(SUM(CASE WHEN p.status = 'reversed' THEN p.amount END), 0) AS reversed {$fromSql}", $params);
[$orderBy, $sortKey, $sortDir] = sort_clause(['date' => 'p.payment_date', 'amount' => 'p.amount', 'payer' => 'pt.last_name', 'number' => 'p.payment_number'], 'date', 'desc');
$pagination = paginate((int) db_value("SELECT COUNT(*) {$fromSql}", $params), 30);
$rows = db_all(
    "SELECT p.id, p.payment_number, p.payment_date, p.payment_method, p.amount, p.status, r.receipt_number, r.receipt_status,
            pt.participant_number, pt.first_name, pt.last_name, CONCAT(u.first_name, ' ', u.last_name) AS received_by
     {$fromSql} ORDER BY {$orderBy}, p.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

$pageTitle = 'Payment History';
$activeNav = 'collections';
$breadcrumb = [auth_context()['label'], 'Collections'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Every recorded payment. Reversed payments stay listed with their voided receipts.',
    button_link('collections/index.php', 'Balances', 'bi-card-checklist', 'btn-outline-primary')
    . (can('collections.record') ? button_link('collections/record_payment.php', 'Record Payment', 'bi-cash-coin') : '')) ?>
<div class="stat-grid">
    <?= stat_card('bi-cash-coin', 'Posted (filtered)', m($totals['posted']), 'Counts toward balances') ?>
    <?= stat_card('bi-arrow-counterclockwise', 'Reversed (filtered)', m($totals['reversed']), 'Excluded from totals', 'danger') ?>
</div>
<div class="card panel">
    <form class="filter-bar" method="get">
        <div class="filter-field grow"><label for="q">Search</label><input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="Payment, receipt, ID or name"></div>
        <div class="filter-field"><label for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
        <div class="filter-field"><label for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
        <div class="filter-field"><label for="method">Method</label><select class="form-select" id="method" name="method"><?= options_html(PAYMENT_METHODS, $method, 'All') ?></select></div>
        <div class="filter-field"><label for="status">Status</label><select class="form-select" id="status" name="status"><?= options_html(['posted' => 'Posted', 'reversed' => 'Reversed', 'pending' => 'Pending', 'refunded' => 'Refunded'], $status, 'All') ?></select></div>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="mine" name="mine" value="1" <?= $mine ? 'checked' : '' ?>><label class="form-check-label" for="mine">Received by me</label></div>
        <button class="btn btn-outline-primary" type="submit">Apply</button>
    </form>
    <?php if (!$rows): ?>
        <div class="card-body"><?= empty_state('bi-clock-history', 'No payments match') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-clean align-middle mb-0">
                <thead><tr>
                    <th><?= sort_link('Payment', 'number', $sortKey, $sortDir) ?></th>
                    <th><?= sort_link('Payer', 'payer', $sortKey, $sortDir) ?></th>
                    <th>Receipt</th><th>Method</th><th>Received by</th>
                    <th class="text-end"><?= sort_link('Amount', 'amount', $sortKey, $sortDir) ?></th>
                    <th>Status</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= e($row['payment_number']) ?></div><div class="text-muted-sm"><?= e(format_datetime($row['payment_date'])) ?></div></td>
                        <td><?= e($row['last_name'] . ', ' . $row['first_name']) ?><div class="text-muted-sm"><?= e($row['participant_number']) ?></div></td>
                        <td><?= e($row['receipt_number'] ?: '—') ?><?= $row['receipt_status'] === 'voided' ? ' ' . status_badge('voided') : '' ?></td>
                        <td><?= e(PAYMENT_METHODS[$row['payment_method']] ?? $row['payment_method']) ?></td>
                        <td class="text-muted-sm"><?= e($row['received_by']) ?></td>
                        <td class="text-end fw-semibold<?= $row['status'] !== 'posted' ? ' text-decoration-line-through text-muted' : '' ?>"><?= e(m($row['amount'])) ?></td>
                        <td><?= status_badge($row['status']) ?></td>
                        <td class="actions"><a class="btn btn-outline-primary btn-xs" href="<?= e(url('collections/payment.php?id=' . $row['id'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?= pagination_links($pagination) ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
