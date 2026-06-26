<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_S3_Key_Builder::class)]
#[Group('AC-WP-024')]
class S3KeyBuilderTest extends TestCase
{
    private const SESSION_ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private const FILE_ID = '11111111-2222-4333-8444-555555555555';

    public function test_sanitize_filename_replaces_special_characters(): void
    {
        $this->assertSame(
            'bracket__v2_.step',
            RFQ_S3_Key_Builder::sanitize_filename('bracket (v2).step')
        );
    }

    public function test_sanitize_filename_truncates_to_one_hundred_characters(): void
    {
        $long_name = str_repeat('a', 120) . '.step';

        $sanitized = RFQ_S3_Key_Builder::sanitize_filename($long_name);

        $this->assertSame(100, strlen($sanitized));
        $this->assertStringStartsWith(str_repeat('a', 100), $sanitized);
    }

    public function test_sanitize_filename_uses_basename_only(): void
    {
        $this->assertSame(
            'drawing.pdf',
            RFQ_S3_Key_Builder::sanitize_filename('../../etc/passwd/drawing.pdf')
        );
    }

    public function test_build_file_key_scopes_part_uploads_under_parts_prefix(): void
    {
        $key = RFQ_S3_Key_Builder::build_file_key(
            self::SESSION_ID,
            'part',
            self::FILE_ID,
            'bracket.step'
        );

        $this->assertSame(
            'intake/' . self::SESSION_ID . '/parts/' . self::FILE_ID . '_bracket.step',
            $key
        );
    }

    public function test_build_file_key_scopes_drawing_uploads_under_drawings_prefix(): void
    {
        $key = RFQ_S3_Key_Builder::build_file_key(
            self::SESSION_ID,
            'drawing',
            self::FILE_ID,
            'drawing.pdf'
        );

        $this->assertSame(
            'intake/' . self::SESSION_ID . '/drawings/' . self::FILE_ID . '_drawing.pdf',
            $key
        );
    }

    public function test_generate_file_id_returns_uuid_v4(): void
    {
        $file_id = RFQ_S3_Key_Builder::generate_file_id();

        $this->assertTrue(RFQ_S3_Key_Builder::is_uuid($file_id));
    }

    public function test_is_uuid_rejects_client_supplied_non_uuid_keys(): void
    {
        $this->assertFalse(RFQ_S3_Key_Builder::is_uuid('intake/session/parts/evil'));
        $this->assertFalse(RFQ_S3_Key_Builder::is_uuid('not-a-uuid'));
    }
}
