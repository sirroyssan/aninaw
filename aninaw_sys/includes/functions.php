<?php
/**
 * Shared helpers: URLs, escaping, CSRF, flash messages, formatting, logging and error pages.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// URLs and requests
// ---------------------------------------------------------------------------

/**
 * Web path of the project folder (e.g. "/aninaw_sys"), with no trailing slash.
 * Uses APP_URL when configured, otherwise derives it from the document root.
 */
function app_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    if (APP_URL !== '') {
        return $base = rtrim((string) (parse_url(APP_URL, PHP_URL_PATH) ?? ''), '/');
    }

    $appRoot = realpath(APP_ROOT) ?: APP_ROOT;

    // Strip the script's path inside the project from its URL. Works with symlinks and aliases.
    $scriptFile = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) ?: '';
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptFile !== '' && str_starts_with($scriptFile, $appRoot . DIRECTORY_SEPARATOR)) {
        $inProject = str_replace('\\', '/', substr($scriptFile, strlen($appRoot)));
        if ($scriptName !== '' && str_ends_with($scriptName, $inProject)) {
            return $base = rtrim(substr($scriptName, 0, -strlen($inProject)), '/');
        }
    }

    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
    if ($documentRoot !== '' && str_starts_with($appRoot, $documentRoot)) {
        $relative = str_replace('\\', '/', substr($appRoot, strlen($documentRoot)));
        return $base = rtrim($relative, '/');
    }

    return $base = '';
}

function url(string $path = ''): string
{
    return app_base_path() . '/' . ltrim($path, '/');
}

/** Asset URL with a modification-time suffix so browsers pick up changes. */
function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . ltrim($path, '/')) . '?v=' . $version;
}

function redirect(string $path): never
{
    header('Location: ' . (preg_match('#^https?://#i', $path) ? $path : url($path)));
    exit;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Client IP from the direct connection only; forwarded headers are client-controlled. */
function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function client_user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
}

/** Random ID shared by log lines and audit rows created during one request. */
function request_id(): string
{
    static $id = null;
    return $id ??= bin2hex(random_bytes(8));
}

function wants_json(): bool
{
    return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Accepts only a local path inside this application for post-login redirects.
 * Anything else (absolute URLs, protocol-relative URLs, backslashes) falls back to the dashboard.
 */
function safe_redirect_path(?string $candidate, string $fallback = 'dashboard/index.php'): string
{
    $candidate = trim((string) $candidate);
    if ($candidate === '' || preg_match('#[\\\\\x00-\x1F]#', $candidate) || !str_starts_with($candidate, '/') || str_starts_with($candidate, '//')) {
        return $fallback;
    }

    $path = (string) parse_url($candidate, PHP_URL_PATH);
    $base = app_base_path() . '/';
    if (!str_starts_with($path, $base) || str_contains($path, '..')) {
        return $fallback;
    }

    $relative = substr($candidate, strlen($base));
    $blocked = ['login.php', 'logout.php', 'select_institution.php', 'session_ping.php'];
    foreach ($blocked as $page) {
        if (str_starts_with($relative, $page)) {
            return $fallback;
        }
    }
    return $relative === '' ? $fallback : $relative;
}

function send_security_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    // SweetAlert2 injects inline styles, so style-src needs 'unsafe-inline'. Scripts stay same-origin only.
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

function send_no_cache_headers(): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
}

// ---------------------------------------------------------------------------
// Output escaping
// ---------------------------------------------------------------------------

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON for HTML attributes (data-*). */
function json_attr(mixed $value): string
{
    return e(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP));
}

