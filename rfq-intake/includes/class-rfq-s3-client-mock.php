<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_S3_Client_Mock implements RFQ_S3_Client_Interface
{
    /** @var array<string, array{body: string, content_type: string, content_length: int, metadata: array<string, string>}> */
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

    public function head_object(string $key): array|false|WP_Error
    {
        if ($this->should_fail) {
            return new WP_Error(
                'rfq_s3_error',
                __('S3 operation failed.', 'rfq-intake'),
                ['status' => 502]
            );
        }

        if (! isset($this->objects[$key])) {
            return false;
        }

        $object = $this->objects[$key];

        return [
            'content_length' => $object['content_length'],
            'content_type' => $object['content_type'],
        ];
    }

    public function seed_object(string $key, string $content_type, int $content_length): void
    {
        $this->objects[$key] = [
            'body' => '',
            'content_type' => $content_type,
            'content_length' => $content_length,
            'metadata' => [],
        ];
    }

    /**
     * @return array<string, mixed>|false|WP_Error
     */
    public function get_json(string $key): array|false|WP_Error
    {
        if ($this->should_fail) {
            return new WP_Error(
                'rfq_s3_error',
                __('S3 operation failed.', 'rfq-intake'),
                ['status' => 502]
            );
        }

        if (! isset($this->objects[$key])) {
            return false;
        }

        $decoded = json_decode($this->objects[$key]['body'], true);

        return is_array($decoded) ? $decoded : false;
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

        $body = wp_json_encode($data, JSON_THROW_ON_ERROR);

        $this->objects[$key] = [
            'body' => $body,
            'content_type' => 'application/json',
            'content_length' => strlen($body),
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
