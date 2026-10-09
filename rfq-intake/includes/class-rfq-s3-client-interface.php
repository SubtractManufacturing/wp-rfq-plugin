<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface RFQ_S3_Client_Interface {

    public const DEFAULT_PRESIGN_EXPIRY_SECONDS = 1800;

    public function get_bucket_name(): string;

    /**
     * @return array{content_length: int, content_type: ?string, last_modified: ?int}|false|WP_Error
     */
    public function head_object( string $key ): array|false|WP_Error;

    /**
     * @return list<array{session_id: string, last_modified: int}>|WP_Error
     */
    public function list_intake_session_prefixes(): array|WP_Error;

    /**
     * Whether any object still exists under `intake/{session_id}/`.
     *
     * Returns WP_Error when S3 cannot answer; callers must treat that as
     * "unknown" and must not delete anything.
     */
    public function has_session_objects( string $session_id ): bool|WP_Error;

    /**
     * @return array<string, mixed>|false|WP_Error
     */
    public function get_json( string $key ): array|false|WP_Error;

    /**
     * @param array<string, mixed> $data
     */
    public function put_json( string $key, array $data ): true|WP_Error;

    public function create_presigned_put(
        string $key,
        string $content_type,
        int $max_bytes,
        int $expires_seconds = self::DEFAULT_PRESIGN_EXPIRY_SECONDS
    ): string|WP_Error;
}