// ---------------------------------------------------------------------------
// CSRF protection
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function rotate_csrf_token(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_is_valid(): bool
{
    $submitted = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['csrf_token'] ?? '';
    return is_string($submitted) && is_string($expected) && $expected !== '' && hash_equals($expected, $submitted);
}

/** Rejects a state-changing request whose CSRF token is missing or wrong. */
function require_csrf(): void
{
    if (csrf_is_valid()) {
        return;
    }
    if (wants_json()) {
        json_response(['ok' => false, 'message' => 'Your form has expired. Please refresh the page and try again.', 'reason' => 'csrf'], 419);
    }
    render_error_page(419, 'Page Expired', 'Your form has expired for security reasons. Please go back, refresh the page and try again.');
}

// ---------------------------------------------------------------------------
// Flash messages (shown once with SweetAlert2)
// ---------------------------------------------------------------------------

/** @param string $type success|error|warning|info */
function flash(string $type, string $title, string $message = ''): void
{
    $_SESSION['flash'][] = ['type' => $type, 'title' => $title, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($messages) ? $messages : [];
}

// ---------------------------------------------------------------------------
// Formatting
// ---------------------------------------------------------------------------

function format_money(mixed $amount, string $currency = 'PHP'): string
{
    $symbols = ['PHP' => '₱', 'USD' => '$', 'EUR' => '€'];
    $prefix = $symbols[$currency] ?? ($currency . ' ');
    $value = (float) $amount;
    return ($value < 0 ? '-' : '') . $prefix . number_format(abs($value), 2);
}

function format_date(?string $value, string $format = 'M j, Y'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $timestamp = strtotime($value);
    return $timestamp === false ? '—' : date($format, $timestamp);
}

function format_datetime(?string $value): string
{
    return format_date($value, 'M j, Y g:i A');
}

/** "draft_status" -> "Draft Status" */
function humanize(?string $value): string
{
    return ucwords(str_replace(['_', '.'], ' ', (string) $value));
}

/** Bootstrap-compatible badge class for a status value. */
function status_badge_class(?string $status): string
{
    return match ((string) $status) {
        'active', 'posted', 'paid', 'verified', 'confirmed', 'liquidated', 'approved', 'resolved', 'issued', 'completed', 'collecting' => 'badge-soft-success',
        'pending', 'submitted', 'received_pending_verification', 'pending_approval', 'submitted_for_review', 'partially_paid', 'partially_liquidated', 'under_review', 'draft', 'recorded', 'pending_confirmation', 'pending_verification' => 'badge-soft-warning',
        'discrepancy', 'overdue', 'rejected', 'returned_for_correction', 'open', 'unpaid', 'suspended', 'locked', 'needs_correction' => 'badge-soft-danger',
        default => 'badge-soft-neutral',
    };
}

function status_badge(?string $status): string
{
    return '<span class="badge ' . status_badge_class($status) . '">' . e(humanize($status)) . '</span>';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $letters .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $letters !== '' ? $letters : '?';
}

function percent(float $part, float $whole): float
{
    return $whole > 0 ? max(0.0, min(100.0, round($part / $whole * 100, 1))) : 0.0;
}

// ---------------------------------------------------------------------------
// Logging and error handling
// ---------------------------------------------------------------------------

/** Writes a private log line. Never shown to users. */
function log_error(string $message, array $context = []): void
{
    $line = sprintf(
        "[%s] [%s] %s %s\n",
        date('Y-m-d H:i:s'),
        request_id(),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
    );

    if (is_dir(LOG_PATH) && is_writable(LOG_PATH)) {
        error_log($line, 3, LOG_PATH . '/app-' . date('Y-m') . '.log');
    } else {
        error_log(trim($line));
    }
}

function handle_uncaught_exception(Throwable $exception): void
{
    log_error('Uncaught exception', [
        'type'    => $exception::class,
        'message' => $exception->getMessage(),
        'file'    => $exception->getFile() . ':' . $exception->getLine(),
    ]);

    if ($exception instanceof DatabaseUnavailableException) {
        $status = 503;
        $title = 'Service Unavailable';
        $message = 'ANINAW cannot reach its database right now. Please try again in a few minutes or contact your system administrator.';
    } else {
        $status = 500;
        $title = 'Something Went Wrong';
        $message = 'An unexpected error occurred. The problem has been logged. Please try again.';
    }

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $title . ': ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
    if (wants_json()) {
        json_response(['ok' => false, 'message' => $message, 'reference' => request_id()], $status);
    }
    render_error_page($status, $title, $message);
}

/** Standalone error page (403, 404, 419, 500, 503). */
function render_error_page(int $status, string $title, string $message): never
{
    if (!headers_sent()) {
        http_response_code($status);
        send_security_headers();
        send_no_cache_headers();
    }
    $loggedIn = session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']);
    $icon = match ($status) {
        403 => 'bi-shield-lock',
        404 => 'bi-signpost-split',
        419 => 'bi-hourglass-split',
        503 => 'bi-database-exclamation',
        default => 'bi-exclamation-octagon',
    };
    require APP_ROOT . '/includes/error_page.php';
    exit;
}

// ---------------------------------------------------------------------------
// Small UI components shared by dashboard panels
// ---------------------------------------------------------------------------

/** Summary card with a pale-green icon. $value must already be formatted text. */
function stat_card(string $icon, string $label, string $value, string $hint = '', string $tone = 'success'): string
{
    return '<div class="stat-card">'
        . '<span class="stat-icon stat-icon-' . e($tone) . '"><i class="bi ' . e($icon) . '" aria-hidden="true"></i></span>'
        . '<div class="stat-body"><div class="stat-label">' . e($label) . '</div>'
        . '<div class="stat-value">' . e($value) . '</div>'
        . ($hint !== '' ? '<div class="stat-hint">' . e($hint) . '</div>' : '')
        . '</div></div>';
}

function empty_state(string $icon, string $title, string $text = ''): string
{
    return '<div class="empty-state"><span class="empty-icon"><i class="bi ' . e($icon) . '" aria-hidden="true"></i></span>'
        . '<div class="empty-title">' . e($title) . '</div>'
        . ($text !== '' ? '<p class="empty-text">' . e($text) . '</p>' : '')
        . '</div>';
}

function panel_error(): string
{
    return '<div class="alert alert-soft-danger mb-0" role="alert"><i class="bi bi-exclamation-triangle me-2" aria-hidden="true"></i>'
        . 'This section could not be loaded right now. Please refresh the page. If the problem continues, contact your administrator.</div>';
}

function progress_bar(float $percent): string
{
    $percent = max(0.0, min(100.0, $percent));
    return '<div class="progress progress-thin" role="progressbar" aria-valuenow="' . e((string) $percent) . '" aria-valuemin="0" aria-valuemax="100">'
        . '<div class="progress-bar" style="width: ' . e((string) $percent) . '%"></div></div>';
}
