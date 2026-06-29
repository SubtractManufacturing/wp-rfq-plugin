<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;

class RFQ_Contact_Validator {

    /**
     * @param array<string, mixed> $params
     * @return array{normalized: array<string, string|null>, errors: array<string, string>}
     */
    public static function normalize_and_validate( array $params ): array {
        $errors = [];

        $first_name = self::normalize_required_string( $params['first_name'] ?? null, 'first_name', $errors );
        $last_name  = self::normalize_required_string( $params['last_name'] ?? null, 'last_name', $errors );
        $email      = self::normalize_required_string( $params['email'] ?? null, 'email', $errors );
        $company    = self::normalize_optional_string( $params['company'] ?? null, 'company', $errors );
        $job_title  = self::normalize_optional_string( $params['job_title'] ?? null, 'job_title', $errors );

        if ( $email !== null && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
            $errors['email'] = __( 'A valid email address is required.', 'rfq-intake' );
        }

        [$phone, $phone_country_code] = self::normalize_phone_fields(
            $params['phone'] ?? null,
            $params['phone_country_code'] ?? null,
            $errors
        );

        return [
            'normalized' => [
                'first_name'         => $first_name,
                'last_name'          => $last_name,
                'email'              => $email,
                'company'            => $company,
                'phone'              => $phone,
                'phone_country_code' => $phone_country_code,
                'job_title'          => $job_title,
            ],
            'errors'     => $errors,
        ];
    }

    /**
     * @param array<string, string> $errors
     */
    private static function normalize_required_string( mixed $value, string $field, array &$errors ): ?string {
        if ( $value === null ) {
            $errors[ $field ] = __( 'This field is required.', 'rfq-intake' );

            return null;
        }

        if ( ! is_string( $value ) ) {
            $errors[ $field ] = __( 'This field must be a string.', 'rfq-intake' );

            return null;
        }

        $trimmed = trim( $value );

        if ( $trimmed === '' ) {
            $errors[ $field ] = __( 'This field is required.', 'rfq-intake' );

            return null;
        }

        return $trimmed;
    }

    /**
     * @param array<string, string> $errors
     */
    private static function normalize_optional_string( mixed $value, string $field, array &$errors ): ?string {
        if ( $value === null ) {
            return null;
        }

        if ( ! is_string( $value ) ) {
            $errors[ $field ] = __( 'This field must be a string.', 'rfq-intake' );

            return null;
        }

        $trimmed = trim( $value );

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, string> $errors
     * @return array{0: ?string, 1: ?string}
     */
    private static function normalize_phone_fields(
        mixed $phone,
        mixed $phone_country_code,
        array &$errors
    ): array {
        if ( $phone !== null && ! is_string( $phone ) ) {
            $errors['phone'] = __( 'This field must be a string.', 'rfq-intake' );

            return [ null, null ];
        }

        if ( $phone_country_code !== null && ! is_string( $phone_country_code ) ) {
            $errors['phone_country_code'] = __( 'This field must be a string.', 'rfq-intake' );

            return [ null, null ];
        }

        $normalized_phone        = $phone === null ? null : trim( $phone );
        $normalized_country_code = $phone_country_code === null ? null : trim( $phone_country_code );

        if ( $normalized_phone === '' ) {
            $normalized_phone = null;
        }

        if ( $normalized_country_code === '' ) {
            $normalized_country_code = null;
        }

        if ( $normalized_phone === null ) {
            if ( $normalized_country_code !== null ) {
                $errors['phone_country_code'] = __(
                    'phone_country_code must be null when phone is omitted.',
                    'rfq-intake'
                );
            }

            return [ null, null ];
        }

        if ( ! preg_match( '/^[0-9]+$/', $normalized_phone ) ) {
            $errors['phone'] = __( 'Phone must contain digits only.', 'rfq-intake' );

            return [ null, null ];
        }

        if ( $normalized_country_code === null ) {
            $errors['phone_country_code'] = __(
                'phone_country_code is required when phone is provided.',
                'rfq-intake'
            );

            return [ null, null ];
        }

        if ( ! preg_match( '/^[0-9]{1,4}$/', $normalized_country_code ) ) {
            $errors['phone_country_code'] = __(
                'phone_country_code must be a numeric country calling code (1–4 digits).',
                'rfq-intake'
            );

            return [ null, null ];
        }

        $phone_util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $phone_util->parse( '+' . $normalized_country_code . $normalized_phone, null );
        } catch ( NumberParseException $exception ) {
            $errors['phone'] = __( 'Enter a valid phone number for the selected country.', 'rfq-intake' );

            return [ null, null ];
        }

        if ( ! $phone_util->isValidNumber( $parsed ) ) {
            $errors['phone'] = __( 'Enter a valid phone number for the selected country.', 'rfq-intake' );

            return [ null, null ];
        }

        $national_number = (string) $parsed->getNationalNumber();
        $calling_code    = (string) $parsed->getCountryCode();

        if ( strlen( $national_number ) > 15 ) {
            $errors['phone'] = __( 'Phone number is too long.', 'rfq-intake' );

            return [ null, null ];
        }

        return [ $national_number, $calling_code ];
    }
}
