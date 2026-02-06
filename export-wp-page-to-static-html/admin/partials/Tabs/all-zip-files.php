<div class="tab-pane" id="tabs-3" role="tabpanel">
    <div class="files_action_select_section">
        <div class="all_zip_files">
            <?php ob_start(); ?>
            <div class="files_action_select_section" style="padding-bottom: 10px;border-bottom: 1px solid #dddddd;margin-bottom: 4px;">
                <input type="checkbox" value="check_all_files" id="check_all_files" style="vertical-align: middle">
                <select name="files_action" id="files_action">
                    <option value=""><?php esc_html_e( 'Select an action', 'export-wp-page-to-static-html' ); ?></option>
                    <option value="remove"><?php esc_html_e( 'Remove', 'export-wp-page-to-static-html' ); ?></option>
                    <option value="hide"><?php esc_html_e( 'Hide', 'export-wp-page-to-static-html' ); ?></option>
                    <option value="visible"><?php esc_html_e( 'Visible', 'export-wp-page-to-static-html' ); ?></option>
                </select>
                <button class="btn submit_files_action btn--blue" style="padding: 15px 12px;line-height: 0;vertical-align: middle;border-radius: 4px;font-size: 14px;">
                    <?php esc_html_e( 'Submit', 'export-wp-page-to-static-html' ); ?>
                </button>

                <a href="#" class="show_hidden_files" style="float: right;position: relative;top: 6px;"><?php esc_html_e( 'Show hidden files', 'export-wp-page-to-static-html' ); ?></a>
            </div>
            <?php
            $c = 0;

            // Ensure trailing slash for safety when concatenating.
            $upload_base = trailingslashit( $upload_url );

            // Nonce for delete/hide actions (use in your JS/AJAX).
            $zip_row_nonce = wp_create_nonce( 'rc_export_zip_row' );

            if ( ! empty( $d ) ) {
                while ( $file = $d->read() ) {
                    if ( false !== strpos( $file, '.zip' ) ) {
                        $c++;

                        // Build safe class list for the wrapper.
                        $hidden_raw   = (string) rcwpth_hidden_class( $file );
                        $hidden_parts = preg_split( '/\s+/', trim( $hidden_raw ) ) ?: array();
                        $hidden_parts = array_map( 'sanitize_html_class', $hidden_parts );
                        $hidden_attr  = implode( ' ', $hidden_parts );

                        printf(
                            '<div class="exported_zip_file %1$s"><input type="checkbox" value="%2$s">%3$d. <a class="file_name" href="%4$s">%5$s</a><span class="delete_zip_file" data-file="%2$s" data-nonce="%6$s" aria-label="%7$s" role="button" tabindex="0"></span></div>',
                            esc_attr( $hidden_attr ),                           // %1$s class
                            esc_attr( $file ),                                  // %2$s checkbox value & data-file
                            (int) $c,                                           // %3$d index
                            esc_url( $upload_base . $file ),                    // %4$s link href
                            esc_html( $file ),                                  // %5$s link text
                            esc_attr( $zip_row_nonce ),                         // %6$s nonce
                            esc_attr__( 'Delete this zip file', 'export-wp-page-to-static-html' ) // %7$s aria-label
                        );
                    }

                }
            }

            $filesHtml = ob_get_clean();

            if ( 0 === (int) $c ) {
                echo '<div class="files-not-found">' . esc_html__( 'Files not found!', 'export-wp-page-to-static-html' ) . '</div>';
            } else {
                // Safe: row HTML is composed with escaping above.
                echo $filesHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
            ?>
        </div>
    </div>
</div>
