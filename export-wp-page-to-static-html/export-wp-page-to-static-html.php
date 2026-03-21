<?php
/**
 * Plugin Name: Export WP Page to Static HTML
 * Plugin URI:        https://myrecorp.com
 * Description:       Export WP Pages to Static HTML is the most flexible static HTML export plugin for WordPress. Unlike full-site generators, Export WP Pages to Static HTML gives you surgical control — export exactly the posts, pages, or custom post types you need, in the status you want, as the user role you choose.
 * Version:           6.0.7.0
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
define('WP_TO_HTML_VERSION', '6.0.7.0');
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
        if (!function_exists('ewptshp_fs')) {
            return false;
        }

        $fs = ewptshp_fs();
        return $fs->can_use_premium_code() && $fs->is_plan('pro', true);
    }
}

if (!function_exists('wp_to_html_allowed_scopes')) {
    /**
     * Allowed export scopes for the current installation.
     * Free: selected (aka custom, max 5 items)
     * Pro: selected (unlimited) + all_pages + all_posts + full_site
     */
    function wp_to_html_allowed_scopes(): array {
        $scopes = ['selected'];
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

/**
 * Safety net: if tables are missing (e.g. activation hook failed silently or
 * was skipped on some hosts), recreate them before anything else runs.
 * Priority 5 ensures this fires before wp_to_html_plugin_update() (priority 10).
 */
add_action('plugins_loaded', function () {
    global $wpdb;
    $queue = $wpdb->prefix . 'wp_to_html_queue';
    if (!$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue))) {
        ob_start();
        wp_to_html_ensure_tables();
        ob_end_clean();
    }
}, 5);

require_once WP_TO_HTML_PATH . 'includes/class-core.php';
require_once WP_TO_HTML_PATH . 'includes/class-admin.php';
require_once WP_TO_HTML_PATH . 'includes/class-whats-new.php';
require_once WP_TO_HTML_PATH . 'includes/class-rest.php';
require_once WP_TO_HTML_PATH . 'includes/class-exporter.php';
require_once WP_TO_HTML_PATH . 'includes/class-diagnostic.php';
require_once WP_TO_HTML_PATH . 'includes/class-advanced-debugger.php';
require_once WP_TO_HTML_PATH . 'includes/class-asset-manager.php';
require_once WP_TO_HTML_PATH . 'includes/class-asset-extractor.php';
require_once WP_TO_HTML_PATH . 'includes/class-bulk-asset-collector.php';
require_once WP_TO_HTML_PATH . 'includes/class-ftp-uploader.php';
require_once WP_TO_HTML_PATH . 'includes/class-quick-export.php';

// Robust RFC3986 URL absolutizer (ported from the older exporter).
require_once WP_TO_HTML_PATH . 'includes/url/url_to_absolute.php';

// PDF Generator — free (2/day) + Pro (unlimited).
require_once WP_TO_HTML_PATH . 'includes/class-pdf-generator.php';

// Export HTML Button shortcode — free (3/day) + Pro (unlimited).
require_once WP_TO_HTML_PATH . 'includes/class-export-html-button.php';

add_action('plugins_loaded', function () {
    \WpToHtml\Core::get_instance();
    new \WpToHtml\PdfGenerator();
    new \WpToHtml\ExportHtmlButton();
});


/**
 * Decide whether a "What's New" redirect should fire for this version update.
 *
 * Rules:
 *   1. Fresh install ($old_version empty): never redirect.
 *   2. User has never been redirected before (wp_to_html_whats_new_version empty):
 *      redirect once, regardless of increment size (one-time catch-up for all users).
 *   3. Going forward: redirect only when the first three version segments (x.y.z)
 *      advance. The fourth "build" segment alone (e.g. 6.0.6.1 → 6.0.6.2) does NOT
 *      trigger a redirect.
 *
 * The caller must also run:
 *   update_option('wp_to_html_whats_new_version', $new_version, false)
 * so future comparisons start from the correct baseline.
 */
function wp_to_html_should_redirect_to_whats_new(string $old_version, string $new_version): bool {
    // Fresh install: no redirect.
    if ($old_version === '') return false;

    $last_seen = (string) get_option('wp_to_html_whats_new_version', '');

    // Never redirected before (all users on first-ever catch-up): redirect once.
    if ($last_seen === '') return true;

    // Major release check: compare x.y.z of last-redirected version vs new version.
    $last_parts = array_pad(explode('.', $last_seen), 4, '0');
    $new_parts  = array_pad(explode('.', $new_version), 4, '0');

    $last_xyz = implode('.', array_slice($last_parts, 0, 3));
    $new_xyz  = implode('.', array_slice($new_parts, 0, 3));

    return version_compare($new_xyz, $last_xyz, '>');
}

register_activation_hook(__FILE__, function() {
    $installed_version = get_option('wp_to_html_version', '');

    // Buffer output to prevent PHP warnings/notices from dbDelta()
    // from being counted as "unexpected output" during activation.
    ob_start();

    if ($installed_version === '') {
        // Fresh install — store current version, no "What's New" redirect.
        update_option('wp_to_html_version', WP_TO_HTML_VERSION, false);
        wp_to_html_ensure_tables();

    } elseif (version_compare($installed_version, WP_TO_HTML_VERSION, '>')) {
        // Downgrade: stored version is higher than what's being activated.
        // Reset so the next upgrade detects a version change correctly.
        update_option('wp_to_html_version', WP_TO_HTML_VERSION, false);
        update_option('wp_to_html_old_tables_removed', false);
        wp_to_html_ensure_tables();

    } elseif (version_compare($installed_version, WP_TO_HTML_VERSION, '<')) {
        // Upgrade: create/restore tables (deactivation dropped them),
        // then bump the stored version.
        wp_to_html_ensure_tables();
        if (wp_to_html_should_redirect_to_whats_new($installed_version, WP_TO_HTML_VERSION)) {
            set_transient('wp_to_html_redirect_to_whats_new', 1, 60);
            update_option('wp_to_html_whats_new_version', WP_TO_HTML_VERSION, false);
        }
        update_option('wp_to_html_version', WP_TO_HTML_VERSION, false);
    }
    // Same version re-activation: nothing to do.

    // Redirect to plugin main page after activation (all cases).
    set_transient('wp_to_html_redirect_after_activation', 1, 60);

    ob_end_clean();
});

