<?php

if (!defined('ABSPATH')) {
    exit;
}

?>
<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    <?php settings_errors(RFQ_Admin_Settings::SETTINGS_GROUP); ?>
    <form action="options.php" method="post">
        <?php
        settings_fields(RFQ_Admin_Settings::SETTINGS_GROUP);
        do_settings_sections(RFQ_Admin_Settings::MENU_SLUG);
        submit_button();
        ?>
    </form>
</div>
