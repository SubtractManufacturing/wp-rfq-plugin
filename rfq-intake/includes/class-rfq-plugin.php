<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Plugin
{
    public static function init(): void
    {
        add_action('rest_api_init', [new RFQ_REST_Controller(), 'register_routes']);
    }
}

