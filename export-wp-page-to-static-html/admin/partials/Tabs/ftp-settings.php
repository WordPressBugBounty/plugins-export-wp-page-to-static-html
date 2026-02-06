<div class="tab-pane" id="tabs-4" role="tabpanel" style="position: relative">
    <div class="pro-mask">
        <div class="pro-content">
            <a href="https://myrecorp.com/export-wp-pages-to-static-html-css-pro?ref=ftp-settings" class="go-pro-btn" target="_blank" rel="noopener">🚀 Go to Pro</a>
            <p class="pro-note"><?php esc_html_e( 'Unlock advanced features with Pro', 'export-wp-page-to-static-html' ); ?></p>
        </div>
    </div>

    <div class="locked-content">
        <div class="ftp_Settings_section3">
            <div class="ftp_settings_item">
                <label for="ftp_host3"><?php esc_html_e( 'FTP host', 'export-wp-page-to-static-html' ); ?></label>
                <input type="text" id="ftp_host3" name="ftp_host" placeholder="Host" value="<?php echo isset( $host ) ? esc_attr( $host ) : ''; ?>">
            </div>

            <div class="ftp_settings_item">
                <label for="ftp_user3"><?php esc_html_e( 'FTP user', 'export-wp-page-to-static-html' ); ?></label>
                <input type="text" id="ftp_user3" name="ftp_user" placeholder="User" value="<?php echo isset( $user ) ? esc_attr( $user ) : ''; ?>">
            </div>

            <div class="ftp_settings_item">
                <label for="ftp_pass3"><?php esc_html_e( 'FTP password', 'export-wp-page-to-static-html' ); ?></label>
                <input type="password" id="ftp_pass3" name="ftp_pass" placeholder="Password" autocomplete="new-password">
                <?php // Security: avoid echoing stored passwords back into the DOM. ?>
            </div>

            <div class="ftp_settings_item">
                <label for="ftp_path3"><?php esc_html_e( 'FTP upload path (default)', 'export-wp-page-to-static-html' ); ?></label>
                <input type="text" id="ftp_path3" name="ftp_path" placeholder="Upload path" value="<?php echo isset( $path ) ? esc_attr( $path ) : ''; ?>">
            </div>

            <?php
            $is_connected   = ( isset( $ftp_status ) && 'connected' === $ftp_status );
            $error_display  = $is_connected ? 'display: none;' : '';
            ?>

            <div class="ftp_status_section">
                <span class="ftp_status_text"><?php esc_html_e( 'FTP connection status:', 'export-wp-page-to-static-html' ); ?> </span>
                <span class="ftp_status">
                    <span class="ftp_connected" style="<?php echo esc_attr( $is_connected ? '' : 'display: none;' ); ?>">
                        <?php esc_html_e( 'Connected', 'export-wp-page-to-static-html' ); ?>
                    </span>
                    <span class="ftp_not_connected" style="<?php echo esc_attr( $is_connected ? 'display: none;' : '' ); ?>">
                        <?php esc_html_e( 'Not Connected', 'export-wp-page-to-static-html' ); ?>
                    </span>
                </span>
            </div>

            <div class="ftp_authentication_failed" style="<?php echo esc_attr( $error_display ); ?>">
                <?php
                // Allow only simple inline <span> with style for the bold "Error:" label.
                echo wp_kses_post(
                    __( '<span style="font-weight: bold;">Error: </span>Host name or username or password is wrong. Please check and try again!', 'export-wp-page-to-static-html' )
                );
                ?>
            </div>

            <button id="test_ftp_connection" class="btn btn--radius-2 btn--green" style="margin-top: 15px;">
                <?php esc_html_e( 'Test Connection', 'export-wp-page-to-static-html' ); ?>
            </button>
        </div>
    </div>
</div>
