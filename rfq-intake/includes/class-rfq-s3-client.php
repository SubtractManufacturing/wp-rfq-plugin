<?php

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_S3_Client implements RFQ_S3_Client_Interface
{
    public function __construct(
        private readonly S3Client $aws_client,
        private readonly string $bucket
    ) {
    }

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

        $client = self::from_settings();

        if ($client instanceof WP_Error) {
            return false;
        }

        return $client->verify_bucket_access();
    }

    public function verify_bucket_access(): bool
    {
        try {
            $this->aws_client->headBucket([
                'Bucket' => $this->bucket,
            ]);

            return true;
        } catch (AwsException) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    public static function from_settings(): self|WP_Error
    {
        if (! self::is_configured()) {
            return new WP_Error(
                'rfq_s3_not_configured',
                __('S3 storage is not configured.', 'rfq-intake'),
                ['status' => 503]
            );
        }

        try {
            return new self(self::create_aws_client(), self::read_bucket_name_from_settings());
        } catch (Throwable $exception) {
            return self::map_exception($exception);
        }
    }

    public static function resolve(): RFQ_S3_Client_Interface|WP_Error
    {
        $filtered = apply_filters('rfq_s3_client', null);

        if ($filtered instanceof RFQ_S3_Client_Interface) {
            return $filtered;
        }

        return self::from_settings();
    }

    public function get_bucket_name(): string
    {
        return $this->bucket;
    }

    private static function read_bucket_name_from_settings(): string
    {
        $bucket = get_option('rfq_s3_bucket', '');

        return is_string($bucket) ? trim($bucket) : '';
    }

    public function head_object(string $key): bool|WP_Error
    {
        try {
            $this->aws_client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return true;
        } catch (AwsException $exception) {
            if (self::is_object_not_found($exception)) {
                return false;
            }

            return self::map_exception($exception);
        } catch (Throwable $exception) {
            return self::map_exception($exception);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put_json(string $key, array $data): true|WP_Error
    {
        try {
            $this->aws_client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => wp_json_encode($data, JSON_THROW_ON_ERROR),
                'ContentType' => 'application/json',
            ]);

            return true;
        } catch (Throwable $exception) {
            return self::map_exception($exception);
        }
    }

    public function create_presigned_put(
        string $key,
        string $content_type,
        int $max_bytes,
        int $expires_seconds = self::DEFAULT_PRESIGN_EXPIRY_SECONDS
    ): string|WP_Error {
        if ($expires_seconds < 1) {
            return new WP_Error(
                'rfq_s3_invalid_expiry',
                __('Presigned upload URL expiry must be positive.', 'rfq-intake'),
                ['status' => 400]
            );
        }

        try {
            $command = $this->aws_client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ContentType' => $content_type,
                'Metadata' => [
                    'rfq-max-bytes' => (string) $max_bytes,
                ],
            ]);

            $request = $this->aws_client->createPresignedRequest(
                $command,
                '+' . $expires_seconds . ' seconds'
            );

            return (string) $request->getUri();
        } catch (Throwable $exception) {
            return self::map_exception($exception);
        }
    }

    private static function create_aws_client(): S3Client
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

    private static function is_object_not_found(AwsException $exception): bool
    {
        if ($exception->getStatusCode() === 404) {
            return true;
        }

        $error_code = $exception->getAwsErrorCode();

        return in_array($error_code, ['NoSuchKey', 'NotFound', '404'], true);
    }

    private static function map_exception(Throwable $exception): WP_Error
    {
        $message = __('S3 operation failed.', 'rfq-intake');

        if ($exception instanceof AwsException) {
            $aws_message = $exception->getAwsErrorMessage() ?? $exception->getMessage();
            $message = self::sanitize_error_message($aws_message);
        } elseif ($exception->getMessage() !== '') {
            $message = self::sanitize_error_message($exception->getMessage());
        }

        return new WP_Error(
            'rfq_s3_error',
            $message,
            ['status' => 502]
        );
    }

    private static function sanitize_error_message(string $message): string
    {
        $secret = RFQ_Secrets::get_secret('rfq_s3_secret_key');

        if (is_string($secret) && $secret !== '') {
            $message = str_replace($secret, '[redacted]', $message);
        }

        $access_key = get_option('rfq_s3_access_key_id', '');

        if (is_string($access_key) && $access_key !== '') {
            $message = str_replace(trim($access_key), '[redacted]', $message);
        }

        if (stripos($message, 'secret') !== false && stripos($message, 'key') !== false) {
            return __('S3 operation failed.', 'rfq-intake');
        }

        return $message;
    }
}
