<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Admin_Intake_List::class)]
#[Group('AC-WP-028')]
class IntakeListTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RFQ_Activator::activate();
        $this->truncate_sessions();
    }

    protected function tearDown(): void
    {
        $this->truncate_sessions();
        parent::tearDown();
    }

    public function test_query_returns_all_sessions_ordered_by_created_at_desc(): void
    {
        $this->insert_session('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'draft', '2026-01-01 10:00:00');
        $this->insert_session('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'submitted', '2026-01-03 10:00:00');
        $this->insert_session('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'draft', '2026-01-02 10:00:00');

        $sessions = RFQ_Admin_Intake_List::query_sessions(25, 1);

        $this->assertCount(3, $sessions);
        $this->assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $sessions[0]->session_id);
        $this->assertSame('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $sessions[1]->session_id);
        $this->assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $sessions[2]->session_id);
    }

    public function test_pagination_limits_results(): void
    {
        for ($index = 0; $index < 3; $index++) {
            $this->insert_session(
                sprintf('dddddddd-dddd-4ddd-8ddd-dddddddd%04d', $index),
                'draft',
                sprintf('2026-02-0%d 10:00:00', $index + 1)
            );
        }

        $this->assertSame(3, RFQ_Admin_Intake_List::count_sessions());
        $this->assertCount(2, RFQ_Admin_Intake_List::query_sessions(2, 1));
        $this->assertCount(1, RFQ_Admin_Intake_List::query_sessions(2, 2));
    }

    public function test_formatters_render_phone_name_and_part_count_rules(): void
    {
        $this->assertSame('Jane Doe', RFQ_Admin_Intake_List::format_display_name('Jane', 'Doe'));
        $this->assertSame('', RFQ_Admin_Intake_List::format_display_name(null, null));
        $this->assertSame('+1 (555) 555-0100', RFQ_Admin_Intake_List::format_phone('5555550100', '1'));
        $this->assertSame('', RFQ_Admin_Intake_List::format_phone('12345', '1'));
        $this->assertSame('3', RFQ_Admin_Intake_List::format_part_count('submitted', 3));
        $this->assertSame('', RFQ_Admin_Intake_List::format_part_count('draft', 3));
    }

    public function test_per_page_options_default_and_validate(): void
    {
        $_GET = [];

        $this->assertSame(25, RFQ_Admin_Intake_List::resolve_per_page());

        $_GET['per_page'] = '50';
        $this->assertSame(50, RFQ_Admin_Intake_List::resolve_per_page());

        $_GET['per_page'] = '99';
        $this->assertSame(25, RFQ_Admin_Intake_List::resolve_per_page());

        unset($_GET['per_page']);
    }

    public function test_resolve_page_number_caps_to_total_pages(): void
    {
        $_GET['paged'] = '99';

        $this->assertSame(3, RFQ_Admin_Intake_List::resolve_page_number(3));

        $_GET['paged'] = '0';
        $this->assertSame(1, RFQ_Admin_Intake_List::resolve_page_number(3));

        unset($_GET['paged']);
    }

    public function test_format_created_at_uses_site_timezone(): void
    {
        update_option('timezone_string', 'America/New_York');
        update_option('date_format', 'Y-m-d');
        update_option('time_format', 'H:i');

        // created_at is stored UTC via current_time('mysql', true).
        $formatted = RFQ_Admin_Intake_List::format_created_at('2026-06-15 14:30:00');

        $this->assertSame('2026-06-15 10:30', $formatted);
        $this->assertSame('', RFQ_Admin_Intake_List::format_created_at('0000-00-00 00:00:00'));
    }

    private function truncate_sessions(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
    }

    private function insert_session(string $session_id, string $status, string $created_at): void
    {
        global $wpdb;

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
}
