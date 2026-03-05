<?php
/**
 * Plugin Name: Export WP Page to Static HTML
 * Plugin URI:        https://myrecorp.com
 * Description:       Export WP Pages to Static HTML is the most flexible static HTML export plugin for WordPress. Unlike full-site generators, Export WP Pages to Static HTML gives you surgical control — export exactly the posts, pages, or custom post types you need, in the status you want, as the user role you choose.
 * Version:           6.0.5.2
 * Author:            ReCorp
 * Author URI:        https://www.upwork.com/fl/rayhan1
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-to-html
 * Domain Path:       /languages
 */
if (!defined('ABSPATH')) exit;

/**
 * Load plugin text domain for translations.
 */
add_action('init', function () {
    load_plugin_textdomain('wp-to-html', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
define('WP_TO_HTML_VERSION', '6.0.5.2');
define('WP_TO_HTML_PATH', plugin_dir_path(__FILE__));
define('WP_TO_HTML_URL', plugin_dir_url(__FILE__));
define('WP_TO_HTML_EXPORT_DIR', WP_CONTENT_DIR . '/wp-to-html-exports');
define('WP_TO_HTML_DEBUG', false);

// Advanced debugger (super debugger)
// Enable in wp-config.php: define('WP_TO_HTML_ADVANCED_DEBUG', true);
if (!defined('WP_TO_HTML_ADVANCED_DEBUG')) {
    define('WP_TO_HTML_ADVANCED_DEBUG', false);
}

/**
 * Pro bridge helpers
 *
 * The Free plugin exposes these helpers so a separate Pro plugin can enable
 * premium scopes (All Pages / All Posts / Full Site) without modifying core logic.
 */
if (!function_exists('wp_to_html_is_pro_active')) {
    function wp_to_html_is_pro_active(): bool {
        // Fast path: Pro plugin can define this constant.
        if (defined('WP_TO_HTML_PRO_ACTIVE') && WP_TO_HTML_PRO_ACTIVE) {
            return true;
        }

        // Alternative: Pro can load a class.
        if (class_exists('WpToHtml_Pro\\Plugin')) {
            return true;
        }

        // Extensible hook for other licensing/loader mechanisms.
        return (bool) apply_filters('wp_to_html/pro_active', false);
    }
}

if (!function_exists('wp_to_html_allowed_scopes')) {
    /**
     * Allowed export scopes for the current installation.
     * Free: selected (aka custom) + all_pages
     * Pro: selected + all_pages + all_posts + full_site
     */
    function wp_to_html_allowed_scopes(): array {
        $scopes = ['selected', 'all_pages'];
        if (wp_to_html_is_pro_active()) {
            $scopes = ['selected', 'all_posts', 'all_pages', 'full_site'];
        }

        return (array) apply_filters('wp_to_html/allowed_scopes', $scopes);
    }
}

/**
 * DB schema upgrades (runs on every load, but only applies changes when needed).
 */

add_action('plugins_loaded', function () {
    global $wpdb;

    $status = $wpdb->prefix . 'wp_to_html_status';
    // If the table doesn't exist yet, activation will create it.
    $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $status));
    if (!$exists) return;

    // Ensure required columns exist (handles upgrades from older schemas).
    $required_cols = [
        'state'            => "ALTER TABLE {$status} ADD COLUMN state VARCHAR(20) DEFAULT 'idle'",
        'is_running'       => "ALTER TABLE {$status} ADD COLUMN is_running TINYINT(1) NOT NULL DEFAULT 0 AFTER state",
        'total_urls'       => "ALTER TABLE {$status} ADD COLUMN total_urls INT DEFAULT 0",
        'processed_urls'   => "ALTER TABLE {$status} ADD COLUMN processed_urls INT DEFAULT 0",
        'total_assets'     => "ALTER TABLE {$status} ADD COLUMN total_assets INT DEFAULT 0",
        'processed_assets' => "ALTER TABLE {$status} ADD COLUMN processed_assets INT DEFAULT 0",
        'last_progress_at'            => "ALTER TABLE {$status} ADD COLUMN last_progress_at DATETIME NULL",
        'last_progress_stage'            => "ALTER TABLE {$status} ADD COLUMN last_progress_stage VARCHAR(30) NULL",
        'last_progress_done'            => "ALTER TABLE {$status} ADD COLUMN last_progress_done INT DEFAULT 0",
        'watchdog_runs'            => "ALTER TABLE {$status} ADD COLUMN watchdog_runs INT DEFAULT 0",
        'watchdog_repairs'            => "ALTER TABLE {$status} ADD COLUMN watchdog_repairs INT DEFAULT 0",
        'failed_assets'            => "ALTER TABLE {$status} ADD COLUMN failed_assets INT DEFAULT 0",
    ];

    foreach ($required_cols as $name => $sql) {
        $col = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$status} LIKE %s", $name));
        if (!$col) {
            $wpdb->query($sql);
        }
    }

    // Ensure row id=1 exists WITHOUT overwriting live state (REPLACE would reset columns to defaults).
    // Only insert defaults if the row is missing.
    $row_exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$status} WHERE id=%d", 1));
    if (!$row_exists) {
        $wpdb->insert($status, [
            'id'               => 1,
            'state'            => 'idle',
            'is_running'       => 0,
            'total_urls'       => 0,
            'processed_urls'   => 0,
            'total_assets'     => 0,
            'processed_assets' => 0,
            'failed_assets' => 0,
        ]);
    }
});

