<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<form action="options.php" method="post" class="rfq-defaults-settings-form">
    <?php
    settings_fields( RFQ_Admin_Settings::SETTINGS_GROUP );
    do_settings_sections( RFQ_Admin_Settings::DEFAULTS_PAGE_SLUG );
    submit_button();
    ?>
</form>
