<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Shortcode::class)]
#[Group('AC-WP-009')]
class ShortcodeTest extends TestCase
{
    private string $build_dir;

    protected function setUp(): void
    {
        parent::setUp();

        global $wp_scripts, $wp_styles;

        $wp_scripts = new WP_Scripts();
        $wp_styles  = new WP_Styles();

        $this->build_dir = RFQ_INTAKE_PLUGIN_DIR . 'build';

        if ( ! is_dir( $this->build_dir ) ) {
            mkdir( $this->build_dir, 0775, true );
        }
    }

    protected function tearDown(): void
    {
        @unlink( $this->build_dir . '/rfq-form.js' );
        @unlink( $this->build_dir . '/rfq-form.css' );

        parent::tearDown();
    }

    public function test_shortcode_renders_mount_point(): void
    {
        $html = RFQ_Shortcode::render();

        $this->assertStringContainsString( 'id="rfq-form-root"', $html );
    }

    public function test_form_config_exposes_public_fields_without_secrets(): void
    {
        update_option( 'rfq_airtable_embed_url', 'https://airtable.com/embed/test' );
        update_option( 'rfq_international_rfq_email', 'intl@example.com' );
        update_option( 'rfq_sales_contact_email', 'sales@example.com' );

        $config = RFQ_Shortcode::get_form_config();

        $this->assertArrayHasKey( 'restBase', $config );
        $this->assertStringContainsString( 'rfq/v1', $config['restBase'] );
        $this->assertSame( 'https://airtable.com/embed/test', $config['airtableEmbedUrl'] );
        $this->assertSame( 'intl@example.com', $config['internationalRfqEmail'] );
        $this->assertSame( 'sales@example.com', $config['salesContactEmail'] );
        $this->assertSame( RFQ_MAX_PARTS, $config['maxParts'] );
        $this->assertIsArray( $config['materials'] );
        $this->assertArrayNotHasKey( 'token', $config );
        $this->assertArrayNotHasKey( 'jwt', $config );
    }

    public function test_enqueue_registers_assets_when_build_files_exist(): void
    {
        file_put_contents( $this->build_dir . '/rfq-form.js', 'window.rfqFormLoaded=true;' );
        file_put_contents( $this->build_dir . '/rfq-form.css', '.rfq-form-root{}' );

        $post_id = wp_insert_post(
            [
                'post_title'   => 'RFQ',
                'post_content' => '[rfq_form]',
                'post_status'  => 'publish',
            ],
            true
        );

        $this->assertIsInt( $post_id );
        $this->simulate_singular_post( (int) $post_id );

        RFQ_Shortcode::maybe_enqueue_assets();

        $this->assertTrue( wp_style_is( 'rfq-form', 'enqueued' ) );
        $this->assertTrue( wp_script_is( 'rfq-form', 'enqueued' ) );
    }

    public function test_enqueue_skipped_when_any_build_file_is_missing(): void
    {
        file_put_contents( $this->build_dir . '/rfq-form.js', 'window.rfqFormLoaded=true;' );

        $post_id = wp_insert_post(
            [
                'post_title'   => 'RFQ',
                'post_content' => '[rfq_form]',
                'post_status'  => 'publish',
            ],
            true
        );

        $this->assertIsInt( $post_id );
        $this->simulate_singular_post( (int) $post_id );

        RFQ_Shortcode::maybe_enqueue_assets();

        $this->assertFalse( wp_style_is( 'rfq-form', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'rfq-form', 'enqueued' ) );
    }

    public function test_localized_config_attached_to_enqueued_script_without_secrets(): void
    {
        global $wp_scripts;

        file_put_contents( $this->build_dir . '/rfq-form.js', 'window.rfqFormLoaded=true;' );
        file_put_contents( $this->build_dir . '/rfq-form.css', '.rfq-form-root{}' );

        RFQ_Secrets::set_secret( 'rfq_s3_secret_key', 'super-secret-s3-key' );
        RFQ_Secrets::set_secret( 'rfq_jwt_secret', 'super-secret-jwt-key' );
        RFQ_Secrets::set_secret( 'rfq_webhook_secret', 'super-secret-webhook-key' );

        $post_id = wp_insert_post(
            [
                'post_title'   => 'RFQ',
                'post_content' => '[rfq_form]',
                'post_status'  => 'publish',
            ],
            true
        );

        $this->assertIsInt( $post_id );
        $this->simulate_singular_post( (int) $post_id );

        RFQ_Shortcode::maybe_enqueue_assets();

        $data = $wp_scripts->get_data( 'rfq-form', 'data' );

        $this->assertIsString( $data );
        $this->assertStringContainsString( 'var rfqFormConfig = ', $data );
        $this->assertStringContainsString( '"restBase"', $data );
        $this->assertStringContainsString( '"materials"', $data );
        $this->assertStringNotContainsString( 'super-secret-s3-key', $data );
        $this->assertStringNotContainsString( 'super-secret-jwt-key', $data );
        $this->assertStringNotContainsString( 'super-secret-webhook-key', $data );
        $this->assertStringNotContainsString( 'rfq_s3_secret_key', $data );
        $this->assertStringNotContainsString( 'rfq_jwt_secret', $data );
        $this->assertStringNotContainsString( 'rfq_webhook_secret', $data );
    }

    public function test_enqueue_skipped_on_pages_without_shortcode(): void
    {
        file_put_contents( $this->build_dir . '/rfq-form.js', 'window.rfqFormLoaded=true;' );
        file_put_contents( $this->build_dir . '/rfq-form.css', '.rfq-form-root{}' );

        $post_id = wp_insert_post(
            [
                'post_title'   => 'Plain page',
                'post_content' => 'No form here.',
                'post_status'  => 'publish',
            ],
            true
        );

        $this->assertIsInt( $post_id );
        $this->simulate_singular_post( (int) $post_id );

        RFQ_Shortcode::maybe_enqueue_assets();

        $this->assertFalse( wp_style_is( 'rfq-form', 'enqueued' ) );
        $this->assertFalse( wp_script_is( 'rfq-form', 'enqueued' ) );
    }

    private function simulate_singular_post( int $post_id ): void
    {
        global $wp_query, $post;

        $post = get_post( $post_id );
        $this->assertInstanceOf( WP_Post::class, $post );

        $wp_query = new WP_Query(
            [
                'p'         => $post_id,
                'post_type' => 'post',
            ]
        );
        $wp_query->is_singular = true;
        $wp_query->is_single   = true;
        $wp_query->queried_object    = $post;
        $wp_query->queried_object_id = $post_id;
    }
}
