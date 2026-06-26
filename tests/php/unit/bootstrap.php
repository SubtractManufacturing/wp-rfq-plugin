<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 3) . '/');
}

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/tests/php/unit/wp-stubs.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-secrets.php';
