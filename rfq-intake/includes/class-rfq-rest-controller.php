<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_REST_Controller
{
    public const NAMESPACE = 'rfq/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'health_check'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/sessions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'create_session'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/sessions/(?P<session_id>[a-f0-9-]{36})/refresh', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'refresh_session'],
            'permission_callback' => [self::class, 'jwt_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/sessions/(?P<session_id>[a-f0-9-]{36})/contact', [
            'methods' => 'PATCH',
            'callback' => [self::class, 'not_implemented'],
            'permission_callback' => [self::class, 'jwt_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/sessions/(?P<session_id>[a-f0-9-]{36})/upload-urls', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'not_implemented'],
            'permission_callback' => [self::class, 'jwt_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/sessions/(?P<session_id>[a-f0-9-]{36})/draft', [
            'methods' => 'PUT',
            'callback' => [self::class, 'not_implemented'],
            'permission_callback' => [self::class, 'jwt_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/sessions/(?P<session_id>[a-f0-9-]{36})/submit', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'not_implemented'],
            'permission_callback' => [self::class, 'jwt_permission'],
        ]);
    }

    public static function health_check(): WP_REST_Response
    {
        if (! RFQ_S3_Client::is_configured() || ! RFQ_S3_Client::verify_connectivity()) {
            return new WP_REST_Response(
                ['status' => 'unavailable'],
                503
            );
        }

        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    public static function create_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $ip = self::get_client_ip();

        if (! RFQ_Rate_Limiter::is_session_creation_allowed($ip)) {
            return new WP_Error(
                'rfq_rate_limited',
                __('Session creation rate limit exceeded.', 'rfq-intake'),
                ['status' => 429]
            );
        }

        $session_id = self::generate_uuid_v4();
        $now = current_time('mysql', true);
        $s3_prefix = 'intake/' . $session_id . '/';

        global $wpdb;

        $inserted = $wpdb->insert(
            $wpdb->prefix . 'rfq_sessions',
            [
                'session_id' => $session_id,
                'status' => 'draft',
                's3_prefix' => $s3_prefix,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error(
                'rfq_session_create_failed',
                __('Unable to create intake session.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        try {
            $token = RFQ_Jwt::issue($session_id);
        } catch (Throwable $exception) {
            $wpdb->delete(
                $wpdb->prefix . 'rfq_sessions',
                ['session_id' => $session_id],
                ['%s']
            );

            return new WP_Error(
                'rfq_token_issue_failed',
                __('Unable to issue session token.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        RFQ_Rate_Limiter::record_session_creation($ip);

        return new WP_REST_Response(
            [
                'session_id' => $session_id,
                'token' => $token,
            ],
            201
        );
    }

    public static function refresh_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $session_id = (string) $request->get_param('session_id');
        $session = self::get_session_row($session_id);

        if ($session === null) {
            return new WP_Error(
                'rfq_session_not_found',
                __('Intake session not found.', 'rfq-intake'),
                ['status' => 404]
            );
        }

        if ($session['status'] === 'submitted') {
            return new WP_Error(
                'rfq_session_submitted',
                __('Submitted sessions cannot be refreshed.', 'rfq-intake'),
                ['status' => 403]
            );
        }

        try {
            $token = RFQ_Jwt::issue($session_id);
        } catch (Throwable $exception) {
            return new WP_Error(
                'rfq_token_issue_failed',
                __('Unable to issue session token.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response(['token' => $token], 200);
    }

    public static function not_implemented(): WP_Error
    {
        return new WP_Error(
            'rfq_not_implemented',
            __('This endpoint is not implemented yet.', 'rfq-intake'),
            ['status' => 501]
        );
    }

    public static function jwt_permission(WP_REST_Request $request): bool|WP_Error
    {
        $session_id = (string) $request->get_param('session_id');
        $authorization = $request->get_header('authorization');

        if (! is_string($authorization) || ! preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            return new WP_Error(
                'rfq_unauthorized',
                __('A valid Bearer token is required.', 'rfq-intake'),
                ['status' => 401]
            );
        }

        try {
            RFQ_Jwt::validate($matches[1], $session_id);
        } catch (Throwable $exception) {
            return new WP_Error(
                'rfq_unauthorized',
                __('The session token is invalid or expired.', 'rfq-intake'),
                ['status' => 401]
            );
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function get_session_row(string $session_id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT session_id, status FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $session_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    private static function get_client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
    }

    private static function generate_uuid_v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }
}
