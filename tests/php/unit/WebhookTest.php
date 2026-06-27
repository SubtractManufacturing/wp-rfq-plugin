<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Webhook::class)]
#[Group('AC-WP-019')]
#[Group('AC-WP-021')]
class WebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['rfq_test_options'] = [];
        $GLOBALS['rfq_test_http_requests'] = [];
        $GLOBALS['rfq_test_error_logs'] = [];
        unset($GLOBALS['rfq_test_http_remote_post_result']);

        delete_option('rfq_encryption_key');
        delete_option('rfq_erp_webhook_url');
        delete_option('rfq_erp_webhook_secret');

        RFQ_Secrets::ensure_encryption_key();
    }

    public function test_blank_webhook_url_skips_http_dispatch(): void
    {
        RFQ_Webhook::notify_receipt(
            'RFQ-20260623-000001',
            '550e8400-e29b-41d4-a716-446655440000',
            'intake/550e8400-e29b-41d4-a716-446655440000/meta/receipt.json'
        );

        $this->assertSame([], $GLOBALS['rfq_test_http_requests']);
    }

    public function test_configured_webhook_url_dispatches_signed_json_payload(): void
    {
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'shared-secret');

        $receipt_number = 'RFQ-20260623-000042';
        $session_id = '550e8400-e29b-41d4-a716-446655440000';
        $receipt_key = 'intake/' . $session_id . '/meta/receipt.json';

        RFQ_Webhook::notify_receipt($receipt_number, $session_id, $receipt_key);

        $this->assertCount(1, $GLOBALS['rfq_test_http_requests']);

        $request = $GLOBALS['rfq_test_http_requests'][0];
        $this->assertSame('https://erp.example.com/rfq/import', $request['url']);
        $this->assertFalse($request['args']['blocking']);
        $this->assertSame(5, $request['args']['timeout']);
        $this->assertSame('application/json', $request['args']['headers']['Content-Type']);

        $expected_body = wp_json_encode([
            'receipt_number' => $receipt_number,
            'session_id' => $session_id,
            'receipt_key' => $receipt_key,
        ]);
        $this->assertSame($expected_body, $request['args']['body']);
        $this->assertSame(
            hash_hmac('sha256', (string) $expected_body, 'shared-secret'),
            $request['args']['headers']['X-RFQ-Signature']
        );
    }

    public function test_missing_webhook_secret_skips_dispatch(): void
    {
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');

        RFQ_Webhook::notify_receipt(
            'RFQ-20260623-000001',
            '550e8400-e29b-41d4-a716-446655440000',
            'intake/550e8400-e29b-41d4-a716-446655440000/meta/receipt.json'
        );

        $this->assertSame([], $GLOBALS['rfq_test_http_requests']);
    }

    public function test_transport_failure_does_not_throw(): void
    {
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'shared-secret');
        $GLOBALS['rfq_test_http_remote_post_result'] = new WP_Error(
            'http_request_failed',
            'Connection timed out'
        );

        RFQ_Webhook::notify_receipt(
            'RFQ-20260623-000001',
            '550e8400-e29b-41d4-a716-446655440000',
            'intake/550e8400-e29b-41d4-a716-446655440000/meta/receipt.json'
        );

        $this->assertCount(1, $GLOBALS['rfq_test_http_requests']);
    }

    public function test_init_registers_receipt_created_action(): void
    {
        $GLOBALS['rfq_test_actions'] = [];

        RFQ_Webhook::init();

        $this->assertArrayHasKey('rfq_receipt_created', $GLOBALS['rfq_test_actions']);
        $this->assertSame([RFQ_Webhook::class, 'notify_receipt'], $GLOBALS['rfq_test_actions']['rfq_receipt_created'][0]);
    }
}
