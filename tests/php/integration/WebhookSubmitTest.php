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
class WebhookSubmitTest extends TestCase
{
    private RFQ_S3_Client_Mock $mock;

    private const WEBHOOK_URL = 'https://erp.example.com/rfq/import';

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        RFQ_Activator::activate();
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_receipt_sequences');

        delete_option('rfq_s3_endpoint');
        delete_option('rfq_s3_bucket');
        delete_option('rfq_s3_access_key_id');
        delete_option('rfq_s3_region');
        delete_option('rfq_s3_secret_key');
        delete_option('rfq_jwt_secret');
        delete_option('rfq_erp_webhook_url');
        delete_option('rfq_erp_webhook_secret');

        RFQ_Activator::bootstrap_secrets();

        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(10, 250);

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_rfq_sessions_%',
                '_transient_timeout_rfq_sessions_%'
            )
        );

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        remove_all_filters('rfq_s3_verify_connectivity');
        remove_all_filters('rfq_s3_client');
        remove_all_filters('pre_http_request');

        $this->mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', fn (): RFQ_S3_Client_Mock => $this->mock);
    }

    protected function tearDown(): void
    {
        remove_all_filters('pre_http_request');

        parent::tearDown();
    }

    public function test_submit_skips_webhook_when_url_blank(): void
    {
        $captured = $this->capture_webhook_requests();

        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $captured);
    }

    public function test_submit_dispatches_signed_webhook_after_durable_receipt(): void
    {
        update_option('rfq_erp_webhook_url', self::WEBHOOK_URL);
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'integration-secret');

        $captured = $this->capture_webhook_requests();

        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());

        $receipt_number = $response->get_data()['receipt_number'];
        $receipt_key = 'intake/' . $session_id . '/meta/receipt.json';

        $this->assertCount(1, $captured);
        $this->assertSame(self::WEBHOOK_URL, $captured[0]['url']);
        $this->assertFalse($captured[0]['args']['blocking']);

        $expected_body = wp_json_encode([
            'receipt_number' => $receipt_number,
            'session_id' => $session_id,
            'receipt_key' => $receipt_key,
        ]);
        $this->assertSame($expected_body, $captured[0]['args']['body']);
        $this->assertSame(
            hash_hmac('sha256', (string) $expected_body, 'integration-secret'),
            $captured[0]['args']['headers']['X-RFQ-Signature']
        );
    }

    public function test_submit_succeeds_when_webhook_secret_missing(): void
    {
        update_option('rfq_erp_webhook_url', self::WEBHOOK_URL);

        $captured = $this->capture_webhook_requests();

        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $captured);
        $this->assertNotEmpty($response->get_data()['receipt_number']);
    }

    public function test_submit_succeeds_when_webhook_target_fails(): void
    {
        update_option('rfq_erp_webhook_url', self::WEBHOOK_URL);
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'integration-secret');

        $this->capture_webhook_requests(static function () {
            return new WP_Error('http_request_failed', 'ERP unavailable');
        });

        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $this->assertNotEmpty($response->get_data()['receipt_number']);
    }

    public function test_submit_response_does_not_leak_webhook_secret(): void
    {
        update_option('rfq_erp_webhook_url', self::WEBHOOK_URL);
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'super-secret-value');

        $this->capture_webhook_requests();

        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);
        $encoded = wp_json_encode($response->get_data());

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('super-secret-value', $encoded);
        $this->assertStringNotContainsString('rfq_erp_webhook_secret', $encoded);
    }

    public function test_idempotent_resubmit_does_not_redispatch_webhook(): void
    {
        update_option('rfq_erp_webhook_url', self::WEBHOOK_URL);
        RFQ_Secrets::set_secret('rfq_erp_webhook_secret', 'integration-secret');

        $captured = $this->capture_webhook_requests();

        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $first = $this->request_submit($session_id, $token, $manifest);
        $second = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $first->get_status());
        $this->assertSame(200, $second->get_status());
        $this->assertCount(1, $captured);
    }

    /**
     * @param callable|null $preempt
     * @return list<array{url: string, args: array<string, mixed>}>
     */
    private function capture_webhook_requests(?callable $preempt = null): array
    {
        $captured = [];

        add_filter(
            'pre_http_request',
            static function ($pre, $parsed_args, $url) use (&$captured, $preempt) {
                if ($url !== self::WEBHOOK_URL) {
                    return $pre;
                }

                $captured[] = [
                    'url' => $url,
                    'args' => $parsed_args,
                ];

                if ($preempt !== null) {
                    return $preempt();
                }

                return [
                    'headers' => [],
                    'body' => 'ok',
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

        return $captured;
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function create_session_ready_for_submit(): array
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $this->assertSame(201, $create->get_status(), 'Session creation failed');

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

        return [$session_id, $token, $this->valid_manifest($session_id)];
    }

    /**
     * @return array<string, mixed>
     */
    private function valid_manifest(string $session_id): array
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
                    'drawing_file_keys' => [
                        'intake/' . $session_id . '/drawings/uuid_drawing.pdf',
                    ],
                    'material' => 'Aluminum 6061',
                    'tolerance' => 'standard',
                    'tolerance_detail' => null,
                    'quantity' => 10,
                    'target_unit_price' => 12.5,
                    'notes' => null,
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
            $this->mock->seed_object($part['part_file_key'], 'application/octet-stream', 100);

            foreach ($part['drawing_file_keys'] as $drawing_key) {
                $this->mock->seed_object($drawing_key, 'application/pdf', 100);
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
