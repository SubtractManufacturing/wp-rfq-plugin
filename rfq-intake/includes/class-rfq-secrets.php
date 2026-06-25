<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Secrets
{
    public static function ensure_encryption_key(): void
    {
        if (get_option('rfq_encryption_key')) {
            return;
        }

        add_option('rfq_encryption_key', base64_encode(random_bytes(32)), '', false);
    }

    public static function ensure_secret(string $option_name, string $plaintext): void
    {
        if (self::get_secret($option_name) !== null) {
            return;
        }

        self::set_secret($option_name, $plaintext);
    }

    public static function encrypt(string $plaintext): string
    {
        $key = base64_decode((string) get_option('rfq_encryption_key'), true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('Missing RFQ encryption key.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Unable to encrypt RFQ secret.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $blob): string
    {
        $decoded = base64_decode($blob, true);
        $key = base64_decode((string) get_option('rfq_encryption_key'), true);

        if ($decoded === false || $key === false || strlen($key) !== 32 || strlen($decoded) < 29) {
            throw new RuntimeException('Unable to decrypt RFQ secret.');
        }

        $nonce = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);

        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt RFQ secret.');
        }

        return $plaintext;
    }

    public static function get_secret(string $option_name): ?string
    {
        $stored = get_option($option_name);

        if (!is_string($stored) || $stored === '') {
            return null;
        }

        return self::decrypt($stored);
    }

    public static function set_secret(string $option_name, string $plaintext): void
    {
        update_option($option_name, self::encrypt($plaintext), false);
    }
}

