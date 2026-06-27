<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Webhook::class)]
#[CoversClass(RFQ_Receipt_Service::class)]
#[Group('AC-WP-005')]
#[Group('AC-WP-019')]
#[Group('AC-WP-021')]
class WebhookIntegrationTest extends TestCase
{
    private RFQ_S3_Client_Mock $mock;

    /** @var list<array{url: string, args: array<string, mixed>}> */
    private array $captured_requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        RFQ_Activator::activate();
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_receipt_sequences');

        $_SERVER['REMOTE_ADDR'] = '198.51.100.43';

        delete_option('rfq_erp_webhook_url');
        delete_option('rfq_erp_webhook_secret');
        RFQ_Secrets::ensure_encryption_key();

        remove_all_filters('rfq_s3_client');
        remove_all_filters('rfq_s3_verify_connectivity');
        remove_all_filters('pre_http_request');

        $this->mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', fn (): RFQ_S3_Client_Mock => $this->mock);

        $this->captured_requests = [];
        add_filter(
            'pre_http_request',
            function ($preempt, $parsed_args, $url) {
                $this->captured_requests[] = [
                    'url' => $url,
                    'args' => $parsed_args,
                ];

                return [
                    'headers' => [],
                    'body' => '',
                    'response' => [
                        'code' => 200,
                        'message' => 'OK',
                    ],
                    'cookies' => [],
                    'filename' => null,
                ];
            },
            10,
            3
        );
    }

    public function test_submit_with_blank_webhook_url_skips_http_dispatch(): void
    {
        update_option('rfq_erp_webhook_url', '');

        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $this->captured_requests);
    }

    public function test_submit_dispatches_signed_webhook_after_durable_receipt(): void
    {
        $secret = 'integration-webhook-secret';
        $url = 'https://erp.example.com/rfq/import';

        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', $secret);
        update_option('rfq_erp_webhook_url', $url);

        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $this->captured_requests);

        $request = $this->captured_requests[0];
        $this->assertSame($url, $request['url']);
        $this->assertSame('application/json', $request['args']['headers']['Content-Type']);
        $this->assertFalse($request['args']['blocking']);

        $body = $request['args']['body'];
        $this->assertSame(
            hash_hmac('sha256', $body, $secret),
            $request['args']['headers']['X-RFQ-Signature']
        );

        $payload = json_decode($body, true);
        $this->assertSame($response->get_data()['receipt_number'], $payload['receipt_number']);
        $this->assertSame($session_id, $payload['session_id']);
        $this->assertSame(RFQ_S3_Key_Builder::receipt_meta_key($session_id), $payload['receipt_key']);
    }

    public function test_submit_succeeds_when_webhook_target_returns_error(): void
    {
        remove_all_filters('pre_http_request');
        add_filter(
            'pre_http_request',
            function ($preempt, $parsed_args, $url) {
                $this->captured_requests[] = [
                    'url' => $url,
                    'args' => $parsed_args,
                ];

                return new WP_Error('http_request_failed', 'ERP endpoint unavailable');
            },
            10,
            3
        );

        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'integration-webhook-secret');
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');

        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $this->assertMatchesRegularExpression('/^RFQ-\d{8}-\d{6}$/', $response->get_data()['receipt_number']);
        $this->assertCount(1, $this->captured_requests);
    }

    public function test_missing_webhook_secret_does_not_block_submit(): void
    {
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');

        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $this->captured_requests);
    }

    public function test_idempotent_resubmit_does_not_dispatch_webhook_again(): void
    {
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'integration-webhook-secret');
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');

        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $first = $this->request_submit($session_id, $token, $manifest);
        $second = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $first->get_status());
        $this->assertSame(200, $second->get_status());
        $this->assertCount(1, $this->captured_requests);
    }

    public function test_submit_response_does_not_leak_webhook_secret(): void
    {
        $secret = 'must-not-appear-in-response';
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', $secret);
        update_option('rfq_erp_webhook_url', 'https://erp.example.com/rfq/import');

        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);
        $encoded = wp_json_encode($response->get_data());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($secret, $encoded);
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function prepare_submittable_session(): array
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $session_id = $create->get_data()['session_id'];
        $token = $create->get_data()['token'];

        $contact = new WP_REST_Request('PATCH', '/rfq/v1/sessions/' . $session_id . '/contact');
        $contact->set_header('Authorization', 'Bearer ' . $token);
        $contact->set_header('Content-Type', 'application/json');
        $contact->set_body(wp_json_encode([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]));
        rest_do_request($contact);

        return [$session_id, $token, $this->validManifest($session_id)];
    }

    /**
     * @return array<string, mixed>
     */
    private function validManifest(string $session_id): array
    {
        return [
            'session_id' => $session_id,
            'contact' => [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane@example.com',
                'company' => null,
                'phone' => null,
                'phone_country_code' => null,
                'job_title' => null,
            ],
            'parts' => [
                [
                    'part_id' => '550e8400-e29b-41d4-a716-446655440000',
                    'part_file_key' => 'intake/' . $session_id . '/parts/uuid_bracket.step',
                    'drawing_file_keys' => [],
                    'material' => 'Aluminum 6061',
                    'tolerance' => 'standard',
                    'tolerance_detail' => null,
                    'quantity' => 10,
                    'target_unit_price' => 12.5,
                ],
            ],
            'global' => [
                'required_delivery_date' => gmdate('Y-m-d'),
                'lead_time_preference' => 'standard',
                'shipping_destination' => [
                    'postal_code' => '90210',
                ],
                'po_number' => null,
                'nda_required' => false,
                'notes' => null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function seed_manifest_files(string $session_id, array $manifest): void
    {
        foreach ($manifest['parts'] as $part) {
            if (! is_array($part)) {
                continue;
            }

            $part_key = $part['part_file_key'] ?? null;

            if (is_string($part_key) && $part_key !== '') {
                $this->mock->seed_object($part_key, 1024, RFQ_S3_Key_Builder::PART_CONTENT_TYPE);
            }
        }
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function request_submit(string $session_id, string $token, array $manifest): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/submit');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($manifest));

        return rest_do_request($request);
    }
}
