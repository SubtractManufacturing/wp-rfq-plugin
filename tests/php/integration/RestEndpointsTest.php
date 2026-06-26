<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_REST_Controller::class)]
#[Group('AC-WP-006')]
#[Group('AC-WP-012')]
#[Group('AC-WP-024')]
class RestEndpointsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        RFQ_Activator::activate();
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');

        delete_option('rfq_s3_endpoint');
        delete_option('rfq_s3_bucket');
        delete_option('rfq_s3_access_key_id');
        delete_option('rfq_s3_region');
        delete_option('rfq_s3_secret_key');
        delete_option('rfq_jwt_secret');

        RFQ_Activator::bootstrap_secrets();

        $_SERVER['REMOTE_ADDR'] = '198.51.100.25';

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

        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                '_transient_rfq_upload_urls_%',
                '_transient_timeout_rfq_upload_urls_%'
            )
        );
    }

    public function test_create_session_returns_uuid_token_and_draft_row(): void
    {
        $response = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));

        $this->assertSame(201, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $data['session_id']
        );
        $this->assertNotSame('', $data['token']);

        $payload = RFQ_Jwt::validate($data['token'], $data['session_id']);
        $this->assertSame('rfq-session', $payload->sub);

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, s3_prefix FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $data['session_id']
            ),
            ARRAY_A
        );

        $this->assertSame('draft', $row['status']);
        $this->assertSame('intake/' . $data['session_id'] . '/', $row['s3_prefix']);
    }

    public function test_refresh_session_returns_new_token_for_valid_jwt(): void
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $session_id = $create->get_data()['session_id'];
        $token = $create->get_data()['token'];

        $request = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/refresh');
        $request->set_header('Authorization', 'Bearer ' . $token);

        $response = rest_do_request($request);

        $this->assertSame(200, $response->get_status());
        $this->assertNotSame($token, $response->get_data()['token']);
        RFQ_Jwt::validate($response->get_data()['token'], $session_id);
    }

    public function test_refresh_session_rejects_submitted_session(): void
    {
        global $wpdb;

        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $session_id = $create->get_data()['session_id'];
        $token = $create->get_data()['token'];

        $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            ['status' => 'submitted'],
            ['session_id' => $session_id],
            ['%s'],
            ['%s']
        );

        $request = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/refresh');
        $request->set_header('Authorization', 'Bearer ' . $token);

        $response = rest_do_request($request);

        $this->assertSame(403, $response->get_status());
    }

    public function test_refresh_session_rejects_invalid_token(): void
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $session_id = $create->get_data()['session_id'];

        $request = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/refresh');
        $request->set_header('Authorization', 'Bearer invalid-token');

        $response = rest_do_request($request);

        $this->assertSame(401, $response->get_status());
    }

    public function test_session_creation_rate_limit_returns_429_on_eleventh_request(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.99';

        for ($index = 0; $index < RFQ_SESSION_RATE_LIMIT; $index++) {
            $response = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
            $this->assertSame(201, $response->get_status(), 'Request ' . ($index + 1));
        }

        $response = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));

        $this->assertSame(429, $response->get_status());
    }

    public function test_create_session_rolls_back_when_jwt_issue_fails(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        update_option('rfq_jwt_secret', 'invalid-blob');

        $this->assertNull(RFQ_Secrets::get_secret('rfq_jwt_secret'));

        $response = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));

        $this->assertSame(500, $response->get_status());

        global $wpdb;

        $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'rfq_sessions');
        $this->assertSame(0, $count);
        $this->assertSame(0, RFQ_Rate_Limiter::get_session_creation_count('203.0.113.50'));
    }

    public function test_health_returns_503_when_s3_is_not_configured(): void
    {
        $response = rest_do_request(new WP_REST_Request('GET', '/rfq/v1/health'));

        $this->assertSame(503, $response->get_status());
        $this->assertSame(['status' => 'unavailable'], $response->get_data());
    }

    public function test_health_returns_ok_when_s3_check_succeeds(): void
    {
        update_option('rfq_s3_endpoint', 'https://example.test');
        update_option('rfq_s3_bucket', 'rfq-test-bucket');
        update_option('rfq_s3_access_key_id', 'test-access-key');
        update_option('rfq_s3_region', 'us-east-1');
        RFQ_Secrets::set_secret('rfq_s3_secret_key', 'test-secret-key');

        add_filter('rfq_s3_verify_connectivity', static fn (): bool => true);

        $response = rest_do_request(new WP_REST_Request('GET', '/rfq/v1/health'));

        $this->assertSame(200, $response->get_status());
        $this->assertSame(['status' => 'ok'], $response->get_data());
    }

    public function test_later_phase_routes_still_return_not_implemented(): void
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $session_id = $create->get_data()['session_id'];
        $token = $create->get_data()['token'];

        $request = new WP_REST_Request('PUT', '/rfq/v1/sessions/' . $session_id . '/draft');
        $request->set_header('Authorization', 'Bearer ' . $token);

        $response = rest_do_request($request);

        $this->assertSame(501, $response->get_status());

        $submit = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/submit');
        $submit->set_header('Authorization', 'Bearer ' . $token);

        $this->assertSame(501, rest_do_request($submit)->get_status());
    }

    public function test_patch_contact_persists_valid_contact_and_keeps_draft_status(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();

        $response = $this->request_contact_patch($session_id, $token, [
            'first_name' => ' Jane ',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'company' => 'Acme Corp',
            'phone' => '5555550100',
            'job_title' => null,
        ]);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertSame('Jane', $data['first_name']);
        $this->assertSame('Smith', $data['last_name']);
        $this->assertSame('jane@example.com', $data['email']);
        $this->assertSame('Acme Corp', $data['company']);
        $this->assertSame('5555550100', $data['phone']);
        $this->assertSame('1', $data['phone_country_code']);
        $this->assertNull($data['job_title']);

        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT status, contact_first_name, contact_last_name, contact_email, contact_company, contact_phone, contact_phone_country_code, contact_job_title
                 FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $session_id
            ),
            ARRAY_A
        );

        $this->assertSame('draft', $row['status']);
        $this->assertSame('Jane', $row['contact_first_name']);
        $this->assertSame('Smith', $row['contact_last_name']);
        $this->assertSame('jane@example.com', $row['contact_email']);
        $this->assertSame('Acme Corp', $row['contact_company']);
        $this->assertSame('5555550100', $row['contact_phone']);
        $this->assertSame('1', $row['contact_phone_country_code']);
        $this->assertNull($row['contact_job_title']);
    }

    public function test_patch_contact_rejects_missing_required_fields(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();

        $response = $this->request_contact_patch($session_id, $token, [
            'first_name' => '',
            'last_name' => 'Smith',
            'email' => 'invalid',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('first_name', $response->get_data()['data']['params']);
        $this->assertArrayHasKey('email', $response->get_data()['data']['params']);
    }

    public function test_patch_contact_rejects_invalid_phone_combination(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();

        $response = $this->request_contact_patch($session_id, $token, [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '5555550100',
            'phone_country_code' => '44',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('phone_country_code', $response->get_data()['data']['params']);
    }

    public function test_patch_contact_persists_blank_optional_fields_as_null(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();

        $response = $this->request_contact_patch($session_id, $token, [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'company' => '   ',
            'phone' => '',
            'job_title' => '',
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertNull($response->get_data()['company']);
        $this->assertNull($response->get_data()['phone']);
        $this->assertNull($response->get_data()['phone_country_code']);
        $this->assertNull($response->get_data()['job_title']);
    }

    public function test_patch_contact_rejects_invalid_jwt(): void
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $session_id = $create->get_data()['session_id'];

        $response = $this->request_contact_patch($session_id, 'invalid-token', [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame(401, $response->get_status());
    }

    public function test_patch_contact_rejects_submitted_session(): void
    {
        global $wpdb;

        [$session_id, $token] = $this->create_authenticated_session();

        $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            ['status' => 'submitted'],
            ['session_id' => $session_id],
            ['%s'],
            ['%s']
        );

        $response = $this->request_contact_patch($session_id, $token, [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame(403, $response->get_status());
    }

    public function test_patch_contact_rejects_non_json_body(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();

        $request = new WP_REST_Request('PATCH', '/rfq/v1/sessions/' . $session_id . '/contact');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body('not-json');

        $response = rest_do_request($request);

        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('body', $response->get_data()['data']['params']);
    }

    public function test_upload_urls_rejects_session_without_contact(): void
    {
        [$session_id, $token] = $this->create_authenticated_session();

        $response = $this->request_upload_url($session_id, $token, [
            'part_id' => '11111111-1111-4111-8111-111111111111',
            'file_type' => 'part',
            'filename' => 'bracket.step',
            'content_type' => 'application/octet-stream',
        ]);

        $this->assertSame(403, $response->get_status());
    }

    public function test_upload_urls_returns_presigned_put_for_part_file(): void
    {
        $mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', static fn (): RFQ_S3_Client_Mock => $mock);

        [$session_id, $token] = $this->create_authenticated_session_with_contact();
        $part_id = '22222222-2222-4222-8222-222222222222';

        $response = $this->request_upload_url($session_id, $token, [
            'part_id' => $part_id,
            'file_type' => 'part',
            'filename' => 'bracket.step',
            'content_type' => 'application/octet-stream',
        ]);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertStringStartsWith('https://mock-s3.test/', $data['upload_url']);
        $this->assertMatchesRegularExpression(
            '#^intake/' . preg_quote($session_id, '#') . '/parts/[0-9a-f-]{36}_bracket\.step$#',
            $data['file_key']
        );
        $this->assertSame($data['file_key'], $mock->presigned_puts[0]['key']);
        $this->assertSame('application/octet-stream', $mock->presigned_puts[0]['content_type']);
        $this->assertSame(RFQ_S3_Key_Builder::PART_MAX_BYTES, $mock->presigned_puts[0]['max_bytes']);
    }

    public function test_upload_urls_returns_presigned_put_for_drawing_file(): void
    {
        $mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', static fn (): RFQ_S3_Client_Mock => $mock);

        [$session_id, $token] = $this->create_authenticated_session_with_contact();
        $part_id = '33333333-3333-4333-8333-333333333333';

        $response = $this->request_upload_url($session_id, $token, [
            'part_id' => $part_id,
            'file_type' => 'drawing',
            'filename' => 'drawing.pdf',
            'content_type' => 'application/pdf',
        ]);

        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertStringContainsString('/drawings/', $data['file_key']);
        $this->assertSame(RFQ_S3_Key_Builder::DRAWING_MAX_BYTES, $mock->presigned_puts[0]['max_bytes']);
    }

    public function test_upload_urls_sanitizes_filename_in_generated_key(): void
    {
        $mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', static fn (): RFQ_S3_Client_Mock => $mock);

        [$session_id, $token] = $this->create_authenticated_session_with_contact();

        $response = $this->request_upload_url($session_id, $token, [
            'part_id' => '44444444-4444-4444-8444-444444444444',
            'file_type' => 'part',
            'filename' => 'my bracket (rev 2).step',
            'content_type' => 'application/octet-stream',
        ]);

        $this->assertSame(200, $response->get_status());
        $this->assertStringEndsWith('_my_bracket__rev_2_.step', $response->get_data()['file_key']);
    }

    public function test_upload_urls_rejects_invalid_jwt(): void
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $session_id = $create->get_data()['session_id'];

        $response = $this->request_upload_url($session_id, 'invalid-token', [
            'part_id' => '55555555-5555-4555-8555-555555555555',
            'file_type' => 'part',
            'filename' => 'bracket.step',
            'content_type' => 'application/octet-stream',
        ]);

        $this->assertSame(401, $response->get_status());
    }

    public function test_upload_urls_rejects_unsupported_file_type(): void
    {
        [$session_id, $token] = $this->create_authenticated_session_with_contact();

        $response = $this->request_upload_url($session_id, $token, [
            'part_id' => '66666666-6666-4666-8666-666666666666',
            'file_type' => 'blueprint',
            'filename' => 'drawing.pdf',
            'content_type' => 'application/pdf',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('file_type', $response->get_data()['data']['params']);
    }

    public function test_upload_urls_rejects_unsupported_content_type_for_drawing(): void
    {
        [$session_id, $token] = $this->create_authenticated_session_with_contact();

        $response = $this->request_upload_url($session_id, $token, [
            'part_id' => '77777777-7777-4777-8777-777777777777',
            'file_type' => 'drawing',
            'filename' => 'drawing.gif',
            'content_type' => 'image/gif',
        ]);

        $this->assertSame(400, $response->get_status());
        $this->assertArrayHasKey('content_type', $response->get_data()['data']['params']);
    }

    public function test_upload_urls_rejects_submitted_session(): void
    {
        global $wpdb;

        [$session_id, $token] = $this->create_authenticated_session();

        $wpdb->update(
            $wpdb->prefix . 'rfq_sessions',
            ['status' => 'submitted'],
            ['session_id' => $session_id],
            ['%s'],
            ['%s']
        );

        $response = $this->request_upload_url($session_id, $token, [
            'part_id' => '88888888-8888-4888-8888-888888888888',
            'file_type' => 'part',
            'filename' => 'bracket.step',
            'content_type' => 'application/octet-stream',
        ]);

        $this->assertSame(403, $response->get_status());
    }

    public function test_upload_urls_rate_limits_after_two_hundred_requests(): void
    {
        $mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', static fn (): RFQ_S3_Client_Mock => $mock);

        [$session_id, $token] = $this->create_authenticated_session_with_contact();

        for ($index = 0; $index < RFQ_MAX_UPLOAD_URLS_PER_SESSION; $index++) {
            $response = $this->request_upload_url($session_id, $token, [
                'part_id' => '99999999-9999-4999-8999-999999999999',
                'file_type' => 'part',
                'filename' => 'bracket-' . $index . '.step',
                'content_type' => 'application/octet-stream',
            ]);

            $this->assertSame(200, $response->get_status(), 'Request ' . ($index + 1));
        }

        $response = $this->request_upload_url($session_id, $token, [
            'part_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'file_type' => 'part',
            'filename' => 'overflow.step',
            'content_type' => 'application/octet-stream',
        ]);

        $this->assertSame(429, $response->get_status());
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function create_authenticated_session_with_contact(): array
    {
        [$session_id, $token] = $this->create_authenticated_session();

        $response = $this->request_contact_patch($session_id, $token, [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame(200, $response->get_status());

        return [$session_id, $token];
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

    /**
     * @param array<string, string> $body
     */
    private function request_upload_url(string $session_id, string $token, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/upload-urls');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_do_request($request);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request_contact_patch(string $session_id, string $token, array $body): WP_REST_Response
    {
        $request = new WP_REST_Request('PATCH', '/rfq/v1/sessions/' . $session_id . '/contact');
        $request->set_header('Authorization', 'Bearer ' . $token);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));

        return rest_do_request($request);
    }
}
