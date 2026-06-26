<?php

declare(strict_types=1);

/**
 * WordPress integration test bootstrap for RFQ Intake.
 */

define(
    'WP_TESTS_PHPUNIT_POLYFILLS_PATH',
    dirname(__DIR__, 3) . '/vendor/yoast/phpunit-polyfills'
);

$_tests_dir = getenv('WP_TESTS_DIR');

if (! $_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

if (! file_exists("{$_tests_dir}/includes/functions.php")) {
    fwrite(
        STDERR,
        "Could not find {$_tests_dir}/includes/functions.php. Run integration tests via wp-env tests-cli.\n"
    );
    exit(1);
}

require_once "{$_tests_dir}/includes/functions.php";

tests_add_filter(
    'muplugins_loaded',
    static function (): void {
        require dirname(__DIR__, 3) . '/rfq-intake/rfq-intake.php';
        // Admin settings class is only loaded in is_admin(); tests need it directly.
        require dirname(__DIR__, 3) . '/rfq-intake/admin/class-rfq-admin-settings.php';
    }
);

require "{$_tests_dir}/includes/bootstrap.php";
