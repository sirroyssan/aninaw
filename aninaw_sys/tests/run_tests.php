<?php
/**
 * ANINAW automated tests for the critical financial workflows.
 * Usage (test database only):  DB_NAME=aninaw_test php tests/run_tests.php
 */
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures.php';

reset_test_database();
$members = build_fixtures();
foreach (glob(__DIR__ . '/cases/*.php') as $caseFile) {
    require $caseFile;
}
finish_tests();
