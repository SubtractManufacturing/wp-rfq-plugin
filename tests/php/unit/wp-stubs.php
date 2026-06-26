<?php

declare(strict_types=1);

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (! defined('RFQ_SESSION_RATE_LIMIT')) {
    define('RFQ_SESSION_RATE_LIMIT', 10);
}

if (! defined('RFQ_MAX_UPLOAD_URLS_PER_SESSION')) {
    define('RFQ_MAX_UPLOAD_URLS_PER_SESSION', 200);
}

if (! isset($GLOBALS['rfq_test_options'])) {
    $GLOBALS['rfq_test_options'] = [];
}

if (! isset($GLOBALS['rfq_test_transients'])) {
    $GLOBALS['rfq_test_transients'] = [];
}

if (! function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return $GLOBALS['rfq_test_options'][$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option($option, $value, $autoload = null)
    {
        $GLOBALS['rfq_test_options'][$option] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option($option)
    {
        unset($GLOBALS['rfq_test_options'][$option]);

        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient($transient)
    {
        if (! isset($GLOBALS['rfq_test_transients'][$transient])) {
            return false;
        }

        $entry = $GLOBALS['rfq_test_transients'][$transient];

        if ($entry['expires_at'] !== 0 && $entry['expires_at'] < time()) {
            unset($GLOBALS['rfq_test_transients'][$transient]);

            return false;
        }

        return $entry['value'];
    }
}

if (! function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0)
    {
        $GLOBALS['rfq_test_transients'][$transient] = [
            'value' => $value,
            'expires_at' => $expiration > 0 ? time() + (int) $expiration : 0,
        ];

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient($transient)
    {
        unset($GLOBALS['rfq_test_transients'][$transient]);

        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters($hook_name, $value, ...$args)
    {
        return $value;
    }
}
