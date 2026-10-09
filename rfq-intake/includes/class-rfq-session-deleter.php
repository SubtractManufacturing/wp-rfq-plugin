<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Deletes intake session rows from the WordPress database.
 *
 * Safety invariant: a non-draft row is only deleted after S3 confirms that no
 * object remains under `intake/{session_id}/`. If S3 still has objects, or S3
 * cannot answer, the row is kept. Draft rows (never submitted) skip the S3
 * check. S3 objects are never deleted by this plugin; the ERP owns that.
 */
class RFQ_Session_Deleter {

    public const OPTION_RETENTION_DAYS = 'rfq_session_retention_days';

    public const DEFAULT_RETENTION_DAYS = 180;

    public const MAX_RETENTION_DAYS = 3650;

    /** Upper bound on S3 lookups per cron run, to keep the daily job short. */
    public const MAX_CHECKS_PER_RUN = 500;

    /** Stop a run early when S3 keeps failing (outage, bad credentials). */
    public const MAX_CONSECUTIVE_ERRORS = 5;

    private const BATCH_SIZE = 100;

    /**
     * Configured retention in days. 0 means automatic deletion is disabled.
     */
    public static function get_retention_days(): int {
        $stored = get_option( self::OPTION_RETENTION_DAYS, null );

        if ( $stored === null || $stored === '' || $stored === false ) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return self::sanitize_retention_days( $stored );
    }

    public static function sanitize_retention_days( mixed $value ): int {
        if ( ! is_numeric( $value ) ) {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return max( 0, min( self::MAX_RETENTION_DAYS, (int) $value ) );
    }

    /**
     * Delete one session row, but only if S3 no longer holds its objects.
     */
    public static function delete_session( string $session_id, RFQ_S3_Client_Interface $s3 ): true|WP_Error {
        global $wpdb;

        $table = $wpdb->prefix . 'rfq_sessions';

        $status = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
                'SELECT status FROM ' . $table . ' WHERE session_id = %s',
                $session_id
            )
        );

        if ( ! is_string( $status ) ) {
            return new WP_Error(
                'rfq_session_not_found',
                __( 'Intake session not found.', 'rfq-intake' ),
                [ 'status' => 404 ]
            );
        }

        // Drafts never reached a receipt, so S3 holds nothing the ERP needs to import.
        // They are deleted without consulting S3; leftover objects are cleaned up later by the ERP.
        if ( $status !== 'draft' ) {
            $in_s3 = $s3->has_session_objects( $session_id );

            if ( $in_s3 instanceof WP_Error ) {
                return new WP_Error(
                    'rfq_session_s3_check_failed',
                    __( 'Could not verify S3 for this intake session, so it was not deleted.', 'rfq-intake' ),
                    [ 'status' => 502 ]
                );
            }

            if ( $in_s3 ) {
                return new WP_Error(
                    'rfq_session_in_s3',
                    __( 'Files for this intake session still exist in S3, so it was not deleted.', 'rfq-intake' ),
                    [ 'status' => 409 ]
                );
            }
        }

        $deleted = $wpdb->delete( $table, [ 'session_id' => $session_id ], [ '%s' ] );

        if ( $deleted === false ) {
            return new WP_Error(
                'rfq_session_delete_failed',
                __( 'Database error while deleting the intake session.', 'rfq-intake' ),
                [ 'status' => 500 ]
            );
        }

        return true;
    }

    /**
     * Delete sessions older than the retention window whose S3 data is gone.
     *
     * Sessions still present in S3 (or whose S3 check fails) are skipped and
     * the run continues with the next candidate.
     *
     * @return array{checked: int, deleted: int, retained_in_s3: int, errors: int}
     */
    public static function purge_expired( RFQ_S3_Client_Interface $s3, int $now ): array {
        global $wpdb;

        $summary = [
            'checked'        => 0,
            'deleted'        => 0,
            'retained_in_s3' => 0,
            'errors'         => 0,
        ];

        $retention_days = self::get_retention_days();

        if ( $retention_days === 0 ) {
            return $summary;
        }

        $table              = $wpdb->prefix . 'rfq_sessions';
        $cutoff             = gmdate( 'Y-m-d H:i:s', $now - ( $retention_days * DAY_IN_SECONDS ) );
        $last_id            = 0;
        $consecutive_errors = 0;

        while ( $summary['checked'] < self::MAX_CHECKS_PER_RUN ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
                    'SELECT id, session_id FROM ' . $table . ' WHERE created_at < %s AND id > %d ORDER BY id ASC LIMIT %d',
                    $cutoff,
                    $last_id,
                    self::BATCH_SIZE
                )
            );

            if ( ! is_array( $rows ) || $rows === [] ) {
                break;
            }

            foreach ( $rows as $row ) {
                if ( $summary['checked'] >= self::MAX_CHECKS_PER_RUN ) {
                    break 2;
                }

                $last_id = (int) $row->id;
                ++$summary['checked'];

                $result = self::delete_session( (string) $row->session_id, $s3 );

                if ( $result === true ) {
                    ++$summary['deleted'];
                    $consecutive_errors = 0;
                    continue;
                }

                if ( $result->get_error_code() === 'rfq_session_in_s3' ) {
                    ++$summary['retained_in_s3'];
                    $consecutive_errors = 0;
                    continue;
                }

                ++$summary['errors'];
                ++$consecutive_errors;

                if ( $consecutive_errors >= self::MAX_CONSECUTIVE_ERRORS ) {
                    error_log( 'RFQ session retention: aborting run after repeated S3 or database errors.' );
                    break 2;
                }
            }
        }

        if ( $summary['checked'] > 0 ) {
            error_log(
                sprintf(
                    'RFQ session retention: checked %d expired session(s) older than %d days; deleted %d, kept %d still in S3, %d error(s).',
                    $summary['checked'],
                    $retention_days,
                    $summary['deleted'],
                    $summary['retained_in_s3'],
                    $summary['errors']
                )
            );
        }

        return $summary;
    }
}
