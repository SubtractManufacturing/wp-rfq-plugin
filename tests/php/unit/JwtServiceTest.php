<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class JwtServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['rfq_options'] = [];
        $GLOBALS['rfq_filters'] = [
            'rfq_intake_now' => [
                static fn ($value) => 1710000000,
            ],
        ];
        RFQ_Secrets::ensure_encryption_key();
        RFQ_Secrets::set_secret('rfq_jwt_secret', 'jwt-secret-value');
    }

    /** @covers AC-WP-006 */
    public function test_issue_and_validate_session_token(): void
    {
        $token = RFQ_JWT::issue('session-123');
        $claims = RFQ_JWT::validate($token, 'session-123');

        self::assertSame('rfq-session', $claims->sub);
        self::assertSame('session-123', $claims->session_id);
        self::assertSame(1710003600, $claims->exp);
    }
}
