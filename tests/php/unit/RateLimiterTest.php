<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['rfq_transients'] = [];
    }

    /** @covers AC-WP-006 */
    public function test_eleventh_session_is_rate_limited(): void
    {
        for ($i = 0; $i < RFQ_SESSION_RATE_LIMIT; $i++) {
            self::assertTrue(RFQ_Rate_Limiter::check_session_creation_limit('127.0.0.1'));
        }

        $result = RFQ_Rate_Limiter::check_session_creation_limit('127.0.0.1');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('rfq_rate_limited', $result->get_error_code());
        self::assertSame(429, $result->get_error_data()['status']);
    }
}

