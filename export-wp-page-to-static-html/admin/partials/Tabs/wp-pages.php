<div class="tab-pane active" id="tabs-1" role="tabpanel">

    <form method="POST" class="pt-3">
        <div class="input-group">
            <label class="label" for="export_pages"><?php esc_html_e('Select a page', 'export-wp-page-to-static-html'); ?></label>

            <span class="select_multi_pages">Select one or more pages</span>
            <div class="rs-select2 js-select-simple select--no-search">
                <select id="export_pages" name="export_pages" multiple>
                    <option value="home_page" permalink="<?php echo home_url('/'); ?>" filename="homepage"><?php esc_html_e('Homepage', 'export-wp-page-to-static-html');?> (<?php echo home_url(); ?>)</option>

                    <?php

                    if (!empty($query->posts)) {
                        foreach ($query->posts as $key => $post) {
                            $post_id = $post->ID;
                            $post_title = $post->post_title;
                            $permalink = get_the_permalink($post_id);
                            $parts = parse_url($permalink);

                            if(isset($parts['query'])){
                                parse_str($parts['query'], $query);
                            }
                            else{
                                $query = "";
                            }

                            if (!empty($query)) {
                                $permalink = strtolower(str_replace(" ", "-", $post_title));
                            }
                            $private = '';
                            if ($post->post_status == "private"){
                                $private = __(' (private)', 'export-wp-page-to-static-html');
                            }

                            if(!empty($post_title)){
                                if ($post->post_status == "private"){
                                    ?>
                                    <option disabled value="<?php echo esc_html($post_id); ?>" permalink="<?php echo esc_html(basename($permalink)); ?>"><?php echo esc_html($post_title . $private) . esc_html(' Pro version only', 'export-wp-page-to-static-html'); ?> </option>
                                    <?php
                                }
                                else{
                                    ?>
                                    <option value="<?php echo esc_html($post_id); ?>" permalink="<?php echo esc_html(basename($permalink)); ?>"><?php echo esc_html($post_title . $private); ?></option>
                                    <?php
                                }
                            }
                        }
                    }
                    ?>
                </select>
                <div class="select-dropdown"></div>
                <span style="color: red"><?php esc_html_e("Max 3 pages you can export at once. Upgarade to pro for unlimited.", "export-wp-page-to-static-html") ; ?></span>
            </div>

            <div class="seach_posts">
                <div class="p-t-10">
                    <label class="checkbox-container m-r-45">Search posts only

                        <input type="checkbox" id="search_posts_to_select2" name="search_posts_to_select2">
                        <span class="checkmark"></span>
                    </label>
                </div>
            </div>
            <div class="select_pages_toesc_html_export">
                <ul class="pages_list">
                </ul>
            </div>
        </div>


        <div class="col-8">
            <div class="input-group">
                <label class="label"><?php esc_html_e('Export settings', 'export-wp-page-to-static-html'); ?></label>

                <div class="p-t-10">
                    <label class="checkbox-container full_site blur" for="full_site" style="filter: blur(.5px);"><?php esc_html_e('Full Site', 'export-wp-page-to-static-html'); ?>
                        <input type="checkbox" id="full_site" name="full_site">
                        <span class="checkmark"></span>
                    </label> <span style="color: red;">(Pro feature)</span>
                </div>

                <div class="p-t-10">
                    <label class="checkbox-container m-r-45"><?php esc_html_e('Replace all url to #', 'export-wp-page-to-static-html'); ?>
                        <input type="checkbox" id="replace_all_url" name="replace_all_url">
                        <span class="checkmark"></span>
                    </label>

                </div>

                <div class="p-t-10">
                    <label class="checkbox-container m-r-45" for="skip_assets"><?php esc_html_e('Skip Assets (Css, Js, Images or Videos)', 'export-wp-page-to-static-html'); ?>
                        <input type="checkbox" id="skip_assets" name="skip_assets">
                        <span class="checkmark"></span>
                    </label>

                    <div class="skip_assets_subsection export_html_sub_settings">
                        <label class="checkbox-container m-r-45" for="skip_stylesheets"><?php esc_html_e('Skip Stylesheets (.css)', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_stylesheets" name="skip_stylesheets" checked>
                            <span class="checkmark"></span>
                        </label>

                        <label class="checkbox-container m-r-45" for="skip_scripts"><?php esc_html_e('Skip Scripts (.js)', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_scripts" name="skip_scripts" checked>
                            <span class="checkmark"></span>
                        </label>

                        <label class="checkbox-container m-r-45" for="skip_images"><?php esc_html_e('Skip Images', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_images" name="skip_images" checked>
                            <span class="checkmark"></span>
                        </label>

                        <label class="checkbox-container m-r-45" for="skip_videos"><?php esc_html_e('Skip Videos', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_videos" name="skip_videos" checked>
                            <span class="checkmark"></span>
                        </label>


                        <label class="checkbox-container m-r-45" for="skip_audios"><?php esc_html_e('Skip Audios', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_audios" name="skip_audios" checked>
                            <span class="checkmark"></span>
                        </label>

                        <label class="checkbox-container m-r-45" for="skip_docs"><?php esc_html_e('Skip Documnets', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_docs" name="skip_docs" checked>
                            <span class="checkmark"></span>
                        </label>

                    </div>

                </div>

                <div class="p-t-10">
                    <label class="checkbox-container" for="image_to_webp" style="filter: blur(.5px);">Compress images size (image to webp)                     
                        <input type="checkbox" id="image_to_webp" name="image_to_webp">
                        <span class="checkmark"></span>
                    </label><span style="color: red;"> (Pro feature)</span>   

                    <div class="image_to_webp_subsection export_html_sub_settings" style="display: none;">
                        <div class="brightness-box">
                            <input type="range" id="image_quality" min="10" max="100" value="80">
                        </div>
                        <input type="text" id="image_quality_input" value="80" style="width: 45px;" onkeyup="if (/\D/g.test(this.value)) this.value = this.value.replace(/\D/g,'')">
                    </div>
                </div>


                <div class="p-t-10">
                    <label class="checkbox-container blur ftp_upload_checkbox <?php
                    if ($ftp_status !== 'connected') {
                        echo 'ftp_disabled';
                    }
                    ?>"  style="filter: blur(.5px);"><?php esc_html_e('Upload to ftp', 'export-wp-page-to-static-html'); ?>
                        <input type="checkbox" id="upload_to_ftp" name="upload_to_ftp"

                            <?php
                            if ($ftp_status !== 'connected') {
                                echo 'disabled=""';
                            }
                            ?>
                        >
                        <span class="checkmark"></span>
                    </label><span style="color: red;"> (Pro feature)</span>

                    <div class="ftp_Settings_section export_html_sub_settings">

                        <div class="ftp_settings_item">
                            <label for="ftp_path">FTP upload path</label>
                            <input type="text" id="ftp_path" name="ftp_path" placeholder="Upload path" value="<?php echo esc_html($path); ?>">
                            <div class="ftp_path_browse1"><a href="#">Browse</a></div>
                        </div>
                    </div>
                </div>

                <div class="p-t-10">
                    <div class="email_settings_section">
                        <div class="email_settings_item2">
                            <label class="checkbox-container m-r-45"><?php esc_html_e('Receive notification when complete', 'export-wp-page-to-static-html'); ?>
                                <input type="checkbox" id="email_notification" name="email_notification">
                                <span class="checkmark"></span>
                            </label>
                        </div>

                        <div class="email_settings_item">
                            <input type="text" id="receive_notification_email" name="notification_email" placeholder="Enter emails (optional)">
                            <span>Enter emails seperated by comma (,) (optional)</span>
                        </div>

                    </div>
                </div>

            </div>
        </div>

        <div class="p-t-15">
            <button class="btn btn--radius-2 btn--blue export_internal_page_to_html" type="submit"><?php esc_html_e('Export HTML', 'export-wp-page-to-static-html'); ?> <span class="spinner_x hide_spin"></span></button>
            <a class="cancel_rc_html_export_process" href="#">
                <?php esc_html_e('Cancel', 'export-wp-page-to-static-html'); ?>
            </a>
            <a href="" class="btn btn--radius-2 btn--green download-btn hide" type="submit" btn-text="<?php esc_html_e('Download the file', 'export-wp-page-to-static-html'); ?>"><?php esc_html_e('Download the file', 'export-wp-page-to-static-html'); ?></a>
            <a href="" class="view_exported_file hide" type="submit" target="_blank"><?php esc_html_e('View Exported File', 'export-wp-page-to-static-html'); ?></a>

            <div class="error-notice something-went-wrong" style="display: none;">
                <p><?php esc_html_e('Something went wrong! please try again. If failed continously then contact us.', 'export-wp-page-to-static-html'); ?></p>
            </div>

        </div>
    </form>

    <?php
    $dateToCheck = get_option('ewpptsh_next_review_status');  // Replace with your date or timestamp

    // Calculate timestamp of 7 days ago
    $sevenDaysAgo = time() - (7 * 24 * 60 * 60);
    ?>

</div>