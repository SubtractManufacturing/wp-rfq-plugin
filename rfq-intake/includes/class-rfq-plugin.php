<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Plugin
{
    public static function init(): void
    {
        if (is_admin()) {
            require_once RFQ_INTAKE_PLUGIN_DIR . 'admin/class-rfq-admin-settings.php';
            RFQ_Admin_Settings::init();
        }
    }
}
