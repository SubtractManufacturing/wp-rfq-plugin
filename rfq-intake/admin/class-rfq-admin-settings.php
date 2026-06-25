<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Admin_Settings
{
    public const SETTINGS_GROUP = 'rfq_intake_settings';

    public const MENU_SLUG = 'rfq-intake-settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function register_menu(): void
    {
        add_menu_page(
            __('RFQ Intake Settings', 'rfq-intake'),
            __('RFQ Intake', 'rfq-intake'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render_page'],
            'dashicons-clipboard',
            80
        );
    }

    public static function register_settings(): void
    {
        add_settings_section(
            'rfq_intake_s3',
            __('S3 storage', 'rfq-intake'),
            [self::class, 'render_s3_section'],
            self::MENU_SLUG
        );
        add_settings_section(
            'rfq_intake_security',
            __('Security', 'rfq-intake'),
            [self::class, 'render_security_section'],
            self::MENU_SLUG
        );
        add_settings_section(
            'rfq_intake_form',
            __('Form display', 'rfq-intake'),
            [self::class, 'render_form_section'],
            self::MENU_SLUG
        );
        add_settings_section(
            'rfq_intake_erp',
            __('ERP webhook', 'rfq-intake'),
            [self::class, 'render_erp_section'],
            self::MENU_SLUG
        );
        add_settings_section(
            'rfq_intake_catalog',
            __('Material catalog', 'rfq-intake'),
            [self::class, 'render_catalog_section'],
            self::MENU_SLUG
        );

        $text_fields = [
            'rfq_s3_endpoint' => __('S3 endpoint URL', 'rfq-intake'),
            'rfq_s3_bucket' => __('S3 bucket name', 'rfq-intake'),
            'rfq_s3_access_key_id' => __('S3 access key ID', 'rfq-intake'),
            'rfq_s3_region' => __('S3 region', 'rfq-intake'),
        ];

        foreach ($text_fields as $option => $label) {
            register_setting(self::SETTINGS_GROUP, $option, [
                'type' => 'string',
                'sanitize_callback' => [self::class, 'sanitize_text_field'],
            ]);

            add_settings_field(
                $option,
                $label,
                [self::class, 'render_text_field'],
                self::MENU_SLUG,
                'rfq_intake_s3',
                ['option' => $option]
            );
        }

        self::register_secret_field('rfq_s3_secret_key', __('S3 secret access key', 'rfq-intake'), 'rfq_intake_s3');
        self::register_secret_field('rfq_jwt_secret', __('JWT signing secret', 'rfq-intake'), 'rfq_intake_security');
        self::register_secret_field('rfq_erp_webhook_secret', __('ERP webhook shared secret', 'rfq-intake'), 'rfq_intake_erp');

        register_setting(self::SETTINGS_GROUP, 'rfq_airtable_embed_url', [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_url'],
        ]);
        add_settings_field(
            'rfq_airtable_embed_url',
            __('Airtable fallback embed URL', 'rfq-intake'),
            [self::class, 'render_url_field'],
            self::MENU_SLUG,
            'rfq_intake_form',
            ['option' => 'rfq_airtable_embed_url']
        );

        $email_fields = [
            'rfq_international_rfq_email' => __('International RFQ email', 'rfq-intake'),
            'rfq_sales_contact_email' => __('Sales contact email', 'rfq-intake'),
        ];

        foreach ($email_fields as $option => $label) {
            register_setting(self::SETTINGS_GROUP, $option, [
                'type' => 'string',
                'sanitize_callback' => [self::class, 'sanitize_email'],
            ]);

            add_settings_field(
                $option,
                $label,
                [self::class, 'render_email_field'],
                self::MENU_SLUG,
                'rfq_intake_form',
                ['option' => $option]
            );
        }

        register_setting(self::SETTINGS_GROUP, 'rfq_erp_webhook_url', [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_url'],
        ]);
        add_settings_field(
            'rfq_erp_webhook_url',
            __('ERP import webhook URL', 'rfq-intake'),
            [self::class, 'render_url_field'],
            self::MENU_SLUG,
            'rfq_intake_erp',
            ['option' => 'rfq_erp_webhook_url']
        );

        register_setting(self::SETTINGS_GROUP, 'rfq_material_overrides', [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_material_overrides'],
        ]);
        add_settings_field(
            'rfq_material_overrides',
            __('Material catalog overrides (JSON)', 'rfq-intake'),
            [self::class, 'render_textarea_field'],
            self::MENU_SLUG,
            'rfq_intake_catalog',
            ['option' => 'rfq_material_overrides']
        );
    }

    private static function register_secret_field(string $option, string $label, string $section): void
    {
        register_setting(self::SETTINGS_GROUP, $option, [
            'type' => 'string',
            'sanitize_callback' => static function ($value) use ($option): string {
                return self::sanitize_secret_field($option, is_string($value) ? $value : '');
            },
        ]);

        add_settings_field(
            $option,
            $label,
            [self::class, 'render_secret_field'],
            self::MENU_SLUG,
            $section,
            ['option' => $option]
        );
    }

    public static function sanitize_secret_field(string $option_name, string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            $existing = get_option($option_name, '');

            return is_string($existing) ? $existing : '';
        }

        return RFQ_Secrets::encrypt($value);
    }

    public static function sanitize_text_field(mixed $value): string
    {
        return sanitize_text_field(is_string($value) ? $value : '');
    }

    public static function sanitize_url(mixed $value): string
    {
        return esc_url_raw(is_string($value) ? trim($value) : '');
    }

    public static function sanitize_email(mixed $value): string
    {
        return sanitize_email(is_string($value) ? $value : '');
    }

    public static function sanitize_material_overrides(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return '';
        }

        json_decode($value);

        if (json_last_error() !== JSON_ERROR_NONE) {
            add_settings_error(
                self::SETTINGS_GROUP,
                'rfq_material_overrides_invalid',
                __('Material catalog overrides must be valid JSON. The previous value was kept.', 'rfq-intake'),
                'error'
            );

            $existing = get_option('rfq_material_overrides', '');

            return is_string($existing) ? $existing : '';
        }

        return $value;
    }

    public static function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        require RFQ_INTAKE_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    public static function render_s3_section(): void
    {
        echo '<p>' . esc_html__(
            'Supabase S3-compatible storage for intake uploads and receipts.',
            'rfq-intake'
        ) . '</p>';
    }

    public static function render_security_section(): void
    {
        echo '<p>' . esc_html__(
            'JWT signing secret is write-only. Rotating it invalidates in-flight session tokens.',
            'rfq-intake'
        ) . '</p>';
    }

    public static function render_form_section(): void
    {
        echo '<p>' . esc_html__(
            'Emails and fallback embed URL are shown in the RFQ form; they do not send mail from WordPress.',
            'rfq-intake'
        ) . '</p>';
    }

    public static function render_erp_section(): void
    {
        echo '<p>' . esc_html__(
            'Leave the webhook URL blank to skip ERP notification; import can rely on S3 polling only.',
            'rfq-intake'
        ) . '</p>';
    }

    public static function render_catalog_section(): void
    {
        echo '<p>' . esc_html__(
            'Optional JSON overrides for the shipped default material catalog. Leave blank to use defaults only.',
            'rfq-intake'
        ) . '</p>';
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_text_field(array $args): void
    {
        $option = $args['option'];
        $value = get_option($option, '');

        printf(
            '<input type="text" class="regular-text" id="%1$s" name="%1$s" value="%2$s" />',
            esc_attr($option),
            esc_attr(is_string($value) ? $value : '')
        );
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_url_field(array $args): void
    {
        $option = $args['option'];
        $value = get_option($option, '');

        printf(
            '<input type="url" class="regular-text" id="%1$s" name="%1$s" value="%2$s" />',
            esc_attr($option),
            esc_attr(is_string($value) ? $value : '')
        );
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_email_field(array $args): void
    {
        $option = $args['option'];
        $value = get_option($option, '');

        printf(
            '<input type="email" class="regular-text" id="%1$s" name="%1$s" value="%2$s" />',
            esc_attr($option),
            esc_attr(is_string($value) ? $value : '')
        );
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_textarea_field(array $args): void
    {
        $option = $args['option'];
        $value = get_option($option, '');

        printf(
            '<textarea class="large-text code" rows="8" id="%1$s" name="%1$s">%2$s</textarea>',
            esc_attr($option),
            esc_textarea(is_string($value) ? $value : '')
        );
    }

    /**
     * @param array{option: string} $args
     */
    public static function render_secret_field(array $args): void
    {
        $option = $args['option'];
        $configured = RFQ_Secrets::has_secret($option);

        printf(
            '<input type="password" class="regular-text" id="%1$s" name="%1$s" value="" autocomplete="new-password" placeholder="%2$s" />',
            esc_attr($option),
            esc_attr(
                $configured
                    ? __('Configured — enter a new value to rotate', 'rfq-intake')
                    : ''
            )
        );

        if ($configured) {
            echo '<p class="description">' . esc_html__(
                'A value is configured. Leave blank to keep the current secret.',
                'rfq-intake'
            ) . '</p>';
        }
    }
}
