<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only result counters for a notice; cast to int / whitelisted.
$rfq_cleanup_notice = RFQ_Admin_Settings::resolve_cleanup_notice(
    isset( $_GET['rfq_cleanup'] ) ? sanitize_key( (string) $_GET['rfq_cleanup'] ) : null,
    isset( $_GET['rfq_checked'] ) ? absint( $_GET['rfq_checked'] ) : 0,
    isset( $_GET['rfq_deleted'] ) ? absint( $_GET['rfq_deleted'] ) : 0,
    isset( $_GET['rfq_kept'] ) ? absint( $_GET['rfq_kept'] ) : 0,
    isset( $_GET['rfq_errors'] ) ? absint( $_GET['rfq_errors'] ) : 0
);
// phpcs:enable WordPress.Security.NonceVerification.Recommended

?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    <?php settings_errors( RFQ_Admin_Settings::SETTINGS_GROUP ); ?>
    <?php if ( $rfq_cleanup_notice !== null ) : ?>
        <div class="notice notice-<?php echo esc_attr( $rfq_cleanup_notice['type'] ); ?> is-dismissible">
            <p><?php echo esc_html( $rfq_cleanup_notice['message'] ); ?></p>
        </div>
    <?php endif; ?>
    <form action="options.php" method="post">
        <?php
        settings_fields( RFQ_Admin_Settings::SETTINGS_GROUP );
        do_settings_sections( RFQ_Admin_Settings::MENU_SLUG );
        submit_button();
        ?>
    </form>

    <?php /* Target of the "Run cleanup now" button in the Data retention section (HTML `form` attribute). */ ?>
    <form id="<?php echo esc_attr( RFQ_Admin_Settings::RUN_CLEANUP_FORM_ID ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
        <input type="hidden" name="action" value="<?php echo esc_attr( RFQ_Admin_Settings::RUN_CLEANUP_ACTION ); ?>" />
        <?php wp_nonce_field( RFQ_Admin_Settings::RUN_CLEANUP_ACTION ); ?>
    </form>
</div>
