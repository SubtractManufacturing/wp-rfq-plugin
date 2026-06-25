<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Admin_Intake_List
{
    public const MENU_SLUG = 'rfq-intake-list';

    public const DEFAULT_PER_PAGE = 25;

    /** @var list<int> */
    public const PER_PAGE_OPTIONS = [25, 50, 75];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            RFQ_Admin_Settings::MENU_SLUG,
            __('Intake Sessions', 'rfq-intake'),
            __('Intake Sessions', 'rfq-intake'),
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render_page']
        );
    }

    public static function resolve_per_page(): int
    {
        $requested = isset($_GET['per_page']) ? (int) $_GET['per_page'] : self::DEFAULT_PER_PAGE;

        return in_array($requested, self::PER_PAGE_OPTIONS, true)
            ? $requested
            : self::DEFAULT_PER_PAGE;
    }

    public static function resolve_page_number(): int
    {
        $paged = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;

        return max(1, $paged);
    }

    public static function count_sessions(): int
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rfq_sessions';

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * @return list<object>
     */
    public static function query_sessions(int $per_page, int $page): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'rfq_sessions';
        $offset = ($page - 1) * $per_page;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );

        return is_array($results) ? $results : [];
    }

    public static function format_display_name(?string $first, ?string $last): string
    {
        $parts = [];

        if ($first !== null && $first !== '') {
            $parts[] = $first;
        }

        if ($last !== null && $last !== '') {
            $parts[] = $last;
        }

        return implode(' ', $parts);
    }

    public static function format_phone(?string $phone, ?string $country_code): string
    {
        if ($phone === null || $phone === '' || strlen($phone) !== 10 || !ctype_digit($phone)) {
            return '';
        }

        if ($country_code === null || $country_code === '') {
            $country_code = '1';
        }

        return sprintf(
            '+%s (%s) %s-%s',
            $country_code,
            substr($phone, 0, 3),
            substr($phone, 3, 3),
            substr($phone, 6, 4)
        );
    }

    public static function format_part_count(string $status, mixed $count): string
    {
        if ($status !== 'submitted' || $count === null) {
            return '';
        }

        return (string) (int) $count;
    }

    public static function format_created_at(?string $created_at): string
    {
        if ($created_at === null || $created_at === '' || $created_at === '0000-00-00 00:00:00') {
            return '';
        }

        $timestamp = mysql2date('U', $created_at, false);

        if ($timestamp === false) {
            return '';
        }

        return wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            (int) $timestamp
        );
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $per_page = self::resolve_per_page();
        $page = self::resolve_page_number();
        $total = self::count_sessions();
        $sessions = self::query_sessions($per_page, $page);
        $total_pages = max(1, (int) ceil($total / $per_page));

        require RFQ_INTAKE_PLUGIN_DIR . 'admin/views/intake-list-page.php';
    }
}
