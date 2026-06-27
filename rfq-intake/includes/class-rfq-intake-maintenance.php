<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_Intake_Maintenance {

    public const CRON_HOOK = 'rfq_intake_maintenance_daily';

    public const ORPHAN_ALERT_TRANSIENT = 'rfq_orphan_manifest_alert';

    public const ORPHAN_MANIFEST_THRESHOLD_SECONDS = 900;

    public const UNRECEIPTED_PREFIX_DAYS = 30;

    public static function init(): void {
        add_action( self::CRON_HOOK, [ self::class, 'run' ] );
        add_action( 'admin_notices', [ self::class, 'render_admin_notice' ] );
    }

    public static function schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    public static function unschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public static function run(): void {
        $s3 = RFQ_S3_Client::resolve();

        if ( $s3 instanceof WP_Error ) {
            error_log( 'RFQ intake maintenance skipped: S3 not configured.' );

            return;
        }

        $orphans = self::find_orphan_manifest_session_ids( $s3, self::load_draft_session_ids(), time() );

        if ( $orphans !== [] ) {
            foreach ( $orphans as $session_id ) {
                error_log(
                    sprintf(
                        'RFQ orphan manifest alert: manifest without receipt for session %s (> %d minutes)',
                        $session_id,
                        self::ORPHAN_MANIFEST_THRESHOLD_SECONDS / 60
                    )
                );
            }

            set_transient(
                self::ORPHAN_ALERT_TRANSIENT,
                [
                    'session_ids' => $orphans,
                    'detected_at' => gmdate( 'c' ),
                ],
                DAY_IN_SECONDS
            );
        } else {
            delete_transient( self::ORPHAN_ALERT_TRANSIENT );
        }

        self::log_unreceipted_prefix_candidates( $s3, time() );
        self::purge_stale_draft_sessions();
    }

    /**
     * @param list<string> $draft_session_ids
     * @return list<string>
     */
    public static function find_orphan_manifest_session_ids(
        RFQ_S3_Client_Interface $s3,
        array $draft_session_ids,
        int $now
    ): array {
        $orphans = [];

        foreach ( $draft_session_ids as $session_id ) {
            if ( ! RFQ_S3_Key_Builder::is_uuid( $session_id ) ) {
                continue;
            }

            $manifest_key = 'intake/' . $session_id . '/meta/manifest.json';
            $receipt_key  = 'intake/' . $session_id . '/meta/receipt.json';

            $manifest_head = $s3->head_object( $manifest_key );

            if ( $manifest_head === false || $manifest_head instanceof WP_Error ) {
                continue;
            }

            $receipt_head = $s3->head_object( $receipt_key );

            if ( $receipt_head !== false && ! ( $receipt_head instanceof WP_Error ) ) {
                continue;
            }

            $last_modified = $manifest_head['last_modified'] ?? null;

            if ( ! is_int( $last_modified ) ) {
                continue;
            }

            if ( ( $now - $last_modified ) >= self::ORPHAN_MANIFEST_THRESHOLD_SECONDS ) {
                $orphans[] = $session_id;
            }
        }

        return $orphans;
    }

    /**
     * @return list<array{session_id: string, age_days: int}>
     */
    public static function find_unreceipted_prefix_candidates(
        RFQ_S3_Client_Interface $s3,
        int $now
    ): array {
        $prefixes = $s3->list_intake_session_prefixes();

        if ( $prefixes instanceof WP_Error ) {
            error_log(
                sprintf(
                    'RFQ unreceipted prefix scan failed: %s',
                    $prefixes->get_error_message()
                )
            );

            return [];
        }

        $threshold_seconds = self::UNRECEIPTED_PREFIX_DAYS * DAY_IN_SECONDS;
        $candidates        = [];

        foreach ( $prefixes as $prefix ) {
            $session_id    = $prefix['session_id'];
            $last_modified = $prefix['last_modified'];
            $receipt_key   = 'intake/' . $session_id . '/meta/receipt.json';
            $receipt_head  = $s3->head_object( $receipt_key );

            if ( $receipt_head !== false && ! ( $receipt_head instanceof WP_Error ) ) {
                continue;
            }

            $age_seconds = max( 0, $now - $last_modified );

            if ( $age_seconds >= $threshold_seconds ) {
                $candidates[] = [
                    'session_id' => $session_id,
                    'age_days'   => (int) floor( $age_seconds / DAY_IN_SECONDS ),
                ];
            }
        }

        return $candidates;
    }

    public static function purge_stale_draft_sessions(): int {
        global $wpdb;

        $table   = $wpdb->prefix . 'rfq_sessions';
        $cutoff  = gmdate(
            'Y-m-d H:i:s',
            time() - ( RFQ_DRAFT_SESSION_RETENTION_DAYS * DAY_IN_SECONDS )
        );
        $deleted = $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
                'DELETE FROM ' . $table . " WHERE status = 'draft' AND created_at < %s",
                $cutoff
            )
        );

        if ( $deleted === false ) {
            error_log( 'RFQ intake maintenance: failed to purge stale draft sessions.' );

            return 0;
        }

        $count = (int) $deleted;

        if ( $count > 0 ) {
            error_log(
                sprintf(
                    'RFQ intake maintenance: purged %d draft session row(s) older than %d days.',
                    $count,
                    RFQ_DRAFT_SESSION_RETENTION_DAYS
                )
            );
        }

        return $count;
    }

    public static function render_admin_notice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $alert = get_transient( self::ORPHAN_ALERT_TRANSIENT );

        if ( ! is_array( $alert ) || empty( $alert['session_ids'] ) || ! is_array( $alert['session_ids'] ) ) {
            return;
        }

        $count = count( $alert['session_ids'] );

        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
            esc_html(
                sprintf(
                    /* translators: %d: number of intake sessions with orphaned manifests */
                    _n(
                        'RFQ Intake: %d session has a manifest in S3 without a receipt for more than 15 minutes. Check error logs.',
                        'RFQ Intake: %d sessions have manifests in S3 without receipts for more than 15 minutes. Check error logs.',
                        $count,
                        'rfq-intake'
                    ),
                    $count
                )
            )
        );
    }

    /**
     * @return list<string>
     */
    private static function load_draft_session_ids(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'rfq_sessions';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
        $rows = $wpdb->get_col( 'SELECT session_id FROM ' . $table . " WHERE status = 'draft'" );

        if ( ! is_array( $rows ) ) {
            return [];
        }

        return array_values(
            array_filter(
                $rows,
                static fn( $session_id ): bool => is_string( $session_id ) && $session_id !== ''
            )
        );
    }

    private static function log_unreceipted_prefix_candidates( RFQ_S3_Client_Interface $s3, int $now ): void {
        $candidates = self::find_unreceipted_prefix_candidates( $s3, $now );

        if ( $candidates === [] ) {
            return;
        }

        $session_ids = array_column( $candidates, 'session_id' );

        error_log(
            sprintf(
                'RFQ unreceipted intake prefix candidates (%d): %s',
                count( $candidates ),
                implode( ', ', $session_ids )
            )
        );
    }
}
