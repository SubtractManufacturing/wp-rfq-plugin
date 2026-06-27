<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_Shortcode {

    public const TAG = 'rfq_form';

    public const SCRIPT_HANDLE = 'rfq-form';

    public const MOUNT_ID = 'rfq-form-root';

    public static function init(): void {
        add_shortcode( self::TAG, [ self::class, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ self::class, 'maybe_enqueue_assets' ] );
    }

    public static function render(): string {
        return sprintf(
            '<div id="%s"></div>',
            esc_attr( self::MOUNT_ID )
        );
    }

    public static function maybe_enqueue_assets(): void {
        if ( ! self::current_request_has_shortcode() ) {
            return;
        }

        $script_path = RFQ_INTAKE_PLUGIN_DIR . 'build/rfq-form.js';
        $style_path  = RFQ_INTAKE_PLUGIN_DIR . 'build/rfq-form.css';

        if ( ! is_readable( $script_path ) || ! is_readable( $style_path ) ) {
            return;
        }

        $build_url = plugins_url( 'build/', RFQ_INTAKE_PLUGIN_FILE );

        wp_enqueue_style(
            self::SCRIPT_HANDLE,
            $build_url . 'rfq-form.css',
            [],
            RFQ_INTAKE_VERSION
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $build_url . 'rfq-form.js',
            [],
            RFQ_INTAKE_VERSION,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'rfqFormConfig',
            self::get_form_config()
        );
    }

    /**
     * @return array{
     *   restBase: string,
     *   nonce: string,
     *   airtableEmbedUrl: string,
     *   internationalRfqEmail: string,
     *   salesContactEmail: string,
     *   maxParts: int,
     *   materials: list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}>
     * }
     */
    public static function get_form_config(): array {
        return [
            'restBase'              => rest_url( 'rfq/v1' ),
            'nonce'                 => wp_create_nonce( 'wp_rest' ),
            'airtableEmbedUrl'      => self::string_option( 'rfq_airtable_embed_url' ),
            'internationalRfqEmail' => self::string_option( 'rfq_international_rfq_email' ),
            'salesContactEmail'     => self::string_option( 'rfq_sales_contact_email' ),
            'maxParts'              => RFQ_MAX_PARTS,
            'materials'             => RFQ_Material_Catalog::get_effective_catalog(),
        ];
    }

    public static function current_request_has_shortcode(): bool {
        if ( is_singular() ) {
            $post = get_post();

            if ( $post instanceof WP_Post && has_shortcode( $post->post_content, self::TAG ) ) {
                return true;
            }
        }

        return self::query_posts_contain_shortcode();
    }

    private static function string_option( string $option_name ): string {
        $value = get_option( $option_name, '' );

        return is_string( $value ) ? $value : '';
    }

    private static function query_posts_contain_shortcode(): bool {
        if ( ! is_main_query() ) {
            return false;
        }

        global $wp_query;

        if ( ! $wp_query instanceof WP_Query || ! is_array( $wp_query->posts ) ) {
            return false;
        }

        foreach ( $wp_query->posts as $post ) {
            if ( $post instanceof WP_Post && has_shortcode( $post->post_content, self::TAG ) ) {
                return true;
            }
        }

        return false;
    }
}
