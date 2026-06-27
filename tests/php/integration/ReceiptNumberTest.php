<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Receipt_Service::class)]
#[Group('AC-WP-022')]
class ReceiptNumberTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        RFQ_Activator::activate();
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_receipt_sequences');
    }

    public function test_receipt_number_uses_daily_sequence_format(): void
    {
        $receipt_number = RFQ_Receipt_Service::allocate_receipt_number();

        $this->assertIsString($receipt_number);
        $this->assertMatchesRegularExpression(
            '/^RFQ-' . gmdate('Ymd') . '-\d{6}$/',
            $receipt_number
        );
        $this->assertSame('RFQ-' . gmdate('Ymd') . '-000001', $receipt_number);
    }

    public function test_receipt_sequence_increments_for_same_day(): void
    {
        $first = RFQ_Receipt_Service::allocate_receipt_number();
        $second = RFQ_Receipt_Service::allocate_receipt_number();

        $this->assertSame('RFQ-' . gmdate('Ymd') . '-000001', $first);
        $this->assertSame('RFQ-' . gmdate('Ymd') . '-000002', $second);
    }

    public function test_concurrent_allocations_return_unique_receipt_numbers(): void
    {
        $numbers = [];

        for ($index = 0; $index < 10; $index++) {
            $numbers[] = RFQ_Receipt_Service::allocate_receipt_number();
        }

        $this->assertCount(10, array_unique($numbers));
        $this->assertSame('RFQ-' . gmdate('Ymd') . '-000010', $numbers[9]);
    }
}
