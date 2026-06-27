<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Webhook
{
    private const WEBHOOK_URL_OPTION = 'rfq_erp_webhook_url';

    private const WEBHOOK_SECRET_OPTION = 'rfq_erp_webhook_secret';

    private const TIMEOUT_SECONDS = 5;

    public static function init(): void
    {
        add_action('rfq_after_receipt_durable', [self::class, 'handle_receipt_durable'], 10, 3);
    }

    public static function handle_receipt_durable(
        string $receipt_number,
        string $session_id,
        string $receipt_key
    ): void {
        self::notify_receipt($receipt_number, $session_id, $receipt_key);
    }

    public static function notify_receipt(
        string $receipt_number,
        string $session_id,
        string $receipt_key
    ): void {
        $url = get_option(self::WEBHOOK_URL_OPTION, '');

        if (! is_string($url) || trim($url) === '') {
            return;
        }

        $secret = RFQ_Secrets::get_secret(self::WEBHOOK_SECRET_OPTION);

        if ($secret === null || $secret === '') {
            error_log(
                'RFQ Intake: ERP webhook URL is configured but webhook shared secret is missing; notification skipped.'
            );

            return;
        }

        $payload = [
            'receipt_number' => $receipt_number,
            'session_id' => $session_id,
            'receipt_key' => $receipt_key,
        ];

        $body = wp_json_encode($payload);

        if (! is_string($body)) {
            error_log('RFQ Intake: failed to encode ERP webhook payload; notification skipped.');

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
                    'RFQ Intake: ERP webhook transport error for receipt %s: %s',
                    $receipt_number,
                    $response->get_error_message()
                )
            );
        }
    }
}
