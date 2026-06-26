<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Admin_Settings::class)]
#[Group('AC-WP-010')]
#[Group('AC-WP-011')]
#[Group('AC-WP-020')]
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

    public function test_invalid_material_override_json_keeps_previous_value(): void
    {
        update_option('rfq_material_overrides', '{"valid":true}');

        $sanitized = RFQ_Admin_Settings::sanitize_material_overrides('{not-json');

        $this->assertSame('{"valid":true}', $sanitized);
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
