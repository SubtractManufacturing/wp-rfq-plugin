<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Rate_Limiter
{
    private const SESSION_TRANSIENT_PREFIX = 'rfq_sessions_';

    private const UPLOAD_URL_TRANSIENT_PREFIX = 'rfq_upload_urls_';

    public static function is_session_creation_allowed(string $ip): bool
    {
        return self::get_session_creation_count($ip) < RFQ_SESSION_RATE_LIMIT;
    }

    public static function get_session_creation_count(string $ip): int
    {
        $count = get_transient(self::session_transient_key($ip));

        return $count === false ? 0 : (int) $count;
    }

    public static function record_session_creation(string $ip): void
    {
        $key = self::session_transient_key($ip);
        $count = self::get_session_creation_count($ip) + 1;

        set_transient($key, $count, HOUR_IN_SECONDS);
    }

    public static function is_upload_url_allowed(string $session_id): bool
    {
        return self::get_upload_url_count($session_id) < RFQ_MAX_UPLOAD_URLS_PER_SESSION;
    }

    public static function get_upload_url_count(string $session_id): int
    {
        $count = get_transient(self::upload_url_transient_key($session_id));

        return $count === false ? 0 : (int) $count;
    }

    public static function record_upload_url(string $session_id): void
    {
        $key = self::upload_url_transient_key($session_id);
        $count = self::get_upload_url_count($session_id) + 1;

        set_transient($key, $count, RFQ_DRAFT_SESSION_RETENTION_DAYS * DAY_IN_SECONDS);
    }

    private static function session_transient_key(string $ip): string
    {
        return self::SESSION_TRANSIENT_PREFIX . hash('sha256', $ip);
    }

    private static function upload_url_transient_key(string $session_id): string
    {
        return self::UPLOAD_URL_TRANSIENT_PREFIX . $session_id;
    }
}
