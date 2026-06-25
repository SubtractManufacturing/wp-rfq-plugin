<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Activator
{
    public static function activate(): void
    {
        global $wpdb;

        $upgrade_file = ABSPATH . 'wp-admin/includes/upgrade.php';

        if (file_exists($upgrade_file)) {
            require_once $upgrade_file;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sessions_table = self::sessions_table_name();
        $sequences_table = self::receipt_sequences_table_name();

        dbDelta(
            "CREATE TABLE {$sessions_table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                session_id CHAR(36) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'draft',
                contact_first_name VARCHAR(255) NULL,
                contact_last_name VARCHAR(255) NULL,
                contact_email VARCHAR(255) NULL,
                contact_company VARCHAR(255) NULL,
                contact_phone CHAR(10) NULL,
                contact_phone_country_code VARCHAR(4) NULL,
                contact_job_title VARCHAR(255) NULL,
                shipping_postal_code VARCHAR(16) NULL,
                submitted_part_count INT UNSIGNED NULL,
                receipt_number VARCHAR(64) NULL,
                s3_prefix VARCHAR(512) NOT NULL,
                draft_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                submitted_at DATETIME NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY session_id (session_id),
                KEY status (status),
                KEY receipt_number (receipt_number)
            ) {$charset_collate};"
        );

        dbDelta(
            "CREATE TABLE {$sequences_table} (
                receipt_date CHAR(8) NOT NULL,
                seq INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (receipt_date)
            ) {$charset_collate};"
        );

        RFQ_Secrets::ensure_encryption_key();
        RFQ_Secrets::ensure_secret('rfq_jwt_secret', wp_generate_password(64, true, true));
    }

    public static function sessions_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'rfq_sessions';
    }

    public static function receipt_sequences_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'rfq_receipt_sequences';
    }
}
