<div class="tab-pane active" id="tabs-1" role="tabpanel">
  <form method="POST" class="pt-3">
    <div class="input-group select-a-page-input-group">
      <label class="label" for="export_pages" style="font-weight:600; display:block; margin-bottom:6px; font-size:14px;">
        <?php esc_html_e( 'Select Pages to Export', 'export-wp-page-to-static-html' ); ?>
      </label>

      <div class="rs-select2 js-select-simple select--no-search">
        <select id="export_pages" name="export_pages" multiple>
          <option
            value="home_page"
            permalink="<?php echo esc_url( home_url( '/' ) ); ?>"
            filename="homepage"
          >
            <?php
              // translators: %s: home URL.
              printf(
                  esc_html__( 'Homepage (%s)', 'export-wp-page-to-static-html' ),
                  esc_html( home_url() )
              );

            ?>
          </option>

          <?php
          if ( ! empty( $query->posts ) ) {
            foreach ( $query->posts as $post ) {
              $post_id    = isset( $post->ID ) ? (int) $post->ID : 0;
              $post_title = isset( $post->post_title ) ? (string) $post->post_title : '';
              $status     = isset( $post->post_status ) ? (string) $post->post_status : '';

              if ( ! $post_id || '' === $post_title ) {
                continue;
              }

              $permalink = get_the_permalink( $post_id );
              $parts     = is_string( $permalink ) ? parse_url( $permalink ) : array();

              // Don’t shadow $query (WP_Query). Use a local var for URL query params.
              $qs = array();
              if ( isset( $parts['query'] ) ) {
                parse_str( $parts['query'], $qs );
              }

              if ( ! empty( $qs ) ) {
                // Fallback slug if URL contains query string.
                $permalink = strtolower( str_replace( ' ', '-', $post_title ) );
              }

              $is_private = ( 'private' === $status );

              $label_text = $post_title;
              if ( $is_private ) {
                /* translators: appended to post title when status is private */
                $label_text .= esc_html__( ' (private)', 'export-wp-page-to-static-html' );
              }
              ?>
              <option
                value="<?php echo esc_attr( $post_id ); ?>"
                permalink="<?php echo esc_attr( basename( (string) $permalink ) ); ?>"
              >
                <?php echo esc_html( $label_text ); ?>
              </option>
              <?php
            }
          }
          ?>
        </select>
        <div class="select-dropdown"></div>
      </div>

      <p style="font-size:13px; color:#555; margin:4px 0 10px;">
        <?php esc_html_e( 'Choose one or more pages from your site to export as static HTML/CSS.', 'export-wp-page-to-static-html' ); ?>
      </p>

      <div class="checkbox-fullsite">
        <div class="checkbox-lock">
          <label class="checkbox-container full_site m-r-45">
            <?php esc_html_e( 'Full Site', 'export-wp-page-to-static-html' ); ?>
            <input type="checkbox" id="full_site" name="full_site" disabled>
            <span class="badge-pro"><?php esc_html_e( 'Pro', 'export-wp-page-to-static-html' ); ?></span>
            <span class="checkmark"></span>
          </label>
          <p style="font-size:13px; color:#555; margin:4px 0 10px;">
            <?php esc_html_e( 'Export everything by crawling internal links. (Available in Pro)', 'export-wp-page-to-static-html' ); ?>
          </p>

          <div class="go-pro-popup">
            <a href="https://myrecorp.com/export-wp-pages-to-static-html-css-pro?ref=full_site_btn" class="go-pro-btn" target="_blank" rel="noopener">🚀
              <?php esc_html_e( 'Go to Pro', 'export-wp-page-to-static-html' ); ?>
            </a>
            <p class="pro-note">
              <?php esc_html_e( 'Unlock advanced features with Pro', 'export-wp-page-to-static-html' ); ?>
            </p>
          </div>
        </div>
      </div>

      <div class="seach_posts">
        <div class="checkbox-lock">
          <div>
            <label class="checkbox-container m-r-45 disabled">
              <?php esc_html_e( 'Select posts', 'export-wp-page-to-static-html' ); ?>
              <input type="checkbox" id="search_posts_to_select2" name="search_posts_to_select2" disabled>
              <span class="badge-pro"><?php esc_html_e( 'Pro', 'export-wp-page-to-static-html' ); ?></span>
              <span class="checkmark"></span>
            </label>
            <p style="font-size:13px; color:#555; margin:4px 0 10px;">
              <?php esc_html_e( 'Include blog posts in the export. (Available in Pro)', 'export-wp-page-to-static-html' ); ?>
            </p>

            <div class="select_posts_section export_html_sub_settings">
              <div class="p-t-10">
                <label class="radio-container m-r-45">
                  <?php esc_html_e( 'Published posts only', 'export-wp-page-to-static-html' ); ?>
                  <input type="radio" id="published_posts_only" name="post_status" value="publish">
                  <span class="checkmark"></span>
                </label>

                <label class="radio-container m-r-45">
                  <?php esc_html_e( 'Draft posts only', 'export-wp-page-to-static-html' ); ?>
                  <input type="radio" id="draft_posts_only" name="post_status" value="draft">
                  <span class="checkmark"></span>
                </label>

                <label class="radio-container m-r-45">
                  <?php esc_html_e( 'Private posts only', 'export-wp-page-to-static-html' ); ?>
                  <input type="radio" id="private_posts_only" name="post_status" value="private">
                  <span class="checkmark"></span>
                </label>

                <label class="radio-container m-r-45">
                  <?php esc_html_e( 'Pending posts only', 'export-wp-page-to-static-html' ); ?>
                  <input type="radio" id="pending_posts_only" name="post_status" value="pending">
                  <span class="checkmark"></span>
                </label>

                <label class="radio-container m-r-45">
                  <?php esc_html_e( 'Scheduled posts only', 'export-wp-page-to-static-html' ); ?>
                  <input type="radio" id="future_posts_only" name="post_status" value="future">
                  <span class="checkmark"></span>
                </label>
              </div>
            </div>
          </div>

          <div class="go-pro-popup">
            <a href="https://myrecorp.com/export-wp-pages-to-static-html-css-pro?ref=select_posts_btn" class="go-pro-btn" target="_blank" rel="noopener">🚀
              <?php esc_html_e( 'Go to Pro', 'export-wp-page-to-static-html' ); ?>
            </a>
            <p class="pro-note">
              <?php esc_html_e( 'Unlock advanced features with Pro', 'export-wp-page-to-static-html' ); ?>
            </p>
          </div>
        </div>
      </div>
    </div>

    <details class="adv-settings" id="advanced_settings" aria-label="<?php echo esc_attr__( 'Advanced settings', 'export-wp-page-to-static-html' ); ?>">
      <summary class="adv-summary">
        <span class="caret" aria-hidden="true"></span>
        <?php esc_html_e( 'Advanced settings', 'export-wp-page-to-static-html' ); ?>
        <span class="meta"><?php esc_html_e( 'Optional', 'export-wp-page-to-static-html' ); ?></span>
      </summary>

      <div class="adv-panel">
        <div class="col-8">
          <div class="p-t-10">
            <div class="input-group">
              <label class="label label_login_as" style="font-weight: bold" for="login_as">
                <?php esc_html_e( 'Login as (optional)', 'export-wp-page-to-static-html' ); ?>
                <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Select a role to run the export as that user.', 'export-wp-page-to-static-html' ); ?>"></span>
              </label>
              <select id="login_as" name="login_as">
                <option value="" selected=""><?php esc_html_e( 'Select a user role', 'export-wp-page-to-static-html' ); ?></option>
                <?php
                global $wp_roles;
                $all_roles = isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ? $wp_roles->roles : array();
                if ( ! empty( $all_roles ) ) {
                  foreach ( $all_roles as $key => $role ) {
                    $role_key  = esc_attr( sanitize_key( $key ) );
                    $role_name = isset( $role['name'] ) ? esc_html( $role['name'] ) : esc_html( $role_key );
                    echo '<option value="' . esc_attr( $role_key ) . '">' . esc_html( $role_name ) . '</option>';

                  }
                }
                ?>
              </select>
            </div>

            <div class="p-t-10">
              <label class="checkbox-container m-r-45">
                <?php esc_html_e( 'Replace all url to #', 'export-wp-page-to-static-html' ); ?>
                <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Rewrites all links to # placeholders for safe testing.', 'export-wp-page-to-static-html' ); ?>"></span>
                <input type="checkbox" id="replace_all_url" name="replace_all_url">
                <span class="checkmark"></span>
              </label>
            </div>

            <div class="p-t-10">
              <label class="checkbox-container m-r-45" for="skip_assets">
                <?php esc_html_e( 'Skip Assets (Css, Js, Images or Videos)', 'export-wp-page-to-static-html' ); ?>
                <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Exclude all CSS, JS, image, and video files from the export.', 'export-wp-page-to-static-html' ); ?>"></span>
                <input type="checkbox" id="skip_assets" name="skip_assets">
                <span class="checkmark"></span>
              </label>

              <div class="skip_assets_subsection export_html_sub_settings">
                <label class="checkbox-container m-r-45" for="skip_stylesheets">
                  <?php esc_html_e( 'Skip Stylesheets (.css)', 'export-wp-page-to-static-html' ); ?>
                  <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Do not download or rewrite .css files.', 'export-wp-page-to-static-html' ); ?>"></span>
                  <input type="checkbox" id="skip_stylesheets" name="skip_stylesheets" checked>
                  <span class="checkmark"></span>
                </label>

                <label class="checkbox-container m-r-45" for="skip_scripts">
                  <?php esc_html_e( 'Skip Scripts (.js)', 'export-wp-page-to-static-html' ); ?>
                  <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Do not download or rewrite .js files.', 'export-wp-page-to-static-html' ); ?>"></span>
                  <input type="checkbox" id="skip_scripts" name="skip_scripts" checked>
                  <span class="checkmark"></span>
                </label>

                <label class="checkbox-container m-r-45" for="skip_images">
                  <?php esc_html_e( 'Skip Images', 'export-wp-page-to-static-html' ); ?>
                  <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Exclude image files and <img> tags.', 'export-wp-page-to-static-html' ); ?>"></span>
                  <input type="checkbox" id="skip_images" name="skip_images" checked>
                  <span class="checkmark"></span>
                </label>

                <label class="checkbox-container m-r-45" for="skip_videos">
                  <?php esc_html_e( 'Skip Videos', 'export-wp-page-to-static-html' ); ?>
                  <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Exclude video files and <video> sources.', 'export-wp-page-to-static-html' ); ?>"></span>
                  <input type="checkbox" id="skip_videos" name="skip_videos" checked>
                  <span class="checkmark"></span>
                </label>

                <label class="checkbox-container m-r-45" for="skip_audios">
                  <?php esc_html_e( 'Skip Audios', 'export-wp-page-to-static-html' ); ?>
                  <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Exclude audio files and <audio> sources.', 'export-wp-page-to-static-html' ); ?>"></span>
                  <input type="checkbox" id="skip_audios" name="skip_audios" checked>
                  <span class="checkmark"></span>
                </label>

                <label class="checkbox-container m-r-45" for="skip_docs">
                  <?php esc_html_e( 'Skip Documents', 'export-wp-page-to-static-html' ); ?>
                  <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Exclude document files such as .pdf, .docx, etc.', 'export-wp-page-to-static-html' ); ?>"></span>
                  <input type="checkbox" id="skip_docs" name="skip_docs" checked>
                  <span class="checkmark"></span>
                </label>
              </div>
            </div>

            <div class="p-t-10">
              <label class="checkbox-container m-r-45" for="image_to_webp">
                <?php esc_html_e( 'Compress images size (image to webp)', 'export-wp-page-to-static-html' ); ?>
                <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Convert images to WebP using the selected quality.', 'export-wp-page-to-static-html' ); ?>"></span>
                <input type="checkbox" id="image_to_webp" name="image_to_webp">
                <span class="checkmark"></span>
              </label>

              <div class="image_to_webp_subsection export_html_sub_settings">
                <div class="brightness-box">
                  <input type="range" id="image_quality" min="10" max="100" value="80">
                </div>
                <input type="text" id="image_quality_input" value="80" style="width:45px;" onkeyup="if (/\D/g.test(this.value)) this.value = this.value.replace(/\D/g,'')">
              </div>
            </div>

            <div class="p-t-10">
              <div class="checkbox-lock" style="margin-top:0;">
                <?php
                $ftp_label_classes = 'checkbox-container ftp_upload_checkbox m-r-45';
                $ftp_disabled = ( ! isset( $ftp_status ) || 'connected' !== $ftp_status );
                if ( $ftp_disabled ) {
                  $ftp_label_classes .= ' disabled';
                }
                ?>
                <label class="<?php echo esc_attr( $ftp_label_classes ); ?>">
                  <?php esc_html_e( 'Upload to ftp', 'export-wp-page-to-static-html' ); ?>
                  <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Upload exported files to your configured FTP server.', 'export-wp-page-to-static-html' ); ?>"></span>
                  <input type="checkbox" id="upload_to_ftp" name="upload_to_ftp" <?php disabled( $ftp_disabled ); ?>>
                  <span class="checkmark"></span>
                </label>

                <div class="ftp_Settings_section export_html_sub_settings">
                  <div class="ftp_settings_item">
                    <label for="ftp_path">
                      <?php esc_html_e( 'FTP upload path', 'export-wp-page-to-static-html' ); ?>
                      <span class="info-icon" data-tooltip="<?php echo esc_attr__( 'Remote directory (path) where files will be uploaded.', 'export-wp-page-to-static-html' ); ?>"></span>
                    </label>
                    <input type="text" id="ftp_path" name="ftp_path" placeholder="<?php echo esc_attr__( 'Upload path', 'export-wp-page-to-static-html' ); ?>" value="<?php echo isset( $path ) ? esc_attr( $path ) : ''; ?>">
                    <div class="ftp_path_browse1"><a href="#"><?php esc_html_e( 'Browse', 'export-wp-page-to-static-html' ); ?></a></div>
                  </div>
                </div>

                <div class="go-pro-popup">
                  <a href="https://myrecorp.com/export-wp-pages-to-static-html-css-pro?ref=ftp_select" class="go-pro-btn" target="_blank" rel="noopener">🚀
                    <?php esc_html_e( 'Go to Pro', 'export-wp-page-to-static-html' ); ?>
                  </a>
                  <p class="pro-note"><?php esc_html_e( 'Unlock advanced features with Pro', 'export-wp-page-to-static-html' ); ?></p>
                </div>
              </div>
            </div>

            <div class="p-t-10">
              <div class="email_settings_section">
                <div class="email_settings_item">
                  <label class="checkbox-container m-r-45">
                    <?php esc_html_e( 'Receive notification when complete', 'export-wp-page-to-static-html' ); ?>
                    <input type="checkbox" id="email_notification" name="email_notification">
                    <span class="checkmark"></span>
                  </label>

                  <div class="email_settings_item export_html_sub_settings">
                    <input type="text" id="receive_notification_email" name="notification_email" placeholder="<?php echo esc_attr__( 'Enter emails (optional)', 'export-wp-page-to-static-html' ); ?>">
                    <span><?php esc_html_e( 'Enter emails separated by comma (,) (optional)', 'export-wp-page-to-static-html' ); ?></span>
                  </div>
                </div>
              </div>
            </div>

          </div> <!-- /.p-t-10 group -->
        </div> <!-- /.col-8 -->
      </div> <!-- /.adv-panel -->
    </details>

    <div class="p-t-15">
      <button class="flat-button primary export_internal_page_to_html" type="submit">
        <?php esc_html_e( 'Export HTML', 'export-wp-page-to-static-html' ); ?>
      </button>

      <span class="spinner_x hide_spin"></span>

      <a class="cancel_rc_html_export_process" href="#">
        <?php esc_html_e( 'Cancel', 'export-wp-page-to-static-html' ); ?>
      </a>

      <a href="" class="action-btn download-btn hide">
        <span class="icon">
          <!-- Download Icon -->
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="7 10 12 15 17 10"></polyline>
            <line x1="12" y1="15" x2="12" y2="3"></line>
          </svg>
        </span>
        <?php esc_html_e( 'Download the Zip File', 'export-wp-page-to-static-html' ); ?>
      </a>

      <a href="" class="view_exported_file hide" target="_blank" rel="noopener">
        <span class="icon">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
            <circle cx="12" cy="12" r="3"></circle>
          </svg>
        </span>
        <?php esc_html_e( 'View Exported File', 'export-wp-page-to-static-html' ); ?>
      </a>

      <div class="error-notice" style="display: none;">
        <p><?php esc_html_e( 'Something went wrong! Please try again. If it fails continuously, contact us.', 'export-wp-page-to-static-html' ); ?></p>
      </div>
    </div>
  </form>
</div>
