<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Capabilities and the "RFQ Intake Viewer" role.
 *
 * Administrators (anyone who effectively has `manage_options`) receive both
 * RFQ capabilities at runtime, so admin access never depends on stored role
 * data. The viewer role is limited to the Intake Sessions list: no delete, no
 * Settings, no Dashboard and no profile editing. Single-site only, like the
 * rest of the plugin.
 */
class RFQ_Roles {

    public const VIEW_CAP = 'rfq_view_intake_sessions';

    public const DELETE_CAP = 'rfq_delete_intake_sessions';

    public const VIEWER_ROLE = 'rfq_intake_viewer';

    public const VERSION_OPTION = 'rfq_intake_roles_version';

    /** Bump when the viewer role's stored capabilities change. */
    public const VERSION = 1;

    /** Admin bar nodes a viewer has no use for (each links to a blocked screen). */
    private const HIDDEN_ADMIN_BAR_NODES = [ 'wp-logo', 'comments', 'new-content', 'updates', 'edit-profile', 'dashboard' ];

    public static function init(): void {
        self::maybe_install();

        add_filter( 'map_meta_cap', [ self::class, 'map_rfq_caps' ], 10, 3 );
        add_filter( 'map_meta_cap', [ self::class, 'block_viewer_self_edit' ], 10, 4 );
        // Core saves the admin color scheme without an edit_user check.
        add_action( 'wp_ajax_save-user-color-scheme', [ self::class, 'block_viewer_profile_ajax' ], 0 );
        add_filter( 'login_redirect', [ self::class, 'filter_login_redirect' ], 10, 3 );
        add_action( 'admin_init', [ self::class, 'restrict_admin_pages' ], 1 );
        // Core denies screens the user lacks caps for before admin_init runs.
        add_action( 'admin_page_access_denied', [ self::class, 'restrict_admin_pages' ] );
        add_action( 'admin_menu', [ self::class, 'prune_admin_menu' ], 999 );
        add_action( 'wp_before_admin_bar_render', [ self::class, 'prune_current_admin_bar' ] );
    }

    /**
     * Roles live in the database, so sites that update without reactivating
     * the plugin get the role on their next request.
     */
    public static function maybe_install(): void {
        if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::VERSION ) {
            return;
        }

