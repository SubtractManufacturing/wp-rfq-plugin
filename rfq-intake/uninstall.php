<?php
/**
 * Runs when the plugin is deleted from wp-admin.
 *
 * Only removes the RFQ Intake Viewer role. Intake data, settings and secrets
 * are kept on purpose (see PRD data retention).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-rfq-roles.php';

if ( is_multisite() ) {
    // Roles are stored per site; network deletion runs this file once.
    foreach ( get_sites(
        [
            'fields' => 'ids',
            'number' => 0,
        ]
    ) as $rfq_site_id ) {
        switch_to_blog( (int) $rfq_site_id );
        RFQ_Roles::uninstall();
        restore_current_blog();
    }
} else {
    RFQ_Roles::uninstall();
}
