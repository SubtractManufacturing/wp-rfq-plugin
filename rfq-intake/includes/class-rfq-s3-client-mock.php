<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_S3_Client_Mock implements RFQ_S3_Client_Interface
{
    /** @var array<string, array{body: string, content_type: string, metadata: array<string, string>}> */
    public array $objects = [];

    /** @var list<array{key: string, content_type: string, max_bytes: int, expires_seconds: int, url: string}> */
    public array $presigned_puts = [];

    public bool $should_fail = false;

    public function __construct(private readonly string $bucket = 'mock-bucket')
    {
    }

    public function get_bucket_name(): string
    {
        return $this->bucket;
    }

    public function head_object(string $key): bool|WP_Error
    {
        if ($this->should_fail) {
            return new WP_Error(
                'rfq_s3_error',
                __('S3 operation failed.', 'rfq-intake'),
                ['status' => 502]
            );
        }

        return isset($this->objects[$key]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put_json(string $key, array $data): true|WP_Error
    {
        if ($this->should_fail) {
            return new WP_Error(
                'rfq_s3_error',
                __('S3 operation failed.', 'rfq-intake'),
                ['status' => 502]
            );
        }

        $this->objects[$key] = [
            'body' => wp_json_encode($data, JSON_THROW_ON_ERROR),
            'content_type' => 'application/json',
            'metadata' => [],
        ];

        return true;
    }

    public function create_presigned_put(
        string $key,
        string $content_type,
        int $max_bytes,
        int $expires_seconds = self::DEFAULT_PRESIGN_EXPIRY_SECONDS
    ): string|WP_Error {
        if ($this->should_fail) {
            return new WP_Error(
                'rfq_s3_error',
                __('S3 operation failed.', 'rfq-intake'),
                ['status' => 502]
            );
        }

        if ($expires_seconds < 1) {
            return new WP_Error(
                'rfq_s3_invalid_expiry',
                __('Presigned upload URL expiry must be positive.', 'rfq-intake'),
                ['status' => 400]
            );
        }

        $url = sprintf(
            'https://mock-s3.test/%s/%s?content-type=%s&max-bytes=%d&expires=%d',
            rawurlencode($this->bucket),
            rawurlencode($key),
            rawurlencode($content_type),
            $max_bytes,
            $expires_seconds
        );

        $this->presigned_puts[] = [
            'key' => $key,
            'content_type' => $content_type,
            'max_bytes' => $max_bytes,
            'expires_seconds' => $expires_seconds,
            'url' => $url,
        ];

        return $url;
    }
}
