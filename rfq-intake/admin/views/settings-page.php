<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$current_tab = RFQ_Admin_Settings::get_current_tab();

?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'rfq-intake' ); ?>">
        <a
            href="<?php echo esc_url( RFQ_Admin_Settings::get_tab_url( RFQ_Admin_Settings::TAB_DEFAULTS ) ); ?>"
            class="nav-tab<?php echo $current_tab === RFQ_Admin_Settings::TAB_DEFAULTS ? ' nav-tab-active' : ''; ?>"
        >
            <?php esc_html_e( 'Form defaults', 'rfq-intake' ); ?>
        </a>
        <a
            href="<?php echo esc_url( RFQ_Admin_Settings::get_tab_url( RFQ_Admin_Settings::TAB_GENERAL ) ); ?>"
            class="nav-tab<?php echo $current_tab === RFQ_Admin_Settings::TAB_GENERAL ? ' nav-tab-active' : ''; ?>"
        >
            <?php esc_html_e( 'Dev', 'rfq-intake' ); ?>
        </a>
    </nav>

    <?php settings_errors( RFQ_Admin_Settings::SETTINGS_GROUP ); ?>

    <?php if ( $current_tab === RFQ_Admin_Settings::TAB_DEFAULTS ) : ?>
        <?php require RFQ_INTAKE_PLUGIN_DIR . 'admin/views/settings-defaults-tab.php'; ?>
    <?php else : ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( RFQ_Admin_Settings::SETTINGS_GROUP );
            do_settings_sections( RFQ_Admin_Settings::MENU_SLUG );
            submit_button();
            ?>
        </form>
    <?php endif; ?>
</div>
