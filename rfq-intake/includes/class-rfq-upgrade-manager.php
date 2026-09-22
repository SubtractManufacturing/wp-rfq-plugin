<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_Upgrade_Manager {

    public const VERSION_OPTION = 'rfq_intake_schema_version';

    public const FAILURE_OPTION = 'rfq_intake_upgrade_failure';

    public const LOCK_OPTION = 'rfq_intake_upgrade_lock';

    private const LOCK_TTL_SECONDS = 300;

    public static function init(): void {
        add_action( 'admin_notices', [ self::class, 'render_failure_notice' ] );
        self::maybe_upgrade();
    }

    public static function maybe_upgrade(): bool {
        $current_version = self::get_current_version();

        if ( $current_version >= RFQ_INTAKE_SCHEMA_VERSION ) {
            delete_option( self::FAILURE_OPTION );
            return true;
        }

        if ( ! self::acquire_lock() ) {
            return false;
        }

        $clear_lock = false;

        try {
            $migrations = self::get_migrations();

            for ( $version = $current_version + 1; $version <= RFQ_INTAKE_SCHEMA_VERSION; $version++ ) {
                if ( ! isset( $migrations[ $version ] ) || ! is_callable( $migrations[ $version ] ) ) {
                    throw new RuntimeException( 'Missing RFQ Intake schema migration.' );
                }

                call_user_func( $migrations[ $version ] );
                update_option( self::VERSION_OPTION, $version, false );
            }

            delete_option( self::FAILURE_OPTION );
            $clear_lock = true;
            return true;
        } catch ( Throwable $exception ) {
            update_option(
                self::FAILURE_OPTION,
                [
                    'target_version' => RFQ_INTAKE_SCHEMA_VERSION,
                    'failed_at'      => time(),
                    'error_type'     => get_class( $exception ),
                ],
                false
            );

            error_log(
                sprintf(
                    'RFQ Intake schema upgrade to version %d failed (%s).',
                    RFQ_INTAKE_SCHEMA_VERSION,
                    get_class( $exception )
                )
            );

            return false;
        } finally {
            if ( $clear_lock ) {
                delete_option( self::LOCK_OPTION );
            }
        }
    }

    public static function is_ready(): bool {
        return self::get_current_version() >= RFQ_INTAKE_SCHEMA_VERSION
            && get_option( self::FAILURE_OPTION, false ) === false;
    }

    public static function render_failure_notice(): void {
        if ( get_option( self::FAILURE_OPTION, false ) === false ) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__(
                'RFQ Intake could not finish its database upgrade. The customer form is using its fallback until an administrator resolves the error.',
                'rfq-intake'
            )
        );
    }

    private static function get_current_version(): int {
        return max( 0, (int) get_option( self::VERSION_OPTION, 0 ) );
    }

    /**
     * @return array<int, callable>
     */
    private static function get_migrations(): array {
        $migrations = [
            1 => [ RFQ_Activator::class, 'create_tables' ],
        ];

        /**
         * Additive schema migrations keyed by their integer target version.
         *
         * @param array<int, callable> $migrations Migrations in ascending order.
         */
        return apply_filters( 'rfq_intake_schema_migrations', $migrations );
    }

    private static function acquire_lock(): bool {
        if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
            return true;
        }

        $lock_started_at = (int) get_option( self::LOCK_OPTION, 0 );

        if ( $lock_started_at > 0 && ( time() - $lock_started_at ) < self::LOCK_TTL_SECONDS ) {
            return false;
        }

        delete_option( self::LOCK_OPTION );

        return add_option( self::LOCK_OPTION, time(), '', false );
    }
}
