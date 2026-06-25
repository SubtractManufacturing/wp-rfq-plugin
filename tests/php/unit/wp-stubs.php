<?php

declare(strict_types=1);

if (! isset($GLOBALS['rfq_test_options'])) {
    $GLOBALS['rfq_test_options'] = [];
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
