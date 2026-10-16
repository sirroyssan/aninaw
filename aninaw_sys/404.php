<?php
/**
 * Not found page (also usable as Apache ErrorDocument 404).
 */
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

start_secure_session();
render_error_page(404, 'Page Not Found', 'The page you requested does not exist or may have been moved.');
