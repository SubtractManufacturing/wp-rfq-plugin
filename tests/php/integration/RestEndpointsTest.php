<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_REST_Controller::class)]
#[Group('AC-WP-006')]
#[Group('AC-WP-012')]
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

    public function test_later_phase_route_returns_not_implemented(): void
    {
        $create = rest_do_request(new WP_REST_Request('POST', '/rfq/v1/sessions'));
        $session_id = $create->get_data()['session_id'];
        $token = $create->get_data()['token'];

        $request = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session_id . '/upload-urls');
        $request->set_header('Authorization', 'Bearer ' . $token);

        $response = rest_do_request($request);

        $this->assertSame(501, $response->get_status());
    }
}
