<?php
/**
 * Runs a tabular report from the registry (includes/reports.php): reports/view.php?r=<key>.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/reports.php';

render_report(get_str('r', 40));
