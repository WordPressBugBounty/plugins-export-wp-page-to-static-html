<div class="tab-pane active" id="tabs-1" role="tabpanel">

    <form method="POST" class="pt-3">
        <div class="input-group select-a-page-input-group">
            <label class="label" for="export_pages"><?php _e('Select a page', 'export-wp-page-to-static-html'); ?></label>

            <span class="select_multi_pages">Select one or more pages</span>
            <div class="rs-select2 js-select-simple select--no-search">
                <select id="export_pages" name="export_pages" multiple>
                    <option value="home_page" permalink="<?php echo home_url('/'); ?>" filename="homepage"><?php _e('Homepage', 'export-wp-page-to-static-html');?> (<?php echo home_url(); ?>)</option>

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
                                    <option value="<?php echo $post_id; ?>" permalink="<?php echo basename($permalink); ?>"><?php echo $post_title . $private; ?> </option>
                                    <?php
                                }
                                else{
                                    ?>
                                    <option value="<?php echo $post_id; ?>" permalink="<?php echo basename($permalink); ?>"><?php echo $post_title; ?></option>
                                    <?php
                                }
                            }
                        }
                    }
                    ?>
                </select>
                <div class="select-dropdown"></div>
            </div>


            <div class="select_all_pages">
                <div class="p-t-10">
                    <label class="checkbox-container m-r-45"><?php _e('Select all pages', 'export-wp-page-to-static-html'); ?>

                        <input type="checkbox" id="selectAllPages" name="selectAllPages">
                        <span class="checkmark"></span>
                    </label>
                </div>
            </div>

            <label class="label" style="display: flex; align-items: center; margin-top: 20px; margin-bottom: 0;">
               <?php _e('Post options', 'export-wp-page-to-static-html'); ?>
            </label>



            <div class="seach_posts">
                <div class="checkbox-lock">
                <div class="p-t-10">
                    <label class="checkbox-container m-r-45 disabled"><?php _e('Select posts', 'export-wp-page-to-static-html'); ?>

                        <input type="checkbox" id="search_posts_to_select2" name="search_posts_to_select2" disabled>
                        <span class="checkmark"></span>
                    </label>

                    <div class="select_posts_section export_html_sub_settings">
                        <div class="p-t-10">

                            <label class="radio-container m-r-45"><?php _e('Published posts only', 'export-wp-page-to-static-html'); ?>
                                <input type="radio" id="published_posts_only" name="post_status" value="publish">
                                <span class="checkmark"></span>
                            </label>

                            <label class="radio-container m-r-45"><?php _e('Draft posts only', 'export-wp-page-to-static-html'); ?>
                                <input type="radio" id="draft_posts_only" name="post_status" value="draft">
                                <span class="checkmark"></span>
                            </label>

                            <label class="radio-container m-r-45"><?php _e('Private posts only', 'export-wp-page-to-static-html'); ?>
                                <input type="radio" id="private_posts_only" name="post_status" value="private">
                                <span class="checkmark"></span>
                            </label>

                            <label class="radio-container m-r-45"><?php _e('Pending posts only', 'export-wp-page-to-static-html'); ?>
                                <input type="radio" id="pending_posts_only" name="post_status" value="pending">
                                <span class="checkmark"></span>
                            </label>

                            <label class="radio-container m-r-45"><?php _e('Scheduled posts only', 'export-wp-page-to-static-html'); ?>
                                <input type="radio" id="future_posts_only" name="post_status" value="future">
                                <span class="checkmark"></span>
                            </label>
                        </div>
                    </div>
                </div>

                    <div class="go-pro-popup">
                        <a href="https://myrecorp.com/export-wp-pages-to-static-html-css-pro?ref=select_posts_btn" class="go-pro-btn">🚀 Go to Pro</a>
                        <p class="pro-note">Unlock advanced features with Pro</p>
                    </div>
                </div>


            </div>
            <!-- <div class="select_pages_to_export">
                <ul class="pages_list">
                </ul>
            </div> -->
        </div>


        <div class="col-8">

            <div class="p-t-10">
                <div class="input-group">
                    <label class="label label_login_as " style="font-weight: bold" for="login_as"><?php _e('Login as (optional)', 'export-wp-page-to-static-html'); ?></label>

                    <select id="login_as" name="login_as">
                        <option value="" selected=""><?php _e('Select a user role', 'export-wp-page-to-static-html'); ?></option>
                        <?php
                        global $wp_roles;

                        $all_roles = $wp_roles->roles;

                        if(!empty($all_roles)){
                            foreach($all_roles as $key => $role){
                                echo '<option value="'.$key.'">' . $role['name'] . '</option>';
                            }
                        }
                        ?>
                    </select>

                </div>


                <div class="checkbox-lock">
                    <label class="checkbox-container full_site m-r-45">Full Site
                        <input type="checkbox" id="full_site" name="full_site" disabled>
                        <span class="checkmark"></span>
                    </label>

                    <div class="go-pro-popup">
                        <a href="https://myrecorp.com/export-wp-pages-to-static-html-css-pro?ref=full_site_btn" class="go-pro-btn">🚀 Go to Pro</a>
                        <p class="pro-note">Unlock advanced features with Pro</p>
                    </div>
                </div>


                <div class="p-t-10">
                    <label class="checkbox-container m-r-45"><?php _e('Replace all url to #', 'export-wp-page-to-static-html'); ?>
                        <input type="checkbox" id="replace_all_url" name="replace_all_url">
                        <span class="checkmark"></span>
                    </label>

                </div>

                <div class="p-t-10">
                    <label class="checkbox-container m-r-45" for="skip_assets"><?php _e('Skip Assets (Css, Js, Images or Videos)', 'export-wp-page-to-static-html'); ?>
                        <input type="checkbox" id="skip_assets" name="skip_assets">
                        <span class="checkmark"></span>
                    </label>

                    <div class="skip_assets_subsection export_html_sub_settings">
                        <label class="checkbox-container m-r-45" for="skip_stylesheets"><?php _e('Skip Stylesheets (.css)', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_stylesheets" name="skip_stylesheets" checked>
                            <span class="checkmark"></span>
                        </label>

                        <label class="checkbox-container m-r-45" for="skip_scripts"><?php _e('Skip Scripts (.js)', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_scripts" name="skip_scripts" checked>
                            <span class="checkmark"></span>
                        </label>

                        <label class="checkbox-container m-r-45" for="skip_images"><?php _e('Skip Images', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_images" name="skip_images" checked>
                            <span class="checkmark"></span>
                        </label>

                        <label class="checkbox-container m-r-45" for="skip_videos"><?php _e('Skip Videos', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_videos" name="skip_videos" checked>
                            <span class="checkmark"></span>
                        </label>

                        <label class="checkbox-container m-r-45" for="skip_audios"><?php _e('Skip Audios', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_audios" name="skip_audios" checked>
                            <span class="checkmark"></span>
                        </label>

                        <label class="checkbox-container m-r-45" for="skip_docs"><?php _e('Skip Documnets', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="skip_docs" name="skip_docs" checked>
                            <span class="checkmark"></span>
                        </label>

                    </div>

                </div>

                <div class="p-t-10">
                    <label class="checkbox-container m-r-45" for="image_to_webp"><?php _e('Compress images size (image to webp)', 'export-wp-page-to-static-html'); ?>
                        <input type="checkbox" id="image_to_webp" name="image_to_webp">
                        <span class="checkmark"></span>
                    </label>

                    <div class="image_to_webp_subsection export_html_sub_settings">
                        <div class="brightness-box">
                            <input type="range" id="image_quality" min="10" max="100" value="80">
                        </div>
                        <input type="text" id="image_quality_input" value="80" style="width: 45px;" onkeyup="if (/\D/g.test(this.value)) this.value = this.value.replace(/\D/g,'')">
                    </div>
                </div>

                <div class="p-t-10">
                    <div class="checkbox-lock" style="margin-top: 0;">
                        <label class="checkbox-container ftp_upload_checkbox m-r-45 <?php
                        if ($ftp_status !== 'connected') {
                            echo 'disabled'; 
                        }
                        ?>"><?php _e('Upload to ftp', 'export-wp-page-to-static-html'); ?>
                            <input type="checkbox" id="upload_to_ftp" name="upload_to_ftp"

                                <?php
                                if ($ftp_status !== 'connected') {
                                    echo 'disabled=""';
                                }
                                ?>
                            >
                            <span class="checkmark"></span>
                        </label>

                        <div class="ftp_Settings_section export_html_sub_settings">

                            <div class="ftp_settings_item">
                                <label for="ftp_path"><?php _e('FTP upload path', 'export-wp-page-to-static-html'); ?></label>
                                <input type="text" id="ftp_path" name="ftp_path" placeholder="Upload path" value="<?php echo $path; ?>">
                                <div class="ftp_path_browse1"><a href="#"><?php _e('Browse', 'export-wp-page-to-static-html'); ?></a></div>
                            </div>
                        </div>

                        <div class="go-pro-popup">
                            <a href="https://myrecorp.com/export-wp-pages-to-static-html-css-pro?ref=ftp_select" class="go-pro-btn">🚀 Go to Pro</a>
                            <p class="pro-note">Unlock advanced features with Pro</p>
                        </div>
                    </div>        
                </div>

 


                <!-- <div class="p-t-10">
                    <label for="run_task_in_bg" class="checkbox-container run_task_in_bg m-r-45"><?php _e('Run in background', 'export-wp-page-to-static-html'); ?>
                        <input type="checkbox" id="run_task_in_bg" name="run_task_in_bg" >
                        <span class="checkmark"></span>
                    </label>

                    <div class="email_settings_section export_html_sub_settings">
                        <div class="email_settings_item">
                            <label class="checkbox-container m-r-45"><?php _e('Receive notification when complete', 'export-wp-page-to-static-html'); ?>
                                <input type="checkbox" id="email_notification" name="email_notification">
                                <span class="checkmark"></span>
                            </label>

                            <div class="email_settings_item export_html_sub_settings">
                                <input type="text" id="receive_notification_email" name="notification_email" placeholder="Enter emails (optional)">
                                <span><?php _e('Enter emails seperated by comma (,) (optional)', 'export-wp-page-to-static-html'); ?></span>
                            </div>
                        </div>


                    </div>
                </div>

                <div class="p-t-10">
                    <div class="util_settings_section">
                        <div class="util_settings_item">
                            <label class="checkbox-container m-r-45"><?php _e('Alternative Export (if any issues appear previously)', 'export-wp-page-to-static-html'); ?>
                                <input type="checkbox" id="alt_export" name="alt_export">
                                <span class="checkmark"></span>
                            </label>
                        </div>
                    </div>
                </div> -->

            </div>
        </div>

        <div class="p-t-15">
            <button class="flat-button primary export_internal_page_to_html" type="submit"><?php _e('Export HTML', 'export-wp-page-to-static-html'); ?> </button>
            <span class="spinner_x hide_spin"></span>
            <a class="cancel_rc_html_export_process" href="#">
                <?php _e('Cancel', 'export-wp-page-to-static-html'); ?>
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
                <?php _e('Download the Zip File', 'export-wp-page-to-static-html'); ?>
                </a>
            <a href="" class="view_exported_file hide" target="_blank">
                <span class="icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </span>
                View Exported File
            </a>
            <div class="error-notice" style="display: none;">
                <p><?php _e('Something went wrong! please try again. If failed continously then contact us.', 'export-wp-page-to-static-html'); ?></p>
            </div>
        </div>
    </form>

</div>


