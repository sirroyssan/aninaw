<?php
/**
 * Product categories: unlimited, per institution.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/products.php';
require_institution_context();
require_permission(['products.view', 'products.manage']);

if (is_post()) {
    require_permission('products.manage');
    $action = post_str('action', 20);
    handle_post(function () use ($action) {
        if ($action === 'save') {
            save_category(post_int('id'), $_POST);
            flash('success', 'Category saved');
        } elseif (in_array($action, ['activate', 'deactivate'], true)) {
            set_category_status(post_int('id') ?? 0, $action === 'activate' ? 'active' : 'inactive');
            flash('success', 'Category ' . $action . 'd');
        } else {
            fail_user('Unknown action.');
        }
        return 'products/categories.php';
    }, false);
}

$rows = db_all(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.institution_id = c.institution_id AND p.category_id = c.id) AS products
     FROM product_categories c WHERE c.institution_id = ? ORDER BY c.status, c.category_name',
    [current_institution_id()]
);
$editing = get_int('edit') ? db_one('SELECT * FROM product_categories WHERE institution_id = ? AND id = ?', [current_institution_id(), get_int('edit')]) : null;

$pageTitle = 'Product Categories';
$activeNav = 'products';
$breadcrumb = [auth_context()['label'], 'Products & Services'];
require APP_ROOT . '/includes/header.php';
?>
<?= page_actions('Group products and services, e.g. Merchandise, Printing, School Supplies.', button_link('products/index.php', 'Products', 'bi-arrow-left', 'btn-outline-secondary')) ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card panel">
            <?php if (!$rows): ?>
                <div class="card-body"><?= empty_state('bi-tags', 'No categories yet') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-clean align-middle mb-0">
                        <thead><tr><th>Category</th><th class="text-end">Products</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="fw-semibold"><?= e($row['category_name']) ?></td>
                                <td class="text-end"><?= e((string) $row['products']) ?></td>
                                <td><?= status_badge($row['status']) ?></td>
                                <td class="actions">
                                    <?php if (can('products.manage')): ?>
                                        <a class="btn btn-outline-primary btn-xs" href="<?= e(url('products/categories.php?edit=' . $row['id'])) ?>">Rename</a>
                                        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="action" value="<?= $row['status'] === 'active' ? 'deactivate' : 'activate' ?>"><button class="btn btn-outline-secondary btn-xs" type="submit"><?= $row['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if (can('products.manage')): ?>
        <div class="col-lg-5">
            <form method="post" class="card panel form-card">
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
                <div class="card-header"><h3><?= $editing ? 'Rename category' : 'New category' ?></h3></div>
                <div class="card-body">
                    <label class="form-label required" for="category_name">Name</label>
                    <input class="form-control mb-3" id="category_name" name="category_name" required maxlength="120" value="<?= e($editing['category_name'] ?? '') ?>">
                    <button class="btn btn-primary w-100" type="submit">Save</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
