<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_S3_Client_Mock implements RFQ_S3_Client_Interface
{
    /** @var array<string, array{body: string, content_type: string, content_length: int, metadata: array<string, string>, last_modified: int}> */
    public array $objects = [];

    /** @var list<string> */
    public array $fail_put_keys = [];

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
        $metadata = $this->head_object_metadata($key);

        if ($metadata instanceof WP_Error) {
            return $metadata;
        }

        return $metadata !== false;
    }

    public function head_object_metadata(string $key): array|false|WP_Error
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

        return [
            'content_length' => $this->objects[$key]['content_length'],
            'content_type' => $this->objects[$key]['content_type'],
            'last_modified' => $this->objects[$key]['last_modified'],
        ];
    }

    public function seed_object(
        string $key,
        int $content_length,
        string $content_type,
        string $body = '',
        ?int $last_modified = null
    ): void {
        $this->objects[$key] = [
            'body' => $body,
            'content_type' => $content_type,
            'content_length' => $content_length,
            'metadata' => [],
            'last_modified' => $last_modified ?? time(),
        ];
    }

    public function list_intake_session_ids(): array
    {
        $session_ids = [];

        foreach (array_keys($this->objects) as $key) {
            if (preg_match('#^intake/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/#', $key, $matches) === 1) {
                $session_ids[$matches[1]] = true;
            }
        }

        return array_keys($session_ids);
    }

    public function get_prefix_oldest_modified(string $session_id): int|false
    {
        $prefix = RFQ_S3_Key_Builder::session_prefix($session_id);
        $oldest = null;

        foreach ($this->objects as $key => $object) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $timestamp = $object['last_modified'];
            $oldest = $oldest === null ? $timestamp : min($oldest, $timestamp);
        }

        return $oldest ?? false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function put_json(string $key, array $data): true|WP_Error
    {
        if ($this->should_fail || in_array($key, $this->fail_put_keys, true)) {
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
            'last_modified' => time(),
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
