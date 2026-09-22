<?php

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_Update_Checker {

    public const REPOSITORY_URL = 'https://github.com/SubtractManufacturing/wp-rfq-plugin';

    public const RELEASE_ASSET_PATTERN = '~^rfq-intake-\d+\.\d+\.\d+\.zip$~i';

    public const STRATEGY_FILTER = 'rfq_intake_update_detection_strategies';

    /**
     * Plugin Update Checker constant Api::REQUIRE_RELEASE_ASSETS.
     *
     * Keeping the value local avoids coupling this class to PUC's minor-version
     * namespace while still preventing source-archive fallback.
     */
    private const REQUIRE_RELEASE_ASSETS = 2;

    /**
     * Plugin Update Checker constant Api::RELEASE_FILTER_SKIP_PRERELEASE.
     */
    private const SKIP_PRERELEASES = 1;

    public static function init(): void {
        add_filter( 'auto_update_plugin', [ self::class, 'disable_automatic_updates' ], PHP_INT_MAX, 2 );
        add_filter( 'plugin_auto_update_setting_html', [ self::class, 'render_manual_update_setting' ], 10, 3 );

        if ( ! class_exists( PucFactory::class ) ) {
            return;
        }

        $checker = PucFactory::buildUpdateChecker(
            self::REPOSITORY_URL,
            RFQ_INTAKE_PLUGIN_FILE,
            'rfq-intake',
            12
        );

        self::configure( $checker );
    }

    /**
     * @param mixed $checker Plugin Update Checker VCS checker.
     */
    public static function configure( $checker ): void {
        if ( ! is_object( $checker ) || ! method_exists( $checker, 'getVcsApi' ) ) {
            return;
        }

        $api = $checker->getVcsApi();

        if ( ! is_object( $api ) ) {
            return;
        }

        if ( method_exists( $api, 'enableReleaseAssets' ) ) {
            $api->enableReleaseAssets( self::RELEASE_ASSET_PATTERN, self::REQUIRE_RELEASE_ASSETS );
        }

        if ( method_exists( $api, 'setReleaseFilter' ) ) {
            $api->setReleaseFilter( [ self::class, 'has_exact_release_asset' ], self::SKIP_PRERELEASES, 20 );
        }

        if ( method_exists( $api, 'setStrategyFilterName' ) ) {
            $api->setStrategyFilterName( self::STRATEGY_FILTER );
            add_filter( self::STRATEGY_FILTER, [ self::class, 'only_stable_releases' ] );
        }
    }

    /**
     * @param array<string, callable> $strategies PUC update detection strategies.
     * @return array<string, callable>
     */
    public static function only_stable_releases( array $strategies ): array {
        if ( ! isset( $strategies['latest_release'] ) ) {
            return [];
        }

        return [ 'latest_release' => $strategies['latest_release'] ];
    }

    /**
     * @param mixed $release GitHub release API object.
     */
    public static function has_exact_release_asset( string $version, $release ): bool {
        if ( preg_match( '/^\d+\.\d+\.\d+$/', $version ) !== 1 || ! is_object( $release ) ) {
            return false;
        }

        $expected_name = 'rfq-intake-' . $version . '.zip';
        $assets        = isset( $release->assets ) && is_array( $release->assets ) ? $release->assets : [];

        foreach ( $assets as $asset ) {
            if ( is_object( $asset ) && isset( $asset->name ) && $asset->name === $expected_name ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool|null $update Whether WordPress should automatically update.
     * @param mixed     $item   Plugin update data.
     * @return bool|null
     */
    public static function disable_automatic_updates( ?bool $update, $item ): ?bool {
        if ( is_object( $item ) && isset( $item->plugin ) && self::is_plugin_file( (string) $item->plugin ) ) {
            return false;
        }

        return $update;
    }

    /**
     * @param array<string, mixed> $plugin_data Plugin header data.
     */
    public static function render_manual_update_setting(
        string $html,
        string $plugin_file,
        array $plugin_data
    ): string {
        unset( $plugin_data );

        if ( ! self::is_plugin_file( $plugin_file ) ) {
            return $html;
        }

        return sprintf(
            '<span class="label">%s</span>',
            esc_html__( 'Manual updates only', 'rfq-intake' )
        );
    }

    private static function is_plugin_file( string $plugin_file ): bool {
        return $plugin_file === plugin_basename( RFQ_INTAKE_PLUGIN_FILE );
    }
}
