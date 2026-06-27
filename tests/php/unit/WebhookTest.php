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
        unset($GLOBALS['rfq_test_http_post_result']);

        RFQ_Secrets::ensure_encryption_key();
    }

    public function test_blank_webhook_url_skips_notification(): void
    {
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'shared-secret');
        update_option('rfq_erp_webhook_url', '');

        RFQ_Webhook::notify_receipt(
            'RFQ-20260626-000001',
            '550e8400-e29b-41d4-a716-446655440000',
            'intake/550e8400-e29b-41d4-a716-446655440000/meta/receipt.json'
        );

        $this->assertSame([], $GLOBALS['rfq_test_http_requests']);
    }

    public function test_configured_url_dispatches_signed_json_payload(): void
    {
        $secret = 'webhook-shared-secret';
        $url = 'https://erp.example.com/rfq/import';

        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', $secret);
        update_option('rfq_erp_webhook_url', $url);

        $receipt_number = 'RFQ-20260626-000042';
        $session_id = '550e8400-e29b-41d4-a716-446655440000';
        $receipt_key = 'intake/' . $session_id . '/meta/receipt.json';

        RFQ_Webhook::notify_receipt($receipt_number, $session_id, $receipt_key);

        $this->assertCount(1, $GLOBALS['rfq_test_http_requests']);

        $request = $GLOBALS['rfq_test_http_requests'][0];
        $this->assertSame($url, $request['url']);
        $this->assertFalse($request['args']['blocking']);
        $this->assertSame(5, $request['args']['timeout']);
        $this->assertSame('application/json', $request['args']['headers']['Content-Type']);

        $body = $request['args']['body'];
        $this->assertSame(
            hash_hmac('sha256', $body, $secret),
            $request['args']['headers']['X-RFQ-Signature']
        );

        $payload = json_decode($body, true);
        $this->assertSame($receipt_number, $payload['receipt_number']);
        $this->assertSame($session_id, $payload['session_id']);
        $this->assertSame($receipt_key, $payload['receipt_key']);
    }

    public function test_missing_secret_with_configured_url_skips_dispatch(): void
    {
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');

        RFQ_Webhook::notify_receipt(
            'RFQ-20260626-000001',
            '550e8400-e29b-41d4-a716-446655440000',
            'intake/550e8400-e29b-41d4-a716-446655440000/meta/receipt.json'
        );

        $this->assertSame([], $GLOBALS['rfq_test_http_requests']);
    }

    public function test_transport_failure_does_not_throw(): void
    {
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'shared-secret');
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');
        $GLOBALS['rfq_test_http_post_result'] = new WP_Error(
            'http_request_failed',
            'Connection timed out'
        );

        RFQ_Webhook::notify_receipt(
            'RFQ-20260626-000001',
            '550e8400-e29b-41d4-a716-446655440000',
            'intake/550e8400-e29b-41d4-a716-446655440000/meta/receipt.json'
        );

        $this->assertCount(1, $GLOBALS['rfq_test_http_requests']);
    }

    public function test_payload_and_headers_do_not_leak_secret(): void
    {
        $secret = 'super-secret-value';
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', $secret);
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');

        RFQ_Webhook::notify_receipt(
            'RFQ-20260626-000001',
            '550e8400-e29b-41d4-a716-446655440000',
            'intake/550e8400-e29b-41d4-a716-446655440000/meta/receipt.json'
        );

        $encoded = wp_json_encode($GLOBALS['rfq_test_http_requests']);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('super-secret-value', $encoded);
    }
}
