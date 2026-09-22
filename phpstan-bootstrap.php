<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/phpstan-stubs/');
define('RFQ_INTAKE_VERSION', '0.1.0');
define('RFQ_INTAKE_SCHEMA_VERSION', 1);
define('RFQ_MAX_PARTS', 20);
define('RFQ_MAX_UPLOAD_URLS_PER_SESSION', 200);
define('RFQ_DRAFT_SESSION_RETENTION_DAYS', 90);
define('RFQ_SESSION_RATE_LIMIT', 10);
define('RFQ_INTAKE_PLUGIN_FILE', __DIR__ . '/rfq-intake/rfq-intake.php');
define('RFQ_INTAKE_PLUGIN_DIR', __DIR__ . '/rfq-intake/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/vendor/autoload.php';
