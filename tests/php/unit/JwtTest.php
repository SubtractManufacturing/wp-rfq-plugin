<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Jwt::class)]
#[Group('AC-WP-006')]
class JwtTest extends TestCase
{
    private const SESSION_ID = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['rfq_test_options'] = [];
        RFQ_Secrets::ensure_encryption_key();
        RFQ_Secrets::set_secret('rfq_jwt_secret', bin2hex(random_bytes(32)));
    }

    public function test_issue_and_validate_round_trip(): void
    {
        $token = RFQ_Jwt::issue(self::SESSION_ID);
        $payload = RFQ_Jwt::validate($token, self::SESSION_ID);

        $this->assertSame('rfq-session', $payload->sub);
        $this->assertSame(self::SESSION_ID, $payload->session_id);
        $this->assertSame($payload->exp, $payload->iat + 3600);
    }

    public function test_validate_rejects_wrong_session_id(): void
    {
        $token = RFQ_Jwt::issue(self::SESSION_ID);

        $this->expectException(InvalidArgumentException::class);
        RFQ_Jwt::validate($token, '22222222-2222-4222-8222-222222222222');
    }

    public function test_validate_rejects_expired_token(): void
    {
        $secret = RFQ_Secrets::get_secret('rfq_jwt_secret');
        $this->assertIsString($secret);

        $token = JWT::encode(
            [
                'sub' => 'rfq-session',
                'session_id' => self::SESSION_ID,
                'iat' => time() - 7200,
                'exp' => time() - 3600,
            ],
            $secret,
            'HS256'
        );

        $this->expectException(Throwable::class);
        RFQ_Jwt::validate($token, self::SESSION_ID);
    }

    public function test_validate_rejects_invalid_signature(): void
    {
        $token = JWT::encode(
            [
                'sub' => 'rfq-session',
                'session_id' => self::SESSION_ID,
                'iat' => time(),
                'exp' => time() + 3600,
            ],
            'wrong-secret-that-is-long-enough-for-hs256',
            'HS256'
        );

        $this->expectException(Throwable::class);
        RFQ_Jwt::validate($token, self::SESSION_ID);
    }
}
