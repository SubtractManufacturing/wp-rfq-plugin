<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Receipt_Service
{
    /**
     * @param array<string, mixed> $manifest
     * @return array{receipt_number: string}|WP_Error
     */
    public static function submit(
        string $session_id,
        array $manifest,
        RFQ_S3_Client_Interface $s3_client
    ): array|WP_Error {
        $session = self::get_session_row($session_id);

        if ($session === null) {
            return new WP_Error(
                'rfq_session_not_found',
                __('Intake session not found.', 'rfq-intake'),
                ['status' => 404]
            );
        }

        if ($session['status'] === 'submitted') {
            $existing_receipt = $session['receipt_number'] ?? null;

            if (is_string($existing_receipt) && $existing_receipt !== '') {
                return ['receipt_number' => $existing_receipt];
            }

            return new WP_Error(
                'rfq_submitted_without_receipt',
                __('Session is submitted but no receipt number is recorded.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        $manifest_session_id = $manifest['session_id'] ?? null;

        if (! is_string($manifest_session_id) || $manifest_session_id !== $session_id) {
            return new WP_Error(
                'rfq_validation',
                __('Invalid manifest.', 'rfq-intake'),
                [
                    'status' => 422,
                    'fields' => [
                        'session_id' => __('session_id must match the URL session.', 'rfq-intake'),
                    ],
                ]
            );
        }

        $validation = RFQ_Manifest_Validator::validate($manifest);

        if ($validation instanceof WP_Error) {
            return $validation;
        }

        $file_errors = self::validate_manifest_file_keys($session_id, $manifest, $s3_client);

        if ($file_errors !== []) {
            return new WP_Error(
                'rfq_file_validation',
                __('One or more manifest file keys failed validation.', 'rfq-intake'),
                [
                    'status' => 422,
                    'fields' => $file_errors,
                ]
            );
        }

        $manifest_key = self::manifest_key($session_id);
        $manifest_write = $s3_client->put_json($manifest_key, $manifest);

        if ($manifest_write instanceof WP_Error) {
            return $manifest_write;
        }

        $receipt_number = self::allocate_receipt_number();

        if ($receipt_number instanceof WP_Error) {
            return $receipt_number;
        }

        $submitted_at = gmdate('Y-m-d\TH:i:s\Z');
        $receipt_key = self::receipt_key($session_id);
        $receipt_payload = [
            'receipt_number' => $receipt_number,
            'session_id' => $session_id,
            'submitted_at' => $submitted_at,
            'manifest_key' => $manifest_key,
        ];

        $receipt_write = $s3_client->put_json($receipt_key, $receipt_payload);

        if ($receipt_write instanceof WP_Error) {
            error_log(
                sprintf(
                    'RFQ Intake: receipt.json write failed after manifest for session %s: %s',
                    $session_id,
                    $receipt_write->get_error_message()
                )
            );

            return $receipt_write;
        }

        $postal_code = self::extract_postal_code($manifest);
        $part_count = count($manifest['parts']);
        $now = current_time('mysql', true);

        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            [
                'status' => 'submitted',
                'receipt_number' => $receipt_number,
                'submitted_at' => $now,
                'shipping_postal_code' => $postal_code,
                'submitted_part_count' => $part_count,
                'updated_at' => $now,
            ],
            ['session_id' => $session_id],
            ['%s', '%s', '%s', '%s', '%d', '%s'],
            ['%s']
        );

        if ($updated === false) {
            error_log(
                sprintf(
                    'RFQ Intake: WordPress receipt index update failed after S3 receipt for session %s.',
                    $session_id
                )
            );

            return new WP_Error(
                'rfq_receipt_index_failed',
                __('Unable to index receipt in WordPress.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        /**
         * Fires after receipt.json and the WordPress receipt index row are written.
         * Phase 4.3 ERP webhook notification hooks here.
         */
        do_action('rfq_after_receipt_submitted', $receipt_number, $session_id, $receipt_key);

        return ['receipt_number' => $receipt_number];
    }

    public static function allocate_receipt_number(): string|WP_Error
    {
        global $wpdb;

        $receipt_date = gmdate('Ymd');
        $table = $wpdb->prefix . 'rfq_receipt_sequences';

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (receipt_date, seq)
                 VALUES (%s, LAST_INSERT_ID(1))
                 ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)",
                $receipt_date
            )
        );

        if ($result === false) {
            return new WP_Error(
                'rfq_receipt_sequence_failed',
                __('Unable to allocate receipt number.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        $seq = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');

        if ($seq < 1) {
            return new WP_Error(
                'rfq_receipt_sequence_failed',
                __('Unable to allocate receipt number.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        return sprintf('RFQ-%s-%06d', $receipt_date, $seq);
    }

    public static function manifest_key(string $session_id): string
    {
        return 'intake/' . $session_id . '/meta/manifest.json';
    }

    public static function receipt_key(string $session_id): string
    {
        return 'intake/' . $session_id . '/meta/receipt.json';
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, string>
     */
    private static function validate_manifest_file_keys(
        string $session_id,
        array $manifest,
        RFQ_S3_Client_Interface $s3_client
    ): array {
        $errors = [];
        $parts = $manifest['parts'] ?? [];

        if (! is_array($parts)) {
            return $errors;
        }

        foreach ($parts as $index => $part) {
            if (! is_array($part)) {
                continue;
            }

            $prefix = 'parts[' . $index . ']';
            $part_file_key = $part['part_file_key'] ?? null;

            if (is_string($part_file_key) && trim($part_file_key) !== '') {
                $error = self::validate_file_key(
                    $session_id,
                    $part_file_key,
                    'part',
                    $s3_client
                );

                if ($error !== null) {
                    $errors[$prefix . '.part_file_key'] = $error;
                }
            }

            $drawing_file_keys = $part['drawing_file_keys'] ?? null;

            if (! is_array($drawing_file_keys)) {
                continue;
            }

            foreach ($drawing_file_keys as $drawing_index => $drawing_key) {
                if (! is_string($drawing_key) || trim($drawing_key) === '') {
                    continue;
                }

                $error = self::validate_file_key(
                    $session_id,
                    $drawing_key,
                    'drawing',
                    $s3_client
                );

                if ($error !== null) {
                    $errors[$prefix . '.drawing_file_keys[' . $drawing_index . ']'] = $error;
                }
            }
        }

        return $errors;
    }

    private static function validate_file_key(
        string $session_id,
        string $file_key,
        string $file_category,
        RFQ_S3_Client_Interface $s3_client
    ): ?string {
        $expected_prefix = 'intake/' . $session_id . '/';

        if (! str_starts_with($file_key, $expected_prefix)) {
            return __('File key must belong to this intake session.', 'rfq-intake');
        }

        if ($file_category === 'part' && ! str_starts_with($file_key, $expected_prefix . 'parts/')) {
            return __('Part file key must be under the session parts prefix.', 'rfq-intake');
        }

        if ($file_category === 'drawing' && ! str_starts_with($file_key, $expected_prefix . 'drawings/')) {
            return __('Drawing file key must be under the session drawings prefix.', 'rfq-intake');
        }

        $metadata = $s3_client->head_object_metadata($file_key);

        if ($metadata instanceof WP_Error) {
            return $metadata->get_error_message();
        }

        if ($metadata === false) {
            return __('Referenced file was not found in storage.', 'rfq-intake');
        }

        $max_bytes = $file_category === 'part'
            ? RFQ_S3_Key_Builder::PART_MAX_BYTES
            : RFQ_S3_Key_Builder::DRAWING_MAX_BYTES;

        if ($metadata['size'] > $max_bytes) {
            return __('Referenced file exceeds the allowed size limit.', 'rfq-intake');
        }

        $content_type = $metadata['content_type'];

        if ($content_type === null || $content_type === '') {
            return null;
        }

        if ($file_category === 'drawing' && ! in_array($content_type, RFQ_S3_Key_Builder::allowed_drawing_content_types(), true)) {
            return __('Drawing file content type is not allowed.', 'rfq-intake');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function extract_postal_code(array $manifest): ?string
    {
        $global = $manifest['global'] ?? null;

        if (! is_array($global)) {
            return null;
        }

        $destination = $global['shipping_destination'] ?? null;

        if (! is_array($destination)) {
            return null;
        }

        $postal_code = $destination['postal_code'] ?? null;

        if (! is_string($postal_code) || trim($postal_code) === '') {
            return null;
        }

        return RFQ_Postal_Code::normalize($postal_code);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function get_session_row(string $session_id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT session_id, status, receipt_number
                 FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $session_id
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}
