<?php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_JWT
{
    public static function issue(string $session_id): string
    {
        $issued_at = self::now();
        $secret = RFQ_Secrets::get_secret('rfq_jwt_secret');

        if ($secret === null) {
            throw new RuntimeException('JWT secret is not configured.');
        }

        return JWT::encode(
            [
                'sub' => 'rfq-session',
                'session_id' => $session_id,
                'iat' => $issued_at,
                'exp' => $issued_at + HOUR_IN_SECONDS,
            ],
            $secret,
            'HS256'
        );
    }

    public static function validate(string $token, string $expected_session_id): object
    {
        $secret = RFQ_Secrets::get_secret('rfq_jwt_secret');

        if ($secret === null) {
            throw new RuntimeException('JWT secret is not configured.');
        }

        $claims = JWT::decode($token, new Key($secret, 'HS256'));

        if (($claims->sub ?? '') !== 'rfq-session') {
            throw new UnexpectedValueException('Unexpected JWT subject.');
        }

        if (($claims->session_id ?? '') !== $expected_session_id) {
            throw new UnexpectedValueException('JWT session mismatch.');
        }

        return $claims;
    }

    private static function now(): int
    {
        return (int) apply_filters('rfq_intake_now', time());
    }
}