/**
 * "What's New" redirect after plugin update — all users on major releases.
 * Fires for every user when the first three version segments (x.y.z) advance,
 * or on first-ever update for users who were never redirected before (free users).
 */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (wp_doing_ajax() || wp_doing_cron()) return;
    if (isset($_GET['activate-multi'])) return;
    if (defined('WP_CLI') && WP_CLI) return;

    if (!get_transient('wp_to_html_redirect_to_whats_new')) return;

    delete_transient('wp_to_html_redirect_to_whats_new');

    if (isset($_GET['page']) && $_GET['page'] === 'wp-to-html-whats-new') return;

    wp_safe_redirect(admin_url('admin.php?page=wp-to-html-whats-new'));
    exit;
});

/**
 * Redirect to the plugin main page after activation.
 */
add_action('admin_init', function () {
    if (!current_user_can('manage_options')) return;
    if (wp_doing_ajax() || wp_doing_cron()) return;
    if (isset($_GET['activate-multi'])) return;
    if (defined('WP_CLI') && WP_CLI) return;

    if (!get_transient('wp_to_html_redirect_after_activation')) return;

    // Clear immediately so it only fires once.
    delete_transient('wp_to_html_redirect_after_activation');

    // Don't redirect if already on the plugin page.
    if (isset($_GET['page']) && $_GET['page'] === 'wp-to-html') return;

    wp_safe_redirect(admin_url('admin.php?page=wp-to-html'));
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
    $installed_version = get_option('wp_to_html_version', '');
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

    // Set What's New redirect transient (major releases for all users; see admin_init hook).
    if (wp_to_html_should_redirect_to_whats_new($installed_version, $current_version)) {
        set_transient('wp_to_html_redirect_to_whats_new', 1, 60);
        update_option('wp_to_html_whats_new_version', $current_version, false);
    }
}
add_action('plugins_loaded', 'wp_to_html_plugin_update');

// ─── Dynamic Pricing & Plugin Info via WP Cron ────────────────────────────────

/**
 * Schedule daily cron to fetch pricing + plugin data from the remote API.
 */
add_action('wp', function () {
    if (!wp_next_scheduled('wp_to_html_fetch_remote_data')) {
        wp_schedule_event(time(), 'daily', 'wp_to_html_fetch_remote_data');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('wp_to_html_fetch_remote_data');
});

// ─── Deactivation Feedback Popup: enqueue admin.js on plugins.php ─────────────
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'plugins.php') return;

    $js_ver = defined('WP_TO_HTML_VERSION') ? WP_TO_HTML_VERSION : '1.0.0';

    // jQuery is always available on plugins.php; just enqueue our admin JS.
    wp_enqueue_script(
        'wp-to-html-admin-deactivate',
        WP_TO_HTML_URL . 'assets/admin.js',
        ['jquery'],
        $js_ver,
        true
    );

    // Pass the minimal data the deactivation popup needs.
    wp_localize_script('wp-to-html-admin-deactivate', 'wpToHtmlData', [
        'site_url'       => home_url('/'),
        'plugin_version' => defined('WP_TO_HTML_VERSION') ? WP_TO_HTML_VERSION : '',
        'wp_version'     => get_bloginfo('version'),
    ]);
});

/**
 * Fetch remote JSON and cache it as a WP option.
 * JSON endpoint: https://api.myrecorp.com/wp-to-html-plugins-data.php
 */
add_action('wp_to_html_fetch_remote_data', 'wp_to_html_do_fetch_remote_data');
function wp_to_html_do_fetch_remote_data(): void {
    $response = wp_remote_get('https://api.myrecorp.com/wp-to-html-plugins-data.php?plugin=' . (defined('WP_TO_HTML_PRO_ACTIVE') ? 'pro' : 'free'), [
        'timeout'    => 15,
        'user-agent' => 'WpToHtml/' . WP_TO_HTML_VERSION . '; ' . home_url('/'),
    ]);

    if (is_wp_error($response)) return;

    $body = wp_remote_retrieve_body($response);
    if (empty($body)) return;

    $data = json_decode($body, true);
    if (!is_array($data)) return;

    update_option('wp_to_html_remote_data', $data, false);
    update_option('wp_to_html_remote_data_updated', time(), false);
}

/**
 * Get cached remote data. Falls back to defaults if not yet fetched.
 */
function wp_to_html_get_remote_data(): array {
    $data = get_option('wp_to_html_remote_data', null);
    if (!is_array($data)) {
        return [
            'pricing' => ['old' => 39.99, 'new' => 15],
            'plugins' => [],
        ];
    }
    return $data;
}

/**
 * Remote data (pricing + plugins) is now fetched live by the browser JS
 * on every settings page load via fetch('https://api.myrecorp.com/wp-to-html-plugins-data.php').
 * The WP cron below still runs daily as a server-side backup/cache, but
 * wp_localize_script is no longer used for this data.
 */
