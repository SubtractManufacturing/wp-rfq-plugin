<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Upgrade_Manager::class)]
#[Group('AC-WP-032')]
class UpgradeManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        delete_option(RFQ_Upgrade_Manager::VERSION_OPTION);
        delete_option(RFQ_Upgrade_Manager::FAILURE_OPTION);
        delete_option(RFQ_Upgrade_Manager::LOCK_OPTION);
        remove_all_filters('rfq_intake_schema_migrations');
        $GLOBALS['rfq_test_error_logs'] = [];
    }

    public function test_runs_migration_once_and_records_version_after_success(): void
    {
        $runs = 0;
        add_filter(
            'rfq_intake_schema_migrations',
            static function (array $migrations) use (&$runs): array {
                unset($migrations);

                return [
                    1 => static function () use (&$runs): void {
                        $runs++;
                    },
                ];
            }
        );

        $this->assertTrue(RFQ_Upgrade_Manager::maybe_upgrade());
        $this->assertSame(1, $runs);
        $this->assertSame(1, get_option(RFQ_Upgrade_Manager::VERSION_OPTION));
        $this->assertTrue(RFQ_Upgrade_Manager::is_ready());

        $this->assertTrue(RFQ_Upgrade_Manager::maybe_upgrade());
        $this->assertSame(1, $runs);
    }

    public function test_failure_is_not_marked_complete_and_successful_retry_recovers(): void
    {
        add_filter(
            'rfq_intake_schema_migrations',
            static fn (array $migrations): array => [
                1 => static function (): void {
                    throw new RuntimeException('sensitive database detail');
                },
            ]
        );

        $this->assertFalse(RFQ_Upgrade_Manager::maybe_upgrade());
        $this->assertSame(0, get_option(RFQ_Upgrade_Manager::VERSION_OPTION, 0));
        $this->assertFalse(RFQ_Upgrade_Manager::is_ready());
        $this->assertIsArray(get_option(RFQ_Upgrade_Manager::FAILURE_OPTION));
        $this->assertStringNotContainsString(
            'sensitive database detail',
            implode("\n", $GLOBALS['rfq_test_error_logs'])
        );

        ob_start();
        RFQ_Upgrade_Manager::render_failure_notice();
        $notice = (string) ob_get_clean();
        $this->assertStringContainsString('could not finish its database upgrade', $notice);

        remove_all_filters('rfq_intake_schema_migrations');
        delete_option(RFQ_Upgrade_Manager::LOCK_OPTION);
        add_filter(
            'rfq_intake_schema_migrations',
            static fn (array $migrations): array => [1 => static function (): void {}]
        );

        $this->assertTrue(RFQ_Upgrade_Manager::maybe_upgrade());
        $this->assertTrue(RFQ_Upgrade_Manager::is_ready());
        $this->assertFalse(get_option(RFQ_Upgrade_Manager::FAILURE_OPTION, false));
    }

    public function test_active_lock_prevents_concurrent_migration(): void
    {
        $runs = 0;
        update_option(RFQ_Upgrade_Manager::LOCK_OPTION, time());
        add_filter(
            'rfq_intake_schema_migrations',
            static function (array $migrations) use (&$runs): array {
                unset($migrations);

                return [1 => static function () use (&$runs): void {
                    $runs++;
                }];
            }
        );

        $this->assertFalse(RFQ_Upgrade_Manager::maybe_upgrade());
        $this->assertSame(0, $runs);
        $this->assertFalse(RFQ_Upgrade_Manager::is_ready());
    }

    public function test_stale_lock_is_replaced_and_migration_retries(): void
    {
        update_option(RFQ_Upgrade_Manager::LOCK_OPTION, time() - 301);
        add_filter(
            'rfq_intake_schema_migrations',
            static fn (array $migrations): array => [1 => static function (): void {}]
        );

        $this->assertTrue(RFQ_Upgrade_Manager::maybe_upgrade());
        $this->assertTrue(RFQ_Upgrade_Manager::is_ready());
        $this->assertFalse(get_option(RFQ_Upgrade_Manager::LOCK_OPTION, false));
    }
}
