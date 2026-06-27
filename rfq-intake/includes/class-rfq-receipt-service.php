<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_Receipt_Service {

    /**
     * @param array<string, mixed> $manifest
     */
    public static function submit( string $session_id, array $manifest ): WP_REST_Response|WP_Error {
        $session = self::get_session_row( $session_id );

        if ( $session === null ) {
            return new WP_Error(
                'rfq_session_not_found',
                __( 'Intake session not found.', 'rfq-intake' ),
                [ 'status' => 404 ]
            );
        }

        if ( $session['status'] === 'submitted' ) {
            $receipt_number = $session['receipt_number'] ?? null;

            if ( ! is_string( $receipt_number ) || $receipt_number === '' ) {
                return new WP_Error(
                    'rfq_submit_inconsistent',
                    __( 'Submitted session is missing a receipt number.', 'rfq-intake' ),
                    [ 'status' => 500 ]
                );
            }

            return new WP_REST_Response( [ 'receipt_number' => $receipt_number ], 200 );
        }

        $manifest_session_id = $manifest['session_id'] ?? null;

        if ( ! is_string( $manifest_session_id ) || $manifest_session_id !== $session_id ) {
            return self::manifest_validation_error(
                [
					'session_id' => __( 'session_id must match the intake session.', 'rfq-intake' ),
				]
            );
        }

        $validation = RFQ_Manifest_Validator::validate( $manifest );

        if ( $validation instanceof WP_Error ) {
            return $validation;
        }

        $s3_client = RFQ_S3_Client::resolve();

        if ( $s3_client instanceof WP_Error ) {
            return $s3_client;
        }

        $file_validation = self::validate_manifest_file_keys( $session_id, $manifest, $s3_client );

        if ( $file_validation instanceof WP_Error ) {
            return $file_validation;
        }

        $existing_receipt = self::resolve_existing_receipt( $session_id, $manifest, $s3_client );

        if ( $existing_receipt instanceof WP_REST_Response ) {
            return $existing_receipt;
        }

        if ( $existing_receipt instanceof WP_Error ) {
            return $existing_receipt;
        }

        $manifest_key   = 'intake/' . $session_id . '/meta/manifest.json';
        $manifest_write = $s3_client->put_json( $manifest_key, $manifest );

        if ( $manifest_write instanceof WP_Error ) {
            return $manifest_write;
        }

        $receipt_number = self::allocate_receipt_number();

        if ( $receipt_number instanceof WP_Error ) {
            return $receipt_number;
        }

        $submitted_at    = gmdate( 'c' );
        $receipt_key     = 'intake/' . $session_id . '/meta/receipt.json';
        $receipt_payload = [
            'receipt_number' => $receipt_number,
            'session_id'     => $session_id,
            'submitted_at'   => $submitted_at,
            'manifest_key'   => $manifest_key,
        ];

        $receipt_write = $s3_client->put_json( $receipt_key, $receipt_payload );

        if ( $receipt_write instanceof WP_Error ) {
            error_log(
                sprintf(
                    'RFQ submit partial write: manifest written without receipt for session %s (%s)',
                    $session_id,
                    $receipt_write->get_error_message()
                )
            );

            return $receipt_write;
        }

        $part_count  = count( $manifest['parts'] );
        $postal_code = self::extract_postal_code( $manifest );
        $now         = current_time( 'mysql', true );

        global $wpdb;

        $updated = $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            [
                'status'               => 'submitted',
                'receipt_number'       => $receipt_number,
                'submitted_at'         => $now,
                'shipping_postal_code' => $postal_code,
                'submitted_part_count' => $part_count,
                'updated_at'           => $now,
            ],
            [
                'session_id' => $session_id,
                'status'     => 'draft',
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s' ],
            [ '%s', '%s' ]
        );

        if ( $updated === false ) {
            error_log(
                sprintf(
                    'RFQ submit partial write: receipt written to S3 without WP index row for session %s',
                    $session_id
                )
            );

            return new WP_Error(
                'rfq_submit_index_failed',
                __( 'Unable to index the receipt.', 'rfq-intake' ),
                [ 'status' => 500 ]
            );
        }

        if ( $updated === 0 ) {
            $existing_receipt = self::resolve_existing_receipt( $session_id, $manifest, $s3_client );

            if ( $existing_receipt instanceof WP_REST_Response ) {
                return $existing_receipt;
            }

            if ( $existing_receipt instanceof WP_Error ) {
                return $existing_receipt;
            }

            error_log(
                sprintf(
                    'RFQ submit index race: receipt written to S3 but WP row was not updated for session %s',
                    $session_id
                )
            );

            return new WP_Error(
                'rfq_submit_index_failed',
                __( 'Unable to index the receipt.', 'rfq-intake' ),
                [ 'status' => 500 ]
            );
        }

        /**
         * Extension point for Phase 4.3 ERP webhook notification (issue #41).
         *
         * @param string $receipt_number Allocated receipt number.
         * @param string $session_id Intake session UUID.
         * @param string $receipt_key S3 key for receipt.json.
         */
        do_action( 'rfq_receipt_created', $receipt_number, $session_id, $receipt_key );

        return new WP_REST_Response( [ 'receipt_number' => $receipt_number ], 200 );
    }

    public static function allocate_receipt_number(): string|WP_Error {
        global $wpdb;

        $table        = $wpdb->prefix . 'rfq_receipt_sequences';
        $receipt_date = gmdate( 'Ymd' );

        $inserted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
                'INSERT INTO ' . $table . ' (receipt_date, seq)
                 VALUES (%s, 1)
                 ON DUPLICATE KEY UPDATE seq = LAST_INSERT_ID(seq + 1)',
                $receipt_date
            )
        );

        if ( $inserted === false ) {
            return new WP_Error(
                'rfq_receipt_sequence_failed',
                __( 'Unable to allocate a receipt number.', 'rfq-intake' ),
                [ 'status' => 500 ]
            );
        }

        $seq = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

        if ( $seq < 1 ) {
            return new WP_Error(
                'rfq_receipt_sequence_failed',
                __( 'Unable to allocate a receipt number.', 'rfq-intake' ),
                [ 'status' => 500 ]
            );
        }

        return sprintf( 'RFQ-%s-%06d', $receipt_date, $seq );
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function validate_manifest_file_keys(
        string $session_id,
        array $manifest,
        RFQ_S3_Client_Interface $s3_client
    ): true|WP_Error {
        $expected_prefix = 'intake/' . $session_id . '/';
        $field_errors    = [];

        foreach ( $manifest['parts'] as $index => $part ) {
            if ( ! is_array( $part ) ) {
                continue;
            }

            $part_key = $part['part_file_key'] ?? null;

            if ( is_string( $part_key ) && $part_key !== '' ) {
                $error_key = 'parts[' . $index . '].part_file_key';
                $message   = self::validate_single_file_key(
                    $part_key,
                    $expected_prefix,
                    'part',
                    $s3_client
                );

                if ( $message !== null ) {
                    $field_errors[ $error_key ] = $message;
                }
            }

            $drawing_keys = $part['drawing_file_keys'] ?? [];

            if ( ! is_array( $drawing_keys ) ) {
                continue;
            }

            foreach ( $drawing_keys as $drawing_index => $drawing_key ) {
                if ( ! is_string( $drawing_key ) || $drawing_key === '' ) {
                    continue;
                }

                $error_key = 'parts[' . $index . '].drawing_file_keys[' . $drawing_index . ']';
                $message   = self::validate_single_file_key(
                    $drawing_key,
                    $expected_prefix,
                    'drawing',
                    $s3_client
                );

                if ( $message !== null ) {
                    $field_errors[ $error_key ] = $message;
                }
            }
        }

        if ( $field_errors !== [] ) {
            return self::file_validation_error( $field_errors );
        }

        return true;
    }

    private static function validate_single_file_key(
        string $key,
        string $expected_prefix,
        string $expected_category,
        RFQ_S3_Client_Interface $s3_client
    ): ?string {
        if ( str_contains( $key, '..' ) ) {
            return __( 'File key is not allowed for this session.', 'rfq-intake' );
        }

        if ( ! str_starts_with( $key, $expected_prefix ) ) {
            return __( 'File key does not belong to this session.', 'rfq-intake' );
        }

        $category = self::file_category_for_key( $key );

        if ( $category === null || $category !== $expected_category ) {
            return __( 'File key path does not match the declared file type.', 'rfq-intake' );
        }

        $head = $s3_client->head_object( $key );

        if ( $head instanceof WP_Error ) {
            return $head->get_error_message();
        }

        if ( $head === false ) {
            return __( 'Referenced file was not found in storage.', 'rfq-intake' );
        }

        $max_bytes = $category === 'part'
            ? RFQ_S3_Key_Builder::PART_MAX_BYTES
            : RFQ_S3_Key_Builder::DRAWING_MAX_BYTES;

        if ( $head['content_length'] > $max_bytes ) {
            return sprintf(
                /* translators: %s: maximum allowed file size in bytes */
                __( 'File exceeds the maximum allowed size of %d bytes.', 'rfq-intake' ),
                $max_bytes
            );
        }

        $content_type = $head['content_type'];

        if ( $content_type === null || $content_type === '' ) {
            return null;
        }

        if ( $category === 'part' && $content_type !== RFQ_S3_Key_Builder::PART_CONTENT_TYPE ) {
            return __( 'Part file content type must be application/octet-stream.', 'rfq-intake' );
        }

        if (
            $category === 'drawing'
            && ! in_array( $content_type, RFQ_S3_Key_Builder::allowed_drawing_content_types(), true )
        ) {
            return __( 'Drawing file content type is not allowed.', 'rfq-intake' );
        }

        return null;
    }

    private static function file_category_for_key( string $key ): ?string {
        if ( preg_match( '#/parts/#', $key ) === 1 ) {
            return 'part';
        }

        if ( preg_match( '#/drawings/#', $key ) === 1 ) {
            return 'drawing';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function extract_postal_code( array $manifest ): string {
        $global = $manifest['global'] ?? null;

        if ( ! is_array( $global ) ) {
            return '';
        }

        $destination = $global['shipping_destination'] ?? null;

        if ( ! is_array( $destination ) ) {
            return '';
        }

        $postal_code = $destination['postal_code'] ?? null;

        if ( ! is_string( $postal_code ) ) {
            return '';
        }

        return RFQ_Postal_Code::normalize( $postal_code );
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function resolve_existing_receipt(
        string $session_id,
        array $manifest,
        RFQ_S3_Client_Interface $s3_client
    ): WP_REST_Response|WP_Error|null {
        $session = self::get_session_row( $session_id );

        if ( $session !== null && $session['status'] === 'submitted' ) {
            $receipt_number = $session['receipt_number'] ?? null;

            if ( ! is_string( $receipt_number ) || $receipt_number === '' ) {
                return new WP_Error(
                    'rfq_submit_inconsistent',
                    __( 'Submitted session is missing a receipt number.', 'rfq-intake' ),
                    [ 'status' => 500 ]
                );
            }

            return new WP_REST_Response( [ 'receipt_number' => $receipt_number ], 200 );
        }

        $receipt_key    = 'intake/' . $session_id . '/meta/receipt.json';
        $stored_receipt = $s3_client->get_json( $receipt_key );

        if ( $stored_receipt === false ) {
            return null;
        }

        if ( $stored_receipt instanceof WP_Error ) {
            return $stored_receipt;
        }

        $receipt_number = $stored_receipt['receipt_number'] ?? null;

        if ( ! is_string( $receipt_number ) || $receipt_number === '' ) {
            return null;
        }

        self::backfill_submitted_index( $session_id, $receipt_number, $manifest );

        return new WP_REST_Response( [ 'receipt_number' => $receipt_number ], 200 );
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function backfill_submitted_index(
        string $session_id,
        string $receipt_number,
        array $manifest
    ): void {
        global $wpdb;

        $now = current_time( 'mysql', true );

        $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            [
                'status'               => 'submitted',
                'receipt_number'       => $receipt_number,
                'submitted_at'         => $now,
                'shipping_postal_code' => self::extract_postal_code( $manifest ),
                'submitted_part_count' => count( $manifest['parts'] ),
                'updated_at'           => $now,
            ],
            [
                'session_id' => $session_id,
                'status'     => 'draft',
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s' ],
            [ '%s', '%s' ]
        );
    }

    /**
     * @param array<string, string> $field_errors
     */
    private static function manifest_validation_error( array $field_errors ): WP_Error {
        return new WP_Error(
            'rfq_validation',
            __( 'Invalid manifest.', 'rfq-intake' ),
            [
                'status' => 422,
                'fields' => $field_errors,
            ]
        );
    }

    /**
     * @param array<string, string> $field_errors
     */
    private static function file_validation_error( array $field_errors ): WP_Error {
        return new WP_Error(
            'rfq_file_validation',
            __( 'One or more manifest file keys failed validation.', 'rfq-intake' ),
            [
                'status' => 422,
                'fields' => $field_errors,
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function get_session_row( string $session_id ): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT session_id, status, receipt_number
                 FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $session_id
            ),
            ARRAY_A
        );

        return is_array( $row ) ? $row : null;
    }
}
