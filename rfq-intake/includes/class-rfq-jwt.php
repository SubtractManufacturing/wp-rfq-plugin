<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Jwt
{
    private const ALGORITHM = 'HS256';

    private const SUBJECT = 'rfq-session';

    private const TTL_SECONDS = 3600;

    public static function issue(string $session_id): string
    {
        $secret = self::get_signing_secret();
        $issued_at = time();

        $payload = [
            'sub' => self::SUBJECT,
            'session_id' => $session_id,
            'iat' => $issued_at,
            'exp' => $issued_at + self::TTL_SECONDS,
            'jti' => bin2hex(random_bytes(16)),
        ];

        return JWT::encode($payload, $secret, self::ALGORITHM);
    }

    /**
     * @return object{sub: string, session_id: string, iat: int, exp: int}
     */
    public static function validate(string $token, string $expected_session_id): object
    {
        $secret = self::get_signing_secret();
        $decoded = JWT::decode($token, new Key($secret, self::ALGORITHM));

        if (! isset($decoded->sub, $decoded->session_id, $decoded->iat, $decoded->exp)) {
            throw new InvalidArgumentException('RFQ JWT is missing required claims.');
        }

        if ($decoded->sub !== self::SUBJECT) {
            throw new InvalidArgumentException('RFQ JWT subject is invalid.');
        }

        if ($decoded->session_id !== $expected_session_id) {
            throw new InvalidArgumentException('RFQ JWT session_id does not match the request.');
        }

        return $decoded;
    }

    private static function get_signing_secret(): string
    {
        $secret = RFQ_Secrets::get_secret('rfq_jwt_secret');

        if ($secret === null || $secret === '') {
            throw new RuntimeException('RFQ JWT signing secret is not configured.');
        }

        return $secret;
    }
}
