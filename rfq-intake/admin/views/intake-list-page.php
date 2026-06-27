<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

    <form method="get" class="rfq-intake-list-controls">
        <input type="hidden" name="page" value="<?php echo esc_attr( RFQ_Admin_Intake_List::MENU_SLUG ); ?>" />
        <label for="rfq-intake-per-page">
            <?php esc_html_e( 'Rows per page', 'rfq-intake' ); ?>
        </label>
        <select id="rfq-intake-per-page" name="per_page" onchange="this.form.submit()">
            <?php foreach ( RFQ_Admin_Intake_List::PER_PAGE_OPTIONS as $option ) : ?>
                <option value="<?php echo esc_attr( (string) $option ); ?>" <?php selected( $per_page, $option ); ?>>
                    <?php echo esc_html( (string) $option ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Name', 'rfq-intake' ); ?></th>
                <th><?php esc_html_e( 'Company', 'rfq-intake' ); ?></th>
                <th><?php esc_html_e( 'Email', 'rfq-intake' ); ?></th>
                <th><?php esc_html_e( 'Phone', 'rfq-intake' ); ?></th>
                <th><?php esc_html_e( 'Postal code', 'rfq-intake' ); ?></th>
                <th><?php esc_html_e( 'Created', 'rfq-intake' ); ?></th>
                <th><?php esc_html_e( 'Status', 'rfq-intake' ); ?></th>
                <th><?php esc_html_e( 'Parts submitted', 'rfq-intake' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( $sessions === [] ) : ?>
                <tr>
                    <td colspan="8"></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $sessions as $session ) : ?>
                    <tr>
                        <td><?php echo esc_html( RFQ_Admin_Intake_List::format_display_name( $session->contact_first_name, $session->contact_last_name ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $session->contact_company ?? '' ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $session->contact_email ?? '' ) ); ?></td>
                        <td><?php echo esc_html( RFQ_Admin_Intake_List::format_phone( $session->contact_phone, $session->contact_phone_country_code ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $session->shipping_postal_code ?? '' ) ); ?></td>
                        <td><?php echo esc_html( RFQ_Admin_Intake_List::format_created_at( $session->created_at ) ); ?></td>
                        <td><?php echo esc_html( (string) ( $session->status ?? '' ) ); ?></td>
                        <td><?php echo esc_html( RFQ_Admin_Intake_List::format_part_count( (string) ( $session->status ?? '' ), $session->submitted_part_count ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: total session count */
                            _n( '%s item', '%s items', $total, 'rfq-intake' ),
                            number_format_i18n( $total )
                        )
                    );
                    ?>
                </span>
                <span class="pagination-links">
                    <?php
                    $base_url = add_query_arg(
                        [
                            'page'     => RFQ_Admin_Intake_List::MENU_SLUG,
                            'per_page' => $per_page,
                        ],
                        admin_url( 'admin.php' )
                    );

                    if ( $page > 1 ) {
                        printf(
                            '<a class="prev-page button" href="%s"><span aria-hidden="true">&laquo;</span></a>',
                            esc_url( add_query_arg( 'paged', $page - 1, $base_url ) )
                        );
                    }

                    echo '<span class="paging-input">';
                    echo esc_html( (string) $page ) . ' / ' . esc_html( (string) $total_pages );
                    echo '</span>';

                    if ( $page < $total_pages ) {
                        printf(
                            '<a class="next-page button" href="%s"><span aria-hidden="true">&raquo;</span></a>',
                            esc_url( add_query_arg( 'paged', $page + 1, $base_url ) )
                        );
                    }
                    ?>
                </span>
            </div>
        </div>
    <?php endif; ?>
</div>
