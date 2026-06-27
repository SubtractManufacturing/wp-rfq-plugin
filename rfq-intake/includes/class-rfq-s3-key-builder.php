<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_S3_Key_Builder {

    public const SANITIZED_FILENAME_MAX_LENGTH = 100;

    public const PART_MAX_BYTES = 524288000;

    public const DRAWING_MAX_BYTES = 52428800;

    public const PART_CONTENT_TYPE = 'application/octet-stream';

	/**
	 * @return list<string>
	 */
    public static function allowed_drawing_content_types(): array {
        return [
            'application/pdf',
            'image/png',
            'image/jpeg',
        ];
    }

    public static function sanitize_filename( string $filename ): string {
        $basename = basename( str_replace( '\\', '/', $filename ) );

        if ( $basename === '' || $basename === '.' || $basename === '..' ) {
            return 'file';
        }

        $sanitized = preg_replace( '/[^a-zA-Z0-9.\-]/', '_', $basename ) ?? '';

        if ( $sanitized === '' ) {
            return 'file';
        }

        if ( strlen( $sanitized ) > self::SANITIZED_FILENAME_MAX_LENGTH ) {
            return substr( $sanitized, 0, self::SANITIZED_FILENAME_MAX_LENGTH );
        }

        return $sanitized;
    }

    public static function build_file_key(
        string $session_id,
        string $file_type,
        string $file_id,
        string $sanitized_filename
    ): string {
        $subdir = $file_type === 'part' ? 'parts' : 'drawings';

        return sprintf(
            'intake/%s/%s/%s_%s',
            $session_id,
            $subdir,
            $file_id,
            $sanitized_filename
        );
    }

    public static function generate_file_id(): string {
        $bytes    = random_bytes( 16 );
        $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
        $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split( bin2hex( $bytes ), 4 )
        );
    }

    public static function is_uuid( string $value ): bool {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $value
        );
    }

    public static function max_bytes_for_file_type( string $file_type ): int {
        return $file_type === 'part' ? self::PART_MAX_BYTES : self::DRAWING_MAX_BYTES;
    }
}
