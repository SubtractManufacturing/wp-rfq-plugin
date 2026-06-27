<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Intake_Maintenance::class)]
#[Group('AC-WP-025')]
class IntakeMaintenanceIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;

        RFQ_Activator::activate();
        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
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
