<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers AC-WP-010
 * @covers AC-WP-011
 * @covers AC-WP-020
 */
class AdminSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        delete_option('rfq_encryption_key');
        delete_option('rfq_s3_secret_key');
        delete_option('rfq_jwt_secret');
        delete_option('rfq_material_overrides');

        RFQ_Secrets::ensure_encryption_key();
    }

    public function test_blank_secret_save_preserves_existing_value(): void
    {
        RFQ_Secrets::set_secret('rfq_s3_secret_key', 'original-secret');
        $blob_before = get_option('rfq_s3_secret_key');

        $sanitized = RFQ_Admin_Settings::sanitize_secret_field('rfq_s3_secret_key', '');
        update_option('rfq_s3_secret_key', $sanitized);

        $this->assertSame($blob_before, get_option('rfq_s3_secret_key'));
        $this->assertSame('original-secret', RFQ_Secrets::get_secret('rfq_s3_secret_key'));
    }

    public function test_nonblank_secret_save_rotates_value(): void
    {
        RFQ_Secrets::set_secret('rfq_jwt_secret', 'old-secret');

        $sanitized = RFQ_Admin_Settings::sanitize_secret_field('rfq_jwt_secret', 'new-secret');
        update_option('rfq_jwt_secret', $sanitized);

        $this->assertSame('new-secret', RFQ_Secrets::get_secret('rfq_jwt_secret'));
        $this->assertNotSame('old-secret', RFQ_Secrets::get_secret('rfq_jwt_secret'));
    }

    public function test_first_secret_save_survives_double_sanitize(): void
    {
        $first_pass = RFQ_Admin_Settings::sanitize_secret_field('rfq_s3_secret_key', 'brand-new-secret');
        $second_pass = RFQ_Admin_Settings::sanitize_secret_field('rfq_s3_secret_key', $first_pass);

        $this->assertSame($first_pass, $second_pass);
        $this->assertSame('brand-new-secret', RFQ_Secrets::get_secret('rfq_s3_secret_key'));
    }

    public function test_secret_save_persists_with_autoload_disabled(): void
    {
        RFQ_Admin_Settings::sanitize_secret_field('rfq_erp_webhook_secret', 'webhook-secret');

        global $wpdb;

        $autoload = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
                'rfq_erp_webhook_secret'
            )
        );

        $this->assertContains($autoload, ['no', 'off'], 'Secret options must not autoload');
    }

    public function test_invalid_material_override_json_keeps_previous_value(): void
    {
        update_option('rfq_material_overrides', '{"valid":true}');

        $sanitized = RFQ_Admin_Settings::sanitize_material_overrides('{not-json');

        $this->assertSame('{"valid":true}', $sanitized);
    }

    public function test_invalid_material_override_shape_keeps_previous_value(): void
    {
        update_option('rfq_material_overrides', '{"disabled":[]}');

        $sanitized = RFQ_Admin_Settings::sanitize_material_overrides('{"disabled":"not-array"}');

        $this->assertSame('{"disabled":[]}', $sanitized);
    }

    public function test_activation_bootstraps_encryption_key_and_jwt_secret(): void
    {
        delete_option('rfq_encryption_key');
        delete_option('rfq_jwt_secret');

        RFQ_Activator::bootstrap_secrets();

        $this->assertNotSame('', get_option('rfq_encryption_key'));
        $this->assertNotNull(RFQ_Secrets::get_secret('rfq_jwt_secret'));
    }
}
