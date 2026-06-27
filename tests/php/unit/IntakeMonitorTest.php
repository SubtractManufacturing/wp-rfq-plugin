<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Intake_Monitor::class)]
#[Group('AC-WP-025')]
#[Group('AC-WP-026')]
class IntakeMonitorTest extends TestCase
{
    private RFQ_S3_Client_Mock $mock;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['rfq_test_transients'] = [];
        $GLOBALS['rfq_test_cron_events'] = [];
        $GLOBALS['rfq_test_current_user_can'] = false;

        remove_all_filters('rfq_s3_client');
        remove_all_filters('rfq_orphan_manifest_threshold_seconds');
        remove_all_filters('rfq_cleanup_candidate_age_seconds');

        $this->mock = new RFQ_S3_Client_Mock('rfq-test-bucket');
        add_filter('rfq_s3_client', fn (): RFQ_S3_Client_Mock => $this->mock);
    }

    public function test_detects_orphan_manifest_after_threshold(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440000';
        $now = 1_700_000_000;
        $manifest_key = RFQ_S3_Key_Builder::manifest_meta_key($session_id);

        $this->mock->seed_object(
            $manifest_key,
            128,
            'application/json',
            '{"session_id":"' . $session_id . '"}',
            $now - 1200
        );

        $orphans = RFQ_Intake_Monitor::detect_orphan_manifests($now);

        $this->assertSame([$session_id], $orphans);
    }

    public function test_does_not_alert_before_orphan_threshold(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440001';
        $now = 1_700_000_000;
        $manifest_key = RFQ_S3_Key_Builder::manifest_meta_key($session_id);

        $this->mock->seed_object(
            $manifest_key,
            128,
            'application/json',
            '{"session_id":"' . $session_id . '"}',
            $now - 600
        );

        $orphans = RFQ_Intake_Monitor::detect_orphan_manifests($now);

        $this->assertSame([], $orphans);
    }

    public function test_does_not_alert_when_receipt_exists(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440002';
        $now = 1_700_000_000;

        $this->mock->seed_object(
            RFQ_S3_Key_Builder::manifest_meta_key($session_id),
            128,
            'application/json',
            '{}',
            $now - 3600
        );
        $this->mock->seed_object(
            RFQ_S3_Key_Builder::receipt_meta_key($session_id),
            128,
            'application/json',
            '{}',
            $now - 3500
        );

        $orphans = RFQ_Intake_Monitor::detect_orphan_manifests($now);

        $this->assertSame([], $orphans);
    }

    public function test_detects_thirty_day_cleanup_candidates_without_receipt(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440003';
        $now = 1_700_000_000;
        $old_timestamp = $now - (31 * DAY_IN_SECONDS);

        $this->mock->seed_object(
            'intake/' . $session_id . '/meta/draft.json',
            64,
            'application/json',
            '{}',
            $old_timestamp
        );

        $candidates = RFQ_Intake_Monitor::detect_cleanup_candidates($now);

        $this->assertSame([$session_id], $candidates);
    }

    public function test_cleanup_detection_ignores_recent_unreceipted_prefixes(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440007';
        $now = 1_700_000_000;

        $this->mock->seed_object(
            'intake/' . $session_id . '/meta/draft.json',
            64,
            'application/json',
            '{}',
            $now - (5 * DAY_IN_SECONDS)
        );

        $candidates = RFQ_Intake_Monitor::detect_cleanup_candidates($now);

        $this->assertSame([], $candidates);
    }

    public function test_cleanup_detection_skips_receipted_sessions(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440004';
        $now = 1_700_000_000;
        $old_timestamp = $now - (40 * DAY_IN_SECONDS);

        $this->mock->seed_object(
            'intake/' . $session_id . '/meta/draft.json',
            64,
            'application/json',
            '{}',
            $old_timestamp
        );
        $this->mock->seed_object(
            RFQ_S3_Key_Builder::receipt_meta_key($session_id),
            128,
            'application/json',
            '{}',
            $old_timestamp
        );

        $candidates = RFQ_Intake_Monitor::detect_cleanup_candidates($now);

        $this->assertSame([], $candidates);
    }

    public function test_scheduled_check_stores_admin_warnings_without_deleting_objects(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440005';
        $now = 1_700_000_000;

        $this->mock->seed_object(
            RFQ_S3_Key_Builder::manifest_meta_key($session_id),
            128,
            'application/json',
            '{}',
            $now - 1200
        );

        RFQ_Intake_Monitor::run_scheduled_check();

        $warnings = get_transient('rfq_intake_monitor_warnings');
        $this->assertIsArray($warnings);
        $this->assertNotEmpty($warnings);
        $this->assertArrayHasKey(RFQ_S3_Key_Builder::manifest_meta_key($session_id), $this->mock->objects);
    }

    public function test_schedule_cron_registers_daily_event_once(): void
    {
        RFQ_Intake_Monitor::schedule_cron();
        RFQ_Intake_Monitor::schedule_cron();

        $matching = array_values(array_filter(
            $GLOBALS['rfq_test_cron_events'],
            static fn (array $event): bool => $event['hook'] === RFQ_Intake_Monitor::CRON_HOOK
        ));

        $this->assertCount(1, $matching);
        $this->assertSame('daily', $matching[0]['recurrence']);
    }

    public function test_monitor_scan_does_not_delete_s3_objects(): void
    {
        $session_id = '550e8400-e29b-41d4-a716-446655440006';
        $now = 1_700_000_000;
        $keys_before = [
            RFQ_S3_Key_Builder::manifest_meta_key($session_id),
            'intake/' . $session_id . '/parts/uuid_part.step',
        ];

        foreach ($keys_before as $index => $key) {
            $this->mock->seed_object($key, 100 + $index, 'application/octet-stream', '', $now - (40 * DAY_IN_SECONDS));
        }

        RFQ_Intake_Monitor::detect_orphan_manifests($now);
        RFQ_Intake_Monitor::detect_cleanup_candidates($now);
        RFQ_Intake_Monitor::run_scheduled_check();

        foreach ($keys_before as $key) {
            $this->assertArrayHasKey($key, $this->mock->objects);
        }
    }
}
