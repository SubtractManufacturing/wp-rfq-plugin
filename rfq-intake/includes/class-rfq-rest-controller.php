<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_REST_Controller
{
    public function register_routes(): void
    {
        register_rest_route(
            'rfq/v1',
            '/health',
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'health_check'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'rfq/v1',
            '/sessions',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_session'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'rfq/v1',
            '/sessions/(?P<session_id>[a-f0-9-]+)/refresh',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'refresh_session'],
                'permission_callback' => [$this, 'authorize_session_request'],
            ]
        );
    }

    public function health_check()
    {
        $status = RFQ_S3_Client::health_check();

        if (is_wp_error($status)) {
            return $status;
        }

        return rest_ensure_response(['status' => 'ok']);
    }

    public function create_session(WP_REST_Request $request)
    {
        $ip_address = $this->resolve_ip_address($request);
        $rate_limit = RFQ_Rate_Limiter::check_session_creation_limit($ip_address);

        if (is_wp_error($rate_limit)) {
            return $rate_limit;
        }

        global $wpdb;

        $session_id = wp_generate_uuid4();
        $table = RFQ_Activator::sessions_table_name();
        $now = gmdate('Y-m-d H:i:s', (int) apply_filters('rfq_intake_now', time()));

        $inserted = $wpdb->insert(
            $table,
            [
                'session_id' => $session_id,
                'status' => 'draft',
                's3_prefix' => 'intake/' . $session_id . '/',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        if ($inserted === false || (property_exists($wpdb, 'last_error') && $wpdb->last_error !== '')) {
            return new WP_Error('rfq_session_create_failed', 'Unable to create RFQ session.', ['status' => 500]);
        }

        return rest_ensure_response(
            [
                'session_id' => $session_id,
                'token' => RFQ_JWT::issue($session_id),
            ]
        );
    }

    public function refresh_session(WP_REST_Request $request)
    {
        global $wpdb;

        $table = RFQ_Activator::sessions_table_name();
        $session_id = (string) $request['session_id'];
        $session = $wpdb->get_row(
            $wpdb->prepare("SELECT session_id, status FROM {$table} WHERE session_id = %s", $session_id),
            ARRAY_A
        );

        if (!is_array($session)) {
            return new WP_Error('rfq_session_not_found', 'RFQ session not found.', ['status' => 404]);
        }

        if (($session['status'] ?? '') === 'submitted') {
            return new WP_Error('rfq_session_submitted', 'Submitted sessions cannot be refreshed.', ['status' => 409]);
        }

        return rest_ensure_response(['token' => RFQ_JWT::issue($session_id)]);
    }

    public function authorize_session_request(WP_REST_Request $request)
    {
        $header = (string) $request->get_header('authorization');

        if (!preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return new WP_Error('rfq_missing_token', 'Missing session token.', ['status' => 401]);
        }

        try {
            $claims = RFQ_JWT::validate($matches[1], (string) $request['session_id']);
        } catch (Throwable $throwable) {
            return new WP_Error('rfq_invalid_token', 'Invalid or expired session token.', ['status' => 401]);
        }

        $request->set_attribute('rfq_session_claims', $claims);

        return true;
    }

    private function resolve_ip_address(WP_REST_Request $request): string
    {
        $forwarded = trim((string) $request->get_header('x-forwarded-for'));

        if ($forwarded !== '') {
            return trim(explode(',', $forwarded)[0]);
        }

        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}
