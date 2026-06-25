<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RestControllerTest extends TestCase
{
    private RFQ_REST_Controller $controller;

    protected function setUp(): void
    {
        $this->controller = new RFQ_REST_Controller();
        $GLOBALS['rfq_transients'] = [];
        $GLOBALS['rfq_options'] = [];
        $GLOBALS['rfq_filters'] = [];
        $GLOBALS['wpdb'] = new RfqTestWpdb();
        RFQ_Secrets::ensure_encryption_key();
        RFQ_Secrets::set_secret('rfq_jwt_secret', 'jwt-secret-value');
    }

    /** @covers AC-WP-012 */
    public function test_health_check_returns_ok_when_s3_passes(): void
    {
        add_filter('rfq_intake_s3_health_check_override', static fn ($value) => true);

        $response = $this->controller->health_check();

        self::assertSame(['status' => 'ok'], $response);
    }

    /** @covers AC-WP-012 */
    public function test_health_check_returns_error_when_s3_settings_missing(): void
    {
        $response = $this->controller->health_check();

        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(503, $response->get_error_data()['status']);
    }

    /** @covers AC-WP-006 */
    public function test_create_session_persists_row_and_returns_token(): void
    {
        $request = new WP_REST_Request('POST', '/rfq/v1/sessions');
        $request->set_header('x-forwarded-for', '203.0.113.10');

        $response = $this->controller->create_session($request);

        self::assertIsArray($response);
        self::assertArrayHasKey('session_id', $response);
        self::assertArrayHasKey('token', $response);
        self::assertCount(1, $GLOBALS['wpdb']->rows[RFQ_Activator::sessions_table_name()]);
    }

    /** @covers AC-WP-006 */
    public function test_refresh_requires_valid_bearer_token(): void
    {
        $create_request = new WP_REST_Request('POST', '/rfq/v1/sessions');
        $session = $this->controller->create_session($create_request);

        $request = new WP_REST_Request('POST', '/rfq/v1/sessions/' . $session['session_id'] . '/refresh');
        $request['session_id'] = $session['session_id'];
        $request->set_header('authorization', 'Bearer ' . $session['token']);

        $permission = $this->controller->authorize_session_request($request);
        $response = $this->controller->refresh_session($request);

        self::assertTrue($permission);
        self::assertSame($session['session_id'], $request->get_attribute('rfq_session_claims')->session_id);
        self::assertIsArray($response);
        self::assertArrayHasKey('token', $response);
    }
}
