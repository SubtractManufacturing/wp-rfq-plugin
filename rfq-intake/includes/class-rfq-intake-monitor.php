<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Intake_Monitor
{
    public const CRON_HOOK = 'rfq_intake_monitor_daily';

    private const WARNINGS_TRANSIENT = 'rfq_intake_monitor_warnings';

    private const ORPHAN_MANIFEST_THRESHOLD_SECONDS = 900;

    private const CLEANUP_CANDIDATE_AGE_SECONDS = 2592000;

    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'run_scheduled_check']);
        add_action('admin_notices', [self::class, 'render_admin_notices']);
    }

    public static function schedule_cron(): void
    {
        if (wp_next_scheduled(self::CRON_HOOK) !== false) {
            return;
        }

        wp_schedule_event(time(), 'daily', self::CRON_HOOK);
    }

    public static function run_scheduled_check(): void
    {
        $orphans = self::detect_orphan_manifests();
        $cleanup_candidates = self::detect_cleanup_candidates();
        $warnings = [];

        foreach ($orphans as $session_id) {
            $message = sprintf(
                'RFQ Intake orphan manifest detected for session %s: manifest.json exists without receipt.json for more than %d minutes.',
                $session_id,
                (int) (self::orphan_manifest_threshold_seconds() / 60)
            );

            error_log('RFQ Intake: ' . $message);
            $warnings[] = $message;
        }

        foreach ($cleanup_candidates as $session_id) {
            $message = sprintf(
                'RFQ Intake cleanup candidate: session %s has no receipt.json and intake prefix age exceeds %d days (detection only; ERP owns deletion).',
                $session_id,
                (int) (self::cleanup_candidate_age_seconds() / DAY_IN_SECONDS)
            );

            error_log('RFQ Intake: ' . $message);
            $warnings[] = $message;
        }

        if ($warnings === []) {
            delete_transient(self::WARNINGS_TRANSIENT);

            return;
        }

        set_transient(self::WARNINGS_TRANSIENT, $warnings, DAY_IN_SECONDS);
    }

    public static function render_admin_notices(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $warnings = get_transient(self::WARNINGS_TRANSIENT);

        if (! is_array($warnings) || $warnings === []) {
            return;
        }

        foreach ($warnings as $warning) {
            if (! is_string($warning) || $warning === '') {
                continue;
            }

            echo '<div class="notice notice-warning"><p>' . esc_html($warning) . '</p></div>';
        }
    }

    /**
     * @return list<string>
     */
    public static function detect_orphan_manifests(?int $now = null): array
    {
        $now ??= time();
        $orphans = [];
        $session_ids = self::list_session_ids();

        if ($session_ids instanceof WP_Error) {
            error_log(
                'RFQ Intake: orphan manifest scan skipped because intake session listing failed: '
                . $session_ids->get_error_message()
            );

            return [];
        }

        foreach ($session_ids as $session_id) {
            if (self::is_orphan_manifest($session_id, $now)) {
                $orphans[] = $session_id;
            }
        }

        return $orphans;
    }

    /**
     * @return list<string>
     */
    public static function detect_cleanup_candidates(?int $now = null): array
    {
        $now ??= time();
        $candidates = [];
        $session_ids = self::list_session_ids();

        if ($session_ids instanceof WP_Error) {
            error_log(
                'RFQ Intake: cleanup candidate scan skipped because intake session listing failed: '
                . $session_ids->get_error_message()
            );

            return [];
        }

        foreach ($session_ids as $session_id) {
            if (self::is_cleanup_candidate($session_id, $now)) {
                $candidates[] = $session_id;
            }
        }

        return $candidates;
    }

    /**
     * @return list<string>|WP_Error
     */
    private static function list_session_ids(): array|WP_Error
    {
        $s3_client = RFQ_S3_Client::resolve();

        if ($s3_client instanceof WP_Error) {
            return $s3_client;
        }

        return $s3_client->list_intake_session_ids();
    }

    private static function is_orphan_manifest(string $session_id, int $now): bool
    {
        $s3_client = RFQ_S3_Client::resolve();

        if ($s3_client instanceof WP_Error) {
            return false;
        }

        $manifest_key = RFQ_S3_Key_Builder::manifest_meta_key($session_id);
        $receipt_key = RFQ_S3_Key_Builder::receipt_meta_key($session_id);

        if ($s3_client->head_object($receipt_key) === true) {
            return false;
        }

        $manifest_metadata = $s3_client->head_object_metadata($manifest_key);

        if ($manifest_metadata instanceof WP_Error || $manifest_metadata === false) {
            return false;
        }

        $manifest_age = $now - $manifest_metadata['last_modified'];

        return $manifest_age > self::orphan_manifest_threshold_seconds();
    }

    private static function is_cleanup_candidate(string $session_id, int $now): bool
    {
        $s3_client = RFQ_S3_Client::resolve();

        if ($s3_client instanceof WP_Error) {
            return false;
        }

        $receipt_key = RFQ_S3_Key_Builder::receipt_meta_key($session_id);

        if ($s3_client->head_object($receipt_key) === true) {
            return false;
        }

        $oldest_modified = $s3_client->get_prefix_oldest_modified($session_id);

        if ($oldest_modified instanceof WP_Error || $oldest_modified === false) {
            return false;
        }

        return ($now - $oldest_modified) > self::cleanup_candidate_age_seconds();
    }

    private static function orphan_manifest_threshold_seconds(): int
    {
        return (int) apply_filters(
            'rfq_orphan_manifest_threshold_seconds',
            self::ORPHAN_MANIFEST_THRESHOLD_SECONDS
        );
    }

    private static function cleanup_candidate_age_seconds(): int
    {
        return (int) apply_filters(
            'rfq_cleanup_candidate_age_seconds',
            self::CLEANUP_CANDIDATE_AGE_SECONDS
        );
    }
}
