<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Plugin
{
    public static function init(): void
    {
        RFQ_Activator::maybe_upgrade();
        RFQ_REST_Controller::init();

        require_once RFQ_INTAKE_PLUGIN_DIR . 'admin/class-rfq-admin-settings.php';
        require_once RFQ_INTAKE_PLUGIN_DIR . 'admin/class-rfq-admin-intake-list.php';

        if (is_admin()) {
            RFQ_Admin_Settings::init();
            RFQ_Admin_Intake_List::init();
        }
    }
}
