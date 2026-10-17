<?php
/**
 * Adds an expense & budget category from the "New" button beside a Category list and answers
 * with JSON, so the form stays open and the new category is selected.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/services/institutions.php';
require_institution_context();
require_permission('institution.settings.manage');

if (!is_post()) {
    redirect('institutions/settings.php');
}
require_csrf();
try {
    $name = add_expense_category(post_str('name', 200));
    json_response(['ok' => true, 'name' => $name, 'message' => 'Category "' . $name . '" is ready to use.']);
} catch (UserFacingException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
}