        self::install();
    }

    public static function install(): void {
        $role = get_role( self::VIEWER_ROLE );

        if ( $role === null ) {
            // Stored untranslated: WordPress translates role names only for core roles.
            add_role(
                self::VIEWER_ROLE,
                'RFQ Intake Viewer',
                [
                    'read'         => true,
                    self::VIEW_CAP => true,
                ]
            );
        } else {
            $role->add_cap( 'read' );
            $role->add_cap( self::VIEW_CAP );
        }

        update_option( self::VERSION_OPTION, self::VERSION, false );
    }

    public static function uninstall(): void {
        remove_role( self::VIEWER_ROLE );
        delete_option( self::VERSION_OPTION );
    }

    /**
     * Users without a stored RFQ capability get it exactly when a full
     * `user_can( 'manage_options' )` check passes, so every site policy on
     * `manage_options` (map_meta_cap or user_has_cap, grant or deny) also
     * applies to RFQ access.
     *
     * @param list<string> $caps
     * @return list<string>
     */
    public static function map_rfq_caps( array $caps, string $cap, int $user_id ): array {
        if ( $cap !== self::VIEW_CAP && $cap !== self::DELETE_CAP ) {
            return $caps;
        }

        $user = get_userdata( $user_id );

        if ( $user instanceof WP_User && ! empty( $user->allcaps[ $cap ] ) ) {
            return $caps;
        }

        // Not recursive: this filter returns early for manage_options.
        return user_can( $user_id, 'manage_options' ) ? [ 'exist' ] : [ 'do_not_allow' ];
    }

    public static function block_viewer_profile_ajax(): void {
        if ( self::is_viewer() ) {
            wp_die( '-1', '', [ 'response' => 403 ] );
        }
    }

    /**
     * Viewers may not edit their own account (profile screen, REST user
     * updates, application passwords all map through `edit_user`).
     *
     * @param list<string> $caps
     * @param array<int, mixed> $args
     * @return list<string>
     */
    public static function block_viewer_self_edit( array $caps, string $cap, int $user_id, array $args = [] ): array {
        if ( $cap !== 'edit_user' || ! isset( $args[0] ) || (int) $args[0] !== $user_id ) {
            return $caps;
        }

        $user = get_userdata( $user_id );

        if ( $user instanceof WP_User && self::is_viewer( $user ) ) {
            return [ 'do_not_allow' ];
        }

        return $caps;
    }

    /**
     * A viewer is a user whose only role is the viewer role and who lacks
     * administrator access. A user given the viewer role on top of another
     * role keeps that role's normal wp-admin access.
     */
    public static function is_viewer( ?WP_User $user = null ): bool {
        $user ??= wp_get_current_user();

        if ( ! $user->exists() || array_values( (array) $user->roles ) !== [ self::VIEWER_ROLE ] ) {
            return false;
        }

        // Safe inside map_meta_cap: our filters return early for manage_options.
        return ! user_can( $user, 'manage_options' );
    }

    public static function intake_list_url(): string {
        return add_query_arg( 'page', RFQ_Admin_Intake_List::MENU_SLUG, admin_url( 'admin.php' ) );
    }

    /**
     * @param string           $redirect_to
     * @param string           $requested_redirect_to
     * @param WP_User|WP_Error $user
     */
    public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ): string {
        if ( ! $user instanceof WP_User || ! self::is_viewer( $user ) ) {
            return (string) $redirect_to;
        }

        if ( self::is_allowed_admin_request( (string) $requested_redirect_to ) ) {
            return (string) $requested_redirect_to;
        }

        return self::intake_list_url();
    }

    /**
     * Sends viewers to the Intake Sessions list from every other wp-admin
     * screen. admin-ajax and admin-post pass through; their handlers check
     * their own capabilities. A viewer whose role lost the view capability
     * goes to the site home instead, so the list cannot redirect to itself.
     */
    public static function restrict_admin_pages(): void {
        $target = self::viewer_redirect_target();

        if ( $target === null ) {
            return;
        }

        // Our own admin/home URL. wp_safe_redirect() would swap it for the
        // Dashboard when the admin host differs from the home host, looping.
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
        wp_redirect( $target );
        exit;
    }

    /**
     * Where the current admin request must send a viewer, or null to let it through.
     */
    public static function viewer_redirect_target(): ?string {
        if ( ! self::is_viewer() || wp_doing_ajax() ) {
            return null;
        }

        global $pagenow, $plugin_page;

        // The page WordPress actually resolved and will render, compared exactly.
        $page     = is_string( $plugin_page ) ? $plugin_page : '';
        $can_view = current_user_can( self::VIEW_CAP );

        if ( self::is_viewer_screen_allowed( (string) $pagenow, $page, $can_view ) ) {
            return null;
        }

        return $can_view ? self::intake_list_url() : home_url( '/' );
    }

    public static function is_viewer_screen_allowed( string $pagenow, string $page, bool $can_view = true ): bool {
        if ( $pagenow === 'admin-post.php' || $pagenow === 'admin-ajax.php' ) {
            return true;
        }

        return $can_view && $pagenow === 'admin.php' && $page === RFQ_Admin_Intake_List::MENU_SLUG;
    }

    public static function prune_admin_menu(): void {
        if ( ! self::is_viewer() ) {
            return;
        }

        remove_menu_page( 'index.php' );
        remove_menu_page( 'profile.php' );
        remove_menu_page( 'separator1' );
    }

    /**
     * Runs after every admin_bar_menu callback (core adds my-account at 9991).
     */
    public static function prune_current_admin_bar(): void {
        global $wp_admin_bar;

        if ( $wp_admin_bar instanceof WP_Admin_Bar ) {
            self::prune_admin_bar( $wp_admin_bar );
        }
    }

    public static function prune_admin_bar( WP_Admin_Bar $admin_bar ): void {
        if ( ! self::is_viewer() ) {
            return;
        }

        foreach ( self::HIDDEN_ADMIN_BAR_NODES as $node_id ) {
            $admin_bar->remove_node( $node_id );
        }

        // The account menu links to the profile screen; keep it a focusable link.
        if ( $admin_bar->get_node( 'my-account' ) !== null ) {
            $admin_bar->add_node(
                [
                    'id'   => 'my-account',
                    'href' => self::intake_list_url(),
                ]
            );
        }

        // Core's user-info title carries an "Edit Profile" label; rebuild it without one.
        if ( $admin_bar->get_node( 'user-info' ) !== null ) {
            $admin_bar->add_node(
                [
                    'id'    => 'user-info',
                    'title' => self::user_info_title( wp_get_current_user() ),
                    'href'  => '',
                ]
            );
        }

        // On the front end the site name links to wp-admin; point it at the list.
        if ( ! is_admin() && $admin_bar->get_node( 'site-name' ) !== null ) {
            $admin_bar->add_node(
                [
                    'id'   => 'site-name',
                    'href' => self::intake_list_url(),
                ]
            );
        }
    }

    private static function user_info_title( WP_User $user ): string {
        $title  = (string) get_avatar( $user->ID, 64 );
        $title .= '<span class="display-name">' . esc_html( $user->display_name ) . '</span>';

        if ( $user->display_name !== $user->user_login ) {
            $title .= '<span class="username">' . esc_html( $user->user_login ) . '</span>';
        }

        return $title;
    }

    private static function is_allowed_admin_request( string $url ): bool {
        if ( $url === '' ) {
            return false;
        }

        $list_url = self::intake_list_url();

        return $url === $list_url || str_starts_with( $url, $list_url . '&' );
    }
}
