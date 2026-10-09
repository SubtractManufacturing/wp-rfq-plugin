<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_Admin_Intake_List {

    public const MENU_SLUG = 'rfq-intake-list';

    public const DEFAULT_PER_PAGE = 25;

    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [ 25, 50, 75 ];

    public const DELETE_ACTION = 'rfq_delete_intake_session';

    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ] );
        add_action( 'admin_post_' . self::DELETE_ACTION, [ self::class, 'handle_delete' ] );
    }

    public static function delete_nonce_action( string $session_id ): string {
        return self::DELETE_ACTION . '_' . $session_id;
    }

    /**
     * Result notice for the list page, keyed by `rfq_delete` query value.
     *
     * @return array{type: string, message: string}|null
     */
    public static function resolve_delete_notice( ?string $code ): ?array {
        $notices = [
            'deleted'      => [
                'success',
                __( 'Intake session deleted.', 'rfq-intake' ),
            ],
            'in_s3'        => [
                'error',
                __( 'Not deleted: files for this intake session still exist in S3.', 'rfq-intake' ),
            ],
            'check_failed' => [
                'error',
                __( 'Not deleted: S3 could not be checked. Verify the S3 settings and try again.', 'rfq-intake' ),
            ],
            'not_found'    => [
                'error',
                __( 'That intake session no longer exists.', 'rfq-intake' ),
            ],
            'error'        => [
                'error',
                __( 'The intake session could not be deleted.', 'rfq-intake' ),
            ],
        ];

        if ( $code === null || ! isset( $notices[ $code ] ) ) {
            return null;
        }

        return [
            'type'    => $notices[ $code ][0],
            'message' => $notices[ $code ][1],
        ];
    }

    public static function delete_result_code( true|WP_Error $result ): string {
        if ( $result === true ) {
            return 'deleted';
        }

        return match ( $result->get_error_code() ) {
            'rfq_session_in_s3'          => 'in_s3',
            'rfq_session_s3_check_failed',
            'rfq_s3_not_configured'      => 'check_failed',
            'rfq_session_not_found'      => 'not_found',
            default                      => 'error',
        };
    }

    public static function handle_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to delete intake sessions.', 'rfq-intake' ), '', [ 'response' => 403 ] );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below, bound to the session ID.
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['session_id'] ) ) : '';

        check_admin_referer( self::delete_nonce_action( $session_id ) );

        $s3 = RFQ_S3_Client::resolve();

        $code = $s3 instanceof WP_Error
            ? self::delete_result_code( $s3 )
            : self::delete_result_code( RFQ_Session_Deleter::delete_session( $session_id, $s3 ) );

        $args = [
            'page'       => self::MENU_SLUG,
            'rfq_delete' => $code,
        ];

        // Return to the same list position (nonce already verified above).
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $per_page = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 0;
        $paged    = isset( $_POST['paged'] ) ? (int) $_POST['paged'] : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( in_array( $per_page, self::PER_PAGE_OPTIONS, true ) ) {
            $args['per_page'] = $per_page;
        }

        if ( $paged > 1 ) {
            $args['paged'] = $paged;
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Top-level "RFQ Intake" menu opens the intake list; Settings is a
     * submenu registered by RFQ_Admin_Settings (later admin_menu priority).
     */
    public static function register_menu(): void {
        add_menu_page(
            __( 'Intake Sessions', 'rfq-intake' ),
            __( 'RFQ Intake', 'rfq-intake' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_page' ],
            'dashicons-clipboard',
            80
        );

        // Same slug as the parent relabels the auto-created first submenu item.
        add_submenu_page(
            self::MENU_SLUG,
            __( 'Intake Sessions', 'rfq-intake' ),
            __( 'Intake Sessions', 'rfq-intake' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    public static function resolve_per_page(): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin pagination; value cast to int and whitelisted.
        $requested = isset( $_GET['per_page'] ) ? (int) $_GET['per_page'] : self::DEFAULT_PER_PAGE;

        return in_array( $requested, self::PER_PAGE_OPTIONS, true )
            ? $requested
            : self::DEFAULT_PER_PAGE;
    }

    public static function resolve_page_number( ?int $total_pages = null ): int {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin pagination; value cast to int.
        $paged = isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1;
        $page  = max( 1, $paged );

        if ( $total_pages !== null ) {
            $page = min( $page, max( 1, $total_pages ) );
        }

        return $page;
    }

    public static function count_sessions(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'rfq_sessions';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
    }

    /**
     * @return list<object>
     */
    public static function query_sessions( int $per_page, int $page ): array {
        global $wpdb;

        $table  = $wpdb->prefix . 'rfq_sessions';
        $offset = ( $page - 1 ) * $per_page;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix.
                'SELECT * FROM ' . $table . ' ORDER BY created_at DESC LIMIT %d OFFSET %d',
                $per_page,
                $offset
            )
        );

        return is_array( $results ) ? $results : [];
    }

    public static function format_display_name( ?string $first, ?string $last ): string {
        $parts = [];

        if ( $first !== null && $first !== '' ) {
            $parts[] = $first;
        }

        if ( $last !== null && $last !== '' ) {
            $parts[] = $last;
        }

        return implode( ' ', $parts );
    }

    public static function format_phone( ?string $phone, ?string $country_code ): string {
        if ( $phone === null || $phone === '' || ! ctype_digit( $phone ) ) {
            return '';
        }

        if ( $country_code === null || $country_code === '' || ! ctype_digit( $country_code ) ) {
            return '';
        }

        if ( $country_code === '1' && strlen( $phone ) === 10 ) {
            return sprintf(
                '+%s (%s) %s-%s',
                $country_code,
                substr( $phone, 0, 3 ),
                substr( $phone, 3, 3 ),
                substr( $phone, 6, 4 )
            );
        }

        return sprintf( '+%s %s', $country_code, $phone );
    }

    public static function format_part_count( string $status, mixed $count ): string {
        if ( $status !== 'submitted' || $count === null ) {
            return '';
        }

        return (string) (int) $count;
    }

    public static function format_created_at( ?string $created_at ): string {
        if ( $created_at === null || $created_at === '' || $created_at === '0000-00-00 00:00:00' ) {
            return '';
        }

        $formatted = get_date_from_gmt(
            $created_at,
            get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
        );

        return is_string( $formatted ) ? $formatted : '';
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $per_page    = self::resolve_per_page();
        $total       = self::count_sessions();
        $total_pages = max( 1, (int) ceil( $total / $per_page ) );
        $page        = self::resolve_page_number( $total_pages );
        $sessions    = self::query_sessions( $per_page, $page );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result code, whitelisted by resolve_delete_notice().
        $notice = self::resolve_delete_notice( isset( $_GET['rfq_delete'] ) ? sanitize_key( (string) $_GET['rfq_delete'] ) : null );

        require RFQ_INTAKE_PLUGIN_DIR . 'admin/views/intake-list-page.php';
    }
}
