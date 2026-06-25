<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ActivatorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['rfq_dbdelta_calls'] = [];
        $GLOBALS['rfq_options'] = [];
    }

    /** @covers AC-WP-023 */
    public function test_activation_is_idempotent(): void
    {
        RFQ_Activator::activate();
        RFQ_Activator::activate();

        self::assertCount(4, $GLOBALS['rfq_dbdelta_calls']);
        self::assertArrayHasKey('rfq_encryption_key', $GLOBALS['rfq_options']);
        self::assertArrayHasKey('rfq_jwt_secret', $GLOBALS['rfq_options']);
    }
}

