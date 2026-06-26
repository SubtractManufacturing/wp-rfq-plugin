<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Manifest_Validator
{
    private const LEAD_TIME_PREFERENCES = [
        'no_rush',
        'standard',
        'target_date',
        'expedited',
        'economy',
    ];

    private const TOLERANCE_VALUES = [
        'standard',
        'precision',
        'custom',
    ];

    /**
     * @param array<string, mixed> $manifest
     */
    public static function validate(array $manifest): true|WP_Error
    {
        $fields = [];

        self::validate_top_level_structure($manifest, $fields);
        self::validate_contact($manifest['contact'] ?? null, $fields);
        self::validate_parts($manifest['parts'] ?? null, $fields);
        self::validate_global($manifest['global'] ?? null, $fields);

        if ($fields !== []) {
            return new WP_Error(
                'rfq_validation',
                __('Invalid manifest.', 'rfq-intake'),
                [
                    'status' => 422,
                    'fields' => $fields,
                ]
            );
        }

        return true;
    }

    /**
     * @param array<string, string> $fields
     */
    private static function validate_top_level_structure(mixed $manifest, array &$fields): void
    {
        if (! is_array($manifest)) {
            $fields['manifest'] = __('Manifest must be a JSON object.', 'rfq-intake');

            return;
        }

        $session_id = $manifest['session_id'] ?? null;

        if (! is_string($session_id) || ! RFQ_S3_Key_Builder::is_uuid($session_id)) {
            $fields['session_id'] = __('A valid session_id UUID is required.', 'rfq-intake');
        }

        foreach (['contact', 'parts', 'global'] as $section) {
            if (! array_key_exists($section, $manifest)) {
                $fields[$section] = __('This section is required.', 'rfq-intake');
            }
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private static function validate_contact(mixed $contact, array &$fields): void
    {
        if (! is_array($contact)) {
            $fields['contact'] = __('Contact must be an object.', 'rfq-intake');

            return;
        }

        $validation = RFQ_Contact_Validator::normalize_and_validate($contact);

        foreach ($validation['errors'] as $field => $message) {
            $fields['contact.' . $field] = $message;
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private static function validate_parts(mixed $parts, array &$fields): void
    {
        if (! is_array($parts)) {
            $fields['parts'] = __('Parts must be an array.', 'rfq-intake');

            return;
        }

        if ($parts === []) {
            $fields['parts'] = __('At least one part is required.', 'rfq-intake');

            return;
        }

        if (count($parts) > RFQ_MAX_PARTS) {
            $fields['parts'] = sprintf(
                /* translators: %d: maximum number of parts allowed per RFQ */
                __('No more than %d parts are allowed.', 'rfq-intake'),
                RFQ_MAX_PARTS
            );

            return;
        }

        $seen_part_ids = [];

        foreach ($parts as $index => $part) {
            $prefix = 'parts[' . $index . ']';

            if (! is_array($part)) {
                $fields[$prefix] = __('Each part must be an object.', 'rfq-intake');
                continue;
            }

            $part_id = $part['part_id'] ?? null;

            if (! is_string($part_id) || ! RFQ_S3_Key_Builder::is_uuid($part_id)) {
                $fields[$prefix . '.part_id'] = __('A valid part_id UUID is required.', 'rfq-intake');
            } elseif (isset($seen_part_ids[$part_id])) {
                $fields[$prefix . '.part_id'] = __('part_id must be unique within the manifest.', 'rfq-intake');
            } else {
                $seen_part_ids[$part_id] = true;
            }

            $part_file_key = $part['part_file_key'] ?? null;

            if (! is_string($part_file_key) || trim($part_file_key) === '') {
                $fields[$prefix . '.part_file_key'] = __('part_file_key is required.', 'rfq-intake');
            }

            $drawing_file_keys = $part['drawing_file_keys'] ?? null;

            if (! is_array($drawing_file_keys)) {
                $fields[$prefix . '.drawing_file_keys'] = __('drawing_file_keys must be an array.', 'rfq-intake');
            } else {
                foreach ($drawing_file_keys as $drawing_index => $drawing_key) {
                    if (! is_string($drawing_key) || trim($drawing_key) === '') {
                        $fields[$prefix . '.drawing_file_keys[' . $drawing_index . ']'] = __(
                            'Each drawing file key must be a non-empty string.',
                            'rfq-intake'
                        );
                    }
                }
            }

            $material = $part['material'] ?? null;

            if (! is_string($material) || trim($material) === '') {
                $fields[$prefix . '.material'] = __('Material is required.', 'rfq-intake');
            }

            $tolerance = $part['tolerance'] ?? null;

            if (! is_string($tolerance) || ! in_array($tolerance, self::TOLERANCE_VALUES, true)) {
                $fields[$prefix . '.tolerance'] = __(
                    'Tolerance must be standard, precision, or custom.',
                    'rfq-intake'
                );
            } elseif ($tolerance === 'custom') {
                $tolerance_detail = $part['tolerance_detail'] ?? null;

                if (! is_string($tolerance_detail) || trim($tolerance_detail) === '') {
                    $fields[$prefix . '.tolerance_detail'] = __(
                        'tolerance_detail is required when tolerance is custom.',
                        'rfq-intake'
                    );
                }
            }

            $quantity = $part['quantity'] ?? null;

            if (! self::is_positive_integer($quantity)) {
                $fields[$prefix . '.quantity'] = __('Quantity must be an integer greater than or equal to 1.', 'rfq-intake');
            }

            if (array_key_exists('target_unit_price', $part) && $part['target_unit_price'] !== null) {
                if (! self::is_valid_target_unit_price($part['target_unit_price'])) {
                    $fields[$prefix . '.target_unit_price'] = __(
                        'target_unit_price must be a number greater than or equal to 0 with at most 2 decimal places.',
                        'rfq-intake'
                    );
                }
            }

            self::validate_optional_string_field($part, 'notes', $prefix . '.notes', $fields);
            self::validate_optional_string_field($part, 'threads_features', $prefix . '.threads_features', $fields);
        }
    }

    /**
     * @param array<string, string> $fields
     */
    private static function validate_global(mixed $global, array &$fields): void
    {
        if (! is_array($global)) {
            $fields['global'] = __('Global metadata must be an object.', 'rfq-intake');

            return;
        }

        $required_delivery_date = $global['required_delivery_date'] ?? null;

        if (! is_string($required_delivery_date) || ! self::is_valid_iso_date($required_delivery_date)) {
            $fields['global.required_delivery_date'] = __(
                'required_delivery_date must be a valid ISO date (YYYY-MM-DD).',
                'rfq-intake'
            );
        } elseif (self::is_past_utc_date($required_delivery_date)) {
            $fields['global.required_delivery_date'] = __(
                'required_delivery_date cannot be before today (UTC).',
                'rfq-intake'
            );
        }

        $lead_time_preference = $global['lead_time_preference'] ?? null;

        if (
            ! is_string($lead_time_preference)
            || ! in_array($lead_time_preference, self::LEAD_TIME_PREFERENCES, true)
        ) {
            $fields['global.lead_time_preference'] = __(
                'lead_time_preference must be one of: no_rush, standard, target_date, expedited, economy.',
                'rfq-intake'
            );
        }

        $shipping_destination = $global['shipping_destination'] ?? null;

        if (! is_array($shipping_destination)) {
            $fields['global.shipping_destination'] = __('shipping_destination is required.', 'rfq-intake');
        } else {
            $postal_code = $shipping_destination['postal_code'] ?? null;

            if (! is_string($postal_code) || trim($postal_code) === '') {
                $fields['global.shipping_destination.postal_code'] = __(
                    'Postal code is required.',
                    'rfq-intake'
                );
            } elseif (! RFQ_Postal_Code::is_valid($postal_code)) {
                $fields['global.shipping_destination.postal_code'] = __(
                    'Invalid US/CA postal code.',
                    'rfq-intake'
                );
            }
        }

        if (! array_key_exists('nda_required', $global) || ! is_bool($global['nda_required'])) {
            $fields['global.nda_required'] = __('nda_required must be a boolean.', 'rfq-intake');
        }

        self::validate_optional_string_field($global, 'po_number', 'global.po_number', $fields);
        self::validate_optional_string_field($global, 'notes', 'global.notes', $fields);
    }

    /**
     * @param array<string, mixed> $container
     * @param array<string, string> $fields
     */
    private static function validate_optional_string_field(
        array $container,
        string $field,
        string $error_key,
        array &$fields
    ): void {
        if (! array_key_exists($field, $container) || $container[$field] === null) {
            return;
        }

        if (! is_string($container[$field])) {
            $fields[$error_key] = __('This field must be a string or null.', 'rfq-intake');
        }
    }

    private static function is_positive_integer(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 1;
        }

        if (is_float($value) && floor($value) === $value) {
            return $value >= 1;
        }

        return false;
    }

    private static function is_valid_target_unit_price(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        if ($value < 0) {
            return false;
        }

        return abs($value - round($value, 2)) < 0.0000001;
    }

    private static function is_valid_iso_date(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof DateTimeImmutable
            && $date->format('Y-m-d') === $value;
    }

    private static function is_past_utc_date(string $value): bool
    {
        $delivery_date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        if (! $delivery_date instanceof DateTimeImmutable) {
            return true;
        }

        return $delivery_date < $today;
    }
}
