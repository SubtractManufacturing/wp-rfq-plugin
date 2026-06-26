<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Postal_Code::class)]
#[Group('AC-WP-017')]
class PostalCodeTest extends TestCase
{
    public function test_accepts_us_five_digit_zip(): void
    {
        $this->assertTrue(RFQ_Postal_Code::is_valid('90210'));
    }

    public function test_accepts_us_nine_digit_zip(): void
    {
        $this->assertTrue(RFQ_Postal_Code::is_valid('902101234'));
    }

    public function test_accepts_canadian_postal_code_with_space(): void
    {
        $this->assertTrue(RFQ_Postal_Code::is_valid('A1A 1A1'));
        $this->assertSame('A1A1A1', RFQ_Postal_Code::normalize('A1A 1A1'));
    }

    public function test_rejects_invalid_postal_code(): void
    {
        $this->assertFalse(RFQ_Postal_Code::is_valid('INVALID'));
        $this->assertFalse(RFQ_Postal_Code::is_valid('1234'));
        $this->assertFalse(RFQ_Postal_Code::is_valid('12A 34B'));
    }
}
