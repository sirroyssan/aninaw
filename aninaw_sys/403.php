<?php
/**
 * Access denied page (also usable as Apache ErrorDocument 403).
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

start_secure_session();
render_error_page(403, 'Access Denied', 'You do not have permission to open this page. If you believe this is a mistake, contact your institution administrator.');
