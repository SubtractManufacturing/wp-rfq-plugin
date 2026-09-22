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

if (! defined('RFQ_DRAFT_SESSION_RETENTION_DAYS')) {
    define('RFQ_DRAFT_SESSION_RETENTION_DAYS', 90);
}

if (! defined('RFQ_MAX_PARTS')) {
    define('RFQ_MAX_PARTS', 20);
}

if (! defined('RFQ_INTAKE_SCHEMA_VERSION')) {
    define('RFQ_INTAKE_SCHEMA_VERSION', 1);
}

if (! defined('RFQ_INTAKE_PLUGIN_FILE')) {
    define('RFQ_INTAKE_PLUGIN_FILE', dirname(__DIR__, 3) . '/rfq-intake/rfq-intake.php');
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

if (! function_exists('add_option')) {
    function add_option($option, $value = '', $deprecated = '', $autoload = 'yes')
    {
        unset($deprecated, $autoload);

        if (array_key_exists($option, $GLOBALS['rfq_test_options'])) {
            return false;
        }

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

if (! function_exists('esc_html__')) {
    function esc_html__($text, $domain = 'default')
    {
        unset($domain);

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('plugin_basename')) {
    function plugin_basename($file)
    {
        return 'rfq-intake/' . basename($file);
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (! isset($GLOBALS['rfq_test_http_requests'])) {
    $GLOBALS['rfq_test_http_requests'] = [];
}

if (! isset($GLOBALS['rfq_test_error_logs'])) {
    $GLOBALS['rfq_test_error_logs'] = [];
}

if (! isset($GLOBALS['rfq_test_actions'])) {
    $GLOBALS['rfq_test_actions'] = [];
}

if (! function_exists('error_log')) {
    function error_log($message)
    {
        $GLOBALS['rfq_test_error_logs'][] = (string) $message;
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

if (! function_exists('wp_remote_post')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_remote_post($url, $args = [])
    {
        $GLOBALS['rfq_test_http_requests'][] = [
            'url' => $url,
            'args' => $args,
        ];

        if (isset($GLOBALS['rfq_test_http_remote_post_result'])) {
            return $GLOBALS['rfq_test_http_remote_post_result'];
        }

        return true;
    }
}

if (! function_exists('add_action')) {
    function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1)
    {
        $GLOBALS['rfq_test_actions'][$hook_name][] = $callback;

        return true;
    }
}

if (! function_exists('do_action')) {
    function do_action($hook_name, ...$args)
    {
        if (! isset($GLOBALS['rfq_test_actions'][$hook_name])) {
            return;
        }

        foreach ($GLOBALS['rfq_test_actions'][$hook_name] as $callback) {
            $callback(...$args);
        }
    }
}
