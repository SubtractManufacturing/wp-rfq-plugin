<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Postal_Code
{
    public static function is_valid(string $code): bool
    {
        $normalized = self::normalize($code);

        if (preg_match('/^\d{5}(\d{4})?$/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^[A-Z]\d[A-Z]\d[A-Z]\d$/', $normalized) === 1) {
            return true;
        }

        return false;
    }

    public static function normalize(string $code): string
    {
        return strtoupper(str_replace(' ', '', trim($code)));
    }
}
