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

    public function test_valid_material_override_json_is_persisted(): void
    {
        $payload = json_encode([
            'disabled' => ['1018-steel'],
            'renamed' => ['6061-aluminum' => '6061-T6 Aluminum'],
            'added' => [
                [
                    'id' => 'brass-360',
                    'label' => '360 Brass',
                    'aliases' => ['c360'],
                    'show_in_dropdown' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $sanitized = RFQ_Admin_Settings::sanitize_material_overrides($payload);
        update_option('rfq_material_overrides', $sanitized);

        $this->assertSame($payload, get_option('rfq_material_overrides'));

        $labels = array_column(RFQ_Material_Catalog::get_effective_catalog(), 'label');

        $this->assertNotContains('1018 Steel', $labels);
        $this->assertContains('6061-T6 Aluminum', $labels);
        $this->assertContains('360 Brass', $labels);
    }

    public function test_editor_state_round_trip_produces_same_effective_catalog(): void
    {
        update_option(
            'rfq_material_overrides',
            json_encode([
                'renamed' => ['304-stainless' => '304 SS'],
            ], JSON_THROW_ON_ERROR)
        );

        $rows = RFQ_Material_Catalog::get_editor_rows();
        $overrides = RFQ_Material_Catalog::build_overrides_from_rows($rows);

        $this->assertNotNull($overrides);

        $encoded = RFQ_Material_Catalog::encode_overrides($overrides);
        $sanitized = RFQ_Admin_Settings::sanitize_material_overrides($encoded);
        update_option('rfq_material_overrides', $sanitized);

        $labels = array_column(RFQ_Material_Catalog::get_effective_catalog(), 'label');

        $this->assertContains('304 SS', $labels);
        $this->assertNotContains('304 Stainless', $labels);
    }

    public function test_get_current_tab_defaults_to_defaults(): void
    {
        unset($_GET['tab']);

        $this->assertSame(RFQ_Admin_Settings::TAB_DEFAULTS, RFQ_Admin_Settings::get_current_tab());
    }

    public function test_get_current_tab_returns_general_for_general_query(): void
    {
        $_GET['tab'] = RFQ_Admin_Settings::TAB_GENERAL;

        $this->assertSame(RFQ_Admin_Settings::TAB_GENERAL, RFQ_Admin_Settings::get_current_tab());

        unset($_GET['tab']);
    }

    public function test_get_current_tab_returns_defaults_for_defaults_query(): void
    {
        $_GET['tab'] = RFQ_Admin_Settings::TAB_DEFAULTS;

        $this->assertSame(RFQ_Admin_Settings::TAB_DEFAULTS, RFQ_Admin_Settings::get_current_tab());

        unset($_GET['tab']);
    }

    public function test_get_tab_url_includes_explicit_tab_query_for_dev(): void
    {
        $dev_url = RFQ_Admin_Settings::get_tab_url(RFQ_Admin_Settings::TAB_GENERAL);

        $this->assertStringContainsString('tab=general', $dev_url);
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
