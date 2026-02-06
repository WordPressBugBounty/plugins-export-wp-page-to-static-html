<div class="tab-pane" id="tabs-5" role="tabpanel">
    <div class="p-t-20">
        <label class="checkbox-container full_site m-r-45" for="createIndexOnSinglePage">
            <?php esc_html_e( 'Create', 'export-wp-page-to-static-html' ); ?>
            <b>index.html</b>
            <?php esc_html_e( 'on single page exporting', 'export-wp-page-to-static-html' ); ?>
            <input
                type="checkbox"
                id="createIndexOnSinglePage"
                name="createIndexOnSinglePage"
                <?php checked( 'on' === $createIndexOnSinglePage ); ?>
            >
            <span class="checkmark"></span>
        </label>
    </div>

    <div class="p-t-20">
        <label class="checkbox-container m-r-45" for="saveAllAssetsToSpecificDir">
            <?php esc_html_e( 'Save all assets files to the specific directory (css, js, images, fonts, audios etc).', 'export-wp-page-to-static-html' ); ?>
            <input
                type="checkbox"
                id="saveAllAssetsToSpecificDir"
                name="saveAllAssetsToSpecificDir"
                <?php checked( 'on' === $saveAllAssetsToSpecificDir ); ?>
            >
            <span class="checkmark"></span>
        </label>

        <?php
        $assets_display = ( 'on' === $saveAllAssetsToSpecificDir ) ? 'block' : 'none';
        ?>
        <div class="saveAllAssetsToSpecificDir_assets_subsection export_html_sub_settings mt-4" style="display: <?php echo esc_attr( $assets_display ); ?>">
            <label class="radio-container m-r-45" for="keepSameName">
                <?php esc_html_e( 'Keep the same name (file will save into year and date directory. Example: 2022/06/filename.png)', 'export-wp-page-to-static-html' ); ?>
                <input
                    type="radio"
                    id="keepSameName"
                    name="keepSameName"
                    value="1"
                    <?php checked( 'on' === $keepSameName ); ?>
                >
                <span class="checkmark"></span>
            </label>

            <label class="radio-container m-r-45 mt-3" for="saveAllInOneDir">
                <?php
                // Contains parentheses and <strong> — allow safe HTML.
                echo wp_kses_post( __( 'Save all files into the related directory (it will add year and month before the file. Example: 2022-06-filename.png)(<strong>Recommended</strong>)', 'export-wp-page-to-static-html' ) );
                ?>
                <input
                    type="radio"
                    id="saveAllInOneDir"
                    name="keepSameName"
                    value="0"
                    <?php checked( 'off' === $keepSameName ); ?>
                >
                <span class="checkmark"></span>
            </label>
        </div>
    </div>

    <div class="p-t-20">
        <label class="label m-r-45" for="excludeUrls">
            <b><?php esc_html_e( 'Exclude Urls', 'export-wp-page-to-static-html' ); ?></b><br>
            <textarea id="excludeUrls" name="excludeUrls" style="height: 80px; width: 100%"><?php echo esc_textarea( $excludeUrls ); ?></textarea>
        </label>
    </div>

    <div class="p-t-20">
        <label class="label m-r-45" for="addContentsToTheHeader">
            <b><?php esc_html_e( 'Add contents to the header', 'export-wp-page-to-static-html' ); ?></b><br>
            <textarea id="addContentsToTheHeader" name="addContentsToTheHeader" style="height: 80px; width: 100%"><?php echo esc_textarea( $addContentsToTheHeader ); ?></textarea>
        </label>
    </div>

    <div class="p-t-20">
        <label class="label m-r-45" for="addContentsToTheFooter">
            <b><?php esc_html_e( 'Add contents to the footer', 'export-wp-page-to-static-html' ); ?></b><br>
            <textarea id="addContentsToTheFooter" name="addContentsToTheFooter" style="height: 80px; width: 100%"><?php echo esc_textarea( $addContentsToTheFooter ); ?></textarea>
        </label>
    </div>

    <div class="p-t-20">
        <label class="label m-r-45" for="searchFor">
            <b><?php esc_html_e( 'Search for', 'export-wp-page-to-static-html' ); ?></b><br>
            <textarea id="searchFor" name="searchFor" style="height: 80px; width: 100%"><?php echo esc_textarea( $searchFor ); ?></textarea>
            <small class="dim-text">
                <?php esc_html_e( 'Description: Enter the text to search for, separated by commas (e.g., term1, term2, term3).', 'export-wp-page-to-static-html' ); ?>
            </small>
        </label>
    </div>

    <div class="p-t-20">
        <label class="label m-r-45" for="replaceWith">
            <b><?php esc_html_e( 'Replace with', 'export-wp-page-to-static-html' ); ?></b><br>
            <textarea id="replaceWith" name="replaceWith" style="height: 80px; width: 100%"><?php echo esc_textarea( $replaceWith ); ?></textarea>
            <small class="dim-text">
                <?php esc_html_e( 'Description: Enter the replacement text, separated by commas (e.g., replacement1, replacement2, replacement3).', 'export-wp-page-to-static-html' ); ?>
            </small>
        </label>
    </div>

    <div class="p-t-20">
        <?php if ( current_user_can( 'administrator' ) ) : ?>
            <div class="settings-item">
                <label class="label"><b><?php esc_html_e( 'User roles can access', 'export-wp-page-to-static-html' ); ?></b></label>
                <?php
                $selected_user_roles = (array) get_option( '_user_roles_can_generate_pdf', array() );
                $selected_user_roles = array_map( 'sanitize_key', $selected_user_roles );

                $wp_roles_obj = wp_roles();
                $roles = is_object( $wp_roles_obj ) ? $wp_roles_obj->roles : array();

                if ( is_array( $roles ) && ! empty( $roles ) ) {
                    foreach ( $roles as $role => $role_data ) {
                        $role_name = isset( $role_data['name'] ) ? $role_data['name'] : $role;
                        if ( 'administrator' === $role ) {
                            ?>
                            <label for="roles-for-pdf-administrator" class="checkbox-label roles-for-pdf-user-roles" style="margin-right: 12px;">
                                <input id="roles-for-pdf-administrator" type="checkbox" name="administrator_for_pdf" checked disabled>
                                <?php esc_html_e( 'Administrator', 'export-wp-page-to-static-html' ); ?>
                            </label>
                            <?php
                        } else {
                            ?>
                            <label for="roles-for-pdf-<?php echo esc_attr( $role ); ?>" class="checkbox-label roles-for-pdf-user-roles" style="margin-right: 12px;">
                                <input
                                    id="roles-for-pdf-<?php echo esc_attr( $role ); ?>"
                                    type="checkbox"
                                    name="user_roles_for_pdf[<?php echo esc_attr( $role ); ?>]"
                                    value="<?php echo esc_attr( $role ); ?>"
                                    <?php checked( in_array( $role, $selected_user_roles, true ) ); ?>
                                >
                                <?php echo esc_html( $role_name ); ?>
                            </label>
                            <?php
                        }
                    }
                } else {
                    echo '<div style="color:red;">' . esc_html__( 'Error: User roles could not be loaded properly.', 'export-wp-page-to-static-html' ) . '</div>';
                }
                ?>
                <div style="margin-top: 5px; font-size: 13px;">
                    <i><?php esc_html_e( 'Select user roles to access the "Export WP Pages to Static HTML/CSS" option.', 'export-wp-page-to-static-html' ); ?></i>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <button class="btn btn--radius-2 btn--blue m-t-20 btn_save_settings" type="submit">
        <?php esc_html_e( 'Save Settings', 'export-wp-page-to-static-html' ); ?>
        <span class="spinner_x hide_spin"></span>
    </button>

    <span class="badge badge-success badge_save_settings" style="display: none; padding: 5px">
        <?php esc_html_e( 'Successfully Saved!', 'export-wp-page-to-static-html' ); ?>
    </span>
</div>
