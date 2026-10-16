<?php
/**
 * Stock movement history (all items, or one variant with ?variant=). Rendered by the
 * shared report engine: filters, totals, CSV and print come from the store report
 * definition 'store_movements'.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/reports.php';

render_report('store_movements');
