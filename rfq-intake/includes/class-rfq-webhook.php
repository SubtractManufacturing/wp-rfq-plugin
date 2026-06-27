<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Webhook
{
    private const TIMEOUT_SECONDS = 5;

    public static function init(): void
    {
        add_action('rfq_receipt_created', [self::class, 'notify_receipt'], 10, 3);
    }

    public static function notify_receipt(string $receipt_number, string $session_id, string $receipt_key): void
    {
        $url = get_option('rfq_erp_webhook_url', '');

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $secret = RFQ_Secrets::get_secret('rfq_erp_webhook_secret');

        if ($secret === null || $secret === '') {
            error_log(
                'RFQ ERP webhook skipped: import webhook URL is configured but the shared secret is missing or invalid.'
            );

            return;
        }

        $payload = [
            'receipt_number' => $receipt_number,
            'session_id' => $session_id,
            'receipt_key' => $receipt_key,
        ];

        $body = wp_json_encode($payload);

        if ($body === false) {
            error_log(
                sprintf(
                    'RFQ ERP webhook skipped: unable to encode payload for session %s.',
                    $session_id
                )
            );

            return;
        }

        $signature = hash_hmac('sha256', $body, $secret);

        $response = wp_remote_post($url, [
            'timeout' => self::TIMEOUT_SECONDS,
            'blocking' => false,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-RFQ-Signature' => $signature,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            error_log(
                sprintf(
                    'RFQ ERP webhook dispatch failed for session %s: %s',
                    $session_id,
                    $response->get_error_message()
                )
            );
        }
    }
}
