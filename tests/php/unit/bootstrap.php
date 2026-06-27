<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 3) . '/');
}

if (! defined('RFQ_INTAKE_PLUGIN_DIR')) {
    define('RFQ_INTAKE_PLUGIN_DIR', dirname(__DIR__, 3) . '/rfq-intake/');
}

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/tests/php/unit/wp-stubs.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-secrets.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-material-catalog.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-jwt.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-rate-limiter.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-s3-client-interface.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-s3-client.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-s3-client-mock.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-s3-key-builder.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-contact-validator.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-postal-code.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-manifest-validator.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-receipt-service.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-webhook.php';
require_once dirname(__DIR__, 3) . '/rfq-intake/includes/class-rfq-intake-monitor.php';
