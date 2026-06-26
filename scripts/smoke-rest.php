<?php

/**
 * REST smoke checks via WordPress REST dispatch (wp-env tests-cli).
 * Run: npx wp-env run tests-cli --env-cwd=wp-content/rfq-plugin-root wp eval-file scripts/smoke-rest.php
 *
 * Clears S3 options in the current WordPress DB for deterministic health checks.
 * Must run on tests-cli only — never on the dev site (cli / localhost:8888).
 */

if (! function_exists('rest_do_request')) {
    fwrite(STDERR, "WordPress REST API is unavailable. Run via wp-env tests-cli.\n");
    exit(1);
}

if (! class_exists('RFQ_S3_Client')) {
    $plugin_file = WP_CONTENT_DIR . '/plugins/rfq-intake/rfq-intake.php';

    if (is_readable($plugin_file)) {
        require_once $plugin_file;
    }
}

if (class_exists('RFQ_REST_Controller')) {
    $routes = rest_get_server()->get_routes();

    if (! isset($routes['/rfq/v1/health'])) {
        RFQ_REST_Controller::register_routes();
    }
}

$pass = 0;
$fail = 0;

$assert_status = static function (string $label, WP_REST_Response|WP_Error $response, int $expected) use (&$pass, &$fail): void {
    $actual = $response instanceof WP_Error
        ? (int) ($response->get_error_data()['status'] ?? 500)
        : $response->get_status();

    if ($actual === $expected) {
        echo "  ok  {$label} (HTTP {$actual})\n";
        $pass++;
        return;
    }

    $detail = $response instanceof WP_Error ? $response->get_error_code() : '';
    fwrite(STDERR, "  FAIL  {$label} (expected HTTP {$expected}, got {$actual}{$detail})\n");
    $fail++;
};

global $wpdb;

RFQ_Activator::activate();
$wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_rfq_sessions_%',
        '_transient_timeout_rfq_sessions_%'
    )
);

delete_option('rfq_s3_endpoint');
delete_option('rfq_s3_bucket');
delete_option('rfq_s3_access_key_id');
delete_option('rfq_s3_region');
delete_option('rfq_s3_secret_key');

RFQ_Secrets::ensure_encryption_key();
$jwt_secret = RFQ_Secrets::get_secret('rfq_jwt_secret');
if (! is_string($jwt_secret) || strlen($jwt_secret) < 32) {
    RFQ_Secrets::set_secret('rfq_jwt_secret', bin2hex(random_bytes(32)));
}

$_SERVER['REMOTE_ADDR'] = '198.51.100.200';

echo "REST smoke (WordPress REST dispatch)\n\n";

echo "1. GET /health (no S3 config)\n";
$assert_status('health unavailable', rest_do_request(new WP_REST_Request('GET', '/rfq/v1/health')), 503);

echo "2. POST /sessions\n";
$create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
$assert_status('create session', $create, 201);

$data = $create->get_data();
if (! is_array($data) || empty($data['session_id']) || empty($data['token'])) {
    fwrite(STDERR, "  FAIL  create session response missing session_id or token\n");
    $fail++;
} else {
    echo "  ok  create session payload includes session_id and token\n";
    $pass++;
}

$session_id = is_array($data) ? (string) ($data['session_id'] ?? '') : '';
$token = is_array($data) ? (string) ($data['token'] ?? '') : '';

echo "3. POST /sessions/{id}/refresh\n";
$refresh = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/refresh');
$refresh->set_header('Authorization', 'Bearer ' . $token);
$refresh_response = rest_do_request($refresh);
$assert_status('refresh session', $refresh_response, 200);

$new_token = is_array($refresh_response->get_data())
    ? (string) ($refresh_response->get_data()['token'] ?? '')
    : '';

if ($new_token !== '' && $new_token !== $token) {
    echo "  ok  refresh issued new token\n";
    $pass++;
} else {
    fwrite(STDERR, "  FAIL  refresh returned identical or empty token\n");
    $fail++;
}

echo "4. POST /sessions/{id}/refresh (invalid token)\n";
$invalid = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/refresh');
$invalid->set_header('Authorization', 'Bearer invalid-token');
$assert_status('reject invalid token', rest_do_request($invalid), 401);

echo "5. POST /sessions/{id}/upload-urls (later phase stub)\n";
$stub = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/upload-urls');
$stub->set_header('Authorization', 'Bearer ' . $new_token);
$assert_status('upload-urls not implemented', rest_do_request($stub), 501);

echo "6. Rate limit (11 creates from test IP)\n";
$_SERVER['REMOTE_ADDR'] = '198.51.100.201';
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_rfq_sessions_%',
        '_transient_timeout_rfq_sessions_%'
    )
);

if (function_exists('wp_cache_flush')) {
    wp_cache_flush();
}

$allowed = 0;
$limited = 0;
for ($index = 0; $index < 11; $index++) {
    $response = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
    $status = $response instanceof WP_Error
        ? (int) ($response->get_error_data()['status'] ?? 500)
        : $response->get_status();

    if ($status === 201) {
        $allowed++;
        continue;
    }

    if ($status === 429) {
        $limited++;
        continue;
    }

    fwrite(STDERR, "  FAIL  rate-limit probe request " . ($index + 1) . " returned HTTP {$status}\n");
    $fail++;
    break;
}

if ($allowed === 10 && $limited === 1) {
    echo "  ok  rate limit (10 allowed, 11th rejected)\n";
    $pass++;
} elseif ($fail === 0) {
    fwrite(STDERR, "  FAIL  rate limit (expected 10x201 + 1x429, got {$allowed}x201 + {$limited}x429)\n");
    $fail++;
}

echo "\n";
if ($fail === 0) {
    echo "REST smoke passed ({$pass} checks).\n";
    exit(0);
}

fwrite(STDERR, "REST smoke failed ({$fail} check(s), {$pass} passed).\n");
exit(1);
