<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Activator
{
    public static function activate(): void
    {
        self::create_tables();
    }

    public static function create_tables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $sessions_table = $wpdb->prefix . 'rfq_sessions';
        $sequences_table = $wpdb->prefix . 'rfq_receipt_sequences';

        $sql_sessions = "CREATE TABLE {$sessions_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id char(36) NOT NULL,
            status enum('draft','submitted','abandoned') NOT NULL DEFAULT 'draft',
            contact_first_name varchar(255) DEFAULT NULL,
            contact_last_name varchar(255) DEFAULT NULL,
            contact_email varchar(255) DEFAULT NULL,
            contact_company varchar(255) DEFAULT NULL,
            contact_phone char(10) DEFAULT NULL,
            contact_phone_country_code varchar(4) DEFAULT NULL,
            contact_job_title varchar(255) DEFAULT NULL,
            shipping_postal_code varchar(16) DEFAULT NULL,
            submitted_part_count int(10) unsigned DEFAULT NULL,
            receipt_number varchar(64) DEFAULT NULL,
            s3_prefix varchar(512) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            submitted_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_id (session_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        $sql_sequences = "CREATE TABLE {$sequences_table} (
            receipt_date char(8) NOT NULL,
            seq int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (receipt_date)
        ) {$charset_collate};";

        dbDelta($sql_sessions);
        dbDelta($sql_sequences);
    }
}
