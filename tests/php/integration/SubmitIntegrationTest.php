<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Receipt_Service::class)]
#[CoversClass(RFQ_REST_Controller::class)]
#[Group('AC-WP-001')]
#[Group('AC-WP-005')]
#[Group('AC-WP-016')]
#[Group('AC-WP-017')]
#[Group('AC-WP-018')]
#[Group('AC-WP-022')]
class SubmitIntegrationTest extends TestCase
{
    private RFQ_S3_Client_Mock $mock;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        RFQ_Activator::activate();
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_receipt_sequences');

        $_SERVER['REMOTE_ADDR'] = '198.51.100.42';

        remove_all_filters('rfq_s3_client');
        remove_all_filters('rfq_s3_verify_connectivity');

        $this->mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', fn (): RFQ_S3_Client_Mock => $this->mock);
    }

    public function test_valid_submit_writes_receipt_and_updates_session(): void
    {
        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $receipt_number = $response->get_data()['receipt_number'];
        $this->assertMatchesRegularExpression('/^RFQ-\d{8}-\d{6}$/', $receipt_number);

        $manifest_key = RFQ_S3_Key_Builder::manifest_meta_key($session_id);
        $receipt_key = RFQ_S3_Key_Builder::receipt_meta_key($session_id);

        $this->assertArrayHasKey($manifest_key, $this->mock->objects);
        $this->assertArrayHasKey($receipt_key, $this->mock->objects);

        $receipt_payload = json_decode($this->mock->objects[$receipt_key]['body'], true);
        $this->assertSame($receipt_number, $receipt_payload['receipt_number']);
        $this->assertSame($session_id, $receipt_payload['session_id']);
        $this->assertSame($manifest_key, $receipt_payload['manifest_key']);

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, receipt_number, shipping_postal_code, submitted_part_count, submitted_at
                 FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $session_id
            ),
            ARRAY_A
        );

        $this->assertSame('submitted', $row['status']);
        $this->assertSame($receipt_number, $row['receipt_number']);
        $this->assertSame('90210', $row['shipping_postal_code']);
        $this->assertSame('1', $row['submitted_part_count']);
        $this->assertNotNull($row['submitted_at']);
    }

    public function test_submit_is_idempotent_for_already_submitted_session(): void
    {
        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $first = $this->request_submit($session_id, $token, $manifest);
        $second = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $first->get_status());
        $this->assertSame(200, $second->get_status());
        $this->assertSame($first->get_data()['receipt_number'], $second->get_data()['receipt_number']);

        $manifest_key = RFQ_S3_Key_Builder::manifest_meta_key($session_id);
        $first_manifest_body = $this->mock->objects[$manifest_key]['body'];

        $altered = $manifest;
        $altered['global']['notes'] = 'changed on retry';
        $this->request_submit($session_id, $token, $altered);

        $this->assertSame($first_manifest_body, $this->mock->objects[$manifest_key]['body']);
    }

    public function test_invalid_manifest_returns_422_field_errors(): void
    {
        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $manifest['contact']['email'] = 'not-an-email';

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('contact.email', $response->get_data()['data']['fields']);
    }

    public function test_more_than_twenty_parts_returns_422(): void
    {
        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $parts = [];

        for ($index = 0; $index < 21; $index++) {
            $parts[] = [
                'part_id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $index),
                'part_file_key' => 'intake/' . $session_id . '/parts/file-' . $index . '.step',
                'drawing_file_keys' => [],
                'material' => 'Aluminum',
                'tolerance' => 'standard',
                'tolerance_detail' => null,
                'quantity' => 1,
            ];
        }

        $manifest['parts'] = $parts;

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('parts', $response->get_data()['data']['fields']);
    }

    public function test_missing_s3_object_returns_failed_keys(): void
    {
        [$session_id, $token, $manifest] = $this->prepare_submittable_session();

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey(
            $manifest['parts'][0]['part_file_key'],
            $response->get_data()['data']['failed_keys']
        );
    }

    public function test_wrong_session_file_key_is_rejected(): void
    {
        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $other_session = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $manifest['parts'][0]['part_file_key'] = 'intake/' . $other_session . '/parts/uuid_bracket.step';

        $this->mock->seed_object(
            $manifest['parts'][0]['part_file_key'],
            1024,
            RFQ_S3_Key_Builder::PART_CONTENT_TYPE
        );

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertStringContainsString(
            'session',
            $response->get_data()['data']['failed_keys'][$manifest['parts'][0]['part_file_key']]
        );
    }

    public function test_concurrent_submits_for_different_sessions_allocate_unique_receipt_numbers(): void
    {
        [$session_a, $token_a, $manifest_a] = $this->prepare_submittable_session();
        [$session_b, $token_b, $manifest_b] = $this->prepare_submittable_session();

        $this->seed_manifest_files($session_a, $manifest_a);
        $this->seed_manifest_files($session_b, $manifest_b);

        $response_a = $this->request_submit($session_a, $token_a, $manifest_a);
        $response_b = $this->request_submit($session_b, $token_b, $manifest_b);

        $this->assertSame(200, $response_a->get_status());
        $this->assertSame(200, $response_b->get_status());
        $this->assertNotSame(
            $response_a->get_data()['receipt_number'],
            $response_b->get_data()['receipt_number']
        );
    }

    public function test_receipt_number_uses_daily_sequence_format(): void
    {
        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $expected_prefix = 'RFQ-' . gmdate('Ymd') . '-';
        $this->assertStringStartsWith($expected_prefix, $response->get_data()['receipt_number']);
        $this->assertSame($expected_prefix . '000001', $response->get_data()['receipt_number']);
    }

    public function test_partial_write_after_manifest_logs_and_fires_action(): void
    {
        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $this->mock->fail_put_keys = [RFQ_S3_Key_Builder::receipt_meta_key($session_id)];

        $logged = false;

        add_action(
            'rfq_partial_submit_write',
            static function (string $logged_session_id, string $manifest_key, string $reason) use (&$logged, $session_id): void {
                $logged = $logged_session_id === $session_id
                    && $manifest_key === RFQ_S3_Key_Builder::manifest_meta_key($session_id)
                    && $reason === 'receipt_json_write_failed';
            }
        );

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertGreaterThanOrEqual(400, $response->get_status());
        $this->assertTrue($logged);
        $this->assertArrayHasKey(RFQ_S3_Key_Builder::manifest_meta_key($session_id), $this->mock->objects);
        $this->assertArrayNotHasKey(RFQ_S3_Key_Builder::receipt_meta_key($session_id), $this->mock->objects);

        global $wpdb;

        $status = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT status FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $session_id
            )
        );

        $this->assertSame('draft', $status);
    }

    public function test_submit_fires_receipt_durable_action_without_webhook(): void
    {
        $fired = false;

        add_action(
            'rfq_after_receipt_durable',
            static function (string $receipt_number, string $session_id, string $receipt_key) use (&$fired): void {
                $fired = $receipt_number !== '' && $session_id !== '' && $receipt_key !== '';
            }
        );

        [$session_id, $token, $manifest] = $this->prepare_submittable_session();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($fired);
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
                    'drawing_file_keys' => [
                        'intake/' . $session_id . '/drawings/uuid_drawing.pdf',
                    ],
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

            foreach ($part['drawing_file_keys'] ?? [] as $drawing_key) {
                if (is_string($drawing_key) && $drawing_key !== '') {
                    $this->mock->seed_object($drawing_key, 512, 'application/pdf');
                }
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
