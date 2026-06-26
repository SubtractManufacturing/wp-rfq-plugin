<?php

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_S3_Client
{
    /**
     * @return list<string>
     */
    public static function required_option_names(): array
    {
        return [
            'rfq_s3_endpoint',
            'rfq_s3_bucket',
            'rfq_s3_access_key_id',
            'rfq_s3_region',
        ];
    }

    public static function is_configured(): bool
    {
        foreach (self::required_option_names() as $option_name) {
            $value = get_option($option_name, '');

            if (! is_string($value) || trim($value) === '') {
                return false;
            }
        }

        return RFQ_Secrets::has_secret('rfq_s3_secret_key');
    }

    public static function verify_connectivity(): bool
    {
        $filtered = apply_filters('rfq_s3_verify_connectivity', null);

        if ($filtered !== null) {
            return (bool) $filtered;
        }

        if (! self::is_configured()) {
            return false;
        }

        try {
            $client = self::create_client();
            $client->headBucket([
                'Bucket' => self::get_bucket_name(),
            ]);

            return true;
        } catch (AwsException) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    public static function get_bucket_name(): string
    {
        $bucket = get_option('rfq_s3_bucket', '');

        return is_string($bucket) ? trim($bucket) : '';
    }

    private static function create_client(): S3Client
    {
        $secret = RFQ_Secrets::get_secret('rfq_s3_secret_key');

        if ($secret === null || $secret === '') {
            throw new RuntimeException('RFQ S3 secret access key is not configured.');
        }

        $endpoint = get_option('rfq_s3_endpoint', '');
        $region = get_option('rfq_s3_region', '');
        $access_key = get_option('rfq_s3_access_key_id', '');

        return new S3Client([
            'version' => 'latest',
            'region' => is_string($region) ? trim($region) : '',
            'endpoint' => is_string($endpoint) ? trim($endpoint) : '',
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => is_string($access_key) ? trim($access_key) : '',
                'secret' => $secret,
            ],
        ]);
    }
}
