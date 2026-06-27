<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_S3_Client_Mock::class)]
#[Group('AC-WP-024')]
class S3ClientMockTest extends TestCase
{
    public function test_mock_put_json_and_head_object_round_trip(): void
    {
        $client = new RFQ_S3_Client_Mock('mock-bucket');
        $key = 'intake/session/meta/draft.json';

        $this->assertFalse($client->head_object($key));

        $result = $client->put_json($key, ['status' => 'draft']);

        $this->assertTrue($result);
        $this->assertTrue($client->head_object($key));
        $this->assertSame('{"status":"draft"}', $client->objects[$key]['body']);
    }

    public function test_mock_presigned_put_records_binding_metadata(): void
    {
        $client = new RFQ_S3_Client_Mock('mock-bucket');

        $url = $client->create_presigned_put(
            'intake/session/parts/uuid_bracket.step',
            'application/octet-stream',
            524288000,
            900
        );

        $this->assertIsString($url);
        $this->assertCount(1, $client->presigned_puts);
        $this->assertSame('intake/session/parts/uuid_bracket.step', $client->presigned_puts[0]['key']);
        $this->assertSame('application/octet-stream', $client->presigned_puts[0]['content_type']);
        $this->assertSame(524288000, $client->presigned_puts[0]['max_bytes']);
        $this->assertSame(900, $client->presigned_puts[0]['expires_seconds']);
    }

    public function test_mock_failure_mode_returns_controlled_error(): void
    {
        $client = new RFQ_S3_Client_Mock();
        $client->should_fail = true;

        $error = $client->put_json('any/key.json', ['x' => 1]);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('rfq_s3_error', $error->get_error_code());
    }

    public function test_mock_lists_intake_session_ids_from_object_keys(): void
    {
        $client = new RFQ_S3_Client_Mock();
        $session_id = '550e8400-e29b-41d4-a716-446655440099';

        $client->seed_object('intake/' . $session_id . '/meta/draft.json', 10, 'application/json');

        $this->assertSame([$session_id], $client->list_intake_session_ids());
    }

    public function test_mock_prefix_oldest_modified_uses_object_timestamps(): void
    {
        $client = new RFQ_S3_Client_Mock();
        $session_id = '550e8400-e29b-41d4-a716-446655440098';

        $client->seed_object('intake/' . $session_id . '/meta/draft.json', 10, 'application/json', '{}', 100);
        $client->seed_object('intake/' . $session_id . '/parts/uuid_part.step', 20, 'application/octet-stream', '', 50);

        $this->assertSame(50, $client->get_prefix_oldest_modified($session_id));
    }
}
