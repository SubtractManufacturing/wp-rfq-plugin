<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Contact_Validator::class)]
class ContactValidatorTest extends TestCase
{
    public function test_valid_contact_with_all_required_fields(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Jane', $result['normalized']['first_name']);
        $this->assertSame('Smith', $result['normalized']['last_name']);
        $this->assertSame('jane@example.com', $result['normalized']['email']);
        $this->assertNull($result['normalized']['company']);
        $this->assertNull($result['normalized']['phone']);
        $this->assertNull($result['normalized']['phone_country_code']);
        $this->assertNull($result['normalized']['job_title']);
    }

    public function test_trims_whitespace_from_contact_fields(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => '  Jane  ',
            'last_name' => ' Smith ',
            'email' => ' jane@example.com ',
            'company' => ' Acme ',
            'job_title' => ' Buyer ',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('Jane', $result['normalized']['first_name']);
        $this->assertSame('Smith', $result['normalized']['last_name']);
        $this->assertSame('jane@example.com', $result['normalized']['email']);
        $this->assertSame('Acme', $result['normalized']['company']);
        $this->assertSame('Buyer', $result['normalized']['job_title']);
    }

    public function test_blank_optional_fields_normalize_to_null(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'company' => '   ',
            'job_title' => '',
            'phone' => '',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertNull($result['normalized']['company']);
        $this->assertNull($result['normalized']['job_title']);
        $this->assertNull($result['normalized']['phone']);
        $this->assertNull($result['normalized']['phone_country_code']);
    }

    public function test_valid_us_phone_with_country_code(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '2025550105',
            'phone_country_code' => '1',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('2025550105', $result['normalized']['phone']);
        $this->assertSame('1', $result['normalized']['phone_country_code']);
    }

    public function test_valid_uk_phone_with_country_code(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '7911123456',
            'phone_country_code' => '44',
        ]);

        $this->assertSame([], $result['errors']);
        $this->assertSame('7911123456', $result['normalized']['phone']);
        $this->assertSame('44', $result['normalized']['phone_country_code']);
    }

    public function test_phone_without_country_code_returns_field_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '2025550105',
        ]);

        $this->assertArrayHasKey('phone_country_code', $result['errors']);
    }

    public function test_missing_required_fields_return_field_errors(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => '',
            'last_name' => null,
            'email' => '   ',
        ]);

        $this->assertArrayHasKey('first_name', $result['errors']);
        $this->assertArrayHasKey('last_name', $result['errors']);
        $this->assertArrayHasKey('email', $result['errors']);
    }

    public function test_invalid_email_returns_field_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'not-an-email',
        ]);

        $this->assertArrayHasKey('email', $result['errors']);
    }

    public function test_invalid_phone_for_country_returns_field_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '5555550100',
            'phone_country_code' => '1',
        ]);

        $this->assertArrayHasKey('phone', $result['errors']);
    }

    public function test_country_code_without_phone_returns_field_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone_country_code' => '1',
        ]);

        $this->assertArrayHasKey('phone_country_code', $result['errors']);
    }

    public function test_mismatched_phone_and_country_code_returns_field_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '2025550105',
            'phone_country_code' => '44',
        ]);

        $this->assertArrayHasKey('phone', $result['errors']);
    }

    public function test_non_digit_phone_returns_field_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '202-555-0105',
            'phone_country_code' => '1',
        ]);

        $this->assertArrayHasKey('phone', $result['errors']);
    }

    public function test_invalid_country_code_format_returns_field_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '2025550105',
            'phone_country_code' => 'abc',
        ]);

        $this->assertArrayHasKey('phone_country_code', $result['errors']);
    }

    public function test_country_code_too_long_returns_field_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '2025550105',
            'phone_country_code' => '12345',
        ]);

        $this->assertArrayHasKey('phone_country_code', $result['errors']);
    }

    public function test_non_string_phone_returns_type_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => 2025550105,
        ]);

        $this->assertArrayHasKey('phone', $result['errors']);
    }

    public function test_non_string_country_code_returns_type_error(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '2025550105',
            'phone_country_code' => 1,
        ]);

        $this->assertArrayHasKey('phone_country_code', $result['errors']);
    }

    public function test_non_string_optional_fields_return_type_errors(): void
    {
        $result = RFQ_Contact_Validator::normalize_and_validate([
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'company' => 123,
            'job_title' => false,
        ]);

        $this->assertArrayHasKey('company', $result['errors']);
        $this->assertArrayHasKey('job_title', $result['errors']);
    }
}
