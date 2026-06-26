<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Activator::class)]
#[Group('AC-WP-023')]
class ActivatorTest extends TestCase
{
    public function test_double_activation_is_idempotent(): void
    {
        global $wpdb;

        $sessions_table = $wpdb->prefix . 'rfq_sessions';
        $sequences_table = $wpdb->prefix . 'rfq_receipt_sequences';

        RFQ_Activator::activate();

        $this->assertSame($sessions_table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sessions_table)));
        $this->assertSame($sequences_table, $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sequences_table)));

        $columns_after_first = $this->get_session_column_names();
        $indexes_after_first = $this->get_session_indexes();

        RFQ_Activator::activate();
        RFQ_Activator::activate();

        $this->assertSame($columns_after_first, $this->get_session_column_names());
        $this->assertSame($indexes_after_first, $this->get_session_indexes());

        $this->assertEqualsCanonicalizing(
            [
                'id',
                'session_id',
                'status',
                'contact_first_name',
                'contact_last_name',
                'contact_email',
                'contact_company',
                'contact_phone',
                'contact_phone_country_code',
                'contact_job_title',
                'shipping_postal_code',
                'draft_json',
                'submitted_part_count',
                'receipt_number',
                's3_prefix',
                'created_at',
                'updated_at',
                'submitted_at',
            ],
            $this->get_session_column_names()
        );

        $s3_prefix = $this->get_session_column('s3_prefix');
        $this->assertNull($s3_prefix['Default']);

        $sequence_columns = $wpdb->get_results("SHOW COLUMNS FROM {$sequences_table}", ARRAY_A);
        $this->assertSame(['receipt_date', 'seq'], array_column($sequence_columns, 'Field'));
    }

    public function test_maybe_upgrade_adds_missing_columns_without_reactivation(): void
    {
        global $wpdb;

        RFQ_Activator::activate();

        $sessions_table = $wpdb->prefix . 'rfq_sessions';
        $wpdb->query("ALTER TABLE {$sessions_table} DROP COLUMN draft_json");
        update_option('rfq_intake_db_version', '0.0.0');

        $this->assertNotContains('draft_json', $this->get_session_column_names());

        RFQ_Activator::maybe_upgrade();

        $this->assertContains('draft_json', $this->get_session_column_names());
        $this->assertSame(RFQ_INTAKE_VERSION, get_option('rfq_intake_db_version'));
    }

    /**
     * @return list<string>
     */
    private function get_session_column_names(): array
    {
        global $wpdb;

        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}rfq_sessions", ARRAY_A);

        return array_column($columns, 'Field');
    }

    /**
     * @return array<string, mixed>
     */
    private function get_session_column(string $name): array
    {
        global $wpdb;

        foreach ($wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}rfq_sessions", ARRAY_A) as $column) {
            if ($column['Field'] === $name) {
                return $column;
            }
        }

        $this->fail("Missing column {$name}");
    }

    /**
     * @return list<string>
     */
    private function get_session_indexes(): array
    {
        global $wpdb;

        $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->prefix}rfq_sessions", ARRAY_A);

        return array_values(array_unique(array_column($indexes, 'Key_name')));
    }
}
