<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Roles::class)]
#[Group('AC-WP-037')]
class RolesTest extends TestCase
{
    /** @var list<int> */
    private array $created_user_ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once ABSPATH . 'wp-admin/includes/user.php';

        RFQ_Activator::activate();
    }

    protected function tearDown(): void
    {
        foreach ($this->created_user_ids as $user_id) {
            wp_delete_user($user_id);
        }

        $this->created_user_ids = [];
        wp_set_current_user(0);
        RFQ_Roles::install();

        parent::tearDown();
    }

    public function test_activation_creates_viewer_role_with_only_read_and_view_caps(): void
    {
        $role = get_role(RFQ_Roles::VIEWER_ROLE);

        $this->assertInstanceOf(WP_Role::class, $role);
        $this->assertSame(
            ['read' => true, RFQ_Roles::VIEW_CAP => true],
            array_filter($role->capabilities)
        );
        $this->assertSame(RFQ_Roles::VERSION, (int) get_option(RFQ_Roles::VERSION_OPTION));
    }

    public function test_install_is_idempotent(): void
    {
        RFQ_Roles::install();
        RFQ_Roles::install();

        $this->assertSame(
            ['read' => true, RFQ_Roles::VIEW_CAP => true],
            array_filter(get_role(RFQ_Roles::VIEWER_ROLE)->capabilities)
        );
    }

    public function test_maybe_install_restores_role_on_update_without_reactivation(): void
    {
        remove_role(RFQ_Roles::VIEWER_ROLE);
        delete_option(RFQ_Roles::VERSION_OPTION);

        RFQ_Roles::maybe_install();

        $this->assertInstanceOf(WP_Role::class, get_role(RFQ_Roles::VIEWER_ROLE));
    }

    public function test_maybe_install_is_a_no_op_when_version_is_current(): void
    {
        get_role(RFQ_Roles::VIEWER_ROLE)->remove_cap(RFQ_Roles::VIEW_CAP);

        RFQ_Roles::maybe_install();

        $this->assertArrayNotHasKey(RFQ_Roles::VIEW_CAP, get_role(RFQ_Roles::VIEWER_ROLE)->capabilities);
    }

    public function test_uninstall_removes_role_and_version_option(): void
    {
        RFQ_Roles::uninstall();

        $this->assertNull(get_role(RFQ_Roles::VIEWER_ROLE));
        $this->assertFalse(get_option(RFQ_Roles::VERSION_OPTION));
    }

    public function test_viewer_can_view_but_not_delete_settings_or_edit_own_profile(): void
    {
        $viewer = $this->create_user(RFQ_Roles::VIEWER_ROLE);

        $this->assertTrue(user_can($viewer, RFQ_Roles::VIEW_CAP));
        $this->assertFalse(user_can($viewer, RFQ_Roles::DELETE_CAP));
        $this->assertFalse(user_can($viewer, 'manage_options'));
        $this->assertFalse(user_can($viewer, 'edit_posts'));
        $this->assertFalse(user_can($viewer, 'edit_user', $viewer));
        $this->assertFalse(user_can($viewer, 'create_app_password', $viewer));
        $this->assertTrue(RFQ_Roles::is_viewer(get_userdata($viewer)));
    }

    public function test_administrator_gets_view_and_delete_caps_and_keeps_profile_access(): void
    {
        $admin = $this->create_user('administrator');

        $this->assertTrue(user_can($admin, RFQ_Roles::VIEW_CAP));
        $this->assertTrue(user_can($admin, RFQ_Roles::DELETE_CAP));
        $this->assertTrue(user_can($admin, 'edit_user', $admin));
        $this->assertFalse(RFQ_Roles::is_viewer(get_userdata($admin)));
    }

    public function test_admin_who_also_holds_viewer_role_is_not_restricted(): void
    {
        $admin = $this->create_user('administrator');
        get_userdata($admin)->add_role(RFQ_Roles::VIEWER_ROLE);

        $user = get_userdata($admin);

        $this->assertFalse(RFQ_Roles::is_viewer($user));
        $this->assertTrue(user_can($user, 'edit_user', $admin));
    }

    public function test_viewer_role_added_to_another_role_keeps_that_roles_access(): void
    {
        $editor = $this->create_user('editor');
        get_userdata($editor)->add_role(RFQ_Roles::VIEWER_ROLE);

        $user = get_userdata($editor);

        $this->assertFalse(RFQ_Roles::is_viewer($user));
        $this->assertTrue(user_can($user, RFQ_Roles::VIEW_CAP));
        $this->assertFalse(user_can($user, RFQ_Roles::DELETE_CAP));
        $this->assertTrue(user_can($user, 'edit_user', $editor));
    }

    public function test_rfq_caps_follow_effective_manage_options(): void
    {
        $admin = $this->create_user('administrator');
        $deny = static fn(array $caps, string $cap): array => $cap === 'manage_options' ? ['do_not_allow'] : $caps;
        add_filter('map_meta_cap', $deny, 10, 2);

        try {
            $this->assertFalse(user_can($admin, RFQ_Roles::VIEW_CAP));
            $this->assertFalse(user_can($admin, RFQ_Roles::DELETE_CAP));
        } finally {
            remove_filter('map_meta_cap', $deny, 10);
        }

        $this->assertTrue(user_can($admin, RFQ_Roles::VIEW_CAP));
    }

    public function test_rfq_caps_follow_dynamic_manage_options_grants_and_denials(): void
    {
        $admin = $this->create_user('administrator');
        $subscriber = $this->create_user('subscriber');

        // Policies keyed on the requested capability, as user_has_cap filters usually are.
        $policy = static function (array $allcaps, array $caps, array $args) use ($admin, $subscriber): array {
            if (($args[0] ?? '') === 'manage_options') {
                $allcaps['manage_options'] = (int) $args[1] === $subscriber;
            }

            return $allcaps;
        };
        add_filter('user_has_cap', $policy, 10, 3);

        try {
            $this->assertFalse(user_can($admin, RFQ_Roles::VIEW_CAP));
            $this->assertFalse(user_can($admin, RFQ_Roles::DELETE_CAP));
            $this->assertTrue(user_can($subscriber, RFQ_Roles::VIEW_CAP));
            $this->assertTrue(user_can($subscriber, RFQ_Roles::DELETE_CAP));
        } finally {
            remove_filter('user_has_cap', $policy, 10);
        }

        $this->assertTrue(user_can($admin, RFQ_Roles::DELETE_CAP));
        $this->assertFalse(user_can($subscriber, RFQ_Roles::VIEW_CAP));
        $this->assertFalse(user_can(0, RFQ_Roles::VIEW_CAP));
    }

    public function test_viewer_redirect_target_uses_resolved_page_exactly(): void
    {
        global $pagenow, $plugin_page;

        $saved = [$pagenow, $plugin_page];
        $viewer = $this->create_user(RFQ_Roles::VIEWER_ROLE);
        $list_url = RFQ_Roles::intake_list_url();

        try {
            wp_set_current_user($viewer);

            $pagenow = 'admin.php';
            $plugin_page = RFQ_Admin_Intake_List::MENU_SLUG;
            $this->assertNull(RFQ_Roles::viewer_redirect_target());

            $plugin_page = 'RFQ-Intake-List';
            $this->assertSame($list_url, RFQ_Roles::viewer_redirect_target());

            $plugin_page = RFQ_Admin_Settings::MENU_SLUG;
            $this->assertSame($list_url, RFQ_Roles::viewer_redirect_target());

            $pagenow = 'profile.php';
            $plugin_page = null;
            $this->assertSame($list_url, RFQ_Roles::viewer_redirect_target());

            $pagenow = 'admin-post.php';
            $this->assertNull(RFQ_Roles::viewer_redirect_target());

            // Role stripped of the view cap: send home, never back to the list.
            get_role(RFQ_Roles::VIEWER_ROLE)->remove_cap(RFQ_Roles::VIEW_CAP);
            wp_set_current_user(0);
            wp_set_current_user($viewer);
            $pagenow = 'admin.php';
            $plugin_page = RFQ_Admin_Intake_List::MENU_SLUG;
            $this->assertSame(home_url('/'), RFQ_Roles::viewer_redirect_target());

            wp_set_current_user($this->create_user('administrator'));
            $pagenow = 'profile.php';
            $this->assertNull(RFQ_Roles::viewer_redirect_target());
        } finally {
            [$pagenow, $plugin_page] = $saved;
        }
    }

    public function test_viewer_cannot_save_color_scheme_via_ajax(): void
    {
        $handler = static fn() => static function (): void {
            throw new RuntimeException('wp_die');
        };
        add_filter('wp_die_handler', $handler);

        try {
            wp_set_current_user($this->create_user('administrator'));
            RFQ_Roles::block_viewer_profile_ajax();

            wp_set_current_user($this->create_user(RFQ_Roles::VIEWER_ROLE));
            $this->expectException(RuntimeException::class);
            RFQ_Roles::block_viewer_profile_ajax();
        } finally {
            remove_filter('wp_die_handler', $handler);
        }
    }

    public function test_orphan_notice_shows_for_viewer_and_admin_only(): void
    {
        set_transient(RFQ_Intake_Maintenance::ORPHAN_ALERT_TRANSIENT, ['session_ids' => ['a', 'b']], 600);

        try {
            $render = static function (int $user_id): string {
                wp_set_current_user($user_id);
                ob_start();
                RFQ_Intake_Maintenance::render_admin_notice();

                return (string) ob_get_clean();
            };

            $this->assertStringContainsString('2 sessions have manifests', $render($this->create_user(RFQ_Roles::VIEWER_ROLE)));
            $this->assertStringContainsString('2 sessions have manifests', $render($this->create_user('administrator')));
            $this->assertSame('', $render($this->create_user('subscriber')));
        } finally {
            delete_transient(RFQ_Intake_Maintenance::ORPHAN_ALERT_TRANSIENT);
        }
    }

    public function test_viewer_menu_shows_intake_sessions_without_settings(): void
    {
        global $menu, $submenu;

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        wp_set_current_user($this->create_user(RFQ_Roles::VIEWER_ROLE));

        $saved_menu = $menu;
        $saved_submenu = $submenu;
        $menu = [];
        $submenu = [];

        try {
            RFQ_Admin_Intake_List::register_menu();
            RFQ_Admin_Settings::register_menu();

            $slugs = array_column($menu, 2);
            $this->assertContains(RFQ_Admin_Intake_List::MENU_SLUG, $slugs);

            $items = array_column(array_values($submenu[RFQ_Admin_Intake_List::MENU_SLUG]), 2);
            $this->assertSame([RFQ_Admin_Intake_List::MENU_SLUG], $items);
        } finally {
            $menu = $saved_menu;
            $submenu = $saved_submenu;
        }
    }

    public function test_viewer_redirect_runs_on_admin_init_and_core_access_denied(): void
    {
        $this->assertSame(1, has_action('admin_init', [RFQ_Roles::class, 'restrict_admin_pages']));
        $this->assertNotFalse(has_action('admin_page_access_denied', [RFQ_Roles::class, 'restrict_admin_pages']));
        // Core adds my-account at admin_bar_menu 9991, so pruning waits for render.
        $this->assertNotFalse(has_action('wp_before_admin_bar_render', [RFQ_Roles::class, 'prune_current_admin_bar']));
    }

    public function test_viewer_screen_allow_list(): void
    {
        $slug = RFQ_Admin_Intake_List::MENU_SLUG;

        $this->assertTrue(RFQ_Roles::is_viewer_screen_allowed('admin.php', $slug));
        $this->assertTrue(RFQ_Roles::is_viewer_screen_allowed('admin-ajax.php', ''));
        $this->assertTrue(RFQ_Roles::is_viewer_screen_allowed('admin-post.php', ''));
        $this->assertFalse(RFQ_Roles::is_viewer_screen_allowed('admin.php', RFQ_Admin_Settings::MENU_SLUG));
        $this->assertFalse(RFQ_Roles::is_viewer_screen_allowed('index.php', ''));
        $this->assertFalse(RFQ_Roles::is_viewer_screen_allowed('profile.php', ''));
        $this->assertFalse(RFQ_Roles::is_viewer_screen_allowed('options-general.php', ''));
        // A viewer whose role lost the view cap is not sent back to the list (no loop).
        $this->assertFalse(RFQ_Roles::is_viewer_screen_allowed('admin.php', $slug, false));
        $this->assertTrue(RFQ_Roles::is_viewer_screen_allowed('admin-ajax.php', '', false));
    }

    public function test_login_redirect_sends_viewer_to_intake_list(): void
    {
        $viewer = get_userdata($this->create_user(RFQ_Roles::VIEWER_ROLE));
        $admin = get_userdata($this->create_user('administrator'));
        $list_url = RFQ_Roles::intake_list_url();

        $this->assertSame($list_url, apply_filters('login_redirect', admin_url(), '', $viewer));
        $this->assertSame($list_url, apply_filters('login_redirect', admin_url('profile.php'), admin_url('profile.php'), $viewer));
        $this->assertSame(
            $list_url . '&paged=2',
            apply_filters('login_redirect', $list_url . '&paged=2', $list_url . '&paged=2', $viewer)
        );
        $this->assertSame(admin_url(), apply_filters('login_redirect', admin_url(), '', $admin));
    }

    public function test_viewer_list_hides_delete_action_and_admin_list_shows_it(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
        $wpdb->insert(
            $wpdb->prefix . 'rfq_sessions',
            [
                'session_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
                'status' => 'draft',
                'created_at' => '2026-03-01 10:00:00',
                'updated_at' => '2026-03-01 10:00:00',
            ]
        );

        try {
            wp_set_current_user($this->create_user(RFQ_Roles::VIEWER_ROLE));
            $viewer_html = $this->render_list();

            wp_set_current_user($this->create_user('administrator'));
            $admin_html = $this->render_list();
        } finally {
            $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . 'rfq_sessions');
        }

        $this->assertStringNotContainsString(RFQ_Admin_Intake_List::DELETE_ACTION, $viewer_html);
        $this->assertStringNotContainsString('>Actions<', $viewer_html);
        $this->assertStringContainsString(RFQ_Admin_Intake_List::DELETE_ACTION, $admin_html);
    }

    public function test_viewer_delete_request_is_rejected(): void
    {
        wp_set_current_user($this->create_user(RFQ_Roles::VIEWER_ROLE));

        $handler = static fn() => static function (string $message): void {
            throw new RuntimeException('wp_die: ' . $message);
        };
        add_filter('wp_die_handler', $handler);

        try {
            RFQ_Admin_Intake_List::handle_delete();
            $this->fail('Viewer delete must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('permission', $exception->getMessage());
        } finally {
            remove_filter('wp_die_handler', $handler);
        }
    }

    public function test_admin_bar_hides_profile_and_dashboard_links_for_viewer(): void
    {
        require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

        wp_set_current_user($this->create_user(RFQ_Roles::VIEWER_ROLE));

        $bar = new WP_Admin_Bar();
        $bar->add_node(['id' => 'my-account', 'title' => 'Howdy', 'href' => admin_url('profile.php')]);
        $bar->add_node([
            'id' => 'user-info',
            'parent' => 'my-account',
            'title' => "<span class='display-name'>Name</span><span class='display-name edit-profile'>Edit Profile</span>",
            'href' => admin_url('profile.php'),
        ]);
        $bar->add_node(['id' => 'edit-profile', 'parent' => 'my-account', 'title' => 'Edit', 'href' => admin_url('profile.php')]);
        $bar->add_node(['id' => 'logout', 'parent' => 'my-account', 'title' => 'Log Out', 'href' => wp_logout_url()]);
        $bar->add_node(['id' => 'wp-logo', 'title' => 'WP', 'href' => admin_url('about.php')]);
        $bar->add_node(['id' => 'site-name', 'title' => 'Site', 'href' => admin_url()]);
        $bar->add_node(['id' => 'dashboard', 'parent' => 'site-name', 'title' => 'Dashboard', 'href' => admin_url()]);

        RFQ_Roles::prune_admin_bar($bar);

        $this->assertNull($bar->get_node('edit-profile'));
        $this->assertNull($bar->get_node('wp-logo'));
        $this->assertNull($bar->get_node('dashboard'));
        $this->assertNotNull($bar->get_node('logout'));
        $this->assertSame(RFQ_Roles::intake_list_url(), $bar->get_node('my-account')->href);
        $this->assertSame('', $bar->get_node('user-info')->href);
        $this->assertStringNotContainsString('Edit Profile', $bar->get_node('user-info')->title);
        $this->assertSame(RFQ_Roles::intake_list_url(), $bar->get_node('site-name')->href);
    }

    private function render_list(): string
    {
        // get_admin_page_title() reads the screen title global set by wp-admin.
        $GLOBALS['title'] = 'Intake Sessions';

        ob_start();
        RFQ_Admin_Intake_List::render_page();

        return (string) ob_get_clean();
    }

    private function create_user(string $role): int
    {
        $user_id = wp_insert_user([
            'user_login' => 'rfq_' . $role . '_' . wp_generate_password(8, false),
            'user_pass' => wp_generate_password(),
            'role' => $role,
        ]);

        $this->assertIsInt($user_id);
        $this->created_user_ids[] = $user_id;

        return $user_id;
    }
}
