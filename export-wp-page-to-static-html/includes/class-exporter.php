<?php
namespace WpToHtml;

class Exporter {
 
    private $group_assets;
    private $log_file;

    private $single_root_index = false;
    private $root_parent_html = false;

    // Optional: export as a specific user (created by REST) so private/draft content renders like that role.
    private $export_user_id = 0;

    // Asset coverage strategy.
    // strict (default), hybrid, full
    private $asset_collection_mode = 'strict';


    public function __construct($group_assets = false) {
        $this->group_assets = (bool) $group_assets;
        $this->log_file = WP_TO_HTML_EXPORT_DIR . '/export-log.txt';

        // Export context (set by REST at start of an export)
        $ctx = get_option('wp_to_html_export_context', []);
        if (is_array($ctx)) {
            $this->single_root_index = !empty($ctx['single_root_index']);
            $this->root_parent_html  = !empty($ctx['root_parent_html']);

            if (!empty($ctx['export_user_id']) && is_numeric($ctx['export_user_id'])) {
                $this->export_user_id = (int) $ctx['export_user_id'];
            }

            // If the REST/UI saved grouped-assets preference, honor it in background cron runs.
            if (array_key_exists('save_assets_grouped', $ctx)) {
                $this->group_assets = !empty($ctx['save_assets_grouped']);
            }

            if (!empty($ctx['asset_collection_mode'])) {
                $m = strtolower(trim((string) $ctx['asset_collection_mode']));
                if (in_array($m, ['strict','hybrid','full'], true)) {
                    $this->asset_collection_mode = $m;
                }
            }
        }

        // If REST configured an export user, switch context for server-side rendering fallbacks.
        if ($this->export_user_id > 0 && function_exists('wp_set_current_user')) {
            wp_set_current_user($this->export_user_id);
        }

        if (WP_TO_HTML_DEBUG) { 
            // Debug: confirm which mode we're in (helps diagnose “option not applied” issues)
            $this->log('Exporter init: group_assets=' . ($this->group_assets ? '1' : '0')
                . ' single_root_index=' . ($this->single_root_index ? '1' : '0')
                . ' root_parent_html=' . ($this->root_parent_html ? '1' : '0')
                . ' export_user_id=' . (int) $this->export_user_id
                . ' asset_collection_mode=' . $this->asset_collection_mode);
        }
    }

