<?php
/**
 * Collection summary: expected, collected, waived and outstanding per event and rule.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/reports.php';

render_report('collection_summary');
