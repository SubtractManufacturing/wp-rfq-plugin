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
            'callback' => [self::class, 'patch_contact'],
            'permission_callback' => [self::class, 'jwt_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/sessions/(?P<session_id>[a-f0-9-]{36})/upload-urls', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'upload_urls'],
            'permission_callback' => [self::class, 'jwt_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/sessions/(?P<session_id>[a-f0-9-]{36})/draft', [
            'methods' => 'PUT',
            'callback' => [self::class, 'put_draft'],
            'permission_callback' => [self::class, 'jwt_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/sessions/(?P<session_id>[a-f0-9-]{36})/submit', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'submit'],
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

    public static function patch_contact(WP_REST_Request $request): WP_REST_Response|WP_Error
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
                __('Submitted sessions cannot update contact information.', 'rfq-intake'),
                ['status' => 403]
            );
        }

        $params = $request->get_json_params();

        if (! is_array($params)) {
            return self::field_validation_error([
                'body' => __('Request body must be a JSON object.', 'rfq-intake'),
            ]);
        }

        $validation = RFQ_Contact_Validator::normalize_and_validate($params);

        if ($validation['errors'] !== []) {
            return self::field_validation_error($validation['errors']);
        }

        $contact = $validation['normalized'];
        $now = current_time('mysql', true);

        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            [
                'contact_first_name' => $contact['first_name'],
                'contact_last_name' => $contact['last_name'],
                'contact_email' => $contact['email'],
                'contact_company' => $contact['company'],
                'contact_phone' => $contact['phone'],
                'contact_phone_country_code' => $contact['phone_country_code'],
                'contact_job_title' => $contact['job_title'],
                'updated_at' => $now,
            ],
            ['session_id' => $session_id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%s']
        );

        if ($updated === false) {
            return new WP_Error(
                'rfq_contact_update_failed',
                __('Unable to update contact information.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        return new WP_REST_Response(self::contact_response($contact), 200);
    }

    public static function upload_urls(WP_REST_Request $request): WP_REST_Response|WP_Error
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
                __('Submitted sessions cannot request upload URLs.', 'rfq-intake'),
                ['status' => 403]
            );
        }

        if (! self::session_has_required_contact($session)) {
            return new WP_Error(
                'rfq_contact_required',
                __('Complete contact information before requesting upload URLs.', 'rfq-intake'),
                ['status' => 403]
            );
        }

        if (! RFQ_Rate_Limiter::is_upload_url_allowed($session_id)) {
            return new WP_Error(
                'rfq_upload_url_rate_limited',
                __('Upload URL limit exceeded for this session.', 'rfq-intake'),
                ['status' => 429]
            );
        }

        $params = $request->get_json_params();

        if (! is_array($params)) {
            return self::field_validation_error([
                'body' => __('Request body must be a JSON object.', 'rfq-intake'),
            ]);
        }

        $field_errors = self::validate_upload_url_params($params);

        if ($field_errors !== []) {
            return self::field_validation_error($field_errors);
        }

        $part_id = (string) $params['part_id'];
        $file_type = (string) $params['file_type'];
        $filename = (string) $params['filename'];
        $content_type = (string) $params['content_type'];

        $sanitized_filename = RFQ_S3_Key_Builder::sanitize_filename($filename);
        $file_id = RFQ_S3_Key_Builder::generate_file_id();
        $file_key = RFQ_S3_Key_Builder::build_file_key(
            $session_id,
            $file_type,
            $file_id,
            $sanitized_filename
        );

        $s3_client = RFQ_S3_Client::resolve();

        if ($s3_client instanceof WP_Error) {
            return $s3_client;
        }

        $upload_url = $s3_client->create_presigned_put(
            $file_key,
            $content_type,
            RFQ_S3_Key_Builder::max_bytes_for_file_type($file_type)
        );

        if ($upload_url instanceof WP_Error) {
            return $upload_url;
        }

        RFQ_Rate_Limiter::record_upload_url($session_id);

        return new WP_REST_Response(
            [
                'upload_url' => $upload_url,
                'file_key' => $file_key,
            ],
            200
        );
    }

    public static function put_draft(WP_REST_Request $request): WP_REST_Response|WP_Error
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
                __('Submitted sessions cannot update draft metadata.', 'rfq-intake'),
                ['status' => 403]
            );
        }

        $params = $request->get_json_params();

        if (! is_array($params)) {
            return self::field_validation_error([
                'body' => __('Request body must be a JSON object.', 'rfq-intake'),
            ]);
        }

        $sanitized = self::sanitize_draft_payload($params, $session_id);

        if ($sanitized instanceof WP_Error) {
            return $sanitized;
        }

        $draft_json = wp_json_encode($sanitized, JSON_THROW_ON_ERROR);
        $now = current_time('mysql', true);

        $update_data = [
            'draft_json' => $draft_json,
            'updated_at' => $now,
        ];
        $update_formats = ['%s', '%s'];

        $postal_code = self::extract_postal_code($sanitized);

        if ($postal_code !== null && RFQ_Postal_Code::is_valid($postal_code)) {
            $update_data['shipping_postal_code'] = RFQ_Postal_Code::normalize($postal_code);
            $update_formats[] = '%s';
        }

        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            $update_data,
            ['session_id' => $session_id],
            $update_formats,
            ['%s']
        );

        if ($updated === false) {
            return new WP_Error(
                'rfq_draft_update_failed',
                __('Unable to persist draft metadata.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        $s3_client = RFQ_S3_Client::resolve();

        if ($s3_client instanceof WP_Error) {
            return $s3_client;
        }

        $s3_key = 'intake/' . $session_id . '/meta/draft.json';
        $s3_result = $s3_client->put_json($s3_key, $sanitized);

        if ($s3_result instanceof WP_Error) {
            return $s3_result;
        }

        return new WP_REST_Response(['status' => 'saved'], 200);
    }

    public static function submit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $session_id = (string) $request->get_param('session_id');
        $params = $request->get_json_params();

        if (! is_array($params)) {
            return self::field_validation_error([
                'body' => __('Request body must be a JSON object.', 'rfq-intake'),
            ]);
        }

        $s3_client = RFQ_S3_Client::resolve();

        if ($s3_client instanceof WP_Error) {
            return $s3_client;
        }

        $result = RFQ_Receipt_Service::submit($session_id, $params, $s3_client);

        if ($result instanceof WP_Error) {
            return $result;
        }

        return new WP_REST_Response(
            ['receipt_number' => $result['receipt_number']],
            200
        );
    }

    public static function not_implemented(): WP_Error
    {
        return new WP_Error(
            'rfq_not_implemented',
            __('This endpoint is not implemented yet.', 'rfq-intake'),
            ['status' => 501]
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private static function validate_upload_url_params(array $params): array
    {
        $errors = [];

        $part_id = $params['part_id'] ?? null;

        if (! is_string($part_id) || ! RFQ_S3_Key_Builder::is_uuid($part_id)) {
            $errors['part_id'] = __('A valid part_id UUID is required.', 'rfq-intake');
        }

        $file_type = $params['file_type'] ?? null;

        if (! is_string($file_type) || ! in_array($file_type, ['part', 'drawing'], true)) {
            $errors['file_type'] = __('file_type must be part or drawing.', 'rfq-intake');
        }

        $filename = $params['filename'] ?? null;

        if (! is_string($filename) || trim($filename) === '') {
            $errors['filename'] = __('filename is required.', 'rfq-intake');
        }

        $content_type = $params['content_type'] ?? null;

        if (! is_string($content_type) || trim($content_type) === '') {
            $errors['content_type'] = __('content_type is required.', 'rfq-intake');
        } elseif (is_string($file_type) && in_array($file_type, ['part', 'drawing'], true) && is_string($content_type)) {
            if ($file_type === 'part' && $content_type !== RFQ_S3_Key_Builder::PART_CONTENT_TYPE) {
                $errors['content_type'] = __('Part uploads must use application/octet-stream.', 'rfq-intake');
            }

            if (
                $file_type === 'drawing'
                && ! in_array($content_type, RFQ_S3_Key_Builder::allowed_drawing_content_types(), true)
            ) {
                $errors['content_type'] = __('Drawing uploads must use application/pdf, image/png, or image/jpeg.', 'rfq-intake');
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $session
     */
    private static function session_has_required_contact(array $session): bool
    {
        foreach (['contact_first_name', 'contact_last_name', 'contact_email'] as $field) {
            $value = $session[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|WP_Error
     */
    private static function sanitize_draft_payload(array $params, string $session_id): array|WP_Error
    {
        $blob_fields = ['file_data', 'file_content', 'blob', 'content_base64', 'bytes'];
        $errors = [];

        foreach ($blob_fields as $field) {
            if (array_key_exists($field, $params)) {
                $errors[$field] = __('File blobs are not accepted in draft autosave.', 'rfq-intake');
            }
        }

        if ($errors !== []) {
            return self::field_validation_error($errors);
        }

        if (isset($params['contact']) && ! is_array($params['contact'])) {
            $errors['contact'] = __('contact must be an object.', 'rfq-intake');
        }

        if (isset($params['global']) && ! is_array($params['global'])) {
            $errors['global'] = __('global must be an object.', 'rfq-intake');
        }

        if (isset($params['parts']) && ! is_array($params['parts'])) {
            $errors['parts'] = __('parts must be an array.', 'rfq-intake');
        }

        if ($errors !== []) {
            return self::field_validation_error($errors);
        }

        $draft = $params;
        $draft['session_id'] = $session_id;

        if (isset($draft['parts']) && is_array($draft['parts'])) {
            $sanitized_parts = [];

            foreach ($draft['parts'] as $index => $part) {
                if (! is_array($part)) {
                    $errors['parts.' . $index] = __('Each part entry must be an object.', 'rfq-intake');

                    continue;
                }

                foreach ($blob_fields as $field) {
                    if (array_key_exists($field, $part)) {
                        $errors['parts.' . $index . '.' . $field] = __(
                            'File blobs are not accepted in draft autosave.',
                            'rfq-intake'
                        );
                    }
                }

                unset($part['upload_url']);
                $sanitized_parts[] = $part;
            }

            if ($errors !== []) {
                return self::field_validation_error($errors);
            }

            $draft['parts'] = $sanitized_parts;
        }

        return $draft;
    }

    /**
     * @param array<string, mixed> $draft
     */
    private static function extract_postal_code(array $draft): ?string
    {
        $global = $draft['global'] ?? null;

        if (! is_array($global)) {
            return null;
        }

        $destination = $global['shipping_destination'] ?? null;

        if (! is_array($destination)) {
            return null;
        }

        $postal_code = $destination['postal_code'] ?? null;

        if (! is_string($postal_code)) {
            return null;
        }

        $trimmed = trim($postal_code);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, string|null> $contact
     * @return array<string, string|null>
     */
    private static function contact_response(array $contact): array
    {
        return [
            'first_name' => $contact['first_name'],
            'last_name' => $contact['last_name'],
            'email' => $contact['email'],
            'company' => $contact['company'],
            'phone' => $contact['phone'],
            'phone_country_code' => $contact['phone_country_code'],
            'job_title' => $contact['job_title'],
        ];
    }

    /**
     * @param array<string, string> $field_errors
     */
    private static function field_validation_error(array $field_errors): WP_Error
    {
        return new WP_Error(
            'rfq_validation_error',
            __('One or more fields are invalid.', 'rfq-intake'),
            [
                'status' => 400,
                'params' => $field_errors,
            ]
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
                'SELECT session_id, status, contact_first_name, contact_last_name, contact_email
                 FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
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
