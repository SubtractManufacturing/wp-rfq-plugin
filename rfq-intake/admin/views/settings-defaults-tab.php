<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$editor_rows       = RFQ_Material_Catalog::get_editor_rows();
$effective_catalog = RFQ_Material_Catalog::get_effective_catalog();
$overrides_encoded = RFQ_Material_Catalog::encode_overrides( RFQ_Material_Catalog::get_overrides() );

?>
<form action="options.php" method="post" class="rfq-defaults-settings-form" id="rfq-catalog-editor-form">
    <?php settings_fields( RFQ_Admin_Settings::SETTINGS_GROUP ); ?>

    <div class="rfq-defaults-section">
        <?php RFQ_Admin_Settings::render_catalog_section(); ?>

        <div id="rfq-catalog-editor-root" class="rfq-catalog-editor" data-initial-rows="<?php echo esc_attr( wp_json_encode( $editor_rows ) ); ?>">
            <table class="widefat striped rfq-catalog-table">
                <thead>
                    <tr>
                        <th scope="col" class="rfq-col-enabled"><?php esc_html_e( 'Enabled', 'rfq-intake' ); ?></th>
                        <th scope="col" class="rfq-col-label"><?php esc_html_e( 'Label', 'rfq-intake' ); ?></th>
                        <th scope="col" class="rfq-col-id"><?php esc_html_e( 'ID', 'rfq-intake' ); ?></th>
                        <th scope="col" class="rfq-col-aliases"><?php esc_html_e( 'Aliases', 'rfq-intake' ); ?></th>
                        <th scope="col" class="rfq-col-dropdown"><?php esc_html_e( 'In dropdown', 'rfq-intake' ); ?></th>
                        <th scope="col" class="rfq-col-source"><?php esc_html_e( 'Source', 'rfq-intake' ); ?></th>
                        <th scope="col" class="rfq-col-actions"><?php esc_html_e( 'Actions', 'rfq-intake' ); ?></th>
                    </tr>
                </thead>
                <tbody id="rfq-catalog-rows">
                    <?php foreach ( $editor_rows as $index => $row ) : ?>
                        <?php
                        $row_class = $row['enabled'] ? '' : ' rfq-catalog-row-disabled';
                        ?>
                        <tr
                            class="rfq-catalog-row<?php echo esc_attr( $row_class ); ?>"
                            data-row-index="<?php echo esc_attr( (string) $index ); ?>"
                            data-source="<?php echo esc_attr( $row['source'] ); ?>"
                        >
                            <td class="rfq-col-enabled">
                                <label class="screen-reader-text" for="rfq-row-<?php echo esc_attr( (string) $index ); ?>-enabled">
                                    <?php esc_html_e( 'Enabled', 'rfq-intake' ); ?>
                                </label>
                                <input
                                    type="checkbox"
                                    class="rfq-row-enabled"
                                    id="rfq-row-<?php echo esc_attr( (string) $index ); ?>-enabled"
                                    <?php checked( $row['enabled'] ); ?>
                                />
                            </td>
                            <td class="rfq-col-label">
                                <label class="screen-reader-text" for="rfq-row-<?php echo esc_attr( (string) $index ); ?>-label">
                                    <?php esc_html_e( 'Label', 'rfq-intake' ); ?>
                                </label>
                                <input
                                    type="text"
                                    class="regular-text rfq-row-label"
                                    id="rfq-row-<?php echo esc_attr( (string) $index ); ?>-label"
                                    value="<?php echo esc_attr( $row['label'] ); ?>"
                                    data-default-label="<?php echo esc_attr( $row['default_label'] ); ?>"
                                />
                            </td>
                            <td class="rfq-col-id">
                                <?php if ( $row['source'] === 'custom' ) : ?>
                                    <label class="screen-reader-text" for="rfq-row-<?php echo esc_attr( (string) $index ); ?>-id">
                                        <?php esc_html_e( 'ID', 'rfq-intake' ); ?>
                                    </label>
                                    <input
                                        type="text"
                                        class="regular-text rfq-row-id"
                                        id="rfq-row-<?php echo esc_attr( (string) $index ); ?>-id"
                                        value="<?php echo esc_attr( $row['id'] ); ?>"
                                    />
                                <?php else : ?>
                                    <code class="rfq-row-id-display"><?php echo esc_html( $row['id'] ); ?></code>
                                    <input type="hidden" class="rfq-row-id" value="<?php echo esc_attr( $row['id'] ); ?>" />
                                <?php endif; ?>
                            </td>
                            <td class="rfq-col-aliases">
                                <?php if ( $row['can_edit_aliases'] ) : ?>
                                    <label class="screen-reader-text" for="rfq-row-<?php echo esc_attr( (string) $index ); ?>-aliases">
                                        <?php esc_html_e( 'Aliases', 'rfq-intake' ); ?>
                                    </label>
                                    <input
                                        type="text"
                                        class="regular-text rfq-row-aliases"
                                        id="rfq-row-<?php echo esc_attr( (string) $index ); ?>-aliases"
                                        value="<?php echo esc_attr( implode( ', ', $row['aliases'] ) ); ?>"
                                        placeholder="<?php esc_attr_e( 'Comma-separated', 'rfq-intake' ); ?>"
                                    />
                                <?php else : ?>
                                    <span class="rfq-row-aliases-display"><?php echo esc_html( implode( ', ', $row['aliases'] ) ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="rfq-col-dropdown">
                                <?php if ( $row['can_edit_dropdown'] ) : ?>
                                    <label class="screen-reader-text" for="rfq-row-<?php echo esc_attr( (string) $index ); ?>-dropdown">
                                        <?php esc_html_e( 'Show in dropdown', 'rfq-intake' ); ?>
                                    </label>
                                    <input
                                        type="checkbox"
                                        class="rfq-row-dropdown"
                                        id="rfq-row-<?php echo esc_attr( (string) $index ); ?>-dropdown"
                                        <?php checked( $row['show_in_dropdown'] ); ?>
                                    />
                                <?php else : ?>
                                    <span class="rfq-row-dropdown-display"><?php echo $row['show_in_dropdown'] ? esc_html__( 'Yes', 'rfq-intake' ) : esc_html__( 'No', 'rfq-intake' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="rfq-col-source">
                                <span class="rfq-source-badge rfq-source-<?php echo esc_attr( $row['source'] ); ?>">
                                    <?php echo $row['source'] === 'custom' ? esc_html__( 'Custom', 'rfq-intake' ) : esc_html__( 'Shipped', 'rfq-intake' ); ?>
                                </span>
                            </td>
                            <td class="rfq-col-actions">
                                <?php if ( $row['can_remove'] ) : ?>
                                    <button type="button" class="button-link-delete rfq-row-remove">
                                        <?php esc_html_e( 'Remove', 'rfq-intake' ); ?>
                                    </button>
                                <?php else : ?>
                                    <span aria-hidden="true">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p>
                <button type="button" class="button" id="rfq-catalog-add-row">
                    <?php esc_html_e( 'Add material', 'rfq-intake' ); ?>
                </button>
            </p>
        </div>

        <div class="rfq-effective-preview">
            <h2><?php esc_html_e( 'Customer preview', 'rfq-intake' ); ?></h2>
            <p class="description">
                <?php esc_html_e( 'Materials enabled below are shown in the RFQ form type-ahead and dropdown.', 'rfq-intake' ); ?>
            </p>
            <ul id="rfq-effective-preview-list" class="rfq-effective-preview-list">
                <?php foreach ( $effective_catalog as $entry ) : ?>
                    <li><?php echo esc_html( $entry['label'] ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="rfq-catalog-json-panel">
            <h2><?php esc_html_e( 'Overrides JSON', 'rfq-intake' ); ?></h2>
            <label class="screen-reader-text" for="rfq_material_overrides">
                <?php esc_html_e( 'Overrides JSON', 'rfq-intake' ); ?>
            </label>
            <textarea
                class="large-text code rfq-catalog-overrides-json"
                rows="10"
                id="rfq_material_overrides"
                name="rfq_material_overrides"
            ><?php echo esc_textarea( $overrides_encoded ); ?></textarea>
            <p class="description">
                <?php esc_html_e( 'Advanced overrides JSON. Editing this field updates the table above automatically, and vice versa.', 'rfq-intake' ); ?>
            </p>
            <p class="rfq-catalog-json-error notice notice-error hidden">
                <strong><?php esc_html_e( 'Invalid JSON', 'rfq-intake' ); ?></strong>
                <span></span>
            </p>
        </div>
    </div>

    <?php submit_button(); ?>
</form>
