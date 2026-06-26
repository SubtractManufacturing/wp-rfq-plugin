<?php

/**
 * QA harness: head or delete an S3 object using server-side credentials.
 *
 * Env:
 *   RFQ_QA_S3_KEY    — object key (required)
 *   RFQ_QA_S3_ACTION — head|delete (default head)
 *
 * Run:
 *   npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp eval-file scripts/qa-s3-object.php
 */

use Aws\S3\S3Client;

if (! function_exists('get_option')) {
    fwrite(STDERR, "Run via wp-env cli.\n");
    exit(1);
}

if (! class_exists('RFQ_S3_Client')) {
    $plugin_file = WP_CONTENT_DIR . '/plugins/rfq-intake/rfq-intake.php';

    if (is_readable($plugin_file)) {
        require_once $plugin_file;
    }
}

$key = getenv('RFQ_QA_S3_KEY') ?: '';
$action = getenv('RFQ_QA_S3_ACTION') ?: 'head';

if ($key === '') {
    fwrite(STDERR, "RFQ_QA_S3_KEY is required.\n");
    exit(1);
}

if (! in_array($action, ['head', 'delete'], true)) {
    fwrite(STDERR, "RFQ_QA_S3_ACTION must be head or delete.\n");
    exit(1);
}

$client = RFQ_S3_Client::from_settings();

if ($client instanceof WP_Error) {
    fwrite(STDERR, $client->get_error_message() . "\n");
    exit(1);
}

if ($action === 'head') {
    $result = $client->head_object($key);

    if ($result === true) {
        echo "exists\n";
        exit(0);
    }

    if ($result === false) {
        echo "missing\n";
        exit(0);
    }

    fwrite(STDERR, $result->get_error_message() . "\n");
    exit(1);
}

$secret = RFQ_Secrets::get_secret('rfq_s3_secret_key');

if (! is_string($secret) || $secret === '') {
    fwrite(STDERR, "S3 secret key is not configured.\n");
    exit(1);
}

$endpoint = get_option('rfq_s3_endpoint', '');
$region = get_option('rfq_s3_region', '');
$access_key = get_option('rfq_s3_access_key_id', '');
$bucket = $client->get_bucket_name();

try {
    $aws = new S3Client([
        'version' => 'latest',
        'region' => is_string($region) ? trim($region) : '',
        'endpoint' => is_string($endpoint) ? trim($endpoint) : '',
        'use_path_style_endpoint' => true,
        'credentials' => [
            'key' => is_string($access_key) ? trim($access_key) : '',
            'secret' => $secret,
        ],
    ]);

    $aws->deleteObject([
        'Bucket' => $bucket,
        'Key' => $key,
    ]);

    echo "deleted\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'S3 delete failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
