<?php
abstract class pp_group_notif_Meta_Box {

    const NONCE_NAME = 'rc_export_html_settings_nonce';
    const NONCE_ACTION = 'rc_export_html_settings_action';

    /**
     * Set up and add the meta box.
     */
    public static function add() {
        $screens = rc_get_all_post_types();
        foreach ( $screens as $screen ) {
            add_meta_box(
                '_export_html_settings',          // Unique ID
                __( 'Export HTML settings', 'export-wp-page-to-static-html' ), // Box title
                [ self::class, 'html' ],          // Content callback
                $screen,                          // Post type
                'side',
                'high'
            );
        }
    }

    /**
     * Save the meta box selections.
     *
     * @param int $post_id  The post ID.
     */
    public static function save( int $post_id ) {

        // ✓ 1. Don't run on autosave/revision/cron
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        // ✓ 2. Check nonce
        if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
            return;
        }

        // ✓ 3. Check user capability
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Now it is safe to touch POST.

        // Is the checkbox sent at all?
        if ( isset( $_POST['upload_to_ftp'] ) ) {

            // ✓ 4. Sanitize checkbox
            $upload_to_ftp_raw = wp_unslash( $_POST['upload_to_ftp'] );
            // Usually checkbox is 'on'
            $upload_to_ftp     = ( 'on' === $upload_to_ftp_raw ) ? 'on' : '';

            // ✓ 5. Sanitize path (if present)
            $ftp_upload_path = '';
            if ( isset( $_POST['ftp_upload_path'] ) ) {
                $ftp_upload_path = sanitize_text_field( wp_unslash( $_POST['ftp_upload_path'] ) );
                update_post_meta(
                    $post_id,
                    '_upload_to_ftp_path',
                    $ftp_upload_path
                );
            }

            // ✓ 6. Keep your options update
            update_option( 'rc_export_pages_as_html_task', 'running' );
            update_option( 'rc_is_export_pages_zip_downloaded', 'no' );

            update_post_meta(
                $post_id,
                '_upload_to_ftp',
                $upload_to_ftp
            );

        } else {
            // If box unchecked, clear meta
            update_post_meta(
                $post_id,
                '_upload_to_ftp',
                ''
            );
        }
    }

    /**
     * Display the meta box HTML to the user.
     *
     * @param \WP_Post $post   Post object.
     */
    public static function html( $post ) {
        $status = get_option( 'rc_export_html_ftp_connection_status' );
        $data   = get_option( 'rc_export_html_ftp_data' );

        $is_ftp = (string) get_post_meta( $post->ID, '_upload_to_ftp', true );
        $path   = (string) get_post_meta( $post->ID, '_upload_to_ftp_path', true );

        // Fallback path if empty and FTP reports connected.
        if ( empty( $path ) && isset( $status, $data->path ) && 'connected' === $status ) {
            $path = (string) $data->path;
        }

        $is_connected    = ( isset( $status ) && 'connected' === $status );
        $show_path_style = ( 'on' === $is_ftp ) ? 'display: block;' : '';
        $settings_url    = admin_url( 'options-general.php?page=export-wp-page-to-html&tab=ftp_settings' );
        ?>
        <!-- ✓ Nonce for save_post -->
        <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

        <div class="ftp_uploading_section">
            <input
                id="upload_to_ftp"
                type="checkbox"
                name="upload_to_ftp"
                <?php disabled( ! $is_connected ); ?>
                <?php checked( 'on' === $is_ftp ); ?>
            >
            <label for="upload_to_ftp"><?php esc_html_e( 'Upload to FTP server', 'export-wp-page-to-static-html' ); ?></label>

            <br>

            <input
                id="ftp_upload_path"
                type="text"
                name="ftp_upload_path"
                placeholder="<?php echo esc_attr__( 'FTP path to upload', 'export-wp-page-to-static-html' ); ?>"
                value="<?php echo esc_attr( $path ); ?>"
                style="<?php echo esc_attr( $show_path_style ); ?>"
            >

            <?php if ( ! $is_connected ) : ?>
                <span>
                    <?php
                    /* translators: %s: settings page URL */
                    printf(
                        esc_html__( 'FTP server is not connected. Configure it on the %s settings page.', 'export-wp-page-to-static-html' ),
                        // Safe internal admin URL.
                        '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Export WP Page to Static HTML', 'export-wp-page-to-static-html' ) . '</a>'
                    );
                    ?>
                </span>
            <?php endif; ?>
        </div>

        <style>
            #ftp_upload_path {
                margin-top: 10px;
                width: 100%;
                display: none;
            }
        </style>

        <script>
            (function ($) {
                'use strict';

                $(document).on("change", "#upload_to_ftp", function(){
                    if ($(this).is(':checked')) {
                        $('#ftp_upload_path').slideDown(200);
                    } else {
                        $('#ftp_upload_path').slideUp(200);
                    }
                });
            })(jQuery);
        </script>
        <?php
    }
}

add_action( 'add_meta_boxes', [ 'pp_group_notif_Meta_Box', 'add' ] );
add_action( 'save_post', [ 'pp_group_notif_Meta_Box', 'save' ] );



function rc_get_all_post_types(){

    $need = array();
    foreach (get_post_types() as $key => $value) {
        if ($value !== 'attachment'&&$value !== 'revision'&&$value !== 'nav_menu_item'&&$value !== 'oembed_cache'&&$value !== 'user_request') {
            $need[] = $value;
        }
        
    }

    return $need;
}

function rc_export_page_to_ftp_server($post_id, $path=''){
    add_cron_job_to_start_html_exporting_for_save_post($post_id, $path);
}

function add_cron_job_to_start_html_exporting_for_save_post($post_id = 0, $path=''){
    $permalink = get_permalink($post_id);

    global $wpdb;
    $upload_dir = wp_upload_dir()['basedir'];
    rmdir_recursive2($upload_dir . '/exported_html_files/tmp_files');
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}export_page_to_html_logs");
    $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}export_urls_logs ");

    $settings = array(
        'skipAssetsFiles' => array(),
        'replaceUrlsToHash' => false,
        'receive_email' => false,
        'email_lists' => "",
        'ftp_upload_enabled' => true,
        'ftp_path' => $path,
    );

    wp_schedule_single_event( time() , 'start_export_custom_url_to_html_event', array( $permalink, $settings ) );
    
    return json_encode(array('success' => 'true', 'status' => 'success', 'response' => 'task running'));

}

    function rmdir_recursive2($dir) {
        foreach(scandir($dir) as $file) {
            if ('.' === $file || '..' === $file) continue;
            if (is_dir("$dir/$file")) rmdir_recursive2("$dir/$file");
            else unlink("$dir/$file");
        }
        remove_dir_wp($dir);
    }


function rc_after_posts_save_hook(){
    if (isset($_GET['post'])&&isset($_GET['action'])&&isset($_GET['message'])&&($_GET['message']=='1'||$_GET['message']=='6')) {
        
        $post_id = $_GET['post'];
        $is_ftp = get_post_meta($post_id, '_upload_to_ftp', true);
        $path = get_post_meta($post_id, '_upload_to_ftp_path', true);
        if ($is_ftp == 'on') {
            $post_name = get_permalink($post_id);
            update_option('rc_single_post_exporting', 'on');
            update_option('rc_single_post_exporting_post_name', basename($post_name));
            rc_export_page_to_ftp_server($post_id, $path);  
            //update_option('rc_single_post_exporting', '');
        }
    }
}
add_action("init", "rc_after_posts_save_hook");