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
    public static function submit(string $session_id, array $manifest): array|WP_Error
    {
        $session = self::get_session_row($session_id);

        if ($session === null) {
            return new WP_Error(
                'rfq_session_not_found',
                __('Intake session not found.', 'rfq-intake'),
                ['status' => 404]
            );
        }

        if ($session['status'] === 'submitted') {
            $receipt_number = $session['receipt_number'];

            if (! is_string($receipt_number) || $receipt_number === '') {
                return new WP_Error(
                    'rfq_receipt_missing',
                    __('Submitted session is missing a receipt number.', 'rfq-intake'),
                    ['status' => 500]
                );
            }

            return ['receipt_number' => $receipt_number];
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

        $s3_client = RFQ_S3_Client::resolve();

        if ($s3_client instanceof WP_Error) {
            return $s3_client;
        }

        $file_validation = self::validate_manifest_files($session_id, $manifest, $s3_client);

        if ($file_validation instanceof WP_Error) {
            return $file_validation;
        }

        $manifest_key = RFQ_S3_Key_Builder::manifest_meta_key($session_id);
        $manifest_write = $s3_client->put_json($manifest_key, $manifest);

        if ($manifest_write instanceof WP_Error) {
            return $manifest_write;
        }

        $receipt_number = self::allocate_receipt_number();

        if ($receipt_number instanceof WP_Error) {
            self::log_partial_write($session_id, $manifest_key, 'receipt_number_allocation_failed');

            return $receipt_number;
        }

        $submitted_at_utc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $receipt_key = RFQ_S3_Key_Builder::receipt_meta_key($session_id);
        $receipt_payload = [
            'receipt_number' => $receipt_number,
            'session_id' => $session_id,
            'submitted_at' => $submitted_at_utc,
            'manifest_key' => $manifest_key,
        ];

        $receipt_write = $s3_client->put_json($receipt_key, $receipt_payload);

        if ($receipt_write instanceof WP_Error) {
            self::log_partial_write($session_id, $manifest_key, 'receipt_json_write_failed');

            return $receipt_write;
        }

        $postal_code = RFQ_Postal_Code::normalize(
            (string) $manifest['global']['shipping_destination']['postal_code']
        );
        $part_count = count($manifest['parts']);
        $submitted_at_mysql = current_time('mysql', true);

        if (! self::mark_session_submitted(
            $session_id,
            $receipt_number,
            $submitted_at_mysql,
            $postal_code,
            $part_count
        )) {
            self::log_partial_write($session_id, $manifest_key, 'session_row_update_failed');

            return new WP_Error(
                'rfq_submit_persist_failed',
                __('Unable to persist submitted session.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        /**
         * Extension point for Phase 4.3 ERP webhook notification.
         *
         * @param string $receipt_number
         * @param string $session_id
         * @param string $receipt_key
         */
        do_action('rfq_after_receipt_durable', $receipt_number, $session_id, $receipt_key);

        return ['receipt_number' => $receipt_number];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function validate_manifest_files(
        string $session_id,
        array $manifest,
        RFQ_S3_Client_Interface $s3_client
    ): true|WP_Error {
        $failed_keys = [];
        $session_prefix = RFQ_S3_Key_Builder::session_prefix($session_id);

        foreach ($manifest['parts'] as $part) {
            if (! is_array($part)) {
                continue;
            }

            $part_file_key = $part['part_file_key'] ?? null;

            if (is_string($part_file_key) && $part_file_key !== '') {
                $error = self::validate_file_key(
                    $part_file_key,
                    $session_prefix,
                    'part',
                    $s3_client
                );

                if ($error !== null) {
                    $failed_keys[$part_file_key] = $error;
                }
            }

            $drawing_file_keys = $part['drawing_file_keys'] ?? [];

            if (! is_array($drawing_file_keys)) {
                continue;
            }

            foreach ($drawing_file_keys as $drawing_key) {
                if (! is_string($drawing_key) || $drawing_key === '') {
                    continue;
                }

                $error = self::validate_file_key(
                    $drawing_key,
                    $session_prefix,
                    'drawing',
                    $s3_client
                );

                if ($error !== null) {
                    $failed_keys[$drawing_key] = $error;
                }
            }
        }

        if ($failed_keys !== []) {
            return new WP_Error(
                'rfq_submit_files_invalid',
                __('One or more manifest file keys failed validation.', 'rfq-intake'),
                [
                    'status' => 422,
                    'failed_keys' => $failed_keys,
                ]
            );
        }

        return true;
    }

    private static function validate_file_key(
        string $key,
        string $session_prefix,
        string $file_type,
        RFQ_S3_Client_Interface $s3_client
    ): ?string {
        if (! str_starts_with($key, $session_prefix)) {
            return __('File key does not belong to this session.', 'rfq-intake');
        }

        $expected_subdir = $file_type === 'part' ? '/parts/' : '/drawings/';

        if (! str_contains($key, $expected_subdir)) {
            return __('File key path does not match the declared file category.', 'rfq-intake');
        }

        $metadata = $s3_client->head_object_metadata($key);

        if ($metadata instanceof WP_Error) {
            return $metadata->get_error_message();
        }

        if ($metadata === false) {
            return __('Object not found in storage.', 'rfq-intake');
        }

        $max_bytes = RFQ_S3_Key_Builder::max_bytes_for_file_type($file_type);

        if ($metadata['content_length'] > $max_bytes) {
            return __('Object exceeds the allowed file size.', 'rfq-intake');
        }

        if ($file_type === 'part' && $metadata['content_type'] !== RFQ_S3_Key_Builder::PART_CONTENT_TYPE) {
            return __('Object content type does not match the part file category.', 'rfq-intake');
        }

        if (
            $file_type === 'drawing'
            && ! in_array($metadata['content_type'], RFQ_S3_Key_Builder::allowed_drawing_content_types(), true)
        ) {
            return __('Object content type does not match the drawing file category.', 'rfq-intake');
        }

        return null;
    }

    private static function allocate_receipt_number(): string|WP_Error
    {
        global $wpdb;

        $receipt_date = gmdate('Ymd');
        $sequences_table = $wpdb->prefix . 'rfq_receipt_sequences';

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$sequences_table} (receipt_date, seq) VALUES (%s, 1)
                 ON DUPLICATE KEY UPDATE seq = seq + 1",
                $receipt_date
            )
        );

        if ($inserted === false) {
            return new WP_Error(
                'rfq_receipt_sequence_failed',
                __('Unable to allocate receipt number.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        $seq = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT seq FROM {$sequences_table} WHERE receipt_date = %s",
                $receipt_date
            )
        );

        if (! is_numeric($seq) || (int) $seq < 1) {
            return new WP_Error(
                'rfq_receipt_sequence_failed',
                __('Unable to allocate receipt number.', 'rfq-intake'),
                ['status' => 500]
            );
        }

        return sprintf('RFQ-%s-%06d', $receipt_date, (int) $seq);
    }

    private static function mark_session_submitted(
        string $session_id,
        string $receipt_number,
        string $submitted_at,
        string $postal_code,
        int $part_count
    ): bool {
        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            [
                'status' => 'submitted',
                'receipt_number' => $receipt_number,
                'submitted_at' => $submitted_at,
                'shipping_postal_code' => $postal_code,
                'submitted_part_count' => $part_count,
                'updated_at' => $submitted_at,
            ],
            [
                'session_id' => $session_id,
                'status' => 'draft',
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s'],
            ['%s', '%s']
        );

        return $updated === 1;
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

    private static function log_partial_write(string $session_id, string $manifest_key, string $reason): void
    {
        error_log(
            sprintf(
                'RFQ Intake partial submit write for session %s (%s): manifest at %s without durable receipt.',
                $session_id,
                $reason,
                $manifest_key
            )
        );
    }
}
