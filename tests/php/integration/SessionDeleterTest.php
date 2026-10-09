<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Session_Deleter::class)]
#[CoversClass(RFQ_Intake_Maintenance::class)]
#[Group('AC-WP-033')]
class SessionDeleterTest extends TestCase
{
    private const OLD_IN_S3 = '550e8400-e29b-41d4-a716-446655440101';
    private const OLD_GONE = '550e8400-e29b-41d4-a716-446655440102';
    private const OLD_GONE_TWO = '550e8400-e29b-41d4-a716-446655440103';
    private const RECENT_GONE = '550e8400-e29b-41d4-a716-446655440104';

    private RFQ_S3_Client_Mock $s3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->s3 = new RFQ_S3_Client_Mock();
        add_filter('rfq_s3_client', [$this, 'provide_s3_client']);

        RFQ_Activator::activate();
        $this->truncate_sessions();
        delete_option(RFQ_Session_Deleter::OPTION_RETENTION_DAYS);
    }

    protected function tearDown(): void
    {
        remove_filter('rfq_s3_client', [$this, 'provide_s3_client']);
        delete_option(RFQ_Session_Deleter::OPTION_RETENTION_DAYS);
        $this->truncate_sessions();

        parent::tearDown();
    }

    public function provide_s3_client(): RFQ_S3_Client_Mock
    {
        return $this->s3;
    }

    public function test_retention_defaults_to_180_days_and_sanitizes_input(): void
    {
        $this->assertSame(180, RFQ_Session_Deleter::get_retention_days());

        update_option(RFQ_Session_Deleter::OPTION_RETENTION_DAYS, '30');
        $this->assertSame(30, RFQ_Session_Deleter::get_retention_days());

        update_option(RFQ_Session_Deleter::OPTION_RETENTION_DAYS, '0');
        $this->assertSame(0, RFQ_Session_Deleter::get_retention_days());

        $this->assertSame(0, RFQ_Session_Deleter::sanitize_retention_days('-5'));
        $this->assertSame(180, RFQ_Session_Deleter::sanitize_retention_days('abc'));
        $this->assertSame(
            RFQ_Session_Deleter::MAX_RETENTION_DAYS,
            RFQ_Session_Deleter::sanitize_retention_days('999999')
        );
    }

    public function test_delete_session_refuses_when_objects_still_exist_in_s3(): void
    {
        $this->insert_session(self::OLD_IN_S3, 200);
        $this->s3->seed_object('intake/' . self::OLD_IN_S3 . '/meta/receipt.json', 'application/json', 10);

        $result = RFQ_Session_Deleter::delete_session(self::OLD_IN_S3, $this->s3);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rfq_session_in_s3', $result->get_error_code());
        $this->assertTrue($this->row_exists(self::OLD_IN_S3));
    }

    public function test_delete_session_removes_row_once_s3_is_empty(): void
    {
        $this->insert_session(self::OLD_GONE, 200);
        $this->s3->seed_object('intake/' . self::OLD_GONE . '/meta/receipt.json', 'application/json', 10);
        $this->s3->remove_session_objects(self::OLD_GONE);

        $this->assertTrue(RFQ_Session_Deleter::delete_session(self::OLD_GONE, $this->s3));
        $this->assertFalse($this->row_exists(self::OLD_GONE));
    }

    public function test_draft_sessions_are_deleted_even_when_objects_exist_in_s3(): void
    {
        $this->insert_session(self::OLD_IN_S3, 200, 'draft');
        $this->s3->seed_object('intake/' . self::OLD_IN_S3 . '/meta/manifest.json', 'application/json', 10);

        $this->assertTrue(RFQ_Session_Deleter::delete_session(self::OLD_IN_S3, $this->s3));
        $this->assertFalse($this->row_exists(self::OLD_IN_S3));
        // The plugin never touches S3 objects; the ERP cleans them up later.
        $this->assertTrue($this->s3->has_session_objects(self::OLD_IN_S3));
    }

    public function test_draft_sessions_are_deleted_even_when_s3_is_unavailable(): void
    {
        $this->insert_session(self::OLD_GONE, 200, 'draft');
        $this->s3->should_fail = true;

        $this->assertTrue(RFQ_Session_Deleter::delete_session(self::OLD_GONE, $this->s3));
        $this->assertFalse($this->row_exists(self::OLD_GONE));
    }

    public function test_abandoned_sessions_still_require_s3_to_be_empty(): void
    {
        $this->insert_session(self::OLD_IN_S3, 200, 'abandoned');
        $this->s3->seed_object('intake/' . self::OLD_IN_S3 . '/meta/manifest.json', 'application/json', 10);

        $result = RFQ_Session_Deleter::delete_session(self::OLD_IN_S3, $this->s3);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rfq_session_in_s3', $result->get_error_code());
    }

    public function test_delete_session_fails_closed_when_s3_cannot_be_checked(): void
    {
        $this->insert_session(self::OLD_GONE, 200);
        $this->s3->should_fail = true;

        $result = RFQ_Session_Deleter::delete_session(self::OLD_GONE, $this->s3);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rfq_session_s3_check_failed', $result->get_error_code());
        $this->assertTrue($this->row_exists(self::OLD_GONE));
    }

    public function test_delete_session_reports_unknown_session(): void
    {
        $result = RFQ_Session_Deleter::delete_session(self::OLD_GONE, $this->s3);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rfq_session_not_found', $result->get_error_code());
    }

    public function test_purge_expired_skips_s3_backed_and_recent_sessions_and_continues(): void
    {
        $this->insert_session(self::OLD_IN_S3, 200);
        $this->insert_session(self::OLD_GONE, 190);
        $this->insert_session(self::OLD_GONE_TWO, 181);
        $this->insert_session(self::RECENT_GONE, 10);
        $this->s3->seed_object('intake/' . self::OLD_IN_S3 . '/parts/a.step', 'application/octet-stream', 10);

        $summary = RFQ_Session_Deleter::purge_expired($this->s3, time());

        $this->assertSame(3, $summary['checked']);
        $this->assertSame(2, $summary['deleted']);
        $this->assertSame(1, $summary['retained_in_s3']);
        $this->assertSame(0, $summary['errors']);
        $this->assertTrue($this->row_exists(self::OLD_IN_S3));
        $this->assertFalse($this->row_exists(self::OLD_GONE));
        $this->assertFalse($this->row_exists(self::OLD_GONE_TWO));
        $this->assertTrue($this->row_exists(self::RECENT_GONE));
    }

    public function test_purge_expired_is_disabled_when_retention_is_zero(): void
    {
        update_option(RFQ_Session_Deleter::OPTION_RETENTION_DAYS, '0');
        $this->insert_session(self::OLD_GONE, 2000);

        $summary = RFQ_Session_Deleter::purge_expired($this->s3, time());

        $this->assertSame(0, $summary['checked']);
        $this->assertTrue($this->row_exists(self::OLD_GONE));
    }

    public function test_purge_expired_deletes_nothing_when_s3_is_unavailable(): void
    {
        $this->insert_session(self::OLD_GONE, 200);
        $this->s3->should_fail = true;

        $summary = RFQ_Session_Deleter::purge_expired($this->s3, time());

        $this->assertSame(0, $summary['deleted']);
        $this->assertSame(1, $summary['errors']);
        $this->assertTrue($this->row_exists(self::OLD_GONE));
    }

    public function test_maintenance_run_applies_retention(): void
    {
        $this->insert_session(self::OLD_GONE, 200);

        RFQ_Intake_Maintenance::run();

        $this->assertFalse($this->row_exists(self::OLD_GONE));
    }

    public function test_delete_result_codes_and_notices_cover_every_outcome(): void
    {
        $this->assertSame('deleted', RFQ_Admin_Intake_List::delete_result_code(true));
        $this->assertSame(
            'in_s3',
            RFQ_Admin_Intake_List::delete_result_code(new WP_Error('rfq_session_in_s3'))
        );
        $this->assertSame(
            'check_failed',
            RFQ_Admin_Intake_List::delete_result_code(new WP_Error('rfq_session_s3_check_failed'))
        );
        $this->assertSame(
            'check_failed',
            RFQ_Admin_Intake_List::delete_result_code(new WP_Error('rfq_s3_not_configured'))
        );
        $this->assertSame(
            'not_found',
            RFQ_Admin_Intake_List::delete_result_code(new WP_Error('rfq_session_not_found'))
        );
        $this->assertSame(
            'error',
            RFQ_Admin_Intake_List::delete_result_code(new WP_Error('anything_else'))
        );

        $this->assertSame('success', RFQ_Admin_Intake_List::resolve_delete_notice('deleted')['type']);
        $this->assertSame('error', RFQ_Admin_Intake_List::resolve_delete_notice('in_s3')['type']);
        $this->assertNull(RFQ_Admin_Intake_List::resolve_delete_notice('<script>'));
        $this->assertNull(RFQ_Admin_Intake_List::resolve_delete_notice(null));
    }

    private function insert_session(string $session_id, int $age_days, string $status = 'submitted'): void
    {
        global $wpdb;

        $created_at = gmdate('Y-m-d H:i:s', time() - ($age_days * DAY_IN_SECONDS));

        $wpdb->insert(
            $wpdb->prefix . 'rfq_sessions',
            [
                'session_id' => $session_id,
                'status' => $status,
                'created_at' => $created_at,
                'updated_at' => $created_at,
            ],
            ['%s', '%s', '%s', '%s']
        );
    }

    private function row_exists(string $session_id): bool
    {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'rfq_sessions WHERE session_id = %s',
                $session_id
            )
        ) > 0;
    }

    private function truncate_sessions(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
    }
}
