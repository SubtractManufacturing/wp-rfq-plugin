<?php

/**
 * Restore dev-site S3 settings in wp-env from a local env file.
 * Run: npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp eval-file scripts/restore-dev-s3-from-env.php
 */

if (! function_exists('update_option')) {
    fwrite(STDERR, "Run via wp-env cli.\n");
    exit(1);
}

if (! class_exists('RFQ_S3_Client')) {
    $plugin_file = WP_CONTENT_DIR . '/plugins/rfq-intake/rfq-intake.php';

    if (is_readable($plugin_file)) {
        require_once $plugin_file;
    }
}

/**
 * @return array<string, string>
 */
function rfq_parse_dev_env_file(string $path): array
{
    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '') {
            $values[$key] = $value;
        }
    }

    return $values;
}

/**
 * @param array<string, string> $env
 */
function rfq_env_value(array $env, string ...$keys): ?string
{
    foreach ($keys as $key) {
        if (isset($env[$key]) && trim($env[$key]) !== '') {
            return trim($env[$key]);
        }

        $from_process = getenv($key);

        if (is_string($from_process) && trim($from_process) !== '') {
            return trim($from_process);
        }
    }

    return null;
}

$repo_root = WP_CONTENT_DIR . '/rfq-plugin-root';
$candidates = [
    getenv('RFQ_DEV_ENV_FILE') ?: '',
    $repo_root . '/config/dev.env.local',
    $repo_root . '/config/.ci-s3-env.tmp',
    $repo_root . '/.env.local',
    $repo_root . '/.env',
];

$env_file = null;
$env = [];

foreach ($candidates as $candidate) {
    if ($candidate !== '' && is_readable($candidate)) {
        $env_file = $candidate;
        $env = rfq_parse_dev_env_file($env_file);
        break;
    }
}

$endpoint = rfq_env_value($env, 'RFQ_S3_ENDPOINT', 'S3_Endpoint');
$bucket = rfq_env_value($env, 'RFQ_S3_BUCKET', 'S3_Bucket_name', 'S3_Bucket');
$access_key = rfq_env_value($env, 'RFQ_S3_ACCESS_KEY_ID', 'S3_Key_ID', 'S3_Access_Key_ID');
$region = rfq_env_value($env, 'RFQ_S3_REGION', 'S3_region', 'S3_Region');
$secret = rfq_env_value($env, 'RFQ_S3_SECRET_KEY', 'S3_Secret_Key');

if ($env_file === null && ($endpoint === null || $bucket === null || $access_key === null || $region === null || $secret === null)) {
    echo "Dev S3 restore skipped (no env file or RFQ_S3_* process env vars found).\n";
    exit(0);
}

$missing = array_filter([
    'endpoint' => $endpoint,
    'bucket' => $bucket,
    'access_key_id' => $access_key,
    'region' => $region,
    'secret_key' => $secret,
], static fn (?string $value): bool => $value === null || $value === '');

if ($missing !== []) {
    fwrite(STDERR, 'Dev S3 restore failed: env file is missing required keys: ' . implode(', ', array_keys($missing)) . "\n");
    exit(1);
}

RFQ_Secrets::ensure_encryption_key();
update_option('rfq_s3_endpoint', $endpoint, false);
update_option('rfq_s3_bucket', $bucket, false);
update_option('rfq_s3_access_key_id', $access_key, false);
update_option('rfq_s3_region', $region, false);
RFQ_Secrets::set_secret('rfq_s3_secret_key', $secret);

$source = $env_file !== null ? basename($env_file) : 'process environment';
echo 'Dev S3 settings restored from ' . $source . ".\n";

if (RFQ_S3_Client::verify_connectivity()) {
    echo "S3 connectivity check: ok\n";
    exit(0);
}

fwrite(STDERR, "Dev S3 settings saved, but connectivity check failed — verify credentials in your env file.\n");
exit(0);
