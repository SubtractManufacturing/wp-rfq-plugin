<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Intake_Maintenance::class)]
#[Group('AC-WP-025')]
#[Group('AC-WP-026')]
class IntakeMaintenanceTest extends TestCase
{
    private RFQ_S3_Client_Mock $mock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock = new RFQ_S3_Client_Mock();
    }

    public function test_find_orphan_manifest_session_ids_when_manifest_old_and_receipt_missing(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440010';
        $now        = 1_700_000_000;
        $manifest_key = 'intake/' . $session_id . '/meta/manifest.json';

        $this->mock->seed_object(
            $manifest_key,
            'application/json',
            128,
            $now - RFQ_Intake_Maintenance::ORPHAN_MANIFEST_THRESHOLD_SECONDS - 60
        );

        $orphans = RFQ_Intake_Maintenance::find_orphan_manifest_session_ids(
            $this->mock,
            [$session_id],
            $now
        );

        self::assertSame([$session_id], $orphans);
    }

    public function test_find_orphan_manifest_session_ids_ignores_recent_manifest(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440011';
        $now        = 1_700_000_000;
        $manifest_key = 'intake/' . $session_id . '/meta/manifest.json';

        $this->mock->seed_object(
            $manifest_key,
            'application/json',
            128,
            $now - 60
        );

        $orphans = RFQ_Intake_Maintenance::find_orphan_manifest_session_ids(
            $this->mock,
            [$session_id],
            $now
        );

        self::assertSame([], $orphans);
    }

    public function test_find_orphan_manifest_session_ids_ignores_when_receipt_exists(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440012';
        $now        = 1_700_000_000;

        $this->mock->seed_object(
            'intake/' . $session_id . '/meta/manifest.json',
            'application/json',
            128,
            $now - 3600
        );
        $this->mock->seed_object(
            'intake/' . $session_id . '/meta/receipt.json',
            'application/json',
            64,
            $now - 3500
        );

        $orphans = RFQ_Intake_Maintenance::find_orphan_manifest_session_ids(
            $this->mock,
            [$session_id],
            $now
        );

        self::assertSame([], $orphans);
    }

    public function test_find_unreceipted_prefix_candidates_returns_old_prefixes_without_receipt(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440020';
        $now        = 1_700_000_000;
        $old        = $now - (31 * DAY_IN_SECONDS);

        $this->mock->seed_object(
            'intake/' . $session_id . '/parts/file.step',
            'application/octet-stream',
            100,
            $old
        );

        $candidates = RFQ_Intake_Maintenance::find_unreceipted_prefix_candidates($this->mock, $now);

        self::assertCount(1, $candidates);
        self::assertSame($session_id, $candidates[0]['session_id']);
        self::assertGreaterThanOrEqual(30, $candidates[0]['age_days']);
    }

    public function test_find_unreceipted_prefix_candidates_skips_prefixes_with_receipt(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440021';
        $now        = 1_700_000_000;
        $old        = $now - (45 * DAY_IN_SECONDS);

        $this->mock->seed_object(
            'intake/' . $session_id . '/parts/file.step',
            'application/octet-stream',
            100,
            $old
        );
        $this->mock->seed_object(
            'intake/' . $session_id . '/meta/receipt.json',
            'application/json',
            64,
            $old
        );

        $candidates = RFQ_Intake_Maintenance::find_unreceipted_prefix_candidates($this->mock, $now);

        self::assertSame([], $candidates);
    }
}
