<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_S3_Client_Mock::class)]
#[Group('AC-WP-018')]
class ReceiptFileValidationTest extends TestCase
{
    public function test_head_object_metadata_returns_size_and_content_type(): void
    {
        $client = new RFQ_S3_Client_Mock('mock-bucket');
        $client->seed_object('intake/session/parts/file.step', 2048, 'application/octet-stream');

        $metadata = $client->head_object_metadata('intake/session/parts/file.step');

        $this->assertIsArray($metadata);
        $this->assertSame(2048, $metadata['content_length']);
        $this->assertSame('application/octet-stream', $metadata['content_type']);
        $this->assertArrayHasKey('last_modified', $metadata);
    }

    public function test_head_object_metadata_returns_false_for_missing_object(): void
    {
        $client = new RFQ_S3_Client_Mock('mock-bucket');

        $this->assertFalse($client->head_object_metadata('missing/key'));
    }
}
