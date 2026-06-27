<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Receipt_Service::class)]
#[CoversClass(RFQ_REST_Controller::class)]
#[Group('AC-WP-001')]
#[Group('AC-WP-005')]
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

        delete_option('rfq_s3_endpoint');
        delete_option('rfq_s3_bucket');
        delete_option('rfq_s3_access_key_id');
        delete_option('rfq_s3_region');
        delete_option('rfq_s3_secret_key');
        delete_option('rfq_jwt_secret');

        RFQ_Activator::bootstrap_secrets();

        $_SERVER['REMOTE_ADDR'] = '198.51.100.30';

        remove_all_filters('rfq_s3_verify_connectivity');
        remove_all_filters('rfq_s3_client');

        $this->mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', fn (): RFQ_S3_Client_Mock => $this->mock);
    }

    public function test_valid_submit_writes_manifest_receipt_and_updates_session(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();
        $manifest = $this->valid_manifest($session_id);
        $this->seed_manifest_files($session_id, $manifest);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());

        $receipt_number = $response->get_data()['receipt_number'];
        $this->assertMatchesRegularExpression('/^RFQ-' . gmdate('Ymd') . '-\d{6}$/', $receipt_number);

        $manifest_key = 'intake/' . $session_id . '/meta/manifest.json';
        $receipt_key = 'intake/' . $session_id . '/meta/receipt.json';

        $this->assertArrayHasKey($manifest_key, $this->mock->objects);
        $this->assertArrayHasKey($receipt_key, $this->mock->objects);

        $stored_manifest = json_decode($this->mock->objects[$manifest_key]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($session_id, $stored_manifest['session_id']);

        $stored_receipt = json_decode($this->mock->objects[$receipt_key]['body'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($receipt_number, $stored_receipt['receipt_number']);
        $this->assertSame($session_id, $stored_receipt['session_id']);
        $this->assertSame($manifest_key, $stored_receipt['manifest_key']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $stored_receipt['submitted_at']);

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

    public function test_invalid_manifest_returns_422_field_errors(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();
        $manifest = $this->valid_manifest($session_id);
        $manifest['contact']['email'] = 'not-an-email';

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('contact.email', $response->get_data()['data']['fields']);
    }

    public function test_missing_s3_object_returns_identifying_error(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();
        $manifest = $this->valid_manifest($session_id);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertSame('rfq_file_validation', $response->get_data()['code']);
        $this->assertArrayHasKey('parts[0].part_file_key', $response->get_data()['data']['fields']);
    }

    public function test_wrong_session_file_key_is_rejected(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();
        $other_session = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $manifest = $this->valid_manifest($session_id);
        $manifest['parts'][0]['part_file_key'] = 'intake/' . $other_session . '/parts/uuid_bracket.step';

        $this->mock->seed_object(
            $manifest['parts'][0]['part_file_key'],
            'application/octet-stream',
            1024
        );

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('parts[0].part_file_key', $response->get_data()['data']['fields']);
    }

    public function test_oversize_file_key_is_rejected(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();
        $manifest = $this->valid_manifest($session_id);
        $part_key = $manifest['parts'][0]['part_file_key'];

        $this->mock->seed_object(
            $part_key,
            'application/octet-stream',
            RFQ_S3_Key_Builder::PART_MAX_BYTES + 1
        );

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('parts[0].part_file_key', $response->get_data()['data']['fields']);
    }

    public function test_drawing_content_type_conflict_is_rejected(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();
        $manifest = $this->valid_manifest($session_id);
        $part_key = $manifest['parts'][0]['part_file_key'];
        $drawing_key = $manifest['parts'][0]['drawing_file_keys'][0];

        $this->mock->seed_object($part_key, 'application/octet-stream', 1024);
        $this->mock->seed_object($drawing_key, 'image/gif', 1024);

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('parts[0].drawing_file_keys[0]', $response->get_data()['data']['fields']);
    }

    public function test_idempotent_resubmit_returns_same_receipt_without_rewriting_s3(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();
        $manifest = $this->valid_manifest($session_id);
        $this->seed_manifest_files($session_id, $manifest);

        $first = $this->request_submit($session_id, $token, $manifest);
        $this->assertSame(200, $first->get_status());
        $receipt_number = $first->get_data()['receipt_number'];

        $manifest_key = 'intake/' . $session_id . '/meta/manifest.json';
        $receipt_key = 'intake/' . $session_id . '/meta/receipt.json';
        $manifest_body_before = $this->mock->objects[$manifest_key]['body'];
        $receipt_body_before = $this->mock->objects[$receipt_key]['body'];

        $second = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $second->get_status());
        $this->assertSame($receipt_number, $second->get_data()['receipt_number']);
        $this->assertSame($manifest_body_before, $this->mock->objects[$manifest_key]['body']);
        $this->assertSame($receipt_body_before, $this->mock->objects[$receipt_key]['body']);

        global $wpdb;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'rfq_receipt_sequences WHERE receipt_date = %s',
                gmdate('Ymd')
            )
        );
        $this->assertSame(1, $count);

        $seq = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT seq FROM ' . $wpdb->prefix . 'rfq_receipt_sequences WHERE receipt_date = %s',
                gmdate('Ymd')
            )
        );
        $this->assertSame(1, $seq);
    }

    public function test_concurrent_submits_for_different_sessions_allocate_unique_receipt_numbers(): void
    {
        $receipt_numbers = [];

        for ($index = 0; $index < 3; $index++) {
            [$session_id, $token] = $this->create_authenticated_session();
            $manifest = $this->valid_manifest($session_id);
            $this->seed_manifest_files($session_id, $manifest);

            $response = $this->request_submit($session_id, $token, $manifest);
            $this->assertSame(200, $response->get_status());
            $receipt_numbers[] = $response->get_data()['receipt_number'];
        }

        $this->assertCount(3, array_unique($receipt_numbers));
        $this->assertSame('RFQ-' . gmdate('Ymd') . '-000001', $receipt_numbers[0]);
        $this->assertSame('RFQ-' . gmdate('Ymd') . '-000002', $receipt_numbers[1]);
        $this->assertSame('RFQ-' . gmdate('Ymd') . '-000003', $receipt_numbers[2]);
    }

    public function test_submit_requires_valid_jwt(): void
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $session_id = $create->get_data()['session_id'];

        $response = $this->request_submit($session_id, 'invalid-token', $this->valid_manifest($session_id));

        $this->assertSame(401, $response->get_status());
    }

    public function test_unlisted_session_prefix_objects_do_not_block_submit(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();
        $manifest = $this->valid_manifest($session_id);
        $this->seed_manifest_files($session_id, $manifest);

        $this->mock->seed_object(
            'intake/' . $session_id . '/parts/orphan_file.step',
            'application/octet-stream',
            1024
        );

        $response = $this->request_submit($session_id, $token, $manifest);

        $this->assertSame(200, $response->get_status());
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function seed_manifest_files(string $session_id, array $manifest): void
    {
        foreach ($manifest['parts'] as $part) {
            $this->mock->seed_object(
                $part['part_file_key'],
                'application/octet-stream',
                1024
            );

            foreach ($part['drawing_file_keys'] as $drawing_key) {
                $this->mock->seed_object(
                    $drawing_key,
                    'application/pdf',
                    2048
                );
            }
        }
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
                    'quantity' => 10,
                ],
            ],
            'global' => [
                'required_delivery_date' => gmdate('Y-m-d'),
                'lead_time_preference' => 'standard',
                'shipping_destination' => [
                    'postal_code' => '90210',
                ],
                'nda_required' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request_submit(string $session_id, string $token, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/submit');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_do_request($request);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function create_authenticated_session(): array
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $data = $create->get_data();

        return [$data['session_id'], $data['token']];
    }
}
