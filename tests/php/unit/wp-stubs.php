<?php

declare(strict_types=1);

if (! isset($GLOBALS['rfq_test_options'])) {
    $GLOBALS['rfq_test_options'] = [];
}

if (! isset($GLOBALS['rfq_test_filters'])) {
    $GLOBALS['rfq_test_filters'] = [];
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
