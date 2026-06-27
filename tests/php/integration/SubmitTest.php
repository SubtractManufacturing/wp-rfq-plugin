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
class SubmitTest extends TestCase
{
    private RFQ_S3_Client_Mock $mock;

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

        RFQ_Activator::bootstrap_secrets();

        $_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int(10, 250);

        global $wpdb;
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

        $this->mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', fn (): RFQ_S3_Client_Mock => $this->mock);
    }

    public function test_submit_returns_receipt_and_writes_manifest_and_receipt_objects(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());

        $receipt_number = $response->get_data()['receipt_number'];
        $this->assertMatchesRegularExpression(
            '/^RFQ-' . gmdate('Ymd') . '-\d{6}$/',
            $receipt_number
        );

        $manifest_key = 'intake/' . $session_id . '/meta/manifest.json';
        $receipt_key = 'intake/' . $session_id . '/meta/receipt.json';

        $this->assertArrayHasKey($manifest_key, $this->mock->objects);
        $this->assertArrayHasKey($receipt_key, $this->mock->objects);

        $stored_receipt = json_decode($this->mock->objects[$receipt_key]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($receipt_number, $stored_receipt['receipt_number']);
        $this->assertSame($session_id, $stored_receipt['session_id']);
        $this->assertSame($manifest_key, $stored_receipt['manifest_key']);
        $this->assertNotEmpty($stored_receipt['submitted_at']);

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, receipt_number, submitted_at, shipping_postal_code, submitted_part_count
                 FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $session_id
            ),
            ARRAY_A
        );

        $this->assertSame('submitted', $row['status']);
        $this->assertSame($receipt_number, $row['receipt_number']);
        $this->assertNotNull($row['submitted_at']);
        $this->assertSame('90210', $row['shipping_postal_code']);
        $this->assertSame('1', $row['submitted_part_count']);
    }

    public function test_submit_retry_returns_same_receipt_without_rewriting_objects(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $first = $this->request_submit($session_id, $token, $manifest);
        $receipt_key = 'intake/' . $session_id . '/meta/receipt.json';
        $first_receipt_body = $this->mock->objects[$receipt_key]['body'];

        $second = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $second->get_status());
        $this->assertSame($first->get_data()['receipt_number'], $second->get_data()['receipt_number']);
        $this->assertSame($first_receipt_body, $this->mock->objects[$receipt_key]['body']);
    }

    public function test_submit_retry_recovers_when_receipt_exists_in_s3_but_db_row_is_still_draft(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $first = $this->request_submit($session_id, $token, $manifest);
        $receipt_number = $first->get_data()['receipt_number'];

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            [
                'status' => 'draft',
                'receipt_number' => null,
                'submitted_at' => null,
                'submitted_part_count' => null,
            ],
            ['session_id' => $session_id],
            ['%s', '%s', '%s', '%s'],
            ['%s']
        );

        $second = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $second->get_status());
        $this->assertSame($receipt_number, $second->get_data()['receipt_number']);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, receipt_number FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $session_id
            ),
            ARRAY_A
        );

        $this->assertSame('submitted', $row['status']);
        $this->assertSame($receipt_number, $row['receipt_number']);
    }

    public function test_submit_rejects_invalid_manifest_with_field_errors(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $manifest['global']['shipping_destination']['postal_code'] = 'NOT-A-ZIP';

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey(
            'global.shipping_destination.postal_code',
            $response->get_data()['data']['fields']
        );
    }

    public function test_submit_rejects_missing_s3_object_with_identifying_field_error(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('parts[0].part_file_key', $response->get_data()['data']['fields']);
    }

    public function test_submit_rejects_wrong_session_file_key(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $other_session = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $manifest['parts'][0]['part_file_key'] = 'intake/' . $other_session . '/parts/uuid_bracket.step';
        $this->mock->seed_object($manifest['parts'][0]['part_file_key'], 'application/octet-stream', 100);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertStringContainsString(
            'session',
            $response->get_data()['data']['fields']['parts[0].part_file_key']
        );
    }

    public function test_submit_rejects_oversize_part_file(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $part_key = $manifest['parts'][0]['part_file_key'];
        $this->mock->seed_object($part_key, 'application/octet-stream', RFQ_S3_Key_Builder::PART_MAX_BYTES + 1);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('parts[0].part_file_key', $response->get_data()['data']['fields']);
    }

    public function test_submit_rejects_conflicting_drawing_content_type(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->mock->seed_object($manifest['parts'][0]['part_file_key'], 'application/octet-stream', 100);
        $drawing_key = $manifest['parts'][0]['drawing_file_keys'][0];
        $this->mock->seed_object($drawing_key, 'image/gif', 100);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('parts[0].drawing_file_keys[0]', $response->get_data()['data']['fields']);
    }

    public function test_submit_rejects_more_than_twenty_parts(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $manifest['parts'] = [];

        for ($index = 0; $index < 21; $index++) {
            $part_id = sprintf('11111111-1111-4111-8111-%012d', $index);
            $manifest['parts'][] = [
                'part_id' => $part_id,
                'part_file_key' => 'intake/' . $session_id . '/parts/' . $part_id . '_part.step',
                'drawing_file_keys' => [],
                'material' => 'Aluminum 6061',
                'tolerance' => 'standard',
                'tolerance_detail' => null,
                'quantity' => 1,
                'target_unit_price' => null,
                'notes' => null,
            ];
            $this->mock->seed_object(
                $manifest['parts'][$index]['part_file_key'],
                'application/octet-stream',
                100
            );
        }

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('parts', $response->get_data()['data']['fields']);
    }

    public function test_concurrent_submits_for_different_sessions_allocate_unique_receipt_numbers(): void
    {
        $receipt_numbers = [];

        for ($index = 0; $index < 3; $index++) {
            [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
            $this->seed_manifest_files($session_id, $manifest);

            $response = $this->request_submit($session_id, $token, $manifest);
            $this->assertSame(200, $response->get_status());
            $receipt_numbers[] = $response->get_data()['receipt_number'];
        }

        $this->assertCount(3, array_unique($receipt_numbers));
    }

    public function test_submit_rejects_invalid_jwt(): void
    {
        [$session_id, , $manifest] = $this->create_session_ready_for_submit();

        $response = $this->request_submit($session_id, 'invalid-token', $manifest);

        $this->assertSame(401, $response->get_status());
    }

    public function test_submit_ignores_extra_objects_in_session_prefix(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);
        $this->mock->seed_object(
            'intake/' . $session_id . '/parts/orphan.step',
            'application/octet-stream',
            100
        );

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
    }

    public function test_receipt_created_action_fires_after_successful_submit(): void
    {
        [$session_id, $token, $manifest] = $this->create_session_ready_for_submit();
        $this->seed_manifest_files($session_id, $manifest);

        $captured = null;

        add_action(
            'rfq_receipt_created',
            static function (string $receipt_number, string $captured_session_id, string $receipt_key) use (&$captured): void {
                $captured = [
                    'receipt_number' => $receipt_number,
                    'session_id' => $captured_session_id,
                    'receipt_key' => $receipt_key,
                ];
            },
            10,
            3
        );

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
        $this->assertIsArray($captured);
        $this->assertSame($response->get_data()['receipt_number'], $captured['receipt_number']);
        $this->assertSame($session_id, $captured['session_id']);
        $this->assertSame('intake/' . $session_id . '/meta/receipt.json', $captured['receipt_key']);
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