require_once WP_TO_HTML_PATH . 'includes/class-core.php';
require_once WP_TO_HTML_PATH . 'includes/class-admin.php';
require_once WP_TO_HTML_PATH . 'includes/class-rest.php';
require_once WP_TO_HTML_PATH . 'includes/class-exporter.php';
require_once WP_TO_HTML_PATH . 'includes/class-diagnostic.php';
require_once WP_TO_HTML_PATH . 'includes/class-advanced-debugger.php';
require_once WP_TO_HTML_PATH . 'includes/class-asset-manager.php';
require_once WP_TO_HTML_PATH . 'includes/class-asset-extractor.php';
require_once WP_TO_HTML_PATH . 'includes/class-bulk-asset-collector.php';
require_once WP_TO_HTML_PATH . 'includes/class-ftp-uploader.php';

// Robust RFC3986 URL absolutizer (ported from the older exporter).
require_once WP_TO_HTML_PATH . 'includes/url/url_to_absolute.php';

add_action('plugins_loaded', function () {
    \WpToHtml\Core::get_instance();
});

register_activation_hook(__FILE__, function() {
    // Buffer output to prevent PHP warnings/notices from dbDelta()
    // from being counted as "unexpected output" during activation
    // (especially when WP_DEBUG_DISPLAY is enabled).
    ob_start();
    wp_to_html_ensure_tables();
    ob_end_clean();

    // Store version on activation so first-install doesn't trigger the "What's New" page.
    update_option('wp_to_html_version', WP_TO_HTML_VERSION, false);
});

/**
 * Redirect to "What's New" page after plugin update (not on first activation).
 *
 * Uses a dedicated transient set by wp_to_html_plugin_update() so that the
 * version bump and the redirect trigger are fully decoupled.
 */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (wp_doing_ajax() || wp_doing_cron()) return;
    if (isset($_GET['activate-multi'])) return;
    if (defined('WP_CLI') && WP_CLI) return;

    // Only redirect if the update routine flagged it.
    if (!get_transient('wp_to_html_redirect_to_whats_new')) return;

    // Clear immediately so it only fires once.
    delete_transient('wp_to_html_redirect_to_whats_new');

    // Don't redirect if already on the page.
    if (isset($_GET['page']) && $_GET['page'] === 'wp-to-html-whats-new') return;

    wp_safe_redirect(admin_url('admin.php?page=wp-to-html-whats-new'));
    exit;
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wp_to_html_process_event');

    global $wpdb;

    $tables = [
        $wpdb->prefix . 'wp_to_html_status',
        $wpdb->prefix . 'wp_to_html_queue',
        $wpdb->prefix . 'wp_to_html_assets',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

});

