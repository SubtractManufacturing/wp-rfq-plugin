<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Rate_Limiter
{
    public static function check_session_creation_limit(string $ip_address)
    {
        $key = 'rfq_sessions_' . md5($ip_address);
        $count = (int) get_transient($key);

        if ($count >= RFQ_SESSION_RATE_LIMIT) {
            return new WP_Error(
                'rfq_rate_limited',
                'Too many sessions created from this IP address.',
                ['status' => 429]
            );
        }

        set_transient($key, $count + 1, HOUR_IN_SECONDS);

        return true;
    }
}

