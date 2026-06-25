<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers AC-WP-006
 */
class RateLimiterTest extends TestCase
{
    private const IP = '203.0.113.10';

    private const SESSION_ID = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['rfq_test_transients'] = [];
    }

    public function test_session_creation_allows_up_to_limit(): void
    {
        for ($index = 0; $index < RFQ_SESSION_RATE_LIMIT; $index++) {
            $this->assertTrue(RFQ_Rate_Limiter::is_session_creation_allowed(self::IP));
            RFQ_Rate_Limiter::record_session_creation(self::IP);
        }

        $this->assertFalse(RFQ_Rate_Limiter::is_session_creation_allowed(self::IP));
        $this->assertSame(RFQ_SESSION_RATE_LIMIT, RFQ_Rate_Limiter::get_session_creation_count(self::IP));
    }

    public function test_upload_url_limit_primitive_tracks_per_session(): void
    {
        for ($index = 0; $index < RFQ_MAX_UPLOAD_URLS_PER_SESSION; $index++) {
            $this->assertTrue(RFQ_Rate_Limiter::is_upload_url_allowed(self::SESSION_ID));
            RFQ_Rate_Limiter::record_upload_url(self::SESSION_ID);
        }

        $this->assertFalse(RFQ_Rate_Limiter::is_upload_url_allowed(self::SESSION_ID));
        $this->assertSame(
            RFQ_MAX_UPLOAD_URLS_PER_SESSION,
            RFQ_Rate_Limiter::get_upload_url_count(self::SESSION_ID)
        );
    }
}
