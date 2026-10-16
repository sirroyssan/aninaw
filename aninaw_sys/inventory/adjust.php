<?php
/**
 * Stock actions for one item/variant: beginning inventory and adjustments
 * (damaged, lost, returned, count correction) with a required reason.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/products.php';
require_institution_context();
require_permission(['inventory.manage', 'inventory.adjust']);

$variantId = get_int('variant') ?? 0;
$variant = require_found(find_variant($variantId));
if ($variant['fulfillment'] === 'service') {
    render_error_page(404, 'No Stock', 'Services do not carry stock.');
}

if (is_post()) {
    $action = post_str('action', 20);
    handle_post(function () use ($action, $variantId) {
        if ($action === 'beginning') {
            record_beginning_stock($variantId, $_POST);
            flash('success', 'Beginning inventory recorded');
        } elseif ($action === 'adjust') {
            record_adjustment($variantId, $_POST);
            flash('success', 'Adjustment recorded');
        } else {
            fail_user('Unknown action.');
        }
        return 'inventory/adjust.php?variant=' . $variantId;
    });
}

$hasBeginning = (bool) db_value("SELECT 1 FROM inventory_movements WHERE institution_id = ? AND variant_id = ? AND movement_type = 'beginning'", [current_institution_id(), $variantId]);
$movements = db_all(
    "SELECT m.*, CONCAT(u.first_name, ' ', u.last_name) AS recorded_by_name FROM inventory_movements m JOIN users u ON u.id = m.recorded_by
     WHERE m.institution_id = ? AND m.variant_id = ? ORDER BY m.movement_date DESC, m.id DESC LIMIT 15",
    [current_institution_id(), $variantId]
);

$pageTitle = $variant['label'];
$activeNav = 'inventory';
$breadcrumb = [auth_context()['label'], 'Inventory'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Record stock counts and adjustments. Every change keeps its date, quantity, reason and the person who recorded it.',
    button_link('inventory/index.php', 'Inventory', 'bi-arrow-left', 'btn-outline-secondary') . button_link('products/view.php?id=' . $variant['product_id'], 'Product', 'bi-box-seam', 'btn-outline-primary')) ?>
<div class="stat-grid">
    <?= stat_card('bi-box-seam', 'On hand', number_format($variant['on_hand']), $variant['unit_of_measure'] . '(s)') ?>
    <?= stat_card('bi-bookmark-check', 'Reserved', number_format($variant['reserved']), 'Promised to orders', 'info') ?>
    <?= stat_card('bi-check2-circle', 'Available', number_format($variant['available']), 'Reorder level ' . $variant['reorder_level'], $variant['available'] <= (int) $variant['reorder_level'] ? 'warning' : 'success') ?>
</div>
<div class="row g-3">
    <div class="col-lg-5">
        <?php if (!$hasBeginning && can('inventory.manage')): ?>
            <form method="post" class="card panel form-card mb-3">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="beginning">
                <div class="card-header"><h3>Beginning inventory</h3></div>
                <div class="card-body">
                    <p class="text-muted-sm">The counted stock when you start using ANINAW for this item. Recorded once.</p>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label required" for="b-qty">Quantity</label><input class="form-control" type="number" min="1" id="b-qty" name="quantity" required></div>
                        <div class="col-6"><label class="form-label required" for="b-date">Count date</label><input class="form-control" type="date" id="b-date" name="movement_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label" for="b-cost">Unit cost</label><input class="form-control text-amount" id="b-cost" name="unit_cost" inputmode="decimal" placeholder="<?= e(cents_to_decimal(to_cents($variant['effective_cost']))) ?>"></div>
                    <div class="mb-3"><label class="form-label required" for="b-note">Basis</label><input class="form-control" id="b-note" name="reason" required maxlength="500" placeholder="e.g. Stock count of 16 Oct, witnessed by the adviser"></div>
                    <button class="btn btn-primary w-100" type="submit">Record beginning inventory</button>
                </div>
            </form>
        <?php endif; ?>
        <?php if (can('inventory.adjust')): ?>
            <form method="post" class="card panel form-card mb-3" data-confirm="Record this stock adjustment?">
                <?= csrf_field() ?><?= idempotency_field() ?><input type="hidden" name="action" value="adjust">
                <div class="card-header"><h3>Adjustment</h3></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label required" for="a-type">Type</label><select class="form-select" id="a-type" name="movement_type" required><?= options_html(['damaged' => 'Damaged (removes stock)', 'lost' => 'Lost (removes stock)', 'returned' => 'Returned to stock (adds)', 'adjustment' => 'Count correction (+ or −)'], null, 'Select…') ?></select></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label required" for="a-qty">Quantity</label><input class="form-control" id="a-qty" name="quantity" required inputmode="numeric" pattern="-?[0-9]+"><div class="form-text">Corrections: −3 removes, 2 adds.</div></div>
                        <div class="col-6"><label class="form-label required" for="a-date">Date</label><input class="form-control" type="date" id="a-date" name="movement_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label required" for="a-reason">Reason</label><input class="form-control" id="a-reason" name="reason" required minlength="5" maxlength="500"></div>
                    <?php if (can('inventory.override')): ?><div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="allow_negative" name="allow_negative" value="1"><label class="form-check-label" for="allow_negative">Allow stock to go below zero (administrator exception)</label></div><?php endif; ?>
                    <button class="btn btn-primary w-100" type="submit">Record adjustment</button>
                </div>
            </form>
        <?php endif; ?>
        <?php if (can('inventory.manage')): ?>
            <div class="card panel"><div class="card-body text-muted-sm">New stock from a supplier or production run is recorded by receiving a <a href="<?= e(url('inventory/batch.php?product=' . $variant['product_id'])) ?>">supplier / production order</a>, so ordered and received quantities stay separate.</div></div>
        <?php endif; ?>
    </div>
    <div class="col-lg-7">
        <div class="card panel">
            <div class="card-header"><h3>Movements</h3><a class="text-muted-sm" href="<?= e(url('inventory/movements.php?variant=' . $variantId)) ?>">Full history</a></div>
            <?php if (!$movements): ?>
                <div class="card-body"><?= empty_state('bi-arrow-down-up', 'No movements yet') ?></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-clean align-middle mb-0">
                    <thead><tr><th>Date</th><th>Movement</th><th class="text-end">Qty</th><th>Reason · By</th></tr></thead>
                    <tbody><?php foreach ($movements as $row): ?>
                        <tr><td><?= e(format_date($row['movement_date'])) ?></td><td><?= e(MOVEMENT_TYPES[$row['movement_type']]) ?><?= $row['negative_override'] ? ' <span class="badge badge-soft-danger">Override</span>' : '' ?></td>
                            <td class="text-end fw-semibold <?= $row['quantity'] > 0 ? 'text-success' : 'text-danger' ?>"><?= e(($row['quantity'] > 0 ? '+' : '') . $row['quantity']) ?></td>
                            <td class="text-muted-sm"><?= e(($row['reason'] ?: '—') . ' · ' . $row['recorded_by_name']) ?></td></tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
