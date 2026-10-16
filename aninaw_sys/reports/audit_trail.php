<?php
/**
 * Audit trail. In an institution: that institution's log (audit.view).
 * In platform context: every log entry (platform.audit.view).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/reports.php';

render_report('audit_trail');
