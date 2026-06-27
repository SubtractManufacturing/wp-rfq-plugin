<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Intake_Maintenance::class)]
#[Group('AC-WP-025')]
class IntakeMaintenanceIntegrationTest extends TestCase
{
    private RFQ_S3_Client_Mock $s3;

    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        $this->s3 = new RFQ_S3_Client_Mock();
        add_filter( 'rfq_s3_client', [ $this, 'provide_s3_client' ] );

        RFQ_Activator::activate();
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
        delete_transient( RFQ_Intake_Maintenance::ORPHAN_ALERT_TRANSIENT );
    }

    protected function tearDown(): void
    {
        remove_filter( 'rfq_s3_client', [ $this, 'provide_s3_client' ] );
        delete_transient( RFQ_Intake_Maintenance::ORPHAN_ALERT_TRANSIENT );

        parent::tearDown();
    }

    public function provide_s3_client(): RFQ_S3_Client_Mock
    {
        return $this->s3;
    }

    public function test_run_sets_orphan_transient_and_clears_when_receipt_exists(): void
    {
        global $wpdb;

        $session_id = '550e8400-e29b-41d4-a716-446655440040';
        $created_at = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $table      = $wpdb->prefix . 'rfq_sessions';

        $wpdb->insert(
            $table,
            [
                'session_id' => $session_id,
                'status'     => 'draft',
                'created_at' => $created_at,
                'updated_at' => $created_at,
            ]
        );

        $this->s3->seed_object(
            'intake/' . $session_id . '/meta/manifest.json',
            'application/json',
            128,
            time() - RFQ_Intake_Maintenance::ORPHAN_MANIFEST_THRESHOLD_SECONDS - 60
        );

        RFQ_Intake_Maintenance::run();

        $alert = get_transient( RFQ_Intake_Maintenance::ORPHAN_ALERT_TRANSIENT );

        $this->assertIsArray( $alert );
        $this->assertSame( [$session_id], $alert['session_ids'] );

        $this->s3->seed_object(
            'intake/' . $session_id . '/meta/receipt.json',
            'application/json',
            64,
            time()
        );

        RFQ_Intake_Maintenance::run();

        $this->assertFalse( get_transient( RFQ_Intake_Maintenance::ORPHAN_ALERT_TRANSIENT ) );
    }

    public function test_purge_stale_draft_sessions_deletes_old_drafts(): void
    {
        global $wpdb;

        $table  = $wpdb->prefix . 'rfq_sessions';
        $old    = gmdate('Y-m-d H:i:s', time() - (91 * DAY_IN_SECONDS));
        $recent = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);

        $wpdb->insert(
            $table,
            [
                'session_id' => '550e8400-e29b-41d4-a716-446655440030',
                'status' => 'draft',
                'created_at' => $old,
                'updated_at' => $old,
            ]
        );
        $wpdb->insert(
            $table,
            [
                'session_id' => '550e8400-e29b-41d4-a716-446655440031',
                'status' => 'draft',
                'created_at' => $recent,
                'updated_at' => $recent,
            ]
        );
        $wpdb->insert(
            $table,
            [
                'session_id' => '550e8400-e29b-41d4-a716-446655440032',
                'status' => 'submitted',
                'created_at' => $old,
                'updated_at' => $old,
            ]
        );

        $deleted = RFQ_Intake_Maintenance::purge_stale_draft_sessions();

        self::assertSame(1, $deleted);
        self::assertSame(
            '550e8400-e29b-41d4-a716-446655440031',
            $wpdb->get_var("SELECT session_id FROM {$table} WHERE status = 'draft'")
        );
    }
}
