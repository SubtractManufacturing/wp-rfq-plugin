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

if (! defined('RFQ_MAX_PARTS')) {
    define('RFQ_MAX_PARTS', 20);
}

if (! defined('RFQ_DRAFT_SESSION_RETENTION_DAYS')) {
    define('RFQ_DRAFT_SESSION_RETENTION_DAYS', 90);
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

if (! isset($GLOBALS['rfq_test_filters'])) {
    $GLOBALS['rfq_test_filters'] = [];
}

if (! function_exists('apply_filters')) {
    function apply_filters($hook_name, $value, ...$args)
    {
        if (! isset($GLOBALS['rfq_test_filters'][$hook_name])) {
            return $value;
        }

        $filtered = $value;

        foreach ($GLOBALS['rfq_test_filters'][$hook_name] as $callback) {
            $filtered = $callback($filtered, ...$args);
        }

        return $filtered;
    }
}

if (! function_exists('add_filter')) {
    function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['rfq_test_filters'][$hook_name][] = $callback;

        return true;
    }
}

if (! function_exists('remove_all_filters')) {
    function remove_all_filters($hook_name)
    {
        unset($GLOBALS['rfq_test_filters'][$hook_name]);
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;

        private string $message;

        /** @var mixed */
        private $data;

        public function __construct(string $code = '', string $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data($code = '')
        {
            if ($code === '' || $code === $this->code) {
                return $this->data;
            }

            return null;
        }
    }
}

if (! function_exists('__')) {
    function __($text, $domain = 'default')
    {
        return $text;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}
