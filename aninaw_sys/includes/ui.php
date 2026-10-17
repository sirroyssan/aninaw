<?php
/**
 * Request input, duplicate-submission protection, pagination and small form helpers
 * shared by all module pages.
 */
declare(strict_types=1);

/** Raised by services for problems the user can fix; the message is safe to display. */
final class UserFacingException extends RuntimeException
{
    /** The form field the message is about (e.g. 'external_reference'), so a pop-up can focus it. */
    public ?string $field = null;
}

function fail_user(string $message, ?string $field = null): never
{
    $exception = new UserFacingException($message);
    $exception->field = $field;
    throw $exception;
}

// ---------------------------------------------------------------------------
// Input
// ---------------------------------------------------------------------------

function input_str(array $source, string $key, int $maxLength = 255): string
{
    $value = $source[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }
    $value = trim($value);
    return mb_substr($value, 0, $maxLength);
}

function input_int(array $source, string $key): ?int
{
    $value = $source[$key] ?? null;
    if (is_string($value) && preg_match('/^\d{1,18}$/', trim($value))) {
        return (int) trim($value);
    }
    return is_int($value) ? $value : null;
}

/** Validated Y-m-d date, or null. */
function input_date(array $source, string $key): ?string
{
    $value = input_str($source, $key, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    [$year, $month, $day] = array_map('intval', explode('-', $value));
    return checkdate($month, $day, $year) ? $value : null;
}

function input_enum(array $source, string $key, array $allowed, ?string $default = null): ?string
{
    $value = input_str($source, $key, 80);
    return in_array($value, $allowed, true) ? $value : $default;
}

/** List of positive integer IDs from a multi-value field (name[]). */
function input_ids(array $source, string $key): array
{
    $values = $source[$key] ?? [];
    if (!is_array($values)) {
        return [];
    }
    $ids = [];
    foreach ($values as $value) {
        if (is_string($value) && preg_match('/^\d{1,18}$/', $value)) {
            $ids[(int) $value] = (int) $value;
        }
    }
    return array_values($ids);
}

function post_str(string $key, int $maxLength = 255): string
{
    return input_str($_POST, $key, $maxLength);
}

function post_int(string $key): ?int
{
    return input_int($_POST, $key);
}

function get_str(string $key, int $maxLength = 255): string
{
    return input_str($_GET, $key, $maxLength);
}

function get_int(string $key): ?int
{
    return input_int($_GET, $key);
}

/** Integer ID from the query string or a 404 page. */
function require_get_id(string $key = 'id'): int
{
    $id = get_int($key);
    if (!$id) {
        render_error_page(404, 'Record Not Found', 'The record you requested does not exist or is not available in this institution.');
    }
    return $id;
}

/** Ends the request with a 404 when a scoped lookup found nothing. */
function require_found(?array $row): array
{
    if ($row === null) {
        render_error_page(404, 'Record Not Found', 'The record you requested does not exist or is not available in this institution.');
    }
    return $row;
}

// ---------------------------------------------------------------------------
// Form state: old input and duplicate-submission tokens
// ---------------------------------------------------------------------------

/** Keeps submitted values (never passwords) so a form can be refilled after an error. */
function remember_input(): void
{
    $keep = $_POST;
    foreach (array_keys($keep) as $key) {
        if (str_contains((string) $key, 'password') || str_starts_with((string) $key, '_')) {
            unset($keep[$key]);
        }
    }
    $_SESSION['old_input'] = $keep;
}

function old(string $key, mixed $default = ''): mixed
{
    return old_all()[$key] ?? $default;
}

/** All values kept by remember_input(), read once per request. */
function old_all(): array
{
    static $old = null;
    if ($old === null) {
        $old = $_SESSION['old_input'] ?? [];
        unset($_SESSION['old_input']);
    }
    return $old;
}

/** Keeps the values for the next request too, e.g. a pop-up form the page loads right after. */
function pass_old_input_on(): void
{
    if (old_all()) {
        $_SESSION['old_input'] = old_all();
    }
}

/**
 * Hidden one-time token. Financial forms include it so a double click or a
 * browser resubmission cannot create the same record twice.
 */
function idempotency_field(): string
{
    $token = bin2hex(random_bytes(16));
    $_SESSION['idem_tokens'][$token] = time();
    // Keep the list small.
    if (count($_SESSION['idem_tokens']) > 200) {
        $_SESSION['idem_tokens'] = array_slice($_SESSION['idem_tokens'], -100, null, true);
    }
    return '<input type="hidden" name="_idem" value="' . e($token) . '">';
}

/** Consumes the form's token; a missing or reused token stops the request. */
function require_idempotency(): void
{
    $token = $_POST['_idem'] ?? '';
    if (is_string($token) && isset($_SESSION['idem_tokens'][$token])) {
        unset($_SESSION['idem_tokens'][$token]);
        return;
    }
    flash('warning', 'Already submitted', 'This form was already processed. Check the record list before trying again.');
    redirect_back();
}

/** Redirects to the referring page inside the app, or to the dashboard. */
function redirect_back(string $fallback = 'dashboard/index.php'): never
{
    // Only the path and query of the referer are used, and safe_redirect_path() keeps it inside the app.
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $path = (string) parse_url($referer, PHP_URL_PATH);
    $query = (string) parse_url($referer, PHP_URL_QUERY);
    redirect($path === '' ? $fallback : safe_redirect_path($path . ($query !== '' ? '?' . $query : ''), $fallback));
}

/**
 * Runs a state-changing action: CSRF + duplicate-submission check, then the callback.
 * UserFacingException messages are shown to the user; anything else is logged.
 * The callback returns the path to redirect to (PRG pattern).
 * Sent from a pop-up (fetch asking for JSON), the answer is JSON instead: where to go on
 * success, or the message (and the field it is about) so the pop-up stays open.
 */
function handle_post(callable $action, bool $idempotent = true): never
{
    require_csrf();
    if (wants_json()) {
        handle_post_json($action, $idempotent);
    }
    if ($idempotent) {
        require_idempotency();
    }
    try {
        $destination = $action();
        unset($_SESSION['old_input']);
        redirect(is_string($destination) ? $destination : 'dashboard/index.php');
    } catch (UserFacingException $exception) {
        if (db_in_transaction()) {
            db()->rollBack();
        }
        remember_input();
        flash('error', 'Not saved', $exception->getMessage());
        redirect_back();
    } catch (DatabaseUnavailableException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        if (db_in_transaction()) {
            db()->rollBack();
        }
        log_error('Action failed', ['type' => $exception::class, 'message' => $exception->getMessage(), 'file' => $exception->getFile() . ':' . $exception->getLine()]);
        remember_input();
        flash('error', 'Something went wrong', 'The action could not be completed and nothing was saved. Reference: ' . request_id());
        redirect_back();
    }
}

function handle_post_json(callable $action, bool $idempotent): never
{
    $token = $_POST['_idem'] ?? '';
    if ($idempotent) {
        if (!is_string($token) || !isset($_SESSION['idem_tokens'][$token])) {
            json_response(['ok' => false, 'message' => 'This form was already processed. Check the record list before trying again.'], 409);
        }
        unset($_SESSION['idem_tokens'][$token]);
    }
    try {
        $destination = $action();
        unset($_SESSION['old_input']);
        json_response(['ok' => true, 'redirect' => url(is_string($destination) ? $destination : 'dashboard/index.php')]);
    } catch (DatabaseUnavailableException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        if (db_in_transaction()) {
            db()->rollBack();
        }
        if ($idempotent && is_string($token) && $token !== '') {
            $_SESSION['idem_tokens'][$token] = time(); // nothing was saved: the same form may be sent again
        }
        if ($exception instanceof UserFacingException) {
            json_response(['ok' => false, 'message' => $exception->getMessage(), 'field' => $exception->field], 422);
        }
        log_error('Action failed', ['type' => $exception::class, 'message' => $exception->getMessage(), 'file' => $exception->getFile() . ':' . $exception->getLine()]);
        json_response(['ok' => false, 'message' => 'The action could not be completed and nothing was saved. Reference: ' . request_id()], 500);
    }
}

function db_in_transaction(): bool
{
    try {
        return db()->inTransaction();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Runs $work inside a database transaction and returns its result. Called while a
 * transaction is already open, it joins that transaction: the outermost call commits,
 * and any failure rolls the whole unit back (e.g. order + confirmation + payment).
 */
function db_transaction(callable $work): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $work($pdo);
    }
    $pdo->beginTransaction();
    try {
        $result = $work($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

// ---------------------------------------------------------------------------
// Query strings and pagination
// ---------------------------------------------------------------------------

/** Current page URL with some query parameters replaced (null removes one). */
function query_url(array $changes = []): string
{
    $params = array_merge($_GET, $changes);
    $params = array_filter($params, static fn($value) => $value !== null && $value !== '');
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    return $path . ($params ? '?' . http_build_query($params) : '');
}

/** @return array{page:int, per_page:int, offset:int, total:int, pages:int} */
/** Every on-screen list shows this many records per page (printouts and CSV exports list everything). */
const LIST_PAGE_SIZE = 10;

function paginate(int $total, int $perPage = LIST_PAGE_SIZE): array
{
    $page = max(1, (int) (get_int('page') ?? 1));
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage, 'total' => $total, 'pages' => $pages];
}

function pagination_links(array $pagination): string
{
    if ($pagination['pages'] <= 1) {
        return '<div class="table-meta">' . e(number_format($pagination['total'])) . ' record' . ($pagination['total'] === 1 ? '' : 's') . '</div>';
    }
    $html = '<div class="table-meta d-flex flex-wrap justify-content-between align-items-center gap-2">'
        . '<span>' . e(number_format($pagination['total'])) . ' records · page ' . $pagination['page'] . ' of ' . $pagination['pages'] . '</span>'
        . '<nav aria-label="Pagination"><ul class="pagination pagination-sm mb-0">';
    $current = $pagination['page'];
    $window = array_unique(array_filter([1, $current - 2, $current - 1, $current, $current + 1, $current + 2, $pagination['pages']], static fn($p) => $p >= 1 && $p <= $pagination['pages']));
    sort($window);
    $previous = 0;
    $html .= '<li class="page-item' . ($current === 1 ? ' disabled' : '') . '"><a class="page-link" href="' . e(query_url(['page' => $current - 1])) . '" aria-label="Previous">&lsaquo;</a></li>';
    foreach ($window as $page) {
        if ($previous && $page > $previous + 1) {
            $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        $html .= '<li class="page-item' . ($page === $current ? ' active' : '') . '"><a class="page-link" href="' . e(query_url(['page' => $page])) . '">' . $page . '</a></li>';
        $previous = $page;
    }
    $html .= '<li class="page-item' . ($current === $pagination['pages'] ? ' disabled' : '') . '"><a class="page-link" href="' . e(query_url(['page' => $current + 1])) . '" aria-label="Next">&rsaquo;</a></li>';
    return $html . '</ul></nav></div>';
}

/** Whitelisted ORDER BY from ?sort= and ?dir=. $columns maps sort keys to SQL expressions. */
function sort_clause(array $columns, string $defaultKey, string $defaultDir = 'asc'): array
{
    $key = get_str('sort', 40);
    $key = isset($columns[$key]) ? $key : $defaultKey;
    $dirParam = strtolower(get_str('dir', 4));
    $dir = $dirParam === '' ? ($key === $defaultKey ? $defaultDir : 'asc') : ($dirParam === 'desc' ? 'desc' : 'asc');
    return [$columns[$key] . ' ' . strtoupper($dir), $key, $dir];
}

function sort_link(string $label, string $key, string $currentKey, string $currentDir): string
{
    $nextDir = ($key === $currentKey && $currentDir === 'asc') ? 'desc' : 'asc';
    $icon = $key === $currentKey ? ($currentDir === 'asc' ? ' <i class="bi bi-caret-up-fill" aria-hidden="true"></i>' : ' <i class="bi bi-caret-down-fill" aria-hidden="true"></i>') : '';
    return '<a class="sort-link" href="' . e(query_url(['sort' => $key, 'dir' => $nextDir, 'page' => null])) . '">' . e($label) . $icon . '</a>';
}

// ---------------------------------------------------------------------------
// Form controls
// ---------------------------------------------------------------------------

/** <option> list. $rows: list of [value => label] pairs or rows with given keys. */
function options_html(array $options, mixed $selected = null, ?string $placeholder = null): string
{
    $html = $placeholder !== null ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($options as $value => $label) {
        $isSelected = is_array($selected) ? in_array((string) $value, array_map('strval', $selected), true) : (string) $selected === (string) $value;
        $html .= '<option value="' . e((string) $value) . '"' . ($isSelected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html;
}

/** Turns DB rows into [id => label] for options_html(). */
function options_from_rows(array $rows, string $valueKey, string|callable $label): array
{
    $options = [];
    foreach ($rows as $row) {
        $options[$row[$valueKey]] = is_callable($label) ? $label($row) : $row[$label];
    }
    return $options;
}

/** Page heading with optional action buttons (HTML). */
function page_actions(string $subtitle = '', string $actionsHtml = ''): string
{
    return '<div class="page-toolbar"><p class="page-subtitle">' . e($subtitle) . '</p>'
        . ($actionsHtml !== '' ? '<div class="page-toolbar-actions">' . $actionsHtml . '</div>' : '') . '</div>';
}

/** $modal: open the page's form or list in a pop-up (assets/js/page_modal.js), e.g. 'lg' or 'xl'. */
function button_link(string $path, string $label, string $icon = '', string $class = 'btn-primary', ?string $modal = null): string
{
    return '<a class="btn ' . e($class) . '" href="' . e(url($path)) . '"' . ($modal ? ' data-page-modal="' . e($modal) . '"' : '') . '>' . ($icon ? '<i class="bi ' . e($icon) . ' me-1" aria-hidden="true"></i>' : '') . e($label) . '</a>';
}

/** Full name from a row with first_name/last_name (and optional middle_name). */
function person_name(array $row, string $prefix = ''): string
{
    return trim(($row[$prefix . 'first_name'] ?? '') . ' ' . ($row[$prefix . 'last_name'] ?? ''));
}

// ---------------------------------------------------------------------------
// One-time password links (shown once, never stored in plain text)
// ---------------------------------------------------------------------------

const RESET_TOKEN_HOURS = 48;

// ---------------------------------------------------------------------------
// ---------------------------------------------------------------------------

function remember_issued_link(string $link, string $recipient): void
{
    $_SESSION['issued_link'] = ['link' => $link, 'recipient' => $recipient];
}

/** Shows a just-issued password link once, with a copy button. */
function issued_link_notice(): string
{
    $issued = $_SESSION['issued_link'] ?? null;
    unset($_SESSION['issued_link']);
    if (!is_array($issued)) {
        return '';
    }
    return '<div class="notice notice-warning" role="status"><i class="bi bi-key" aria-hidden="true"></i><div class="flex-grow-1">'
        . '<strong>One-time password link for ' . e($issued['recipient']) . '</strong>'
        . '<p class="mb-2">Give this link to the person privately. It works once and expires in ' . RESET_TOKEN_HOURS . ' hours. It will not be shown again.</p>'
        . '<div class="input-group"><input class="form-control" id="issued-link" type="text" readonly value="' . e($issued['link']) . '">'
        . '<button class="btn btn-outline-primary" type="button" data-copy="issued-link"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copy</button></div>'
        . '</div></div>';
}

/** After paying from a pop-up: remember the payment so the page shows its receipt link once. */
function remember_recent_payment(int $paymentId): void
{
    $row = db_one('SELECT p.amount, r.receipt_number, pt.first_name, pt.last_name FROM payments p
        JOIN participants pt ON pt.id = p.payer_participant_id AND pt.institution_id = p.institution_id
        LEFT JOIN receipts r ON r.payment_id = p.id AND r.institution_id = p.institution_id
        WHERE p.institution_id = ? AND p.id = ?', [current_institution_id(), $paymentId]);
    if ($row) {
        $_SESSION['recent_payment'] = $row + ['id' => $paymentId];
    }
}

/** "₱850.00 received from JUAN DELA CRUZ · receipt AR-… [Print Receipt]", shown once. */
function recent_payment_notice(): string
{
    $paid = $_SESSION['recent_payment'] ?? null;
    unset($_SESSION['recent_payment']);
    if (!is_array($paid)) {
        return '';
    }
    return '<div class="notice notice-success d-flex flex-wrap align-items-center gap-2 mb-3 d-print-none"><i class="bi bi-check-circle" aria-hidden="true"></i>'
        . '<div class="flex-grow-1"><strong>' . e(money(to_cents($paid['amount']), current_currency())) . ' received from ' . e(trim($paid['first_name'] . ' ' . $paid['last_name'])) . '</strong>'
        . ($paid['receipt_number'] ? ' · receipt ' . e($paid['receipt_number']) : '') . '</div>'
        . '<a class="btn btn-outline-primary btn-sm" href="' . e(url('collections/payment.php?id=' . $paid['id'])) . '">Details</a>'
        . '<a class="btn btn-primary btn-sm" href="' . e(url('collections/print_receipt.php?id=' . $paid['id'])) . '" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Print Receipt</a></div>';
}

/** Keeps a just-generated default password for one display (session only, never in the database). */
function remember_issued_password(string $name, string $username, string $password): void
{
    $_SESSION['issued_password'] = ['name' => $name, 'username' => $username, 'password' => $password];
}

/** Shows a just-generated default password once, with copy buttons. */
function issued_password_notice(): string
{
    $issued = $_SESSION['issued_password'] ?? null;
    unset($_SESSION['issued_password']);
    if (!is_array($issued)) {
        return '';
    }
    return '<div class="notice notice-warning" role="status"><i class="bi bi-key" aria-hidden="true"></i><div class="flex-grow-1">'
        . '<strong>Default password for ' . e($issued['name']) . '</strong>'
        . '<p class="mb-2">Give these to the person privately. They must change the password the first time they sign in. It will not be shown again.</p>'
        . '<div class="row g-2"><div class="col-md-5"><label class="form-label small mb-1" for="issued-username">Username</label><div class="input-group"><input class="form-control" id="issued-username" type="text" readonly value="' . e($issued['username']) . '">'
        . '<button class="btn btn-outline-primary" type="button" data-copy="issued-username" aria-label="Copy username"><i class="bi bi-clipboard" aria-hidden="true"></i></button></div></div>'
        . '<div class="col-md-5"><label class="form-label small mb-1" for="issued-password">Default password</label><div class="input-group"><input class="form-control font-monospace" id="issued-password" type="text" readonly value="' . e($issued['password']) . '">'
        . '<button class="btn btn-outline-primary" type="button" data-copy="issued-password" aria-label="Copy password"><i class="bi bi-clipboard" aria-hidden="true"></i></button></div></div></div>'
        . '</div></div>';
}

// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------

/**
 * Streams rows as a CSV download. Cells starting with = + - @ are prefixed with an
 * apostrophe so spreadsheets do not execute them as formulas (CSV injection).
 */
function send_csv(string $filename, array $header, iterable $rows, array $preamble = []): never
{
    $safe = static function ($value): string {
        $value = (string) ($value ?? '');
        return preg_match('/^[=+\-@\t\r]/', $value) && !preg_match('/^-?\d+(\.\d+)?$/', $value) ? "'" . $value : $value;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    foreach ($preamble as $line) {
        fputcsv($out, array_map($safe, (array) $line), ',', '"', '\\');
    }
    if ($preamble) {
        fputcsv($out, [], ',', '"', '\\');
    }
    fputcsv($out, array_map($safe, $header), ',', '"', '\\');
    foreach ($rows as $row) {
        fputcsv($out, array_map($safe, array_values($row)), ',', '"', '\\');
    }
    fclose($out);
    if (auth_user()) {
        audit_log('report.exported', 'report', null, current_institution_id(), (int) auth_user()['id'], ['file' => $filename]);
    }
    exit;
}

/** "T-shirt\nLanyard" or "T-shirt, Lanyard" (one per line or comma-separated) → ['T-shirt', 'Lanyard']. */
function rule_inclusions(?string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R|[;,]/', (string) $text) ?: [])));
}

/** Migration 008 adds collection_rules.inclusions; until it is run, rules simply list nothing. */
function rule_inclusions_ready(): bool
{
    return db_column_exists('collection_rules', 'inclusions');
}

/** SELECT expression for a rule's inclusions under table alias $alias. */
function rule_inclusions_sql(string $alias): string
{
    return rule_inclusions_ready() ? $alias . '.inclusions' : 'NULL AS inclusions';
}

/** "Includes: T-shirt, Lanyard, Lunch" (escaped), or '' when the rule lists nothing. */
function inclusions_html(?string $text = null, string $class = 'text-muted-sm'): string
{
    $items = rule_inclusions($text);
    return $items ? '<div class="' . e($class) . '"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Includes: ' . e(implode(', ', $items)) . '</div>' : '';
}
