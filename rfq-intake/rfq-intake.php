<?php
/**
 * Plugin Name: RFQ Intake
 * Plugin URI: https://github.com/SubtractManufacturing/wp-rfq-plugin
 * Update URI: https://github.com/SubtractManufacturing/wp-rfq-plugin
 * Description: Custom RFQ intake form with durable S3-backed submission.
 * x-release-please-start-version
 * Version: 0.1.0
 * x-release-please-end
 * Requires at least: 6.4
 * Requires PHP: 8.3
 * Author: Subtract Manufacturing
 * Text Domain: rfq-intake
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Apache/CGI often strips Authorization before PHP sets HTTP_AUTHORIZATION.
if ( ! isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
    if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) && is_string( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
        $_SERVER['HTTP_AUTHORIZATION'] = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
    } elseif ( function_exists( 'apache_request_headers' ) ) {
        $apache_headers = apache_request_headers();
        $authorization  = $apache_headers['Authorization'] ?? $apache_headers['authorization'] ?? null;

        if ( is_string( $authorization ) && $authorization !== '' ) {
            $_SERVER['HTTP_AUTHORIZATION'] = $authorization;
        }
    }
}

define( 'RFQ_INTAKE_PLUGIN_FILE', __FILE__ );
define( 'RFQ_INTAKE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$rfq_intake_headers = get_file_data(
    RFQ_INTAKE_PLUGIN_FILE,
    [ 'version' => 'Version' ]
);

if ( empty( $rfq_intake_headers['version'] ) ) {
    throw new RuntimeException( 'RFQ Intake plugin header is missing its version.' );
}

define( 'RFQ_INTAKE_VERSION', $rfq_intake_headers['version'] );
define( 'RFQ_INTAKE_SCHEMA_VERSION', 1 );
define( 'RFQ_MAX_PARTS', 20 );
define( 'RFQ_MAX_UPLOAD_URLS_PER_SESSION', 200 );
/** Draft session retention in WP DB before archive/delete (PRD §10). */
define( 'RFQ_DRAFT_SESSION_RETENTION_DAYS', 90 );
/** Session creation rate limit: requests per hour per IP. */
define( 'RFQ_SESSION_RATE_LIMIT', 10 );

$rfq_autoload_candidates = [
    RFQ_INTAKE_PLUGIN_DIR . 'vendor/autoload.php',
    dirname( __DIR__ ) . '/vendor/autoload.php',
];

if ( defined( 'WP_CONTENT_DIR' ) ) {
    $rfq_autoload_candidates[] = WP_CONTENT_DIR . '/rfq-plugin-root/vendor/autoload.php';
}

foreach ( $rfq_autoload_candidates as $rfq_autoload ) {
    if ( file_exists( $rfq_autoload ) ) {
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
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-contact-validator.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-postal-code.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-manifest-validator.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-receipt-service.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-webhook.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-rest-controller.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-upgrade-manager.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-activator.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-intake-maintenance.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-shortcode.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-update-checker.php';
require_once RFQ_INTAKE_PLUGIN_DIR . 'includes/class-rfq-plugin.php';

register_activation_hook( __FILE__, [ 'RFQ_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'RFQ_Intake_Maintenance', 'unschedule' ] );
add_action( 'plugins_loaded', [ 'RFQ_Plugin', 'init' ] );
