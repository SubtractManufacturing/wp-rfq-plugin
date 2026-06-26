<?php
/**
 * Plugin Name: RFQ Intake
 * Plugin URI: https://github.com/SubtractManufacturing/wp-rfq-plugin
 * Description: Custom RFQ intake form with durable S3-backed submission.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.3
 * Author: Subtract Manufacturing
 * Text Domain: rfq-intake
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RFQ_INTAKE_VERSION', '0.1.0');
define('RFQ_MAX_PARTS', 20);
define('RFQ_MAX_UPLOAD_URLS_PER_SESSION', 200);
/** Draft session retention in WP DB before archive/delete (PRD §10). */
define('RFQ_DRAFT_SESSION_RETENTION_DAYS', 90);
/** Session creation rate limit: requests per hour per IP. */
define('RFQ_SESSION_RATE_LIMIT', 10);
define('RFQ_INTAKE_PLUGIN_FILE', __FILE__);
define('RFQ_INTAKE_PLUGIN_DIR', plugin_dir_path(__FILE__));

$rfq_autoload_candidates = [
    dirname(__DIR__) . '/vendor/autoload.php',
];

if (defined('WP_CONTENT_DIR')) {
    $rfq_autoload_candidates[] = WP_CONTENT_DIR . '/rfq-plugin-root/vendor/autoload.php';
}

foreach ($rfq_autoload_candidates as $rfq_autoload) {
    if (file_exists($rfq_autoload)) {
        require_once $rfq_autoload;
        break;
    }
}

require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-secrets.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-material-catalog.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-jwt.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-rate-limiter.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-s3-client-interface.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-s3-client.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-s3-client-mock.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-s3-key-builder.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-rest-controller.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-activator.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-plugin.php';

register_activation_hook(__FILE__, ['RFQ_Activator', 'activate']);
add_action('plugins_loaded', ['RFQ_Plugin', 'init']);
