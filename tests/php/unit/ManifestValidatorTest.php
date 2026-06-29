<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Manifest_Validator::class)]
#[Group('AC-WP-016')]
#[Group('AC-WP-017')]
class ManifestValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validManifest(array $overrides = []): array
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440001';
        $today = gmdate('Y-m-d');

        $manifest = [
            'session_id' => $session_id,
            'contact' => [
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane@example.com',
                'company' => null,
                'phone' => null,
                'phone_country_code' => null,
                'job_title' => null,
            ],
            'parts' => [
                [
                    'part_id' => '550e8400-e29b-41d4-a716-446655440000',
                    'part_file_key' => 'intake/' . $session_id . '/parts/uuid_bracket.step',
                    'drawing_file_keys' => [
                        'intake/' . $session_id . '/drawings/uuid_drawing.pdf',
                    ],
                    'material' => 'Aluminum 6061',
                    'tolerance' => 'standard',
                    'tolerance_detail' => null,
                    'quantity' => 10,
                    'target_unit_price' => 12.5,
                    'notes' => 'Handle with care',
                ],
            ],
            'global' => [
                'required_delivery_date' => $today,
                'lead_time_preference' => 'standard',
                'shipping_destination' => [
                    'postal_code' => '90210',
                ],
                'po_number' => null,
                'nda_required' => false,
                'notes' => null,
            ],
        ];

        if (array_key_exists('parts', $overrides)) {
            $manifest['parts'] = $overrides['parts'];
            unset($overrides['parts']);
        }

        return array_replace_recursive($manifest, $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function fieldErrors(WP_Error $error): array
    {
        $data = $error->get_error_data();

        return is_array($data) && isset($data['fields']) && is_array($data['fields'])
            ? $data['fields']
            : [];
    }

    public function test_valid_manifest_passes_validation(): void
    {
        $result = RFQ_Manifest_Validator::validate($this->validManifest());

        $this->assertTrue($result);
    }

    public function test_missing_email_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'contact' => [
                'email' => '',
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rfq_validation', $result->get_error_code());
        $this->assertSame(422, $result->get_error_data()['status']);
        $this->assertArrayHasKey('contact.email', $this->fieldErrors($result));
    }

    public function test_invalid_email_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'contact' => [
                'email' => 'not-an-email',
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('contact.email', $this->fieldErrors($result));
    }

    public function test_phone_country_code_mismatch_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'contact' => [
                'phone' => '2025550105',
                'phone_country_code' => '44',
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('contact.phone', $this->fieldErrors($result));
    }

    public function test_valid_international_phone_passes_validation(): void
    {
        $manifest = $this->validManifest([
            'contact' => [
                'phone' => '7911123456',
                'phone_country_code' => '44',
            ],
        ]);

        $this->assertTrue(RFQ_Manifest_Validator::validate($manifest));
    }

    public function test_zero_parts_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'parts' => [],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('parts', $this->fieldErrors($result));
    }

    public function test_too_many_parts_returns_field_error(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440001';
        $parts = [];

        for ($index = 0; $index < 21; $index++) {
            $parts[] = [
                'part_id' => sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $index),
                'part_file_key' => 'intake/' . $session_id . '/parts/file_' . $index . '.step',
                'drawing_file_keys' => [],
                'material' => 'Steel',
                'tolerance' => 'standard',
                'quantity' => 1,
            ];
        }

        $result = RFQ_Manifest_Validator::validate($this->validManifest(['parts' => $parts]));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('parts', $this->fieldErrors($result));
    }

    public function test_duplicate_part_ids_return_field_error(): void
    {
        $duplicate_id = '550e8400-e29b-41d4-a716-446655440099';
        $session_id = '550e8400-e29b-41d4-a716-446655440001';

        $manifest = $this->validManifest([
            'parts' => [
                [
                    'part_id' => $duplicate_id,
                    'part_file_key' => 'intake/' . $session_id . '/parts/a.step',
                    'drawing_file_keys' => [],
                    'material' => 'Steel',
                    'tolerance' => 'standard',
                    'quantity' => 1,
                ],
                [
                    'part_id' => $duplicate_id,
                    'part_file_key' => 'intake/' . $session_id . '/parts/b.step',
                    'drawing_file_keys' => [],
                    'material' => 'Aluminum',
                    'tolerance' => 'precision',
                    'quantity' => 2,
                ],
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('parts[1].part_id', $this->fieldErrors($result));
    }

    public function test_missing_material_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'parts' => [
                [
                    'material' => '   ',
                ],
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('parts[0].material', $this->fieldErrors($result));
    }

    public function test_custom_tolerance_without_detail_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'parts' => [
                [
                    'tolerance' => 'custom',
                    'tolerance_detail' => null,
                ],
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('parts[0].tolerance_detail', $this->fieldErrors($result));
    }

    public function test_quantity_zero_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'parts' => [
                [
                    'quantity' => 0,
                ],
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('parts[0].quantity', $this->fieldErrors($result));
    }

    public function test_invalid_target_unit_price_returns_field_error(): void
    {
        $negative = RFQ_Manifest_Validator::validate($this->validManifest([
            'parts' => [
                [
                    'target_unit_price' => -1,
                ],
            ],
        ]));
        $too_many_decimals = RFQ_Manifest_Validator::validate($this->validManifest([
            'parts' => [
                [
                    'target_unit_price' => 12.345,
                ],
            ],
        ]));

        $this->assertInstanceOf(WP_Error::class, $negative);
        $this->assertArrayHasKey('parts[0].target_unit_price', $this->fieldErrors($negative));

        $this->assertInstanceOf(WP_Error::class, $too_many_decimals);
        $this->assertArrayHasKey('parts[0].target_unit_price', $this->fieldErrors($too_many_decimals));
    }

    public function test_invalid_lead_time_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'global' => [
                'lead_time_preference' => 'rush',
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('global.lead_time_preference', $this->fieldErrors($result));
    }

    public function test_past_delivery_date_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'global' => [
                'required_delivery_date' => gmdate('Y-m-d', strtotime('-1 day')),
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('global.required_delivery_date', $this->fieldErrors($result));
    }

    public function test_invalid_postal_code_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'global' => [
                'shipping_destination' => [
                    'postal_code' => 'INVALID',
                ],
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey(
            'global.shipping_destination.postal_code',
            $this->fieldErrors($result)
        );
    }

    public function test_non_boolean_nda_required_returns_field_error(): void
    {
        $manifest = $this->validManifest([
            'global' => [
                'nda_required' => 'false',
            ],
        ]);

        $result = RFQ_Manifest_Validator::validate($manifest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertArrayHasKey('global.nda_required', $this->fieldErrors($result));
    }
}
