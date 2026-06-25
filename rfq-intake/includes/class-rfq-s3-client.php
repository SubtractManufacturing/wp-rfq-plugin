<?php

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_S3_Client
{
    public static function health_check()
    {
        $override = apply_filters('rfq_intake_s3_health_check_override', null);

        if ($override !== null) {
            return (bool) $override;
        }

        $config = self::config();

        if ($config === null) {
            return new WP_Error(
                'rfq_s3_not_configured',
                'S3 settings are incomplete.',
                ['status' => 503]
            );
        }

        try {
            $bucket = $config['bucket'];
            unset($config['bucket']);
            $client = new S3Client($config);
            $client->headBucket(['Bucket' => $bucket]);
        } catch (AwsException|Throwable $exception) {
            return new WP_Error(
                'rfq_s3_unavailable',
                'Unable to reach configured S3 bucket.',
                ['status' => 503]
            );
        }

        return true;
    }

    private static function config(): ?array
    {
        $endpoint = trim((string) get_option('rfq_s3_endpoint', ''));
        $bucket = trim((string) get_option('rfq_s3_bucket', ''));
        $access_key = trim((string) get_option('rfq_s3_access_key_id', ''));
        $secret_key = RFQ_Secrets::get_secret('rfq_s3_secret_key');

        if ($endpoint === '' || $bucket === '' || $access_key === '' || $secret_key === null) {
            return null;
        }

        $region = trim((string) get_option('rfq_s3_region', 'us-east-1'));

        return [
            'version' => 'latest',
            'region' => $region === '' ? 'us-east-1' : $region,
            'endpoint' => $endpoint,
            'bucket' => $bucket,
            'credentials' => [
                'key' => $access_key,
                'secret' => $secret_key,
            ],
            'use_path_style_endpoint' => true,
        ];
    }
}
