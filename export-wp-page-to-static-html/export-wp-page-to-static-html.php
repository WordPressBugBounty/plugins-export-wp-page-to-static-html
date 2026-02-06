<?php

/**
 * @link              https://www.upwork.com/fl/rayhan1
 * @since             1.0.0
 * @package           Export_Wp_Page_To_Static_Html
 *
 * @wordpress-plugin
 * Plugin Name: Export WP Pages to HTML & PDF – Simply Create a Static Website
 * Plugin URI:        https://myrecorp.com
 * Description:       Seamlessly export any WordPress page or post into lightweight, fully responsive static HTML/CSS and print-ready PDF with a single click. Boost your site’s performance and security by serving pre-rendered pages, create offline-friendly backups. Perfect for developers, content creators, and businesses needing fast, reliable exports of WordPress content.
 * Version:           5.0.0
 * Author:            ReCorp
 * Author URI:        https://www.upwork.com/fl/rayhan1
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       export-wp-page-to-static-html
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// if (version_compare(PHP_VERSION, '8.1.13') > 0) {
//     add_action('admin_notices', function (){
//         $content = __("To use the \"<strong>Export WP Pages to HTML & PDF – Simply Create a Static Website</strong>\" plugin, you require PHP version <strong>8.1.13 or lower</strong>. Your current PHP version is: <strong>" . PHP_VERSION . '</strong>', 'export-wp-page-to-static-html') ;
//         $html =  '<div class="notice notice-error wpptsh wpptsh-php-not-compatible" wpptsh_notice_key="" style="padding: 19px;font-size: 16px;">
//                 '.$content.'
//             </div>';

//         echo $html;
//     });
//     echo ' '  . "\n";
// }
// else{

    /**
     * The code that runs during plugin activation
     *
     * This action is documented in includes/class-export-wp-page-to-static-html-activator.php
     */
    function activate_export_wp_page_to_static_html() {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-export-wp-page-to-static-html-activator.php';
        Export_Wp_Page_To_Static_Html_Activator::activate();
    }


    register_activation_hook( __FILE__, 'activate_export_wp_page_to_static_html' );

    if (!function_exists('run_export_wp_page_to_static_html_pro')){

        /**
         * Currently plugin version.
         * Start at version 1.0.0 and use SemVer - https://semver.org
         * Rename this for your plugin and update it as you release new versions.
         */
        define( 'EXPORT_WP_PAGE_TO_STATIC_HTML_VERSION', '5.0.0' );
        define( 'EWPPTSH_PLUGIN_DIR_URL', plugin_dir_url(__FILE__) );
        define( 'EWPPTSH_PLUGIN_DIR_PATH', plugin_dir_path(__FILE__) );
        define( 'EWPPTSH_DEVELOPER_MODE', false );
        define( 'WPPTSH_DB_VERSION', '1.2');

        /**
         * The code that runs during plugin deactivation.
         * This action is documented in includes/class-export-wp-page-to-static-html-deactivator.php
         */
        function deactivate_export_wp_page_to_static_html() {
            require_once plugin_dir_path( __FILE__ ) . 'includes/class-export-wp-page-to-static-html-deactivator.php';
            Export_Wp_Page_To_Static_Html_Deactivator::deactivate();
        }
        register_deactivation_hook( __FILE__, 'deactivate_export_wp_page_to_static_html' );

        register_activation_hook(__FILE__, 'export_wp_page_to_html_save_redirect_option');
        add_action('admin_init', 'export_wp_page_to_html_redirect_to_menu');


        /*Activating daily task*/
        register_activation_hook( __FILE__, 'rc_static_html_task_events_activate' );
        register_deactivation_hook( __FILE__, 'rc_static_html_task_events_deactivate' );


        /*Redirect to plugin's settings page when plugin will active*/
        function export_wp_page_to_html_save_redirect_option() {
            add_option('export_wp_page_to_html_activation_check', true);
        }


        function export_wp_page_to_html_redirect_to_menu() {
            if (get_option('export_wp_page_to_html_activation_check', false)) {
                delete_option('export_wp_page_to_html_activation_check');
                wp_redirect( esc_url_raw( admin_url( 'admin.php?page=export-wp-page-to-html&welcome=true' ) ) );
                exit;
            }
        }


        /**
         * The core plugin class that is used to define internationalization,
         * admin-specific hooks, and public-facing site hooks.
         */
        require plugin_dir_path( __FILE__ ) . 'includes/class-export-wp-page-to-static-html.php';

        /**
         * Begins execution of the plugin.
         *
         * Since everything within the plugin is registered via hooks,
         * then kicking off the plugin from this point in the file does
         * not affect the page life cycle.
         *
         * @since    1.0.0
         */
        function run_export_wp_page_to_static_html() {

            $plugin = new Export_Wp_Page_To_Static_Html();
            $plugin->run();

        }
        run_export_wp_page_to_static_html();
        

        function wpptsh_error_log($log){
            if (EWPPTSH_DEVELOPER_MODE) {
                error_log($log);
            }
        }
        // On plugin activation (once), create/store a token
        register_activation_hook(__FILE__, function(){
            if (!get_option('ewptshp_worker_token')) {
                add_option('ewptshp_worker_token', wp_generate_password(32, false, false));
            }
        });

        // Runs on every load, no __FILE__ here
        function wpptsh_update_db_check() {
            global $wpdb;

            $installed_ver = get_option('wpptsh_db_version', '0');
            $table_name    = $wpdb->prefix . 'export_urls_logs';
            $column_name   = 'type';

            // Early bail if version is current
            if ((string) $installed_ver === (string) WPPTSH_DB_VERSION) {
                return;
            }

            // Ensure we have upgrade helpers for dbDelta
            if ( ! function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            // --- 1) Try to add the column via dbDelta (preferred) ---
            // dbDelta needs a full CREATE TABLE statement. If you know the table schema,
            // define it here. If not, skip to the fallback below.
            // NOTE: Replace the columns below with your real schema (keep 'type' in it).
            $charset_collate = $wpdb->get_charset_collate();

            $known_schema = "
                CREATE TABLE {$table_name} (
                    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    url TEXT NOT NULL,
                    `{$column_name}` TINYTEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id)
                ) {$charset_collate};
            ";

            // If you KNOW the table structure above is accurate, uncomment the next line:
            // dbDelta($known_schema);

            // --- 2) Cache the existence check to satisfy NoCaching sniff ---
            $cache_group = 'wpptsh_schema';
            $cache_key   = 'has_col_' . md5($table_name . '|' . $column_name);

            $column_exists = wp_cache_get($cache_key, $cache_group);

            if (false === $column_exists) {
                // PHPCS flags any $wpdb call as "direct", but this is a read-only, prepared query.
                // We cache the result to address the NoCaching rule.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $column_exists = (bool) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                        WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                        DB_NAME,
                        $table_name,
                        $column_name
                    )
                );
                wp_cache_set($cache_key, $column_exists, $cache_group, 12 * HOUR_IN_SECONDS);
            }

            // --- 3) Fallback: add column via ALTER if still missing ---
            if ( ! $column_exists ) {
                // If you cannot reliably use dbDelta with the full CREATE TABLE statement,
                // do a minimal ALTER TABLE. Document and ignore the PHPCS warnings:
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `{$column_name}` TINYTEXT NOT NULL");

                // Invalidate cache after schema change
                wp_cache_delete($cache_key, $cache_group);
            }

            // --- 4) Update DB version ---
            update_option('wpptsh_db_version', WPPTSH_DB_VERSION);

            // --- 5) Ensure worker token exists ---
            if ( ! get_option('ewptshp_worker_token')) {
                add_option('ewptshp_worker_token', wp_generate_password(32, false, false));
            }
        }


        add_action('plugins_loaded', 'wpptsh_update_db_check');
    }

//}