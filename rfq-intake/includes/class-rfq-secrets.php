<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Secrets
{
    public const ENCRYPTION_KEY_OPTION = 'rfq_encryption_key';

    /** @var list<string> */
    public const SECRET_OPTION_NAMES = [
        'rfq_s3_secret_key',
        'rfq_jwt_secret',
        'rfq_erp_webhook_secret',
    ];

    public static function ensure_encryption_key(): void
    {
        $existing = get_option(self::ENCRYPTION_KEY_OPTION, '');

        if (is_string($existing) && $existing !== '') {
            return;
        }

        update_option(self::ENCRYPTION_KEY_OPTION, base64_encode(random_bytes(32)), false);
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::get_encryption_key_binary();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('RFQ secret encryption failed.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);

        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('RFQ secret blob is invalid.');
        }

        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            self::get_encryption_key_binary(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('RFQ secret decryption failed.');
        }

        return $plaintext;
    }

    public static function get_secret(string $option_name): ?string
    {
        $blob = get_option($option_name, '');

        if (! is_string($blob) || $blob === '') {
            return null;
        }

        try {
            return self::decrypt($blob);
        } catch (RuntimeException) {
            return null;
        }
    }

    public static function set_secret(string $option_name, string $plaintext): void
    {
        update_option($option_name, self::encrypt($plaintext), false);
    }

    public static function has_secret(string $option_name): bool
    {
        $blob = get_option($option_name, '');

        return is_string($blob) && $blob !== '';
    }

    /**
     * @return string 32-byte binary key
     */
    private static function get_encryption_key_binary(): string
    {
        $encoded = get_option(self::ENCRYPTION_KEY_OPTION, '');

        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('RFQ encryption key is not configured.');
        }

        $key = base64_decode($encoded, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('RFQ encryption key is invalid.');
        }

        return $key;
    }
}
