<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_Admin_Settings {

    public const SETTINGS_GROUP = 'rfq_intake_settings';

    public const MENU_SLUG = 'rfq-intake-settings';

    public const RUN_CLEANUP_ACTION = 'rfq_run_retention_cleanup';

    public const RUN_CLEANUP_FORM_ID = 'rfq-run-cleanup-form';

    public static function init(): void {
        // Priority 11: the parent "RFQ Intake" menu is registered by RFQ_Admin_Intake_List at 10.
        add_action( 'admin_menu', [ self::class, 'register_menu' ], 11 );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
        add_action( 'admin_post_' . self::RUN_CLEANUP_ACTION, [ self::class, 'handle_run_cleanup' ] );
    }

    public static function register_menu(): void {
        add_submenu_page(
            RFQ_Admin_Intake_List::MENU_SLUG,
            __( 'RFQ Intake Settings', 'rfq-intake' ),
            __( 'Settings', 'rfq-intake' ),
            'manage_options',
            self::MENU_SLUG,
            [ self::class, 'render_page' ]
        );
    }

    public static function register_settings(): void {
        add_settings_section(
            'rfq_intake_s3',
            __( 'S3 storage', 'rfq-intake' ),
            [ self::class, 'render_s3_section' ],
            self::MENU_SLUG
        );
        add_settings_section(
            'rfq_intake_security',
            __( 'Security', 'rfq-intake' ),
            [ self::class, 'render_security_section' ],
            self::MENU_SLUG
        );
        add_settings_section(
            'rfq_intake_form',
            __( 'Form display', 'rfq-intake' ),
            [ self::class, 'render_form_section' ],
            self::MENU_SLUG
        );
        add_settings_section(
            'rfq_intake_erp',
            __( 'ERP webhook', 'rfq-intake' ),
            [ self::class, 'render_erp_section' ],
            self::MENU_SLUG
        );
        add_settings_section(
            'rfq_intake_catalog',
            __( 'Material catalog', 'rfq-intake' ),
            [ self::class, 'render_catalog_section' ],
            self::MENU_SLUG
        );

        add_settings_section(
            'rfq_intake_retention',
            __( 'Data retention', 'rfq-intake' ),
            [ self::class, 'render_retention_section' ],
            self::MENU_SLUG
        );

        register_setting(
            self::SETTINGS_GROUP,
            RFQ_Session_Deleter::OPTION_RETENTION_DAYS,
            [
                'type'              => 'integer',
                'default'           => RFQ_Session_Deleter::DEFAULT_RETENTION_DAYS,
                'sanitize_callback' => [ RFQ_Session_Deleter::class, 'sanitize_retention_days' ],
            ]
        );
        add_settings_field(
            RFQ_Session_Deleter::OPTION_RETENTION_DAYS,
            __( 'Auto-delete intake sessions after (days)', 'rfq-intake' ),
            [ self::class, 'render_retention_field' ],
            self::MENU_SLUG,
            'rfq_intake_retention',
            [ 'option' => RFQ_Session_Deleter::OPTION_RETENTION_DAYS ]
        );

        $text_fields = [
            'rfq_s3_endpoint'      => __( 'S3 endpoint URL', 'rfq-intake' ),
            'rfq_s3_bucket'        => __( 'S3 bucket name', 'rfq-intake' ),
            'rfq_s3_access_key_id' => __( 'S3 access key ID', 'rfq-intake' ),
            'rfq_s3_region'        => __( 'S3 region', 'rfq-intake' ),
        ];

        foreach ( $text_fields as $option => $label ) {
            register_setting(
                self::SETTINGS_GROUP,
                $option,
                [
					'type'              => 'string',
					'sanitize_callback' => [ self::class, 'sanitize_text_field' ],
				]
            );

            add_settings_field(
                $option,
                $label,
                [ self::class, 'render_text_field' ],
                self::MENU_SLUG,
                'rfq_intake_s3',
                [ 'option' => $option ]
            );
        }

        self::register_secret_field( 'rfq_s3_secret_key', __( 'S3 secret access key', 'rfq-intake' ), 'rfq_intake_s3' );
        self::register_secret_field( 'rfq_jwt_secret', __( 'JWT signing secret', 'rfq-intake' ), 'rfq_intake_security' );
        self::register_secret_field( 'rfq_erp_webhook_secret', __( 'ERP webhook shared secret', 'rfq-intake' ), 'rfq_intake_erp' );

        register_setting(
            self::SETTINGS_GROUP,
            'rfq_airtable_embed_url',
            [
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_url' ],
			]
        );
        add_settings_field(
            'rfq_airtable_embed_url',
            __( 'Airtable fallback embed URL', 'rfq-intake' ),
            [ self::class, 'render_url_field' ],
            self::MENU_SLUG,
            'rfq_intake_form',
            [ 'option' => 'rfq_airtable_embed_url' ]
        );

        $email_fields = [
            'rfq_international_rfq_email' => __( 'International RFQ email', 'rfq-intake' ),
            'rfq_sales_contact_email'     => __( 'Sales contact email', 'rfq-intake' ),
        ];

        foreach ( $email_fields as $option => $label ) {
            register_setting(
                self::SETTINGS_GROUP,
                $option,
                [
					'type'              => 'string',
					'sanitize_callback' => [ self::class, 'sanitize_email' ],
				]
            );

            add_settings_field(
                $option,
                $label,
                [ self::class, 'render_email_field' ],
                self::MENU_SLUG,
                'rfq_intake_form',
                [ 'option' => $option ]
            );
        }

        register_setting(
            self::SETTINGS_GROUP,
            'rfq_erp_webhook_url',
            [
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_url' ],
			]
        );
        add_settings_field(
            'rfq_erp_webhook_url',
            __( 'ERP import webhook URL', 'rfq-intake' ),
            [ self::class, 'render_url_field' ],
            self::MENU_SLUG,
            'rfq_intake_erp',
            [ 'option' => 'rfq_erp_webhook_url' ]
        );

        register_setting(
            self::SETTINGS_GROUP,
            'rfq_material_overrides',
            [
				'type'              => 'string',
				'sanitize_callback' => [ self::class, 'sanitize_material_overrides' ],
			]
        );
        add_settings_field(
            'rfq_material_overrides',
            __( 'Material catalog overrides (JSON)', 'rfq-intake' ),
            [ self::class, 'render_textarea_field' ],
            self::MENU_SLUG,
            'rfq_intake_catalog',
            [ 'option' => 'rfq_material_overrides' ]
        );
    }

    private static function register_secret_field( string $option, string $label, string $section ): void {
        register_setting(
            self::SETTINGS_GROUP,
            $option,
            [
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ) use ( $option ): string {
					return self::sanitize_secret_field( $option, is_string( $value ) ? $value : '' );
				},
			]
        );

        add_settings_field(
            $option,
            $label,
            [ self::class, 'render_secret_field' ],
            self::MENU_SLUG,
            $section,
            [ 'option' => $option ]
        );
    }

    public static function sanitize_secret_field( string $option_name, string $value ): string {
        $value = trim( $value );

        if ( $value === '' ) {
            $existing = get_option( $option_name, '' );

            return is_string( $existing ) ? $existing : '';
        }

        // Settings API may sanitize twice on first save; the second pass receives the encrypted blob.
        if ( RFQ_Secrets::is_encrypted_blob( $value ) ) {
            return $value;
        }

        RFQ_Secrets::set_secret( $option_name, $value );
        $stored = get_option( $option_name, '' );

        return is_string( $stored ) ? $stored : '';
    }

    public static function sanitize_text_field( mixed $value ): string {
        return sanitize_text_field( is_string( $value ) ? $value : '' );
    }

    public static function sanitize_url( mixed $value ): string {
        return esc_url_raw( is_string( $value ) ? trim( $value ) : '' );
    }

    public static function sanitize_email( mixed $value ): string {
        return sanitize_email( is_string( $value ) ? $value : '' );
    }

    public static function sanitize_material_overrides( mixed $value ): string {
        $value = is_string( $value ) ? trim( $value ) : '';

        if ( $value === '' ) {
            return '';
        }

        $decoded = json_decode( $value, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! RFQ_Material_Catalog::is_valid_override_shape( $decoded ) ) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'rfq_material_overrides_invalid',
                __( 'Material catalog overrides must be valid JSON with disabled, renamed, and added keys in the expected shape. The previous value was kept.', 'rfq-intake' ),
                'error'
            );

            $existing = get_option( 'rfq_material_overrides', '' );

            return is_string( $existing ) ? $existing : '';
        }

        return $value;
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        require RFQ_INTAKE_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    public static function render_s3_section(): void {
        echo '<p>' . esc_html__(
            'Supabase S3-compatible storage for intake uploads and receipts.',
            'rfq-intake'
        ) . '</p>';
    }

    public static function render_security_section(): void {
        echo '<p>' . esc_html__(
            'JWT signing secret is write-only. Rotating it invalidates in-flight session tokens.',
            'rfq-intake'
        ) . '</p>';
    }

    public static function render_form_section(): void {
        echo '<p>' . esc_html__(
            'Emails and fallback embed URL are shown in the RFQ form; they do not send mail from WordPress.',
            'rfq-intake'
        ) . '</p>';
    }

    public static function render_erp_section(): void {
        echo '<p>' . esc_html__(
            'Leave the webhook URL blank to skip ERP notification; import can rely on S3 polling only.',
            'rfq-intake'
        ) . '</p>';
    }

    public static function render_retention_section(): void {
        echo '<p>' . esc_html__(
            'Intake sessions older than this are removed from the WordPress database by the daily maintenance job, but only after S3 confirms their files are gone. Submitted sessions whose files still exist in S3 are kept; drafts are deleted regardless of S3. Set to 0 to disable automatic deletion.',
            'rfq-intake'
        ) . '</p>';
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_retention_field( array $args ): void {
        printf(
            '<input type="number" class="small-text" id="%1$s" name="%1$s" value="%2$d" min="0" max="%3$d" step="1" />',
            esc_attr( $args['option'] ),
            absint( RFQ_Session_Deleter::get_retention_days() ),
            absint( RFQ_Session_Deleter::MAX_RETENTION_DAYS )
        );

        // The button submits a separate form (rendered after the settings form) so it
        // cannot be confused with the settings save; nested forms are invalid HTML.
        printf(
            ' <button type="submit" form="%1$s" class="button" onclick="return confirm(%2$s);">%3$s</button>',
            esc_attr( self::RUN_CLEANUP_FORM_ID ),
            esc_attr( wp_json_encode( __( 'Run the cleanup now using the saved retention setting? Sessions still in S3 are kept.', 'rfq-intake' ) ) ),
            esc_html__( 'Run cleanup now', 'rfq-intake' )
        );

        echo '<p class="description">' . esc_html__(
            'Runs automatically once a day (WordPress cron, triggered by site visits). "Run cleanup now" uses the last saved value, so save changes first.',
            'rfq-intake'
        ) . '</p>';
    }

    public static function handle_run_cleanup(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run the cleanup.', 'rfq-intake' ), '', [ 'response' => 403 ] );
        }

        check_admin_referer( self::RUN_CLEANUP_ACTION );

        $args = [
            'page' => self::MENU_SLUG,
        ];

        $s3 = RFQ_S3_Client::resolve();

        if ( RFQ_Session_Deleter::get_retention_days() === 0 ) {
            $args['rfq_cleanup'] = 'disabled';
        } elseif ( $s3 instanceof WP_Error ) {
            $args['rfq_cleanup'] = 's3_unavailable';
        } else {
            $summary = RFQ_Session_Deleter::purge_expired( $s3, time() );

            $args['rfq_cleanup'] = 'done';
            $args['rfq_checked'] = $summary['checked'];
            $args['rfq_deleted'] = $summary['deleted'];
            $args['rfq_kept']    = $summary['retained_in_s3'];
            $args['rfq_errors']  = $summary['errors'];
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * @return array{type: string, message: string}|null
     */
    public static function resolve_cleanup_notice( ?string $code, int $checked = 0, int $deleted = 0, int $kept = 0, int $errors = 0 ): ?array {
        if ( $code === 'disabled' ) {
            return [
                'type'    => 'warning',
                'message' => __( 'Automatic deletion is disabled (retention is 0 days). Nothing was deleted.', 'rfq-intake' ),
            ];
        }

        if ( $code === 's3_unavailable' ) {
            return [
                'type'    => 'error',
                'message' => __( 'Cleanup did not run: S3 is not configured, so sessions cannot be verified.', 'rfq-intake' ),
            ];
        }

        if ( $code !== 'done' ) {
            return null;
        }

        $message = sprintf(
            /* translators: 1: sessions checked, 2: deleted, 3: kept because still in S3, 4: errors */
            __( 'Cleanup finished: %1$d expired session(s) checked, %2$d deleted, %3$d kept because files still exist in S3, %4$d could not be verified.', 'rfq-intake' ),
            $checked,
            $deleted,
            $kept,
            $errors
        );

        if ( $checked >= RFQ_Session_Deleter::MAX_CHECKS_PER_RUN ) {
            $message .= ' ' . __( 'The per-run limit was reached; run it again to continue.', 'rfq-intake' );
        }

        return [
            'type'    => $errors > 0 ? 'warning' : 'success',
            'message' => $message,
        ];
    }

    public static function render_catalog_section(): void {
        echo '<p>' . esc_html__(
            'Optional JSON overrides for the shipped default material catalog. Leave blank to use defaults only.',
            'rfq-intake'
        ) . '</p>';
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_text_field( array $args ): void {
        $option = $args['option'];
        $value  = get_option( $option, '' );

        printf(
            '<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" />',
            esc_attr( $option ),
            esc_attr( is_string( $value ) ? $value : '' )
        );
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_url_field( array $args ): void {
        $option = $args['option'];
        $value  = get_option( $option, '' );

        printf(
            '<input type="url" class="regular-text" id="%1$s" name="%1$s" value="%2$s" />',
            esc_attr( $option ),
            esc_attr( is_string( $value ) ? $value : '' )
        );
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_email_field( array $args ): void {
        $option = $args['option'];
        $value  = get_option( $option, '' );

        printf(
            '<input type="email" class="regular-text" id="%1$s" name="%1$s" value="%2$s" />',
            esc_attr( $option ),
            esc_attr( is_string( $value ) ? $value : '' )
        );
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_textarea_field( array $args ): void {
        $option = $args['option'];
        $value  = get_option( $option, '' );

        printf(
            '<textarea class="large-text code" rows="8" id="%1$s" name="%1$s">%2$s</textarea>',
            esc_attr( $option ),
            esc_textarea( is_string( $value ) ? $value : '' )
        );
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_secret_field( array $args ): void {
        $option     = $args['option'];
        $configured = RFQ_Secrets::has_secret( $option );

        printf(
            '<input type="password" class="regular-text" id="%1$s" name="%1$s" value="" autocomplete="new-password" placeholder="%2$s" />',
            esc_attr( $option ),
            esc_attr(
                $configured
                    ? __( 'Configured — enter a new value to rotate', 'rfq-intake' )
                    : ''
            )
        );

        if ( $configured ) {
            echo '<p class="description">' . esc_html__(
                'A value is configured. Leave blank to keep the current secret.',
                'rfq-intake'
            ) . '</p>';
        }
    }
}
