<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/wp/');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            public string $code = '',
            public string $message = '',
            public array $data = []
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess
    {
        private array $headers = [];
        private array $params = [];
        private array $attributes = [];

        public function __construct(string $method = 'GET', string $route = '/')
        {
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): string
        {
            return (string) ($this->headers[strtolower($key)] ?? '');
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function set_attribute(string $key, mixed $value): void
        {
            $this->attributes[$key] = $value;
        }

        public function get_attribute(string $key): mixed
        {
            return $this->attributes[$key] ?? null;
        }

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists((string) $offset, $this->params);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->params[(string) $offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->params[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->params[(string) $offset]);
        }
    }
}

final class RfqTestWpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public string $last_error = '';
    public array $rows = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4';
    }

    public function insert(string $table, array $data): bool
    {
        $this->insert_id++;
        $data['id'] = $this->insert_id;
        $this->rows[$table][] = $data;

        return true;
    }

    public function get_row(array $prepared, string $output = ARRAY_A): ?array
    {
        [$query, $value] = $prepared;
        preg_match('/FROM\s+([a-zA-Z0-9_]+)/', $query, $matches);
        $table = $matches[1] ?? '';

        foreach ($this->rows[$table] ?? [] as $row) {
            if (($row['session_id'] ?? null) === $value) {
                return $row;
            }
        }

        return null;
    }

    public function prepare(string $query, string $value): array
    {
        return [$query, $value];
    }
}

$GLOBALS['wpdb'] = new RfqTestWpdb();
$GLOBALS['rfq_options'] = [];
$GLOBALS['rfq_transients'] = [];
$GLOBALS['rfq_filters'] = [];
$GLOBALS['rfq_registered_routes'] = [];
$GLOBALS['rfq_dbdelta_calls'] = [];

function add_action(string $hook, callable $callback): void
{
}

function add_filter(string $hook, callable $callback): void
{
    $GLOBALS['rfq_filters'][$hook][] = $callback;
}

function apply_filters(string $hook, mixed $value): mixed
{
    foreach ($GLOBALS['rfq_filters'][$hook] ?? [] as $callback) {
        $value = $callback($value);
    }

    return $value;
}

function register_activation_hook(string $file, callable $callback): void
{
}

function register_rest_route(string $namespace, string $route, array $args): void
{
    $GLOBALS['rfq_registered_routes'][] = [$namespace, $route, $args];
}

function rest_ensure_response(array $data): array
{
    return $data;
}

function is_wp_error(mixed $value): bool
{
    return $value instanceof WP_Error;
}

function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['rfq_options'][$name] ?? $default;
}

function add_option(string $name, mixed $value, string $deprecated = '', bool $autoload = true): bool
{
    $GLOBALS['rfq_options'][$name] = $value;

    return true;
}

function update_option(string $name, mixed $value, bool $autoload = true): bool
{
    $GLOBALS['rfq_options'][$name] = $value;

    return true;
}

function get_transient(string $name): mixed
{
    return $GLOBALS['rfq_transients'][$name]['value'] ?? false;
}

function set_transient(string $name, mixed $value, int $expiration): bool
{
    $GLOBALS['rfq_transients'][$name] = [
        'value' => $value,
        'expiration' => $expiration,
    ];

    return true;
}

function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string
{
    return str_repeat('a', $length);
}

function wp_generate_uuid4(): string
{
    static $counter = 0;
    $counter++;

    return sprintf('00000000-0000-4000-8000-%012d', $counter);
}

function plugin_dir_path(string $path): string
{
    return dirname($path) . '/';
}

function dbDelta(string $sql): void
{
    $GLOBALS['rfq_dbdelta_calls'][] = $sql;
}

function __return_true(): bool
{
    return true;
}

require_once __DIR__ . '/../../vendor/autoload.php';