    /**
     * Enqueue additional assets based on the configured asset collection mode.
     * Intended to be called once when URL export phase completes.
     */
    public function enqueue_bulk_assets(): int {
        $mode = $this->asset_collection_mode;
        if (!in_array($mode, ['strict','hybrid','full'], true)) {
            $mode = 'strict';
        }

        if ($mode === 'strict') {
            return 0;
        }

        try {
            $collector = new Bulk_Asset_Collector([$this, 'log_public']);
            return (int) $collector->enqueue_for_mode($mode);
        } catch (\Throwable $e) {
            $this->log('Bulk asset enqueue failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Build a Cookie header for wp_remote_get() so front-end requests behave like a logged-in user.
     * We generate a short-lived cookie at request-time (do NOT store cookies in DB).
     */
    private function build_auth_cookie_header() {
        if ($this->export_user_id <= 0) return '';
        if (!defined('LOGGED_IN_COOKIE')) return '';
        if (!function_exists('wp_generate_auth_cookie')) return '';

        // Short-lived cookie (15 minutes) is enough for export batches.
        $exp = time() + 15 * MINUTE_IN_SECONDS;
        $cookie_val = wp_generate_auth_cookie($this->export_user_id, $exp, 'logged_in');
        if (!$cookie_val) return '';
        return LOGGED_IN_COOKIE . '=' . $cookie_val;
    }

    /**
     * Allow REST/UI to toggle grouped assets after construction.
     */
    public function set_save_assets_grouped($group_assets) {
        $this->group_assets = (bool) $group_assets;
    }

    /**
     * Extract the URL path relative to the WordPress install root.
     * Strips the home URL base path for subdirectory installs so that
     * /elementor/my-page/ becomes my-page (not elementor/my-page).
     */
    private function relative_url_path(string $url): string {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $home_path = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home_path !== '' && strpos($path, $home_path) === 0) {
            $path = trim(substr($path, strlen($home_path)), '/');
        }
        return $path;
    }


    /**
     * Save an exported HTML document using the URL path.
     * Example: https://site.com/about/ -> /wp-to-html-exports/about/index.html
     */
        private function save_html($url, $html) {

        $path = $this->relative_url_path($url);

        // 1) Forced single-page export: always write root /index.html
        if ($this->single_root_index) {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
            file_put_contents(WP_TO_HTML_EXPORT_DIR . '/index.html', $html);
            return;
        }

        // 2) Homepage -> export root index.html
        if ($path === '') {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
            file_put_contents(WP_TO_HTML_EXPORT_DIR . '/index.html', $html);
            return;
        }

        // 3) Optional: flatten first-level (parent) pages/posts into root as "slug.html"
        // Applies only to single-segment paths like "/about/" or "/my-post/"
        if ($this->root_parent_html && strpos($path, '/') === false) {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
            file_put_contents(WP_TO_HTML_EXPORT_DIR . '/' . $path . '.html', $html);
            return;
        }

        // 4) Default behavior: /path/index.html
        $dir = WP_TO_HTML_EXPORT_DIR . '/' . $path;
        wp_mkdir_p($dir);

        file_put_contents($dir . '/index.html', $html);
    }


    /**
     * Clear WP_TO_HTML_EXPORT_DIR contents (best-effort).
     * Runs in background to avoid REST timeouts.
     */
    private function clear_export_dir_background(): void {
        $dir = WP_TO_HTML_EXPORT_DIR;
        if (!is_dir($dir)) return;

        $files = @scandir($dir);
        if (!is_array($files)) return;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            // Preserve the log file so we can keep writing while cleaning.
            if ($file === 'export-log.txt') continue;
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_dir_recursive_background($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function delete_dir_recursive_background(string $dir): void {
        if (!file_exists($dir)) return;
        $files = @scandir($dir);
        if (!is_array($files)) return;
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->delete_dir_recursive_background($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function log($message) {

        $time = date('H:i:s');
        $line = "[$time] " . $message . PHP_EOL;

        // Ensure log file path exists
        $log_file = $this->log_file;
        $log_dir  = dirname($log_file);

        // 1️⃣ Create directory if missing
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir); // WordPress-safe directory creation
        }

        // 2️⃣ Create empty file if missing
        if (!file_exists($log_file)) {
            touch($log_file);
            @chmod($log_file, 0644);
        }

        // 3️⃣ Append safely
        file_put_contents($log_file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Public wrapper so REST/Core can write log lines.
     */
    public function log_public($message) {
        $this->log($message);
    }


    /**
     * Truncate the public log file for a fresh run.
     */
    public function reset_log_file() {
        $log_file = $this->log_file;
        $log_dir  = dirname($log_file);
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        file_put_contents($log_file, '');
        @chmod($log_file, 0644);
    }


    public function build_queue($args = []) {

        global $wpdb;
        $table_assets = $wpdb->prefix . 'wp_to_html_assets';
        $table = $wpdb->prefix . 'wp_to_html_queue';

        $wpdb->query("TRUNCATE TABLE $table");
        $wpdb->query("TRUNCATE TABLE $table_assets");
        if (!empty($wpdb->last_error)) {
            $this->log('DB error truncating queue table: ' . $wpdb->last_error);
        }

        $this->log('Building URL queue...');

        $full_site    = !empty($args['full_site']);
        $include_home = !isset($args['include_home']) ? true : (bool) $args['include_home'];
        $selected     = !empty($args['selected']) && is_array($args['selected']) ? $args['selected'] : [];
        $scope        = isset($args['scope']) ? (string) $args['scope'] : ($full_site ? 'full_site' : 'selected');

        $statuses = [];
        if (!empty($args['statuses']) && is_array($args['statuses'])) {
            $statuses = array_filter(array_map('trim', array_map('strval', $args['statuses'])));
        }
        if (empty($statuses)) $statuses = ['publish'];

        // Validate statuses to avoid WP_Query warnings
        $valid = array_keys(get_post_stati([], 'names'));
        $statuses = array_values(array_intersect($statuses, $valid));
        if (empty($statuses)) $statuses = ['publish'];

        // Reset phase markers so logs can report transitions cleanly for each run.
        update_option('wp_to_html_phase_urls_done_logged', 0, false);
        update_option('wp_to_html_phase_assets_done_logged', 0, false);
        update_option('wp_to_html_bulk_assets_enqueued', 0, false);

        // Reset throttled queue-build log markers
        update_option('wp_to_html_queue_build_last_logged', 0, false);
        update_option('wp_to_html_queue_build_last_phase', '', false);

        $urls = [];

        // For full site export we ALWAYS want the homepage exported as the root index.html.
        // (Even if the UI toggles include_home off.)
        if ($scope === 'full_site') {
            $include_home = true;
        }

        if ($include_home) {
            $urls[] = home_url('/');
        }

        if ($scope === 'full_site') {

            $post_types = get_post_types(['public' => true], 'names');

            $posts = get_posts([
                'post_type'   => $post_types,
                'post_status' => $statuses,
                'numberposts' => -1
            ]);

            foreach ($posts as $post) {
                $urls[] = get_permalink($post);
            }

        } elseif ($scope === 'all_posts' || $scope === 'all_pages') {

            if ($scope === 'all_pages') {
                $post_types = ['page'];
            } else {
                // All posts can optionally include multiple post types.
                $post_types = isset($args['post_types']) && is_array($args['post_types']) ? (array) $args['post_types'] : ['post'];
                $post_types = array_values(array_filter(array_map('sanitize_key', $post_types)));
                // Guardrails: never allow 'page' here.
                $post_types = array_values(array_diff($post_types, ['page', 'attachment']));
                if (empty($post_types)) $post_types = ['post'];
            }

            $posts = get_posts([
                'post_type'   => $post_types,
                'post_status' => $statuses,
                'numberposts' => -1,
            ]);

            foreach ($posts as $post) {
                $urls[] = get_permalink($post);
            }

        } else {

            // Selected posts/pages only
            foreach ($selected as $item) {
                $id = isset($item['id']) ? (int) $item['id'] : 0;
                if ($id <= 0) continue;

                $permalink = get_permalink($id);
                if ($permalink) {
                    $urls[] = $permalink;
                }
            }
        }

        // --- Smart URL discovery (taxonomy archives, post type archives, pagination, sitemap, etc.) ---
        // IMPORTANT: default URL discovery should ONLY run for full_site.
        // For all_posts/all_pages/custom/selected, discovery can enqueue author/taxonomy/pagination URLs
        // that are outside the intended scope.
        $enable_discovery = array_key_exists('enable_url_discovery', $args)
            ? (bool) $args['enable_url_discovery']
            : ($scope === 'full_site');

        if ($scope === 'full_site' && $enable_discovery) {
            try {
                $this->ensure_url_discovery_loaded();
                if (class_exists('\\WpToHtml\\UrlDiscovery\\Url_Discovery')) {
                    $discovery = new \WpToHtml\UrlDiscovery\Url_Discovery([$this, 'log_public']);
                    $found = $discovery->discover(is_array($args) ? $args : []);
                    if (is_array($found) && !empty($found)) {
                        $urls = array_merge($urls, $found);
                        $this->log('URL discovery: merged ' . count($found) . ' discovered URLs into queue');
                    }
                }
            } catch (\Throwable $e) {
                $this->log('URL discovery failed: ' . $e->getMessage());
            }
        }

        $urls = array_values(array_unique(array_filter($urls)));

        if (!$full_site && !$include_home && empty($urls)) {
            $this->log('No URLs selected. Aborting queue build.');
            return;
        }

        foreach ($urls as $url) {
            $wpdb->insert($table, [
                'url'    => $url,
                'status' => 'pending'
            ]);
        }

        $this->log('Queue built. Total URLs: ' . count($urls));
    }


    /**
     * Initialize a chunked queue build (background-safe).
     * This makes the REST /export request fast by moving heavy work into cron ticks.
     */
    public function init_queue_build(array $args, int $batch_size = 50): void {
        global $wpdb;
        $table_assets = $wpdb->prefix . 'wp_to_html_assets';
        $table_queue  = $wpdb->prefix . 'wp_to_html_queue';

        // Truncate tables quickly (still much cheaper than building the whole queue in-request)
        $wpdb->query("TRUNCATE TABLE {$table_queue}");
        $wpdb->query("TRUNCATE TABLE {$table_assets}");
        if (!empty($wpdb->last_error)) {
            $this->log('DB error truncating tables (init_queue_build): ' . $wpdb->last_error);
        }

        // Reset phase markers so logs can report transitions cleanly for each run.
        update_option('wp_to_html_phase_urls_done_logged', 0, false);
        update_option('wp_to_html_phase_assets_done_logged', 0, false);
        update_option('wp_to_html_bulk_assets_enqueued', 0, false);

        // Normalize 'custom' scope alias to 'selected' in args before persisting,
        // so background build_queue_tick() always reads a canonical scope value.
        if (isset($args['scope']) && $args['scope'] === 'custom') {
            $args['scope'] = 'selected';
        }

        // Persist a small state blob used by build_queue_tick()
        $state = [
            'started_at'         => current_time('mysql'),
            'batch_size'         => max(1, (int)$batch_size),
            'args'               => $args,
            'phase'              => 'home', // home -> posts -> discovery -> discovery_insert -> done
            'include_home_done'  => 0,
            'last_post_id'       => 0,
            'inserted'           => 0,
            'selected_left'      => [],
            'discovered_left'    => [],
            'discovery_done'     => 0,
            'cleanup_done'       => 0,
        ];

        // Normalize selected list for chunking (IDs only) — usually small but keep consistent
        $scope = isset($args['scope']) ? (string)$args['scope'] : (!empty($args['full_site']) ? 'full_site' : 'selected');

        // Map UI scope 'custom' to backend 'selected'
        if ($scope === 'custom') { $scope = 'selected'; }
        if ($scope === 'selected' && !empty($args['selected']) && is_array($args['selected'])) {
            $ids = [];
            foreach ($args['selected'] as $it) {
                if (is_array($it)) {
                    if (isset($it['id'])) $ids[] = (int)$it['id'];
                    elseif (isset($it['ID'])) $ids[] = (int)$it['ID'];
                    elseif (!empty($it['url']) && is_string($it['url'])) {
                        // URL-only item: resolve to post ID.
                        $maybe = url_to_postid($it['url']);
                        if ($maybe > 0) $ids[] = (int)$maybe;
                    }
                } elseif (is_numeric($it)) {
                    $ids[] = (int)$it;
                }
            }
            $state['selected_left'] = array_values(array_unique(array_filter($ids)));
        }

        update_option('wp_to_html_queue_build_state', $state, false);

        // Lightweight progress log for the admin UI.
        // Throttle to avoid huge logs: log when inserted count changes by >= 50 or on phase transitions.
        try {
            $last_logged = (int) get_option('wp_to_html_queue_build_last_logged', 0);
            $ins = (int) ($state['inserted'] ?? 0);
            $ph  = (string) ($state['phase'] ?? '');
            $last_phase = (string) get_option('wp_to_html_queue_build_last_phase', '');

            if ($ins - $last_logged >= $batch_size || $ph !== $last_phase) {
                if (WP_TO_HTML_DEBUG) {
                    $this->log('Queue build tick: phase=' . $ph . ' inserted=' . $ins);
                }

                update_option('wp_to_html_queue_build_last_logged', $ins, false);
                update_option('wp_to_html_queue_build_last_phase', $ph, false);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        // Ensure we don't accumulate multiple queue-build events.
        wp_clear_scheduled_hook('wp_to_html_build_queue_event');

        // Clear stale process events from previous runs so they can't fire
        // before the queue build completes and prematurely finish the export.
        wp_clear_scheduled_hook('wp_to_html_process_event');

        if (WP_TO_HTML_DEBUG) {
            $this->log('Queue build initialized (chunked). batch_size=' . (int)$state['batch_size']);
        }
        
    }


    /**
     * Build the URL queue in small chunks.
     * Call this from a cron hook (wp_to_html_build_queue_event).
     */
    public function build_queue_tick(int $batch_size = 50): void {
        global $wpdb;
        $table_queue = $wpdb->prefix . 'wp_to_html_queue';

        $state = get_option('wp_to_html_queue_build_state', []);
        if (!is_array($state) || empty($state['args']) || empty($state['phase'])) {
            return;
        }

        $args  = is_array($state['args']) ? $state['args'] : [];
        $scope = isset($args['scope']) ? (string)$args['scope'] : (!empty($args['full_site']) ? 'full_site' : 'selected');
        $statuses = [];
        if (!empty($args['statuses']) && is_array($args['statuses'])) {
            $statuses = array_values(array_filter(array_map('strval', $args['statuses'])));
        }
        if (empty($statuses)) $statuses = ['publish'];

        // Batch size: prefer stored, but allow override.
        $bs = (int)($state['batch_size'] ?? 0);
        if ($bs <= 0) $bs = (int)$batch_size;
        if ($bs <= 0) $bs = 50;

        // Perform export directory cleanup ONCE in background (so /export can return fast)
        if (empty($state['cleanup_done'])) {
            $pending_cleanup = (int) get_option('wp_to_html_pending_cleanup', 0);
            if ($pending_cleanup === 1) {
                // Clear previous run outputs in the background (can be slow on some hosts).
                $this->clear_export_dir_background();

                // Clear previous run's zip info so UI doesn't show old download link.
                delete_option('wp_to_html_last_zip');
                update_option('wp_to_html_pending_cleanup', 0, false);
            }
            $state['cleanup_done'] = 1;
        }

        $urls_to_insert = [];

        // Phase: include home page once (forced for full_site)
        if ($state['phase'] === 'home') {
            $include_home = !isset($args['include_home']) ? true : (bool)$args['include_home'];
            if ($scope === 'full_site') {
                $include_home = true;
            }
            if ($include_home && empty($state['include_home_done'])) {
                $urls_to_insert[] = home_url('/');
                $state['include_home_done'] = 1;
                $state['inserted'] = (int)$state['inserted'] + 1;
            }
            $state['phase'] = 'posts';
        }

        // Phase: selected IDs
        if ($state['phase'] === 'posts' && $scope === 'selected') {
            $left = isset($state['selected_left']) && is_array($state['selected_left']) ? $state['selected_left'] : [];
            $take = array_splice($left, 0, $bs);
            foreach ($take as $pid) {
                $u = get_permalink((int)$pid);
                if ($u) $urls_to_insert[] = $u;
            }
            $state['selected_left'] = $left;

            if (empty($left)) {
                $state['phase'] = 'discovery';
            }
        }

        // Phase: scope-based post/page/full_site build via keyset pagination (50 per tick)
        if ($state['phase'] === 'posts' && $scope !== 'selected') {
            $last_id = (int)($state['last_post_id'] ?? 0);
            $limit = $bs;

            $post_types = [];
            if ($scope === 'all_pages') {
                $post_types = ['page'];
            } elseif ($scope === 'all_posts') {
                // Allow restricting to specific post types from the UI.
                $post_types = isset($args['post_types']) && is_array($args['post_types']) ? (array) $args['post_types'] : ['post'];
                $post_types = array_values(array_filter(array_map('sanitize_key', $post_types)));
                $post_types = array_values(array_diff($post_types, ['page', 'attachment']));
                if (empty($post_types)) $post_types = ['post'];
            } else {
                $post_types = get_post_types(['public' => true], 'names');
                if (!is_array($post_types)) $post_types = ['post','page'];
            }
            $post_types = array_values(array_filter(array_map('sanitize_key', (array)$post_types)));
            if (empty($post_types)) $post_types = ['post','page'];

            // Build SQL safely
            $pt_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $st_placeholders = implode(',', array_fill(0, count($statuses), '%s'));

            $sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ({$st_placeholders}) AND post_type IN ({$pt_placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d";
            $prepare_args = array_merge($statuses, $post_types, [$last_id, $limit]);
            $ids = $wpdb->get_col($wpdb->prepare($sql, ...$prepare_args));
            if (!is_array($ids)) $ids = [];

            foreach ($ids as $pid) {
                $u = get_permalink((int)$pid);
                if ($u) $urls_to_insert[] = $u;
            }

            if (!empty($ids)) {
                $state['last_post_id'] = (int) end($ids);
            }

            // If no more posts, move to discovery
            if (empty($ids)) {
                $state['phase'] = 'discovery';
            }
        }

        // Phase: discovery (compute once, then insert in chunks)
        if ($scope === 'full_site' && $state['phase'] === 'discovery') {
            $enable_discovery = array_key_exists('enable_url_discovery', $args) ? (bool) $args['enable_url_discovery'] : true;
            if ($scope !== 'selected' && $enable_discovery && empty($state['discovery_done'])) {
                try {
                    $this->ensure_url_discovery_loaded();
                    if (class_exists('\\WpToHtml\\UrlDiscovery\\Url_Discovery')) {
                        $discovery = new \WpToHtml\UrlDiscovery\Url_Discovery([$this, 'log_public']);
                        $found = $discovery->discover($args);
                        if (is_array($found) && !empty($found)) {
                            $found = array_values(array_unique(array_filter(array_map('esc_url_raw', $found))));
                            $state['discovered_left'] = $found;
                            $this->log('URL discovery: prepared ' . count($found) . ' discovered URLs for chunked insert');
                        }
                    }
                } catch (\Throwable $e) {
                    $this->log('URL discovery failed: ' . $e->getMessage());
                }
                $state['discovery_done'] = 1;
            }
            $state['phase'] = 'discovery_insert';
        }

        // Non-full_site scopes (selected, all_posts, all_pages) don't need URL discovery.
        // Skip straight to 'done' so the queue build can finalize.
        if ($state['phase'] === 'discovery' && $scope !== 'full_site') {
            $state['phase'] = 'done';
        }

        if ($state['phase'] === 'discovery_insert') {
            $left = isset($state['discovered_left']) && is_array($state['discovered_left']) ? $state['discovered_left'] : [];
            if (!empty($left)) {
                $take = array_splice($left, 0, $bs);
                foreach ($take as $u) {
                    if ($u) $urls_to_insert[] = $u;
                }
                $state['discovered_left'] = $left;
            }
            if (empty($left)) {
                $state['phase'] = 'done';
            }
        }

        // Insert (dedupe in-memory for this tick). DB-level dedupe is handled by unique constraints if present.
        $urls_to_insert = array_values(array_unique(array_filter($urls_to_insert)));
        $actually_inserted = 0;
        foreach ($urls_to_insert as $u) {
            $result = $wpdb->insert($table_queue, ['url' => $u, 'status' => 'pending']);
            if ($result === false) {
                $this->log('DB insert failed for URL: ' . esc_url_raw($u) . ' — ' . $wpdb->last_error);
            } else {
                $actually_inserted++;
            }
        }
        $state['inserted'] = (int)$state['inserted'] + $actually_inserted;

        update_option('wp_to_html_queue_build_state', $state, false);

        if ($state['phase'] !== 'done') {
            // Schedule the next queue-build tick.
            $next = time() + 2;
            if (!wp_next_scheduled('wp_to_html_build_queue_event')) {
                wp_schedule_single_event($next, 'wp_to_html_build_queue_event');
            }
            return;
        }

        // Finalize: update totals and start processing.
        $total_urls = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_queue}");

        // Guard: if queue is empty, log a clear warning so the user/admin can diagnose.
        if ($total_urls === 0) {
            $this->log('Warning: Queue build completed with 0 URLs. Check that: (1) scope is correct, (2) selected items are valid, (3) the site has published content, and (4) DB table permissions are OK.');
        }

        $status_table = $wpdb->prefix . 'wp_to_html_status';
        $wpdb->update($status_table, [
            'state'      => 'running',
            'is_running' => 1,
            'total_urls' => $total_urls,
        ], ['id' => 1]);

        if (WP_TO_HTML_DEBUG) {
            $this->log('Queue built (chunked). Total URLs: ' . $total_urls);
        }

        delete_option('wp_to_html_queue_build_state');

        // Kick off exporter processing.
        wp_schedule_single_event(time() + 1, 'wp_to_html_process_event');
    }

    /**
     * Load URL discovery classes + crawlers shipped in includes/url-discovery.
     * This keeps the plugin compatible even without Composer/autoload.
     */
    private function ensure_url_discovery_loaded(): void {
        static $loaded = false;
        if ($loaded) return;

        $base = trailingslashit(WP_TO_HTML_PATH) . 'includes/url-discovery/';

        $files = [
            $base . 'interface-wp-to-html-url-crawler.php',
            $base . 'class-wp-to-html-url-rules.php',
            $base . 'crawlers/class-wp-to-html-sitemap-crawler.php',
            $base . 'crawlers/class-wp-to-html-taxonomy-crawler.php',
            $base . 'crawlers/class-wp-to-html-author-crawler.php',
            $base . 'crawlers/class-wp-to-html-post-type-archive-crawler.php',
            $base . 'crawlers/class-wp-to-html-date-archive-crawler.php',
            $base . 'crawlers/class-wp-to-html-pagination-crawler.php',
            $base . 'crawlers/class-wp-to-html-rss-crawler.php',
            $base . 'crawlers/class-wp-to-html-rest-api-crawler.php',
            $base . 'class-wp-to-html-url-discovery.php',
        ];

        foreach ($files as $f) {
            if (file_exists($f)) {
                require_once $f;
            }
        }

        $loaded = true;
    }

    public function process_batch($limit = 20) {

        global $wpdb;
        $table = $wpdb->prefix . 'wp_to_html_queue';

        // Respect backoff: only pick rows whose next_attempt_at is due.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP()) ORDER BY id ASC LIMIT %d",
                $limit
            )
        );

        if (!$rows) return false;

        $max_retries = (int) apply_filters('wp_to_html_url_max_retries', 3);
        $base_backoff = (int) apply_filters('wp_to_html_url_retry_backoff_base_seconds', 5);
        $cap_backoff  = (int) apply_filters('wp_to_html_url_retry_backoff_cap_seconds', 120);

        foreach ($rows as $row) {

            if (Advanced_Debugger::enabled()) {
                Advanced_Debugger::mark('url_pick', [
                    'queue_id' => (int) $row->id,
                    'url'      => (string) $row->url,
                ]);
            }

            // Mark as processing (helps diagnostics / avoids duplicate picks)
            $wpdb->update($table, ['status' => 'processing', 'started_at' => current_time('mysql')], ['id' => $row->id]);

            $result = $this->export_single_url($row->url);
            $ok = is_array($result) ? !empty($result['ok']) : (bool) $result;
            $err = is_array($result) && !empty($result['error']) ? (string) $result['error'] : '';

            if ($ok) {
                $wpdb->update(
                    $table,
                    [
                        'status'          => 'done',
                        'last_error'      => null,
                        'last_attempt_at' => current_time('mysql'),
                        'next_attempt_at' => null,
                    ],
                    ['id' => (int) $row->id]
                );
                $this->increment_status_col('processed_urls');
            } else {
                $retry = isset($row->retry_count) ? (int) $row->retry_count : 0;
                $retry++;

                // Exponential backoff: base * 2^(retry-1)
                $delay = (int) ($base_backoff * pow(2, max(0, $retry - 1)));
                if ($delay > $cap_backoff) $delay = $cap_backoff;
                $next_attempt = gmdate('Y-m-d H:i:s', time() + $delay);

                // Treat $max_retries as TOTAL attempts, not retries-after-first.
                // Example: max_retries=3 => attempt #1, #2, #3 then stop.
                if ($retry < $max_retries) {
                    $wpdb->update(
                        $table,
                        [
                            'status'          => 'pending',
                            'retry_count'     => $retry,
                            'last_error'      => $err !== '' ? $err : 'unknown_error',
                            'last_attempt_at' => current_time('mysql'),
                            'next_attempt_at' => $next_attempt,
                        ],
                        ['id' => (int) $row->id]
                    );
                    $this->log('Retry scheduled (attempt ' . $retry . '/' . $max_retries . ', +' . $delay . 's): ' . (string)$row->url);
                } else {
                    $wpdb->update(
                        $table,
                        [
                            'status'          => 'failed',
                            'retry_count'     => $retry,
                            'last_error'      => $err !== '' ? $err : 'unknown_error',
                            'last_attempt_at' => current_time('mysql'),
                            'next_attempt_at' => null,
                        ],
                        ['id' => (int) $row->id]
                    );
                    $this->log('URL failed permanently (attempt ' . $retry . '): ' . (string)$row->url);
                    $this->increment_status_col('failed_urls');
                }
            }
        }

        return true;
    }

    private function export_single_url($url) {

        // Normalize URL (some sites store/emit encoded forms like %3D)
        $url = is_string($url) ? trim($url) : '';
        if ($url === '') {
            return ['ok' => false, 'error' => 'empty_url'];
        }

        $decoded_url = urldecode($url);

        // Skip URLs that are not meaningful for static export (feeds, logout, add-to-cart, nonce URLs, REST, etc.)
        if ($this->should_skip_url($decoded_url)) {
            $this->log('Skipping URL (not needed for static export): ' . $url);
            return ['ok' => true];
        }

        $this->log('Exporting: ' . $url);

        if (Advanced_Debugger::enabled()) {
            Advanced_Debugger::mark('export_url_start', [
                'url' => (string) $url,
            ]);
        }

        // --- 1) Fetch HTML (HTTP first, then server-side fallback) ---
        $html = '';

        $cookie_header = $this->build_auth_cookie_header();

        $headers = [
            'User-Agent' => 'WP to HTML Exporter',
        ];
        if ($cookie_header !== '') {
            $headers['Cookie'] = $cookie_header;
        }

        // Keep per-URL fetch timeout configurable; huge sites with heavy pages can
        // hang PHP-FPM workers if this is too high.
        $timeout_s = (int) apply_filters('wp_to_html_http_fetch_timeout_seconds', 15, $url);
        $timeout_s = max(5, min(60, $timeout_s));

        $response = wp_remote_get($url, [
            'timeout'     => $timeout_s,
            'redirection' => 10,
            'sslverify'   => false,
            'headers'     => $headers,
        ]);

        $use_fallback = false;

        if (is_wp_error($response)) {
            $this->log('HTTP fetch failed: ' . $url . ' — ' . $response->get_error_message());
            $use_fallback = true;
        } else {
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                $this->log('HTTP fetch failed: ' . $url . ' — HTTP ' . $code);
                $use_fallback = true;
            } else {
                $html = (string) wp_remote_retrieve_body($response);
                if ($html === '') {
                    $this->log('HTTP fetch failed: empty HTML — ' . $url);
                    $use_fallback = true;
                }
            }
        }

        if ($use_fallback) {
            $fallback_html = $this->try_server_side_render($url);
            if (is_string($fallback_html) && $fallback_html !== '') {
                $this->log('Using server-side render fallback: ' . $url);
                $html = $fallback_html;

                if (Advanced_Debugger::enabled()) {
                    Advanced_Debugger::mark('export_url_fallback_used', [
                        'url' => (string) $url,
                    ]);
                }
            } else {
                $this->log('Failed: no HTML available (HTTP + fallback) — ' . $url);

                if (Advanced_Debugger::enabled()) {
                    Advanced_Debugger::mark('export_url_failed', [
                        'url' => (string) $url,
                        'reason' => 'no_html_http_and_fallback',
                    ]);
                }
                return ['ok' => false, 'error' => 'no_html_http_and_fallback'];
            }
        }

        // --- 2) Extract assets using DOM + CSS url(...) (better than regex) ---
        $assets = [];

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();

        // Ensure encoding safety
        $html_for_dom = $html;
        if (stripos($html_for_dom, '<meta charset=') === false) {
            $html_for_dom = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html_for_dom;
        }

        $dom->loadHTML($html_for_dom, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Helper: normalize URLs to absolute, same-origin only
        $normalize = function ($raw) use ($url) {
            if (!is_string($raw)) return null;

            $raw = html_entity_decode(trim($raw), ENT_QUOTES);
            if ($raw === '' || $raw[0] === '#') return null;
            if (stripos($raw, 'data:') === 0) return null;
            if (stripos($raw, 'mailto:') === 0) return null;
            if (stripos($raw, 'tel:') === 0) return null;
            if (stripos($raw, 'javascript:') === 0) return null;

            // protocol-relative
            if (strpos($raw, '//') === 0) {
                $raw = (is_ssl() ? 'https:' : 'http:') . $raw;
            }

            // Absolute http(s)
            if (preg_match('~^https?://~i', $raw)) {
                return $raw;
            }

            // Root-relative
            if (strpos($raw, '/') === 0) {
                return home_url($raw);
            }

            // Relative to page URL
            return rtrim($url, '/') . '/' . ltrim($raw, '/');
        };

        $add_asset = function (&$assets, $asset_url) {
            if (!is_string($asset_url) || $asset_url === '') return;

            // Only keep same-origin
            if (strpos($asset_url, home_url()) !== 0) return;

            // Avoid directory URLs
            $p = wp_parse_url($asset_url);
            if (!empty($p['path']) && substr($p['path'], -1) === '/') return;

            $assets[] = $asset_url;
        };

        // 2.1 src/href for common nodes (img, script, link, source, video, audio)
        foreach ($xpath->query('//*[@src]') as $node) {
            $u = $normalize($node->getAttribute('src'));
            if ($u) $add_asset($assets, $u);
        }

        foreach ($xpath->query('//*[@href]') as $node) {
            $u = $normalize($node->getAttribute('href'));
            if ($u) $add_asset($assets, $u);
        }

        // 2.2 Lazy-load attributes often used by themes/plugins
        foreach ($xpath->query('//img') as $img) {
            foreach (['data-src', 'data-lazyload', 'data-original', 'data-srcset'] as $attr) {
                $v = $img->getAttribute($attr);
                $u = $normalize($v);
                if ($u) $add_asset($assets, $u);
            }
        }

        // 2.3 srcset parsing (important)
        foreach ($xpath->query('//*[@srcset]') as $node) {
            $srcset = $node->getAttribute('srcset');
            if (!is_string($srcset) || trim($srcset) === '') continue;

            foreach (explode(',', $srcset) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') continue;

                // first token is URL
                $parts = preg_split('/\s+/', $candidate);
                $u = $normalize($parts[0] ?? '');
                if ($u) $add_asset($assets, $u);
            }
        }

        // 2.4 Inline <style> blocks: url(...)
        foreach ($xpath->query('//style') as $styleNode) {
            $css = (string) $styleNode->textContent;
            foreach ($this->extract_css_urls($css, $url) as $cssUrl) {
                $add_asset($assets, $cssUrl);
            }
        }

        // 2.5 style="" attributes: url(...)
        foreach ($xpath->query('//*[@style]') as $node) {
            $css = (string) $node->getAttribute('style');
            foreach ($this->extract_css_urls($css, $url) as $cssUrl) {
                $add_asset($assets, $cssUrl);
            }
        }

        // Deduplicate
        $assets = array_values(array_unique($assets));

        // --- 3) Insert assets into queue table (INSERT IGNORE) ---
        global $wpdb;
        $assets_table = $wpdb->prefix . 'wp_to_html_assets';

        foreach ($assets as $asset_url) {
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$assets_table} (url, status)
                    VALUES (%s, %s)",
                    $asset_url,
                    'pending'
                )
            );
        }

        // --- 4) Optional: rewrite HTML to offline-friendly paths before saving ---
        // Minimal safe rewrite: remove absolute domain for same-origin links.
        // If your export is hosted at domain root, this works well.
        $html = str_replace(home_url(), '', $html);
        
        $html = $this->rewrite_html_with_dot_path($url, $html);

        // If grouped assets are enabled, copy referenced assets into ./images, ./css, ...
        // next to the exported HTML file and rewrite paths accordingly.
        if (!empty($this->group_assets)) {
            $page_dir = $this->get_page_output_dir($url);
            $asset_manager = new Asset_Manager(true);
            $html = $asset_manager->process($html, $page_dir);
        }
        // --- 5) Save HTML ---
        $this->save_html($url, $html);
        $this->log('Saved HTML for: ' . $url . ' (assets queued: ' . count($assets) . ')');

        return ['ok' => true];
    }

    /**
     * Decide whether a URL should be skipped for static export.
     *
     * This is a safety net because some URL sources (sitemaps, internal links, plugins)
     * can include endpoints that are not real static pages (feeds, actions, logout, etc.).
     *
     * Developers can override via the `wp_to_html_should_skip_url` filter.
     */
    private function should_skip_url($url) {

        $url = is_string($url) ? trim($url) : '';
        if ($url === '') return true;

        // Must be same host
        $home = home_url('/');
        $home_host = wp_parse_url($home, PHP_URL_HOST);
        $url_host  = wp_parse_url($url, PHP_URL_HOST);
        if ($url_host && $home_host && strcasecmp($url_host, $home_host) !== 0) {
            return true;
        }

        $path  = (string) wp_parse_url($url, PHP_URL_PATH);
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);

        // Common feed endpoints
        if (preg_match('~/(feed|comments/feed)/?$~i', $path)) return true;
        if (preg_match('~/(feed)/.+$~i', $path)) return true;

        // WP REST API (not HTML pages)
        if (stripos($path, '/wp-json/') !== false) return true;

        // Auth / session / actions
        if (stripos($path, '/wp-login.php') !== false && stripos($query, 'action=logout') !== false) return true;
        if (stripos($path, 'customer-logout') !== false) return true;
        if (stripos($path, 'logout') !== false && stripos($path, 'my-account') !== false) return true;

        // WooCommerce / dynamic endpoints
        if (stripos($query, 'add-to-cart=') !== false) return true;
        if (stripos($query, 'wc-ajax=') !== false) return true;

        // Nonce / preview / transient-style URLs
        if (stripos($query, '_wpnonce=') !== false) return true;
        if (stripos($query, 'preview=true') !== false) return true;
        if (stripos($query, 'customize_changeset_uuid=') !== false) return true;

        // REST route via query
        if (stripos($query, 'rest_route=') !== false) return true;

        // Allow developers to override
        $skip = apply_filters('wp_to_html_should_skip_url', false, $url, $path, $query);
        return (bool) $skip;
    }

    /**
     * Compute the absolute directory where the exported HTML file for $url is written.
     * Mirrors save_html() routing rules.
     */
    private function get_page_output_dir($url) {

        $path = $this->relative_url_path($url);

        // 1) Forced single-page export: always root
        if ($this->single_root_index) {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
            return rtrim(WP_TO_HTML_EXPORT_DIR, '/\\');
        }

        // 2) Homepage -> export root
        if ($path === '') {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
            return rtrim(WP_TO_HTML_EXPORT_DIR, '/\\');
        }

        // 3) Optional: flatten first-level pages/posts into root as "slug.html"
        if ($this->root_parent_html && strpos($path, '/') === false) {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
            return rtrim(WP_TO_HTML_EXPORT_DIR, '/\\');
        }

        // 4) Default: /path/index.html
        $dir = rtrim(WP_TO_HTML_EXPORT_DIR, '/\\') . '/' . $path;
        wp_mkdir_p($dir);
        return rtrim($dir, '/\\');
    }

    /**
     * Extract url(...) items from CSS snippets.
     * Returns absolute URLs (normalized) where possible.
     */
    private function extract_css_urls($css, $base_url) {

        if (!is_string($css) || $css === '') return [];

        preg_match_all('~url\(([^)]+)\)~i', $css, $m);
        $out = [];

        foreach (($m[1] ?? []) as $raw) {

            $raw = trim($raw, " \t\n\r\0\x0B\"'");
            if ($raw === '' || stripos($raw, 'data:') === 0) continue;

            // protocol-relative
            if (strpos($raw, '//') === 0) {
                $raw = (is_ssl() ? 'https:' : 'http:') . $raw;
            }

            // absolute
            if (preg_match('~^https?://~i', $raw)) {
                $out[] = $raw;
                continue;
            }

            // root-relative
            if (strpos($raw, '/') === 0) {
                $out[] = home_url($raw);
                continue;
            }

            // relative to base page url
            $out[] = rtrim($base_url, '/') . '/' . ltrim($raw, '/');
        }

        // keep only same-origin
        $out = array_values(array_filter($out, function ($u) {
            return is_string($u) && strpos($u, home_url()) === 0;
        }));

        return array_values(array_unique($out));
    }

    
/**
 * Atomically increment a counter column in the status table.
 * Called right after each URL/asset reaches a terminal state so the DB
 * always reflects current progress even mid-tick.
 */
private function increment_status_col(string $col) {
    global $wpdb;
    $table = $wpdb->prefix . 'wp_to_html_status';
    $wpdb->query("UPDATE `{$table}` SET `{$col}` = `{$col}` + 1, updated_at = NOW() WHERE id = 1");
}

public function process_asset_batch($limit = 50) {

    global $wpdb;
    $table = $wpdb->prefix . 'wp_to_html_assets';

    // Respect backoff and avoid re-picking items too early.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status='pending' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP()) ORDER BY id ASC LIMIT %d",
            $limit
        )
    );

    if (!$rows) return false;

    $max_retries  = (int) apply_filters('wp_to_html_asset_max_retries', 3);
    $base_backoff = (int) apply_filters('wp_to_html_asset_retry_backoff_base_seconds', 5);
    $cap_backoff  = (int) apply_filters('wp_to_html_asset_retry_backoff_cap_seconds', 180);

    foreach ($rows as $row) {

        if (Advanced_Debugger::enabled()) {
            Advanced_Debugger::mark('asset_pick', [
                'asset_id' => (int) $row->id,
                'url'      => (string) $row->url,
            ]);
        }

        // Mark processing with timestamps so watchdog can reclaim stuck items.
        $wpdb->update(
            $table,
            [
                'status'          => 'processing',
                'started_at'      => current_time('mysql'),
                'last_attempt_at' => current_time('mysql'),
            ],
            ['id' => (int) $row->id]
        );

        $result = $this->download_asset((string) $row->url);
        $ok  = is_array($result) ? !empty($result['ok']) : (bool) $result;
        $err = is_array($result) && !empty($result['error']) ? (string) $result['error'] : '';
        $permanent = is_array($result) && !empty($result['permanent']);
        $http_code = is_array($result) && isset($result['http_code']) ? (int) $result['http_code'] : 0;

        /* Defensive: if permanent flag was not propagated, infer from error string. */
        if (!$permanent && $http_code >= 400 && $http_code < 500 && $http_code !== 408 && $http_code !== 429) {
            $permanent = true;
            if ($err === '') { $err = 'http_status:' . $http_code; }
        }

        if (!$permanent && $err !== '' && preg_match('/http_status:(\d+)/', $err, $m)) {
            $code = (int) $m[1];
            if ($code >= 400 && $code < 500 && $code !== 408 && $code !== 429) {
                $permanent = true;
            }
        }

        if ($ok) {
            $wpdb->update(
                $table,
                [
                    'status'          => 'done',
                    'retry_count'     => (int) ($row->retry_count ?? 0),
                    'last_error'      => null,
                    'started_at'      => null,
                    'last_attempt_at' => current_time('mysql'),
                    'next_attempt_at' => null,
                ],
                ['id' => (int) $row->id]
            );
            $this->increment_status_col('processed_assets');
        } else {
            $retry = isset($row->retry_count) ? (int) $row->retry_count : 0;
            $retry++;

            // Certain failures should be skipped immediately (e.g., hard 4xx like 404/410).
            // This prevents long backoff loops on missing assets.
            if ($permanent) {
                $wpdb->update(
                    $table,
                    [
                        'status'          => 'failed',
                        'retry_count'     => $retry,
                        'last_error'      => $err !== '' ? $err : 'permanent_error',
                        'started_at'      => null,
                        'last_attempt_at' => current_time('mysql'),
                        'next_attempt_at' => null,
                    ],
                    ['id' => (int) $row->id]
                );
                $this->log('Asset skipped (permanent): ' . (string)$row->url . ($err ? ' — ' . $err : ''));
                $this->increment_status_col('failed_assets');
                continue;
            }

            // Exponential backoff: base * 2^(retry-1)
            $delay = (int) ($base_backoff * pow(2, max(0, $retry - 1)));
            if ($delay > $cap_backoff) $delay = $cap_backoff;
            $next_attempt = gmdate('Y-m-d H:i:s', time() + $delay);

            // Treat $max_retries as TOTAL attempts, not retries-after-first.
            // Example: max_retries=3 => attempt #1, #2, #3 then stop.
            if ($retry < $max_retries) {
                $wpdb->update(
                    $table,
                    [
                        'status'          => 'pending',
                        'retry_count'     => $retry,
                        'last_error'      => $err !== '' ? $err : 'unknown_error',
                        'started_at'      => null,
                        'last_attempt_at' => current_time('mysql'),
                        'next_attempt_at' => $next_attempt,
                    ],
                    ['id' => (int) $row->id]
                );
                $this->log('Asset retry scheduled (attempt ' . $retry . '/' . $max_retries . ', +' . $delay . 's): ' . (string)$row->url);
            } else {
                $wpdb->update(
                    $table,
                    [
                        'status'          => 'failed',
                        'retry_count'     => $retry,
                        'last_error'      => $err !== '' ? $err : 'unknown_error',
                        'started_at'      => null,
                        'last_attempt_at' => current_time('mysql'),
                        'next_attempt_at' => null,
                    ],
                    ['id' => (int) $row->id]
                );
                $this->log('Asset failed permanently (attempt ' . $retry . '): ' . (string)$row->url);
                $this->increment_status_col('failed_assets');
            }
        }
    }

    return true;
}


private function download_asset($url) {

    if (!is_string($url) || $url === '') return ['ok' => false, 'error' => 'empty_url'];

    if (Advanced_Debugger::enabled()) {
        Advanced_Debugger::mark('download_asset_start', [
            'url' => (string) $url,
        ]);
    }

    $parsed = wp_parse_url($url);
    if (empty($parsed['path'])) return ['ok' => false, 'error' => 'no_path'];

    $path = (string) $parsed['path'];

    // block directories
    if (substr($path, -1) === '/') {
        $this->log('Skip directory asset: ' . $url);
        return ['ok' => false, 'error' => 'directory_path'];
    }

    // Decide where to write the asset.
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!empty($this->group_assets)) {
        switch ($ext) {
            case 'jpg': case 'jpeg': case 'png': case 'gif': case 'webp': case 'svg':
                $folder = 'images';
                break;
            case 'css':
                $folder = 'css';
                break;
            case 'js':
                $folder = 'js';
                break;
            case 'mp4': case 'webm':
                $folder = 'videos';
                break;
            case 'mp3': case 'wav':
                $folder = 'audios';
                break;
            default:
                $folder = 'assets';
        }
        $target = rtrim(WP_TO_HTML_EXPORT_DIR, '/\\') . '/' . $folder . '/' . basename($path);
    } else {
        $target = WP_TO_HTML_EXPORT_DIR . $path;
    }

    $dir_ok = wp_mkdir_p(dirname($target));
    if (!$dir_ok && !is_dir(dirname($target))) {
        $this->log('mkdir failed for: ' . dirname($target));
        return ['ok' => false, 'error' => 'mkdir_failed'];
    }

    if (Advanced_Debugger::enabled()) {
        Advanced_Debugger::mark('download_asset_target', [
            'url'      => (string) $url,
            'target'   => (string) $target,
            'mkdir_ok' => (bool) $dir_ok,
        ]);
    }

    // Try local filesystem first
    $source = ABSPATH . ltrim($path, '/');
    if (file_exists($source) && is_file($source)) {
        if (@copy($source, $target)) {
            if (Advanced_Debugger::enabled()) {
                Advanced_Debugger::mark('download_asset_done', [
                    'url'    => (string) $url,
                    'method' => 'copy',
                ]);
            }
            return ['ok' => true];
        }
        $this->log('Copy failed: ' . $source . ' -> ' . $target);
        if (Advanced_Debugger::enabled()) {
            Advanced_Debugger::mark('download_asset_failed', [
                'url'    => (string) $url,
                'reason' => 'copy_failed',
                'source' => (string) $source,
                'target' => (string) $target,
            ]);
        }
        return ['ok' => false, 'error' => 'copy_failed'];
    }

    // Fallback: HTTP fetch (streaming to disk to avoid memory spikes)
    $tmp = $target . '.part';
    @unlink($tmp);

    $res = wp_remote_get($url, [
        'timeout'     => (int) apply_filters('wp_to_html_asset_http_timeout_seconds', 20),
        'redirection' => 5,
        'sslverify'   => false,
        'stream'      => true,
        'filename'    => $tmp,
        'headers'     => [
            'User-Agent' => 'WP to HTML Exporter',
        ],
    ]);

    if (is_wp_error($res)) {
        $msg = $res->get_error_message();
        $this->log('HTTP fetch failed: ' . $url . ' — ' . $msg);
        @unlink($tmp);
        if (Advanced_Debugger::enabled()) {
            Advanced_Debugger::mark('download_asset_failed', [
                'url'    => (string) $url,
                'reason' => 'http_error',
                'message'=> (string) $msg,
            ]);
        }
        return ['ok' => false, 'error' => 'http_error:' . $msg, 'http_code' => 0];
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) {
        $this->log('HTTP fetch failed: ' . $url . ' — HTTP ' . $code);
        @unlink($tmp);
        // Mark most 4xx as permanent (except 408/429 which can be transient).
        $permanent = false;
        if ($code >= 400 && $code < 500 && $code !== 408 && $code !== 429) {
            $permanent = true;
        }
        return ['ok' => false, 'error' => 'http_status:' . $code, 'permanent' => $permanent, 'http_code' => $code];
    }

    if (!file_exists($tmp) || filesize($tmp) === 0) {
        $this->log('Empty asset file: ' . $url);
        @unlink($tmp);
        return ['ok' => false, 'error' => 'empty_body'];
    }

    // Move temp file into place atomically.
    if (!@rename($tmp, $target)) {
        // Fallback move
        if (!@copy($tmp, $target)) {
            $this->log('Write failed: ' . $target);
            @unlink($tmp);
            return ['ok' => false, 'error' => 'write_failed'];
        }
        @unlink($tmp);
    }

    // If CSS, crawl its url(...) dependencies
    if ($ext === 'css') {
        $css = @file_get_contents($target);
        if (is_string($css) && $css !== '') {
            $this->enqueue_css_dependencies($css, $url);
        }
    }

    if (Advanced_Debugger::enabled()) {
        Advanced_Debugger::mark('download_asset_done', [
            'url'    => (string) $url,
            'method' => 'http_stream',
            'target' => (string) $target,
        ]);
    }

    return ['ok' => true];
}

private function enqueue_css_dependencies($css, $css_url) {
        global $wpdb;
        $assets_table = $wpdb->prefix . 'wp_to_html_assets';

        $extractor = new Asset_Extractor();
        $deps = (new \ReflectionClass($extractor))->getMethod('extract_css_urls');
        $deps->setAccessible(true);
        $urls = $deps->invoke($extractor, $css, $css_url);

        $home = wp_parse_url(home_url('/'));
        $up = wp_upload_dir();
        $hosts = [];
        if (!empty($home['host'])) $hosts[] = strtolower((string) $home['host']);
        if (!empty($up['baseurl'])) {
            $uph = wp_parse_url($up['baseurl']);
            if (!empty($uph['host'])) $hosts[] = strtolower((string) $uph['host']);
        }
        $hosts = array_values(array_unique(array_filter($hosts)));
        $hosts = apply_filters('wp_to_html_allowed_hosts', $hosts);

        foreach ($urls as $u) {
            $pu = wp_parse_url($u);
            $h = !empty($pu['host']) ? strtolower((string) $pu['host']) : '';
            if ($h === '' || !in_array($h, $hosts, true)) continue;
            $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$assets_table} (url, found_on, asset_type, status)
                    VALUES (%s, %s, %s, 'pending')",
                    $u, $css_url, 'css_asset'
                )
            );
        }
    }
            

    private function rewrite_dom_paths($html, $dot) {

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//*[@src]') as $node) {
            $src = $node->getAttribute('src');
            if (strpos($src, home_url()) === 0) {
                $node->setAttribute('src', $dot . ltrim(parse_url($src, PHP_URL_PATH), '/'));
            }
        }

        foreach ($xpath->query('//*[@href]') as $node) {
            $href = $node->getAttribute('href');
            if (strpos($href, home_url()) === 0) {
                $node->setAttribute('href', $dot . ltrim(parse_url($href, PHP_URL_PATH), '/'));
            }
        }

        return $dom->saveHTML();
    }


    private function get_dot_path($url) {

        $parsed = wp_parse_url($url);

        if (empty($parsed['path'])) {
            return '';
        }

        $path = trim($parsed['path'], '/');

        // Example: blog/2026/post/
        $segments = explode('/', $path);

        // Remove last segment (because that's the page itself)
        array_pop($segments);

        $depth = count($segments);

        if ($depth <= 0) {
            return '';
        }

        return str_repeat('../', $depth);
    }

    private function get_dot_path_from_export_path($export_path) {

        $export_path = trim((string) $export_path, '/');

        // Your homepage is stored at /index/index.html — treat it as root depth, use "./"
        if ($export_path === '' || $export_path === 'index') {
            return './';
        }

        $segments = array_values(array_filter(explode('/', $export_path)));
        $depth = count($segments);

        return ($depth > 0) ? str_repeat('../', $depth) : './';
    }
    private function rewrite_html_with_dot_path($page_url, $html) {

        // Mirror your save_html() path logic
        $export_path = $this->relative_url_path($page_url);

        // If the file ends up in root (single export OR root_parent_html), use "./" dot base.
        if ($this->single_root_index) {
            $export_path = 'index';
        } elseif ($export_path === '') {
            $export_path = 'index';
        } elseif ($this->root_parent_html && strpos($export_path, '/') === false) {
            $export_path = 'index';
        }

        $dot  = $this->get_dot_path_from_export_path($export_path);
        $home = rtrim(home_url(), '/');

        // Rewrites same-origin absolute URLs AND root-relative URLs (/path) to dot-path-relative
        $rewrite_same_origin = function ($maybe_url) use ($home, $dot) {

            if (!is_string($maybe_url)) return $maybe_url;

            $maybe_url = html_entity_decode(trim($maybe_url), ENT_QUOTES);

            if ($maybe_url === '') return $maybe_url;

            // Keep non-urls / special schemes untouched
            if ($maybe_url[0] === '#') return $maybe_url;
            if (stripos($maybe_url, 'data:') === 0) return $maybe_url;
            if (stripos($maybe_url, 'mailto:') === 0) return $maybe_url;
            if (stripos($maybe_url, 'tel:') === 0) return $maybe_url;
            if (stripos($maybe_url, 'javascript:') === 0) return $maybe_url;

            // protocol-relative URLs //example.com/...
            if (strpos($maybe_url, '//') === 0) {
                $maybe_url = (is_ssl() ? 'https:' : 'http:') . $maybe_url;
            }

            // 1) Root-relative: /wp-content/... or /about/...
            // (but NOT protocol-relative //example.com/...)
            if ($maybe_url[0] === '/') {
                $p = wp_parse_url($maybe_url);
                $path = $p['path'] ?? '';
                if ($path === '') return $maybe_url;

                $query = isset($p['query']) ? ('?' . $p['query']) : '';
                $frag  = isset($p['fragment']) ? ('#' . $p['fragment']) : '';

                return $dot . ltrim($path, '/') . $query . $frag;
            }

            // 2) Same-origin absolute: https://yoursite.com/wp-content/...
            if (strpos($maybe_url, $home) !== 0) return $maybe_url;

            $p = wp_parse_url($maybe_url);
            $path = $p['path'] ?? '';
            if ($path === '') return $maybe_url;

            $query = isset($p['query']) ? ('?' . $p['query']) : '';
            $frag  = isset($p['fragment']) ? ('#' . $p['fragment']) : '';

            return $dot . ltrim($path, '/') . $query . $frag;
        };

        // Rewrite url(...) inside CSS (inline style + <style> blocks)
        $rewrite_css_urls = function ($css) use ($rewrite_same_origin) {

            if (!is_string($css) || $css === '') return $css;

            return preg_replace_callback('~url\(([^)]+)\)~i', function ($m) use ($rewrite_same_origin) {

                $raw = trim($m[1]);

                // Preserve quotes if present
                $quote = '';
                if ($raw !== '' && ($raw[0] === '"' || $raw[0] === "'")) {
                    $quote = $raw[0];
                    $raw = trim($raw, "\"'");
                } else {
                    $raw = trim($raw, "\"'");
                }

                $rewritten = $rewrite_same_origin($raw);

                // If rewritten result contains spaces etc, quotes help; keep original quote if any
                if ($quote !== '') {
                    return 'url(' . $quote . $rewritten . $quote . ')';
                }

                return 'url(' . $rewritten . ')';
            }, $css);
        };

        // DOM parse
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();

        // Make DOMDocument more tolerant with encoding
        if (stripos($html, '<meta charset=') === false) {
            $html = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html;
        }

        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // 1) Rewrite src attributes
        foreach ($xpath->query('//*[@src]') as $node) {
            $node->setAttribute('src', $rewrite_same_origin($node->getAttribute('src')));
        }

        // 2) Rewrite href attributes
        foreach ($xpath->query('//*[@href]') as $node) {
            $node->setAttribute('href', $rewrite_same_origin($node->getAttribute('href')));
        }

        // 3) Rewrite srcset attributes
        foreach ($xpath->query('//*[@srcset]') as $node) {
            $srcset = (string) $node->getAttribute('srcset');
            if ($srcset === '') continue;

            $rebuilt = [];
            foreach (explode(',', $srcset) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') continue;

                // Split into "url" + optional descriptor (1x, 400w, etc.)
                $parts = preg_split('/\s+/', $candidate, 2);
                $u = $parts[0] ?? '';
                $desc = $parts[1] ?? '';

                $u2 = $rewrite_same_origin($u);
                $rebuilt[] = trim($u2 . ' ' . $desc);
            }

            $node->setAttribute('srcset', implode(', ', $rebuilt));
        }

        // 4) Rewrite inline style=""
        foreach ($xpath->query('//*[@style]') as $node) {
            $style = (string) $node->getAttribute('style');
            if ($style === '') continue;

            $node->setAttribute('style', $rewrite_css_urls($style));
        }

        // 5) Rewrite <style> blocks
        foreach ($xpath->query('//style') as $styleNode) {
            $css = (string) $styleNode->textContent;
            if ($css === '') continue;

            $css2 = $rewrite_css_urls($css);

            // Replace content safely
            while ($styleNode->firstChild) {
                $styleNode->removeChild($styleNode->firstChild);
            }
            $styleNode->appendChild($dom->createTextNode($css2));
        }

        return $dom->saveHTML();
    }
    // $dot = $this->get_dot_path($url);
    // $html = $this->rewrite_dom_paths($html, $dot);

    /**
     * Attempt to render the URL server-side (no HTTP). This is mainly for non-public statuses
     * like draft/private/pending/future where wp_remote_get() will usually be blocked.
     *
     * @param string $url
     * @return string|null
     */
    private function try_server_side_render($url) {

        $post_id = $this->url_to_post_id_flexible($url);
        if ($post_id <= 0) {
            return null;
        }

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return null;
        }

        return $this->render_post_html($post);
    }

    /**
     * Resolve a URL to a post ID more aggressively than url_to_postid().
     * Supports query params like ?p=123, ?page_id=123 and preview links.
     *
     * @param string $url
     * @return int
     */
    private function url_to_post_id_flexible($url) {

        $url = (string) $url;
        if ($url === '') return 0;

        // 1) WordPress core resolver (works for published pretty permalinks)
        $post_id = (int) url_to_postid($url);
        if ($post_id > 0) return $post_id;

        // 2) Query param fallbacks (?p=ID, ?page_id=ID, ?post=ID)
        $q = (string) parse_url($url, PHP_URL_QUERY);
        if ($q !== '') {
            parse_str($q, $vars);

            foreach (['p', 'page_id', 'post', 'post_id', 'preview_id'] as $k) {
                if (isset($vars[$k]) && is_numeric($vars[$k])) {
                    $id = (int) $vars[$k];
                    if ($id > 0) return $id;
                }
            }
        }

        // 3) Last resort: try matching by path slug (can help for drafts/private if permalink structure is used)
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') return 0;

        // If it looks like /something/... use the last segment
        $parts = explode('/', $path);
        $slug = sanitize_title(end($parts));
        if ($slug === '') return 0;

        $maybe = get_page_by_path($slug, OBJECT, get_post_types([], 'names'));
        if ($maybe instanceof \WP_Post) {
            return (int) $maybe->ID;
        }

        return 0;
    }

    /**
     * Server-side HTML rendering that includes wp_head/wp_footer so theme assets are present.
     * This won't perfectly replicate the theme templates, but it is reliable for exporting
     * content that is not publicly reachable over HTTP.
     *
     * @param \WP_Post $post
     * @return string
     */
    private function render_post_html($post_obj) {

        // Prime globals similar to the main query
        global $wp_query, $post;

        $old_wp_query  = $wp_query;
        $old_post      = $post;

        $wp_query = new \WP_Query([
            'p'           => (int) $post_obj->ID,
            'post_type'   => (string) $post_obj->post_type,
            'post_status' => (string) $post_obj->post_status,
        ]);

        $post = $post_obj;
        setup_postdata($post);

        // Let themes/plugins enqueue assets as if this were a front-end request
        do_action('wp_enqueue_scripts');

        ob_start();
        ?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html(wp_get_document_title()); ?></title>
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
        if (function_exists('wp_body_open')) {
            wp_body_open();
        }
?>
<main id="wp-to-html-export-main">
    <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
        <header class="entry-header">
            <h1 class="entry-title"><?php echo esc_html(get_the_title()); ?></h1>
        </header>
        <div class="entry-content">
            <?php echo apply_filters('the_content', $post->post_content); ?>
        </div>
    </article>
</main>
<?php wp_footer(); ?>
</body>
</html>
<?php
        $html = (string) ob_get_clean();

        wp_reset_postdata();

        // Restore globals
        $wp_query = $old_wp_query;
        $post     = $old_post;

        return $html;
    }


}