function wp_to_html_ensure_tables() {

    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $queue_table  = $wpdb->prefix . 'wp_to_html_queue';
    $assets_table = $wpdb->prefix . 'wp_to_html_assets';
    $status_table = $wpdb->prefix . 'wp_to_html_status';

    /*
    |--------------------------------------------------------------------------
    | 1. Queue Table
    |--------------------------------------------------------------------------
    */
    // Queue now supports retries/backoff + failed-only reruns.
    $sql_queue = "CREATE TABLE $queue_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        url TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        retry_count INT NOT NULL DEFAULT 0,
        last_error LONGTEXT NULL,
        last_attempt_at DATETIME NULL,
        next_attempt_at DATETIME NULL,
        started_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY status (status),
        KEY next_attempt_at (next_attempt_at)
    ) $charset_collate;";

    dbDelta($sql_queue);


    /*
    |--------------------------------------------------------------------------
    | 2. Assets Table
    |--------------------------------------------------------------------------
    */

    $sql_assets = "CREATE TABLE $assets_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        url TEXT NOT NULL,
        found_on TEXT NULL,
        asset_type VARCHAR(30) NOT NULL DEFAULT 'asset',
        local_path TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        retry_count INT NOT NULL DEFAULT 0,
        last_error LONGTEXT NULL,
        last_attempt_at DATETIME NULL,
        next_attempt_at DATETIME NULL,
        started_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),

        UNIQUE KEY unique_url (url(191)),
        KEY status (status),
        KEY asset_type (asset_type),
        KEY next_attempt_at (next_attempt_at),
        KEY started_at (started_at)
    ) $charset_collate;";

    dbDelta($sql_assets);


    /*
    |--------------------------------------------------------------------------
    | 3. Status Table
    |--------------------------------------------------------------------------
    */
    $sql_status = "CREATE TABLE $status_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        state VARCHAR(20) DEFAULT 'idle',
        is_running TINYINT(1) DEFAULT 0,
        pipeline_stage VARCHAR(30) NOT NULL DEFAULT 'idle',
        stage_total INT DEFAULT 0,
        stage_done INT DEFAULT 0,
        failed_urls INT DEFAULT 0,
        failed_assets INT DEFAULT 0,
        total_urls INT DEFAULT 0,
        processed_urls INT DEFAULT 0,
        total_assets INT DEFAULT 0,
        processed_assets INT DEFAULT 0,
        last_progress_at DATETIME NULL,
        last_progress_stage VARCHAR(30) NULL,
        last_progress_done INT DEFAULT 0,
        watchdog_runs INT DEFAULT 0,
        watchdog_repairs INT DEFAULT 0,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    dbDelta($sql_status);


    /*
    |--------------------------------------------------------------------------
    | 4. Ensure Single Status Row Exists
    |--------------------------------------------------------------------------
    */
    $exists = $wpdb->get_var("SELECT COUNT(*) FROM $status_table");

    if (!$exists) {
        $wpdb->insert($status_table, [
            'state'            => 'idle',
            'is_running'       => 0,
            'total_urls'       => 0,
            'processed_urls'   => 0,
            'total_assets'     => 0,
            'processed_assets' => 0,
            'failed_assets'    => 0,
        ]);
    }

}

function wp_to_html_plugin_update() {
    global $wpdb;
    $installed_version = get_option('wp_to_html_version', ''); // previous stored version
    $current_version   = WP_TO_HTML_VERSION;
    $tables_removed    = get_option('wp_to_html_old_tables_removed', false);

    // Nothing to do if already up to date.
    if ($installed_version === $current_version) return;

    // Remove legacy tables only once (upgrades from pre-6.x).
    if (!$tables_removed) {
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}exportable_urls");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}export_page_to_html_logs");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}export_urls_logs");
        update_option('wp_to_html_old_tables_removed', true);
    }

    // Create new tables for fresh/migrating installs.
    wp_to_html_ensure_tables();

    // Bump stored version FIRST so this block won't re-run on the next load.
    update_option('wp_to_html_version', $current_version, false);

    // Show "What's New" page only when upgrading FROM a version below 6.0.0.
    // - Empty string = fresh install → no redirect.
    // - Pre-6.0.0 install upgrading to any version → redirect once.
    // - 6.0.0 or higher upgrading to a newer 6.x+ → no redirect.
    if ($installed_version !== '' && version_compare($installed_version, '6.0.0', '<')) {
        set_transient('wp_to_html_redirect_to_whats_new', 1, 60);
    }
}
add_action('plugins_loaded', 'wp_to_html_plugin_update');