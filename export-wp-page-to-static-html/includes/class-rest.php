<?php
namespace WpToHtml;

class REST {

    /**
     * Ensure a runner token exists (used to authorize the server-side runner endpoint).
     */
    private function ensure_runner_token() {
        $saved = (string) get_option('wp_to_html_runner_token', '');
        if ($saved !== '') return $saved;
        $token = wp_generate_password(32, false, false);
        update_option('wp_to_html_runner_token', $token, false);
        return $token;
    }

    /**
     * Fire-and-forget loopback request to advance background work.
     * This is required when the admin UI does not poll /status and WP-Cron is unreliable.
     */
    private function spawn_runner() {
        // Throttle runner spawns to avoid request storms on large sites.
        // Many UI actions (start/kick/resume) can call spawn_runner() repeatedly.
        $min_gap = (float) apply_filters('wp_to_html_runner_spawn_min_gap_seconds', 2.0);
        $last = (float) get_transient('wp_to_html_last_runner_spawn_ts');
        $now  = microtime(true);
        if ($last > 0 && ($now - $last) < $min_gap) {
            return;
        }
        set_transient('wp_to_html_last_runner_spawn_ts', $now, (int) ceil($min_gap) + 2);

        $token = $this->ensure_runner_token();
        $url = add_query_arg(
            [
                '_' => time(),
            ],
            rest_url('wp_to_html/v1/runner')
        );

        // Non-blocking request. Token sent in header to avoid log exposure.
        @wp_remote_post($url, [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'headers'   => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'      => '{}',
        ]);
    }

    /**
     * Create a temporary export user for the chosen role.
     * We store ONLY the user ID in options and generate auth cookies on-the-fly.
     */
    private function ensure_export_user_for_role($role, $exporter = null) {
        $role = sanitize_key((string) $role);
        if ($role === '') return 0;

        if (!function_exists('wp_roles')) return 0;
        $roles = wp_roles();
        if (!$roles || empty($roles->roles) || !isset($roles->roles[$role])) {
            return 0;
        }

        // If a previous temp user exists in context, reuse it if still valid.
        $ctx = get_option('wp_to_html_export_context', []);
        if (is_array($ctx) && !empty($ctx['export_user_id'])) {
            $maybe_id = (int) $ctx['export_user_id'];
            if ($maybe_id > 0) {
                $u = get_user_by('id', $maybe_id);
                if ($u && (int) get_user_meta($maybe_id, 'wp_to_html_temp_export_user', true) === 1) {
                    // Ensure role matches requested.
                    $u->set_role($role);
                    return $maybe_id;
                }
            }
        }

        // Create a fresh temp user.
        $login = 'wp_to_html_export_' . strtolower(wp_generate_password(10, false, false));
        $pass  = wp_generate_password(32, true, true);
        $email = $login . '@example.invalid';

        $user_id = wp_create_user($login, $pass, $email);
        if (is_wp_error($user_id) || (int)$user_id <= 0) {
            if ($exporter && method_exists($exporter, 'log_public')) {
                $exporter->log_public('Failed to create temp export user for role=' . $role . ' — ' . (is_wp_error($user_id) ? $user_id->get_error_message() : 'unknown'));
            }
            return 0;
        }

        $u = get_user_by('id', (int) $user_id);
        if ($u) {
            $u->set_role($role);
        }

        update_user_meta((int) $user_id, 'wp_to_html_temp_export_user', 1);
        update_user_meta((int) $user_id, 'wp_to_html_temp_export_user_created_at', current_time('mysql'));

        // Hardening: make it less useful if leaked.
        update_user_meta((int) $user_id, 'show_admin_bar_front', 'false');

        if ($exporter && method_exists($exporter, 'log_public')) {
            $exporter->log_public('Temp export user created: id=' . (int)$user_id . ' role=' . $role);
        }

        return (int) $user_id;
    }

    private function cleanup_temp_export_user() {
        $ctx = get_option('wp_to_html_export_context', []);
        if (!is_array($ctx) || empty($ctx['export_user_id'])) return;
        $uid = (int) $ctx['export_user_id'];
        if ($uid <= 0) return;

        // Only delete users created by this plugin.
        $flag = (int) get_user_meta($uid, 'wp_to_html_temp_export_user', true);
        if ($flag !== 1) return;

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $deleted = wp_delete_user($uid);
        if (!$deleted) {
            error_log('[WP to HTML] Failed to delete temp export user ID: ' . $uid);
        }

        // Remove from context.
        $ctx['export_user_id'] = 0;
        update_option('wp_to_html_export_context', $ctx, false);
    }

    public function __construct() {
        add_action('rest_api_init', function () {
            register_rest_route('wp_to_html/v1', '/export', [
                'methods'  => 'POST',
                'callback' => [$this, 'handle_export'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);
            
            register_rest_route('wp_to_html/v1', '/log', [
                'methods'  => 'GET',
                'callback' => [$this, 'get_log'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            
            register_rest_route('wp_to_html/v1', '/log-reset', [
                'methods'  => 'POST',
                'callback' => [$this, 'reset_log'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

register_rest_route('wp_to_html/v1', '/status', [
                'methods' => 'GET',
                'callback' => [$this, 'get_status'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            // UI polling endpoint (lightweight): includes progress + whether outputs/zip exist
            register_rest_route('wp_to_html/v1', '/poll', [
                'methods' => 'GET',
                'callback' => [$this, 'get_poll'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            // Server-side runner endpoint to keep background work moving without UI polling.
            // Protected by a token in wp_options; no cookie/nonce auth required.
            register_rest_route('wp_to_html/v1', '/runner', [
                'methods' => 'POST',
                'callback' => [$this, 'runner_tick'],
                'permission_callback' => [$this, 'runner_permission'],
            ]);


            register_rest_route('wp_to_html/v1', '/pause', [
                'methods' => 'POST',
                'callback' => [$this, 'pause_export'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            register_rest_route('wp_to_html/v1', '/resume', [
                'methods' => 'POST',
                'callback' => [$this, 'resume_export'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            register_rest_route('wp_to_html/v1', '/stop', [
                'methods' => 'POST',
                'callback' => [$this, 'stop_export'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            // "Kick" endpoint: triggers a single background processing tick.
            // Useful on hosts where WP-Cron/loopback is unreliable.
            register_rest_route('wp_to_html/v1', '/kick', [
                'methods'  => 'POST',
                'callback' => [$this, 'kick_export'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            // Maintenance tools (quality-of-life)
            register_rest_route('wp_to_html/v1', '/queue-reset', [
                'methods'  => 'POST',
                'callback' => [$this, 'reset_background_queue'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            register_rest_route('wp_to_html/v1', '/rerun-failed', [
                'methods'  => 'POST',
                'callback' => [$this, 'rerun_failed_urls'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            register_rest_route('wp_to_html/v1', '/failed-urls', [
                'methods'  => 'GET',
                'callback' => [$this, 'get_failed_urls'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            register_rest_route('wp_to_html/v1', '/clear-temp', [
                'methods'  => 'POST',
                'callback' => [$this, 'clear_temp_files'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);


        

            register_rest_route('wp_to_html/v1', '/content', [
                'methods'  => 'GET',
                'callback' => [$this, 'get_content'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            // List exported files + zip info
            register_rest_route('wp_to_html/v1', '/exports', [
                'methods'  => 'GET',
                'callback' => [$this, 'get_exports'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            // Preview an exported file (streams html/css/js/images)
            register_rest_route('wp_to_html/v1', '/preview', [
                'methods'  => 'GET',
                'callback' => [$this, 'preview_export_file'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            // Download the latest zip (streams file)
            register_rest_route('wp_to_html/v1', '/download', [
                'methods'  => 'GET',
                'callback' => [$this, 'download_zip'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            // FTP settings (admin-only)
            register_rest_route('wp_to_html/v1', '/ftp-settings', [
                'methods'  => 'GET',
                'callback' => [$this, 'get_ftp_settings'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);
            register_rest_route('wp_to_html/v1', '/ftp-settings', [
                'methods'  => 'POST',
                'callback' => [$this, 'save_ftp_settings'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);
            register_rest_route('wp_to_html/v1', '/ftp-test', [
                'methods'  => 'POST',
                'callback' => [$this, 'ftp_test'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);


            register_rest_route('wp_to_html/v1', '/ftp-list', [
                'methods'  => 'POST',
                'callback' => [$this, 'ftp_list'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            // System diagnostics / preflight checks
            register_rest_route('wp_to_html/v1', '/system-status', [
                'methods'  => 'GET',
                'callback' => [$this, 'get_system_status'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            register_rest_route('wp_to_html/v1', '/check-can-run', [
                'methods'  => 'POST',
                'callback' => [$this, 'check_can_run'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);

            register_rest_route('wp_to_html/v1', '/reset-diagnostics', [
                'methods'  => 'POST',
                'callback' => [$this, 'reset_diagnostics'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                }
            ]);




});

    }

    /**
     * Reset queue + assets tables and set status back to idle.
     * This is a support-friendly "panic button".
     */
    public function reset_background_queue($request) {
        global $wpdb;

        $status_table = $wpdb->prefix . 'wp_to_html_status';
        $queue_table  = $wpdb->prefix . 'wp_to_html_queue';
        $assets_table = $wpdb->prefix . 'wp_to_html_assets';

        wp_clear_scheduled_hook('wp_to_html_process_event');
        wp_clear_scheduled_hook('wp_to_html_build_queue_event');

        $wpdb->query("TRUNCATE TABLE {$queue_table}");
        $wpdb->query("TRUNCATE TABLE {$assets_table}");

        $wpdb->update($status_table, [
            'state'            => 'idle',
            'is_running'       => 0,
            'pipeline_stage'   => 'idle',
            'stage_total'      => 0,
            'stage_done'       => 0,
            'failed_urls'      => 0,
            'total_urls'       => 0,
            'processed_urls'   => 0,
            'total_assets'     => 0,
            'processed_assets' => 0,
        ], ['id' => 1]);

        // Also reset per-run option markers.
        delete_option('wp_to_html_queue_build_state');
        update_option('wp_to_html_phase_urls_done_logged', 0, false);
        update_option('wp_to_html_phase_assets_done_logged', 0, false);
        update_option('wp_to_html_bulk_assets_enqueued', 0, false);

        return rest_ensure_response([
            'ok' => true,
            'message' => __('Queue and assets tables reset. Status set to idle.', 'wp-to-html'),
        ]);
    }

    /**
     * Return failed URLs (with retry/error info).
     */
    public function get_failed_urls($request) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'wp_to_html_queue';
        $limit = isset($request['limit']) ? max(1, min(500, (int)$request['limit'])) : 200;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, url, retry_count, last_error, last_attempt_at FROM {$queue_table} WHERE status='failed' ORDER BY id DESC LIMIT %d",
            $limit
        ));
        if (!is_array($rows)) $rows = [];

        return rest_ensure_response([
            'ok' => true,
            'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status='failed'"),
            'items' => $rows,
        ]);
    }

    /**
     * Re-run ONLY failed URLs.
     * By default resets retry_count to 0 and clears errors.
     */
    public function rerun_failed_urls($request) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'wp_to_html_queue';
        $reset_retries = true;
        try {
            $p = $request->get_json_params();
            if (is_array($p) && array_key_exists('reset_retries', $p)) {
                $reset_retries = (bool) $p['reset_retries'];
            }
        } catch (\Throwable $e) {}

        $set = [
            'status' => 'pending',
            'next_attempt_at' => null,
        ];
        if ($reset_retries) {
            $set['retry_count'] = 0;
            $set['last_error'] = null;
            $set['last_attempt_at'] = null;
        }

        // Update all failed URLs.
        $wpdb->update($queue_table, $set, ['status' => 'failed']);
        $affected = (int) $wpdb->rows_affected;

        // Ensure background tick is scheduled.
        if (!wp_next_scheduled('wp_to_html_process_event')) {
            wp_schedule_single_event(time() + 1, 'wp_to_html_process_event');
        }
        $this->spawn_runner();

        return rest_ensure_response([
            'ok' => true,
            'rerun_count' => $affected,
            'message' => __('Failed URLs re-queued.', 'wp-to-html'),
        ]);
    }

    /**
     * Clear temp/export cache files (export dir contents).
     * Keeps the export directory itself and does NOT delete plugin files.
     */
    public function clear_temp_files($request) {
        $dir = rtrim(WP_TO_HTML_EXPORT_DIR, '/\\');
        if (!is_dir($dir)) {
            return rest_ensure_response(['ok' => true, 'deleted' => 0, 'message' => __('Export dir not found (nothing to clear).', 'wp-to-html')]);
        }

        $keep = ['.', '..'];
        $deleted = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if (!$path) continue;

            // Keep nothing by default; remove everything under export dir.
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
            $deleted++;
        }

        // Recreate base dir if needed.
        if (!file_exists($dir)) {
            @wp_mkdir_p($dir);
        }

        return rest_ensure_response([
            'ok' => true,
            'deleted' => $deleted,
            'message' => __('Temporary/export files cleared.', 'wp-to-html'),
        ]);
    }

    public function get_status() {

        global $wpdb;

        $status_table = $wpdb->prefix . 'wp_to_html_status';

        // Some hosts disable/limit WP-Cron (or it only runs on front-end traffic).
        // Since the admin UI polls /status, we can safely advance the export here
        // in small batches. Core already uses a transient lock to avoid overlap.
        $maybe_status = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$status_table} WHERE id=%d",
                1
            )
        );

        if ($maybe_status) {
            $st = (string)($maybe_status->state ?? '');
            $rn = (int)($maybe_status->is_running ?? 0);

            // Some hosts disable/limit WP-Cron (or loopback). Historically this endpoint advanced
            // background work. That can overload huge sites if polled frequently.
            // ✅ New behavior: only "inline drive" when an authenticated admin is calling.
            // Additionally throttle inline ticks and cap tick time.
            $can_inline_drive = is_user_logged_in() && current_user_can('manage_options');
            if ($can_inline_drive) {
                $min_gap = (float) apply_filters('wp_to_html_inline_tick_min_gap_seconds', 4.0);
                $last = (float) get_transient('wp_to_html_last_inline_tick_ts');
                $now  = microtime(true);
                if (!($last > 0 && ($now - $last) < $min_gap)) {
                    set_transient('wp_to_html_last_inline_tick_ts', $now, (int) ceil($min_gap) + 2);

                    try {
                        $core = \WpToHtml\Core::get_instance();

                        // Tight budget for inline ticks so /status stays responsive.
                        $budget = (float) apply_filters('wp_to_html_inline_tick_time_budget_seconds', 2.5);
                        $f = function () use ($budget) { return $budget; };
                        add_filter('wp_to_html_bg_tick_time_budget_seconds', $f, 1000);

                        if ($st === 'running' && $rn === 1) {
                            $core->process_background();
                        } elseif ($st === 'building_queue' && $rn === 1) {
                            $core->build_queue_background();
                        }

                        remove_filter('wp_to_html_bg_tick_time_budget_seconds', $f, 1000);
                    } catch (\Throwable $e) {
                        // Don't break polling. Core/exporter will log/update status if needed.
                    }
                }
            }
        }

        $status = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$status_table} WHERE id=%d",
                1
            )
        );

        // if (!$status) {
        //     return rest_ensure_response([
        //         'state' => 'idle',
        //         'is_running' => 0,
        //         'total_urls' => 0,
        //         'processed_urls' => 0,
        //         'total_assets' => 0,
        //         'processed_assets' => 0,
        //     ]);
        // }

        $response = rest_ensure_response([
            'state' => isset($status->state) ? (string) $status->state : 'idle',
            'is_running' => isset($status->is_running) ? (int) $status->is_running : 0,
            'pipeline_stage' => isset($status->pipeline_stage) ? (string) $status->pipeline_stage : 'idle',
            'stage_total' => isset($status->stage_total) ? (int) $status->stage_total : 0,
            'stage_done' => isset($status->stage_done) ? (int) $status->stage_done : 0,
            'failed_urls' => isset($status->failed_urls) ? (int) $status->failed_urls : 0,
            'total_urls' => isset($status->total_urls) ? (int) $status->total_urls : 0,
            'processed_urls' => isset($status->processed_urls) ? (int) $status->processed_urls : 0,
            'total_assets' => isset($status->total_assets) ? (int) $status->total_assets : 0,
            'processed_assets' => isset($status->processed_assets) ? (int) $status->processed_assets : 0,
            'failed_assets' => isset($status->failed_assets) ? (int) $status->failed_assets : 0,
        ]);

        // Prevent host/CDN caching of progress responses
        if ($response instanceof \WP_REST_Response) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
        }

        return $response;

    }




    /**
     * Lightweight polling endpoint for the admin UI.
     * Avoids listing /exports during active runs.
     */
    public function get_poll(\WP_REST_Request $request = null) {
        $status = $this->get_status();

        // get_status() returns a WP_REST_Response already; extract data
        $data = ($status instanceof \WP_REST_Response) ? $status->get_data() : (is_array($status) ? $status : []);
        if (!is_array($data)) $data = [];

        $processed_urls = isset($data['processed_urls']) ? (int)$data['processed_urls'] : 0;

        // Outputs are assumed to exist once at least one URL has been processed.
        // This keeps polling cheap and avoids scanning the filesystem on every tick.
        $data['has_outputs'] = ($processed_urls > 0) ? 1 : 0;

        // ZIP availability (if created). We only expose booleans here.
        $zip_info = get_option('wp_to_html_last_zip', null);
        $zip_ready = 0;
        if (is_array($zip_info)) {
            // New multi-part format: array of arrays
            if (isset($zip_info[0]) && is_array($zip_info[0])) {
                foreach ($zip_info as $zi) {
                    $zip_path = trailingslashit(WP_TO_HTML_EXPORT_DIR) . basename((string)($zi['file'] ?? ''));
                    if (file_exists($zip_path)) { $zip_ready = 1; break; }
                }
            } elseif (!empty($zip_info['file'])) {
                // Old single-zip format
                $zip_path = WP_TO_HTML_EXPORT_DIR . $zip_info['file'];
                if (is_string($zip_path) && file_exists($zip_path)) {
                    $zip_ready = 1;
                }
            }
        }
        $data['zip_ready'] = $zip_ready;

        // If auto-zip is skipped due to large export, surface that to the UI.
        $data['zip_skipped'] = (int) get_option('wp_to_html_zip_skipped', 0);

        // Optional: include exports payload for the Preview modal.
        // This is heavier (filesystem walk) so it must be explicitly requested.
        $include_exports = 0;
        if ($request instanceof \WP_REST_Request) {
            $include_exports = (int) $request->get_param('include_exports');
        }

        if ($include_exports === 1) {
            // Reuse existing exports builder to keep response shape identical to /exports,
            // but nest under `exports`.
            $exports_req  = new \WP_REST_Request('GET', '/wp_to_html/v1/exports');
            $exports_resp = $this->get_exports($exports_req);
            $exports_data = ($exports_resp instanceof \WP_REST_Response) ? $exports_resp->get_data() : (is_array($exports_resp) ? $exports_resp : null);
            $data['exports'] = $exports_data;
        }

        $resp = rest_ensure_response($data);
        if ($resp instanceof \WP_REST_Response) {
            $resp->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $resp->header('Pragma', 'no-cache');
            $resp->header('Expires', '0');
        }
        return $resp;
    }

    /**
     * Diagnostics endpoints
     */
    public function get_system_status(\WP_REST_Request $request = null) {
        $force = 0;
        if ($request instanceof \WP_REST_Request) {
            $force = (int) $request->get_param('force');
        }

        $diag = new \WpToHtml\Diagnostic();
        $report = $diag->get_report($force === 1);
        return rest_ensure_response($report);
    }

    public function check_can_run(\WP_REST_Request $request = null) {
        $diag = new \WpToHtml\Diagnostic();
        $report = $diag->get_report(true);
        // Keep response small but structured.
        return rest_ensure_response([
            'generated_at' => $report['generated_at'] ?? time(),
            'summary'      => $report['summary'] ?? ['can_run' => 0],
            'checks'       => $report['checks'] ?? [],
        ]);
    }

    public function reset_diagnostics(\WP_REST_Request $request = null) {
        $diag = new \WpToHtml\Diagnostic();
        $ok = $diag->reset_cache();
        return rest_ensure_response([
            'reset' => $ok ? 1 : 0,
        ]);
    }

    public function handle_export($request) {
        // IMPORTANT: do NOT clear the export directory synchronously.
        // On many hosts, recursive deletion can take 60s–120s+ and cause REST timeouts.
        // We mark cleanup as pending and let background ticks handle it.
        update_option('wp_to_html_pending_cleanup', 1, false);

        // Clear previous run's zip info so the UI doesn't show an old
        // "Download zip file" button during a new export.
        delete_option('wp_to_html_last_zip');

        // Clear ZIP-skip marker for a fresh run.
        update_option('wp_to_html_zip_skipped', 0, false);

        // Reset log cursor for the new export run.
        // Without this, the incremental /log reader can seek past EOF and return nothing.
        update_option('wp_to_html_export_log_last_line', 0, false);

        // Reset progress-log heartbeat meta so each run starts clean.
        delete_option('wp_to_html_progress_log_meta');

        global $wpdb;

        $status_table = $wpdb->prefix . 'wp_to_html_status';

        // Ensure the status row exists
        $wpdb->replace($status_table, ['id' => 1]);

        // Prevent double run
        $state = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT state FROM {$status_table} WHERE id=%d", 1)
        );

        if ($state === 'running') {
            return rest_ensure_response(['message' => __('Already running', 'wp-to-html')]);
        }
        $exporter = new Exporter();
        if (method_exists($exporter, 'reset_log_file')) { $exporter->reset_log_file(); }

        $params = (array) $request->get_json_params();
        $full_site = !empty($params['full_site']);
        $include_home = array_key_exists('include_home', $params) ? (bool) $params['include_home'] : true;
        $save_assets_grouped = !empty($params['save_assets_grouped']);
        // Pro gate: "Group assets by type" requires Pro.
        if ($save_assets_grouped && function_exists('wp_to_html_is_pro_active') && !wp_to_html_is_pro_active()) {
            $save_assets_grouped = false;
        }

        // Asset coverage strategy: strict | hybrid | full
        $asset_collection_mode = isset($params['asset_collection_mode']) ? strtolower(trim((string)$params['asset_collection_mode'])) : 'strict';
        if (!in_array($asset_collection_mode, ['strict','hybrid','full'], true)) {
            $asset_collection_mode = 'strict';
        }

        
        // Hybrid discovery scope: auto | selected | sitewide
        $hybrid_scope = isset($params['hybrid_scope']) ? strtolower(trim((string)$params['hybrid_scope'])) : 'auto';
        if (!in_array($hybrid_scope, ['auto','selected','sitewide'], true)) {
            $hybrid_scope = 'auto';
        }

// Export-as role (used to fetch/render private/draft content like that role would see it).
        // NOTE: role selection may be overridden later if draft/private are requested.
        $export_as_role = isset($params['export_as_role']) ? sanitize_key((string) $params['export_as_role']) : '';
        $export_user_id = 0;

        // pass asset option to exporter (optional)
        if (method_exists($exporter, 'set_save_assets_grouped')) {
            $exporter->set_save_assets_grouped($save_assets_grouped);
        }

        if (WP_TO_HTML_DEBUG) {
            $exporter->log_public('REST blog_id=' . get_current_blog_id() . ' prefix=' . $wpdb->prefix . ' table=' . $status_table);
        }

        // Reset status first (so UI immediately shows a preparing state)
        $wpdb->update($status_table, [
            'state'            => 'building_queue',
            'is_running'       => 1,
            'total_urls'       => 0,
            'processed_urls'   => 0,
            'total_assets'     => 0,
            'processed_assets' => 0,
        ], ['id' => 1]);

        
        $state = (string) $wpdb->get_var(
            $wpdb->prepare("SELECT state FROM {$status_table} WHERE id=%d", 1)
        );
        $exporter->log_public('State: '. $state);
        $exporter->log_public('Starting export (queue build)...');
        
        $params = (array) $request->get_json_params();
 
        // Scope options:
        // - full_site: export all public post types
        // - all_posts: export all "post" items matching status filter
        // - all_pages: export all "page" items matching status filter
        // - selected: export only selected IDs
        $scope = isset($params['scope']) ? (string) $params['scope'] : '';
        // Normalize UI scope aliases before any gating or validation.
        // The UI sends 'custom' for the "Custom/Selected" mode — map it to 'selected'.
        if ($scope === 'custom') { $scope = 'selected'; }
        if (!in_array($scope, ['full_site', 'all_posts', 'all_pages', 'selected'], true)) {
            // Back-compat: if full_site flag is set, treat as full_site, else selected.
            $scope = !empty($params['full_site']) ? 'full_site' : 'selected';
        }

        // 🔒 Pro gating: only allow premium scopes when Pro is active.
        // IMPORTANT: this is server-side enforcement (UI-only gating is bypassable).
        if (function_exists('wp_to_html_allowed_scopes')) {
            $allowed_scopes = (array) wp_to_html_allowed_scopes();
            if (!in_array($scope, $allowed_scopes, true)) {
                return new \WP_Error(
                    'wp_to_html_pro_required',
                    __('This export scope requires Export WP Pages to Static HTML Pro (All Posts / Full Site).', 'wp-to-html'),
                    ['status' => 403]
                );
            }
        } else {
            // Safe default if helper is missing: Free-only (selected + all_pages).
            if (!in_array($scope, ['selected', 'all_pages'], true)) {
                return new \WP_Error(
                    'wp_to_html_pro_required',
                    __('This export scope requires Export WP Pages to Static HTML Pro (All Posts / Full Site).', 'wp-to-html'),
                    ['status' => 403]
                );
            }
        }

        $statuses = [];
        if (!empty($params['statuses'])) {
            if (is_string($params['statuses'])) {
                $statuses = array_filter(array_map('trim', explode(',', (string) $params['statuses'])));
            } elseif (is_array($params['statuses'])) {
                $statuses = array_filter(array_map('trim', array_map('strval', $params['statuses'])));
            }
        }
        // Default status: publish only
        if (empty($statuses)) {
            $statuses = ['publish'];
        }

        // Only create/switch to a temporary export user IF a role was explicitly selected in the UI.
        // If the role dropdown is left unselected/empty, export runs as the current logged-in user.
        $role_was_selected = !empty($export_as_role);

        if ($role_was_selected) {
            // If draft/private are selected, force editor (requested behavior).
            if (in_array('draft', $statuses, true) || in_array('private', $statuses, true)) {
                $export_as_role = 'editor';
            }

            // Validate role exists; fallback to administrator.
            $roles_obj = function_exists('wp_roles') ? wp_roles() : null;
            $roles = ($roles_obj && !empty($roles_obj->roles)) ? array_keys($roles_obj->roles) : [];
            if (empty($export_as_role) || !in_array($export_as_role, $roles, true)) {
                $export_as_role = 'administrator';
            }

            // Create (or reuse) temporary export user for the effective role.
            $export_user_id = $this->ensure_export_user_for_role($export_as_role, $exporter);
        }
        // Validate post statuses
        $valid = array_keys(get_post_stati([], 'names'));
        $statuses = array_values(array_intersect($statuses, $valid));
        if (empty($statuses)) {
            $statuses = ['publish'];
        }

        $args = [
            'full_site'    => ($scope === 'full_site'),
            'scope'        => $scope,
            'include_home' => array_key_exists('include_home', $params) ? (bool) $params['include_home'] : true,
            'selected'     => !empty($params['selected']) && is_array($params['selected']) ? $params['selected'] : [],
            'statuses'     => $statuses,
        ];

        // Free plan: cap custom scope to 5 selected items.
        if ($scope === 'selected' && !(function_exists('wp_to_html_is_pro_active') && wp_to_html_is_pro_active())) {
            $free_limit = 5;
            if (count($args['selected']) > $free_limit) {
                $args['selected'] = array_slice($args['selected'], 0, $free_limit);
            }
        }

        // Optional: restrict All Posts scope to a subset of post types.
        // UI provides only public post types; server still sanitizes and applies guardrails.
        if ($scope === 'all_posts') {
            $pts = [];
            if (!empty($params['post_types']) && is_array($params['post_types'])) {
                $pts = array_values(array_filter(array_map('sanitize_key', (array) $params['post_types'])));
            }
            // Never allow pages/attachments to be exported via All Posts.
            $pts = array_values(array_diff($pts, ['page', 'attachment']));
            if (empty($pts)) $pts = ['post'];
            $args['post_types'] = $pts;
        }

        // Export context used by Exporter/Core for path + zip naming behaviors
        $single_root_index = false;
        $single_slug = '';
        if ($scope === 'selected' && !$args['include_home'] && is_array($args['selected']) && count($args['selected']) === 1) {
            $single_root_index = true;

            $one = $args['selected'][0];
            $id = isset($one['id']) ? (int) $one['id'] : 0;

            if ($id > 0) {
                $p = get_post($id);
                if ($p && !is_wp_error($p)) {
                    $single_slug = sanitize_title($p->post_name);
                }
            }

            // Fallback: derive from permalink path
            if ($single_slug === '') {
                $u = ($id > 0) ? get_permalink($id) : '';
                if (!$u && !empty($one['url'])) $u = (string) $one['url'];
                if ($u) {
                    $pp = trim((string) parse_url($u, PHP_URL_PATH), '/');
                    if ($pp !== '') {
                        $seg = explode('/', $pp);
                        $single_slug = sanitize_title(end($seg));
                    }
                }
            }
        }

        $root_parent_html = !empty($params['root_parent_html']);

        
        // Build selected post IDs for scoped Hybrid asset discovery (Selected export).
        $selected_post_ids = [];
        if ($scope === 'selected' && !empty($args['selected']) && is_array($args['selected'])) {
            foreach ($args['selected'] as $it) {
                $pid = 0;
                if (is_array($it)) {
                    if (isset($it['id'])) $pid = (int) $it['id'];
                    elseif (isset($it['ID'])) $pid = (int) $it['ID'];
                    elseif (!empty($it['url']) && is_string($it['url'])) {
                        $maybe = url_to_postid($it['url']);
                        if ($maybe > 0) $pid = (int) $maybe;
                    }
                } elseif (is_numeric($it)) {
                    $pid = (int) $it;
                } elseif (is_string($it)) {
                    $maybe = url_to_postid($it);
                    if ($maybe > 0) $pid = (int) $maybe;
                }
                if ($pid > 0) $selected_post_ids[] = $pid;
            }
            $selected_post_ids = array_values(array_unique(array_filter(array_map('intval', $selected_post_ids))));
        }

        // Auto default: if exporting Selected content, Hybrid should be scoped to selected posts unless user forces sitewide.
        if ($hybrid_scope === 'auto') {
            $hybrid_scope = ($scope === 'selected') ? 'selected' : 'sitewide';
        }

update_option('wp_to_html_export_context', [
            'single_root_index' => $single_root_index ? 1 : 0,
            'single_slug'       => (string) $single_slug,
            'root_parent_html'  => $root_parent_html ? 1 : 0,
            // Persist UI toggles so background cron Exporter instances can read them.
            'save_assets_grouped' => $save_assets_grouped ? 1 : 0,
            'asset_collection_mode' => $asset_collection_mode,
            'hybrid_scope'        => $hybrid_scope,
            'scope'               => $scope,
            'selected_post_ids'   => $selected_post_ids,
            'export_as_role'     => $export_as_role,
            'export_user_id'     => $export_user_id,

            // Delivery options
            'upload_to_ftp'      => !empty($params['upload_to_ftp']) ? 1 : 0,
            'ftp_remote_path'    => isset($params['ftp_remote_path']) ? (string) $params['ftp_remote_path'] : '',
            // Pro delivery options (e.g., S3). Stored here so background cron can read it.
            'upload_to_s3'       => !empty($params['upload_to_s3']) ? 1 : 0,
            's3_prefix'          => isset($params['s3_prefix']) ? (string) $params['s3_prefix'] : '',
            'notify_complete'    => !empty($params['notify_complete']) ? 1 : 0,
            'notify_emails'      => isset($params['notify_emails']) ? (string) $params['notify_emails'] : '',

            // Used for email notifications (cron-safe)
            'initiator_user_id'  => get_current_user_id(),

            'saved_at'          => current_time('mysql'),
        ], false);

        if (WP_TO_HTML_DEBUG) {
            $exporter->log_public('Export args: ' . wp_json_encode($args));
        }
        
        // Initialize a chunked queue build (50 URLs per tick) and schedule the first tick.
        if (method_exists($exporter, 'init_queue_build')) {
            $exporter->init_queue_build($args, 50);
        } else {
            // Back-compat: fallback to synchronous build (not recommended)
            $exporter->build_queue($args);
        }

        // Start background queue build ticks.
        wp_schedule_single_event(time() + 1, 'wp_to_html_build_queue_event');

        // Force cron to run now (best-effort) so background ticks begin immediately.
        //spawn_cron();
        wp_remote_post(site_url('/wp-cron.php?doing_wp_cron=' . time()), [
            'timeout'  => 0.01,
            'blocking' => false,
            'sslverify'=> false,
        ]);

        // If the admin UI is configured to NOT poll /status during a run, then
        // WP-Cron loopback may not fire at all on some hosts. To keep the job
        // moving truly in the background, start a server-side runner chain.
        // The runner performs small ticks and re-spawns itself until completion.
        update_option('wp_to_html_runner_next_at', time(), false);
        $this->spawn_runner();

        // Extra reliability: run one queue-build tick inline.
        // The state is 'building_queue' at this point, so advance the queue builder
        // (NOT process_background, which would see 0 URLs and mark the export as completed).
        try {
            if (class_exists('\\WpToHtml\\Core') && method_exists('\\WpToHtml\\Core', 'get_instance')) {
                \WpToHtml\Core::get_instance()->build_queue_background();
            }
        } catch (\Throwable $e) {
            // Don't fail the request.
        }

        return rest_ensure_response(['message' => __('Export started', 'wp-to-html')]);
    }

    /**
     * Permission callback for /runner (token-based via Authorization header).
     */
    public function runner_permission( $request ) {
        $header = (string) $request->get_header('Authorization');
        $token = '';
        if (strpos($header, 'Bearer ') === 0) {
            $token = substr($header, 7);
        }
        // Fallback: also check query param for backward compatibility.
        if ($token === '') {
            $token = (string) $request->get_param('token');
        }
        $saved = (string) get_option('wp_to_html_runner_token', '');
        return ($saved !== '' && $token !== '' && hash_equals($saved, $token));
    }

    /**
     * Runner endpoint: advances background work without needing browser polling.
     * Runs ONE small tick and then spawns itself again if still running.
     */
    public function runner_tick( $request ) {
        // Throttle to avoid tight loops.
        $next_at = (int) get_option('wp_to_html_runner_next_at', 0);
        $now = time();
        if ($next_at > $now) {
            return rest_ensure_response(['ok' => true, 'skipped' => 1]);
        }
        // Allow next run in ~2 seconds.
        update_option('wp_to_html_runner_next_at', $now + 2, false);

        global $wpdb;
        $status_table = $wpdb->prefix . 'wp_to_html_status';
        $row = $wpdb->get_row($wpdb->prepare("SELECT state,is_running,total_urls,processed_urls,total_assets,processed_assets FROM {$status_table} WHERE id=%d", 1), ARRAY_A);
        $state = strtolower((string)($row['state'] ?? ''));
        $is_running = (int)($row['is_running'] ?? 0);

        if ($is_running !== 1) {
            return rest_ensure_response(['ok' => true, 'idle' => 1]);
        }

        // Advance one tick depending on current phase.
        if ($state === 'building_queue') {
            do_action('wp_to_html_build_queue_event');
        } else {
            do_action('wp_to_html_process_event');
        }

        // Re-read status to decide whether to continue.
        $row2 = $wpdb->get_row($wpdb->prepare("SELECT state,is_running FROM {$status_table} WHERE id=%d", 1), ARRAY_A);
        $state2 = strtolower((string)($row2['state'] ?? ''));
        $is_running2 = (int)($row2['is_running'] ?? 0);

        if ($is_running2 === 1 && !in_array($state2, ['completed','stopped','error'], true)) {
            $this->spawn_runner();
        }

        return rest_ensure_response(['ok' => true, 'state' => $state2, 'is_running' => $is_running2]);
    }


    public function reset_log() {
        // Reset cursor
        update_option('wp_to_html_export_log_last_line', 0, false);

        // Reset progress-log heartbeat meta
        delete_option('wp_to_html_progress_log_meta');

        $file = WP_TO_HTML_EXPORT_DIR . '/export-log.txt';

        if (!file_exists(WP_TO_HTML_EXPORT_DIR)) {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
        }

        // Truncate log file (avoid stale lines being shown before /export starts)
        file_put_contents($file, '');
        @chmod($file, 0644);

        return rest_ensure_response(['ok' => true]);
    }

    function get_log() {

        $file = WP_TO_HTML_EXPORT_DIR . '/export-log.txt';

        // Ensure directory exists
        if (!file_exists(WP_TO_HTML_EXPORT_DIR)) {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
        }

        // Cursor: last line number already returned (1-based)
        $last_sent_line = (int) get_option('wp_to_html_export_log_last_line', 0);

        // If file does not exist, return empty
        if (!file_exists($file)) {
            return rest_ensure_response([
                'log'       => '',
                'from_line' => $last_sent_line,
                'to_line'   => $last_sent_line,
                'has_more'  => false,
            ]);
        }

        // If a new export run truncated the file but the cursor wasn't reset (or multiple tabs),
        // seeking past EOF would return nothing forever. Detect and reset.
        if ($last_sent_line > 0) {
            try {
                $probe = new \SplFileObject($file, 'r');
                $probe->seek(PHP_INT_MAX);
                $total_lines = $probe->key() + 1; // 1-based
                if ($total_lines < $last_sent_line) {
                    $last_sent_line = 0;
                    update_option('wp_to_html_export_log_last_line', 0, false);
                }
            } catch (\Throwable $e) {
                // ignore; main reader will handle file errors
            }
        }

        // Cap lines per request to keep responses bounded
        $max_lines_per_request = 500;

        $lines = [];
        $to_line = $last_sent_line;
        $has_more = false;

        try {
            $f = new \SplFileObject($file, 'r');
            $f->setFlags(\SplFileObject::DROP_NEW_LINE);

            // key() is 0-based; last_sent_line is 1-based
            $start_key = max(0, $last_sent_line);
            $f->seek($start_key);

            while (!$f->eof()) {
                $line = $f->current();
                if ($line === false) break;

                // Normalize CRLF and remove trailing newlines
                $line = rtrim((string) $line, "\r\n");

                // Skip truly empty lines (prevents visual gaps)
                if ($line === '') {
                    $f->next();
                    continue;
                }

                $current_line_no = $f->key() + 1;
                if ($current_line_no <= $last_sent_line) {
                    $f->next();
                    continue;
                }

                $lines[] = $line;
                $to_line = $current_line_no;

                if (count($lines) >= $max_lines_per_request) {
                    $f->next();
                    $has_more = !$f->eof();
                    break;
                }

                $f->next();
            }
        } catch (\Throwable $e) {
            return rest_ensure_response([
                'log'       => '',
                'error'     => 'Failed to read log: ' . $e->getMessage(),
                'from_line' => $last_sent_line,
                'to_line'   => $last_sent_line,
                'has_more'  => false,
            ]);
        }

        if ($to_line > $last_sent_line) {
            update_option('wp_to_html_export_log_last_line', $to_line, false);
        }

        $log = $lines ? implode("\n", $lines) : '';
        // Avoid chunk-boundary gaps: no leading blank lines
        $log = ltrim($log, "\r\n");
        if ($log !== '' && substr($log, -1) !== "\n") {
            $log .= "\n";
        }

        return rest_ensure_response([
            'log'       => $log,
            'from_line' => $last_sent_line + (empty($lines) ? 0 : 1),
            'to_line'   => $to_line,
            'has_more'  => $has_more,
        ]);
    }


    public function pause_export() {

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'wp_to_html_status',
            ['state' => 'paused', 'is_running' => 0],
            ['id' => 1]
        );

        // Prevent any scheduled ticks from continuing while paused.
        wp_clear_scheduled_hook('wp_to_html_process_event');

        try { (new \WpToHtml\Exporter())->log_public('Export paused by the user.'); } catch (\Throwable $e) {}

        return ['message' => __('Paused', 'wp-to-html')];
    }


    public function resume_export() {

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'wp_to_html_status',
            ['state' => 'running', 'is_running' => 1],
            ['id' => 1]
        );

        try { (new \WpToHtml\Exporter())->log_public('Export resumed by the user.'); } catch (\Throwable $e) {}

        wp_schedule_single_event(time(), 'wp_to_html_process_event');
        //spawn_cron();
        wp_remote_post(site_url('/wp-cron.php?doing_wp_cron=' . time()), [
            'timeout'  => 0.01,
            'blocking' => false,
            'sslverify'=> false,
        ]);

        // Same inline kick on resume (helps when cron doesn't spawn).
        try {
            if (class_exists('\\WpToHtml\\Core') && method_exists('\\WpToHtml\\Core', 'get_instance')) {
                \WpToHtml\Core::get_instance()->process_background();
            }
        } catch (\Throwable $e) {}

        return ['message' => __('Resumed', 'wp-to-html')];
    }

    /**
     * REST: run a single background tick.
     */
    public function kick_export() {
        try {
            if (class_exists('\\WpToHtml\\Core') && method_exists('\\WpToHtml\\Core', 'get_instance')) {
                \WpToHtml\Core::get_instance()->process_background();
                return rest_ensure_response(['message' => __('Kicked', 'wp-to-html')]);
            }
        } catch (\Throwable $e) {
            return rest_ensure_response(['message' => 'Kick failed: ' . $e->getMessage()]);
        }
        return rest_ensure_response(['message' => __('Kick not available', 'wp-to-html')]);
    }


    public function stop_export() {

        global $wpdb;

        $status_table = $wpdb->prefix . 'wp_to_html_status';
        $queue_table  = $wpdb->prefix . 'wp_to_html_queue';
        $assets_table = $wpdb->prefix . 'wp_to_html_assets';

        // Capture stats BEFORE changing state (so counts are accurate).
        $total_urls       = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table}");
        $processed_urls   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status='done'");
        $failed_urls      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status='failed'");
        $total_assets     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assets_table}");
        $processed_assets = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assets_table} WHERE status='done'");
        $failed_assets    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assets_table} WHERE status='failed'");

        $wpdb->update(
            $status_table,
            [
                'state'      => 'stopped',
                'is_running' => 0,
            ],
            ['id' => 1]
        );

        wp_clear_scheduled_hook('wp_to_html_process_event');

        try { (new \WpToHtml\Exporter())->log_public('Export stopped by the user.'); } catch (\Throwable $e) {}

        // Best-effort cleanup of temp export user.
        $this->cleanup_temp_export_user();

        // Send export report to API server (stopped by user).
        try {
            $core = \WpToHtml\Core::get_instance();
            $core->send_export_report_to_api(false, [
                'total_urls'       => $total_urls,
                'processed_urls'   => $processed_urls,
                'failed_urls'      => $failed_urls,
                'total_assets'     => $total_assets,
                'processed_assets' => $processed_assets,
                'failed_assets'    => $failed_assets,
                'stopped'          => true,
            ]);
        } catch (\Throwable $e) {
            // Never fail the stop action due to report.
        }

        return ['message' => __('Stopped', 'wp-to-html')];
    }


    public function get_content($request) {

        // Allow browsing any public post type (incl. CPTs), but never attachments.
        $type = (string) $request->get_param('type');
        $allowed_types = get_post_types([
            'public'  => true,
            'show_ui' => true,
        ], 'names');
        unset($allowed_types['attachment']);

        if (empty($allowed_types)) {
            $allowed_types = ['post', 'page'];
        }

        if (!in_array($type, $allowed_types, true)) {
            $type = in_array('post', $allowed_types, true) ? 'post' : array_values($allowed_types)[0];
        }

        // Status filter: comma-separated (e.g. publish,draft) or array
        $status_param = $request->get_param('status');
        $statuses = [];
        if (is_array($status_param)) {
            $statuses = array_map('strval', $status_param);
        } elseif (is_string($status_param)) {
            $statuses = array_filter(array_map('trim', explode(',', $status_param)));
        }
        if (empty($statuses)) $statuses = ['publish'];

        $valid = array_keys(get_post_stati([], 'names'));
        $statuses = array_values(array_intersect($statuses, $valid));
        if (empty($statuses)) $statuses = ['publish'];

        $search = (string) $request->get_param('search');
        $page = max(1, (int) $request->get_param('page'));
        $per_page = (int) $request->get_param('per_page');
        if ($per_page <= 0) $per_page = 30;
        if ($per_page > 50) $per_page = 50;

        $args = [
            'post_type'      => $type,
            'post_status'    => $statuses,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC',
            's'              => $search,
        ];

        $q = new \WP_Query($args);

        $items = [];
        foreach ($q->posts as $p) {
            $items[] = [
                'id'        => (int) $p->ID,
                'type'      => $type,
                'title'     => html_entity_decode(get_the_title($p), ENT_QUOTES),
                'slug'      => (string) $p->post_name,
                'date'      => (string) mysql2date('Y-m-d', $p->post_date),
                'status'    => (string) $p->post_status,
                'permalink' => (string) get_permalink($p),
            ];
        }

        return rest_ensure_response([
            'items'     => $items,
            'page'      => $page,
            'per_page'  => $per_page,
            'found'     => (int) $q->found_posts,
            'has_more'  => ($page * $per_page) < (int) $q->found_posts,
        ]);
    }


    private function clear_export_directory() {

        $dir = WP_TO_HTML_EXPORT_DIR;

        if (!file_exists($dir)) {
            return;
        }

        // Delete all files & folders inside directory
        $files = scandir($dir);

        foreach ($files as $file) {

            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->delete_directory_recursive($path);
            } else {
                unlink($path);
            }
        }
    }

    private function delete_directory_recursive($dir) {

        if (!file_exists($dir)) {
            return;
        }

        $files = scandir($dir);

        foreach ($files as $file) {

            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $this->delete_directory_recursive($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }



    public function get_exports(\WP_REST_Request $request) {

        $export_dir = WP_TO_HTML_EXPORT_DIR;

        $files = [];
        if (is_dir($export_dir)) {
            $root = realpath($export_dir);
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isDir()) continue;
                $fp = $file->getRealPath();
                if (!$fp) continue;

                // Skip logs and zips in file list
                $bn = basename($fp);
                if ($bn === 'export-log.txt' || preg_match('/\.zip$/i', $bn)) continue;

                $rel = ltrim(str_replace($root, '', $fp), DIRECTORY_SEPARATOR);

                // Provide only exportable preview types
                $files[] = [
                    'path' => $rel,
                    'size' => (int) $file->getSize(),
                ];
            }
        }

        // Sort stable
        usort($files, function($a,$b){
            return strcmp($a['path'], $b['path']);
        });

        $zip_info_raw = get_option('wp_to_html_last_zip', null);

        // Normalize zip info to array of parts for consistent UI handling.
        // NOTE: download_url is intentionally NOT included here — the JS adds the
        // _wpnonce at render time so the URL is always authenticated.
        $zip_parts = [];
        if (is_array($zip_info_raw)) {
            if (isset($zip_info_raw[0]) && is_array($zip_info_raw[0])) {
                // New multi-part format
                foreach ($zip_info_raw as $zi) {
                    $zip_fp = trailingslashit(WP_TO_HTML_EXPORT_DIR) . basename((string)($zi['file'] ?? ''));
                    if (file_exists($zip_fp)) {
                        // Remove any stale server-generated download_url; JS will build it
                        unset($zi['download_url']);
                        $zip_parts[] = $zi;
                    }
                }
            } elseif (!empty($zip_info_raw['file'])) {
                // Old single-zip format - wrap in array for uniform handling
                $zip_fp = trailingslashit(WP_TO_HTML_EXPORT_DIR) . basename((string)$zip_info_raw['file']);
                if (file_exists($zip_fp)) {
                    unset($zip_info_raw['download_url']);
                    $zip_info_raw['part'] = 1;
                    $zip_info_raw['total_parts'] = 1;
                    $zip_parts[] = $zip_info_raw;
                }
            }
        }

        // Public base URL for direct file preview (served by the web server under wp-content).
        // Example: https://example.com/wp-content/wp-to-html-exports/
        $public_base_url = trailingslashit(content_url('wp-to-html-exports'));

        // Legacy REST-based preview (kept for backward compatibility).
        $preview_base = rest_url('wp_to_html/v1/preview?path=');
        // Base download URL (no nonce — JS appends nonce + part param at render time)
        $download_url = rest_url('wp_to_html/v1/download');

        return rest_ensure_response([
            'export_dir'     => $export_dir,
            'files'          => $files,
            'zip'            => !empty($zip_parts) ? $zip_parts[0] : null,  // legacy compat
            'zip_parts'      => $zip_parts,
            'total_zip_parts'=> count($zip_parts),
            'public_base_url'=> $public_base_url,
            'preview_base'   => $preview_base,
            'download_url'   => $download_url,
        ]);
    }

    public function preview_export_file(\WP_REST_Request $request) {

        $path = (string) $request->get_param('path');
        $path = ltrim($path, '/\\');

        if ($path === '') {
            $path = 'index/index.html';
        }

        $base = realpath(WP_TO_HTML_EXPORT_DIR);
        if (!$base) {
            return new \WP_Error('wp_to_html_no_export_dir', __('Export directory not found', 'wp-to-html'), ['status' => 404]);
        }

        // Resolve file path and prevent traversal
        $target = realpath($base . DIRECTORY_SEPARATOR . $path);

        // If target is a directory, try index.html inside it
        if ($target && is_dir($target)) {
            $target = realpath($target . DIRECTORY_SEPARATOR . 'index.html');
        }

        if (!$target || strpos($target, $base) !== 0 || !file_exists($target) || is_dir($target)) {
            return new \WP_Error('wp_to_html_not_found', __('File not found', 'wp-to-html'), ['status' => 404]);
        }

        $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        $mime = 'application/octet-stream';

        switch ($ext) {
            case 'html': $mime = 'text/html; charset=utf-8'; break;
            case 'css':  $mime = 'text/css; charset=utf-8'; break;
            case 'js':   $mime = 'application/javascript; charset=utf-8'; break;
            case 'json': $mime = 'application/json; charset=utf-8'; break;
            case 'xml':  $mime = 'application/xml; charset=utf-8'; break;
            case 'txt':  $mime = 'text/plain; charset=utf-8'; break;
            case 'png':  $mime = 'image/png'; break;
            case 'jpg':
            case 'jpeg': $mime = 'image/jpeg'; break;
            case 'gif':  $mime = 'image/gif'; break;
            case 'webp': $mime = 'image/webp'; break;
            case 'svg':  $mime = 'image/svg+xml'; header("Content-Security-Policy: default-src 'none'"); break;
            case 'woff': $mime = 'font/woff'; break;
            case 'woff2': $mime = 'font/woff2'; break;
            case 'ttf':  $mime = 'font/ttf'; break;
            case 'otf':  $mime = 'font/otf'; break;
        }

        // Stream file
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($target));
        header('X-Content-Type-Options: nosniff');

        readfile($target);
        exit;
    }

    public function download_zip(\WP_REST_Request $request) {

        // Optional: download ZIP by file group (images/css/js/etc)
        $group = (string) $request->get_param('group');
        $group = strtolower(trim($group));
        if ($group !== '') {
            return $this->download_group_zip($group);
        }

        $zip_info_raw = get_option('wp_to_html_last_zip', null);
        if (!$zip_info_raw) {
            return new \WP_Error('wp_to_html_no_zip', __('No zip archive found. Run an export first.', 'wp-to-html'), ['status' => 404]);
        }

        // Determine which part to download (default: part 1)
        $requested_part = (int) $request->get_param('part');
        if ($requested_part <= 0) $requested_part = 1;

        // Normalize to array of parts
        $zip_parts = [];
        if (is_array($zip_info_raw)) {
            if (isset($zip_info_raw[0]) && is_array($zip_info_raw[0])) {
                $zip_parts = $zip_info_raw;
            } elseif (!empty($zip_info_raw['file'])) {
                // Old single-zip format
                $zip_parts = [$zip_info_raw];
            }
        }

        // Find the requested part
        $selected_zip = null;
        foreach ($zip_parts as $zi) {
            $part_num = isset($zi['part']) ? (int)$zi['part'] : 1;
            if ($part_num === $requested_part) {
                $selected_zip = $zi;
                break;
            }
        }

        // Fallback to first part if specific part not found
        if (!$selected_zip && !empty($zip_parts)) {
            $selected_zip = $zip_parts[0];
        }

        if (!$selected_zip || empty($selected_zip['file'])) {
            return new \WP_Error('wp_to_html_no_zip', __('No zip archive found. Run an export first.', 'wp-to-html'), ['status' => 404]);
        }

        $zip_fp = trailingslashit(WP_TO_HTML_EXPORT_DIR) . basename((string)$selected_zip['file']);
        if (!file_exists($zip_fp)) {
            return new \WP_Error('wp_to_html_zip_missing', sprintf(__('Zip file missing on disk (part %d).', 'wp-to-html'), $requested_part), ['status' => 404]);
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zip_fp) . '"');
        header('Content-Length: ' . filesize($zip_fp));
        header('X-Zip-Part: ' . (int)($selected_zip['part'] ?? 1));
        header('X-Zip-Total-Parts: ' . (int)($selected_zip['total_parts'] ?? 1));

        readfile($zip_fp);
        exit;
    }

    private function download_group_zip(string $group) {

        $base = realpath(WP_TO_HTML_EXPORT_DIR);
        if (!$base || !is_dir($base)) {
            return new \WP_Error('wp_to_html_no_export_dir', __('Export directory not found. Run an export first.', 'wp-to-html'), ['status' => 404]);
        }

        $allowed = [
            'html'   => ['html','htm'],
            'images' => ['png','jpg','jpeg','gif','webp','svg','ico','avif'],
            'css'    => ['css'],
            'js'     => ['js'],
            'audios' => ['mp3','wav','ogg','m4a','aac','flac','opus'],
            'videos' => ['mp4','webm','mov','mkv','m4v','avi'],
            'docs'   => ['pdf','doc','docx','xls','xlsx','ppt','pptx','csv','txt','md','rtf'],
            'fonts'  => ['woff','woff2','ttf','otf','eot'],
            'other'  => []
        ];

        if (!array_key_exists($group, $allowed)) {
            return new \WP_Error('wp_to_html_bad_group', __('Unknown group', 'wp-to-html'), ['status' => 400]);
        }

        $exts = $allowed[$group];

        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) continue;
            $fp = $file->getRealPath();
            if (!$fp) continue;

            $bn = basename($fp);
            if ($bn === 'export-log.txt' || preg_match('/\.zip$/i', $bn)) continue;

            $rel = ltrim(str_replace($base, '', $fp), DIRECTORY_SEPARATOR);
            $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION));

            $match = false;
            if ($group === 'other') {
                // other = anything not matched by the explicit groups above
                $match = true;
                foreach ($allowed as $g => $gexts) {
                    if ($g === 'other') continue;
                    if (in_array($ext, $gexts, true)) { $match = false; break; }
                }
            } else {
                $match = in_array($ext, $exts, true);
            }

            if ($match) {
                $files[] = ['abs' => $fp, 'rel' => $rel];
            }
        }

        if (empty($files)) {
            return new \WP_Error('wp_to_html_no_files_in_group', __('No files found for this group.', 'wp-to-html'), ['status' => 404]);
        }

        $stamp = gmdate('Ymd-His');
        $zip_name = 'wp-to-html-' . $group . '-' . $stamp . '.zip';
        $zip_fp = trailingslashit($base) . $zip_name;

        if (!class_exists('ZipArchive')) {
            return new \WP_Error('wp_to_html_zip_missing_ext', __('ZipArchive is not available on this server.', 'wp-to-html'), ['status' => 500]);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zip_fp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return new \WP_Error('wp_to_html_zip_create_failed', __('Failed to create zip file.', 'wp-to-html'), ['status' => 500]);
        }

        foreach ($files as $f) {
            $zip->addFile($f['abs'], $f['rel']);
        }
        $zip->close();

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_name . '"');
        header('Content-Length: ' . filesize($zip_fp));
        header('X-Content-Type-Options: nosniff');

        readfile($zip_fp);
        @unlink($zip_fp);
        exit;
    }

    // ---------------------------------------------------------------------
    // FTP Settings API

    public function get_ftp_settings() {
        $s = get_option('wp_to_html_ftp_settings', []);
        $s = is_array($s) ? $s : [];

        // Never return stored password to the browser.
        if (isset($s['pass'])) {
            $s['pass'] = '';
        }

        return rest_ensure_response([
            'ok' => true,
            'settings' => $s,
        ]);
    }

    public function save_ftp_settings($request) {
        $params = (array) $request->get_json_params();
        $san = \WpToHtml\FTP_Uploader::sanitize_settings($params);

        // If host/user are empty, treat as "disable/clear" only if password is also empty.
        if ($san['host'] === '' || $san['user'] === '') {
            if ($san['host'] === '' && $san['user'] === '' && $san['pass'] === '') {
                update_option('wp_to_html_ftp_settings', [], false);
                return rest_ensure_response(['ok' => true, 'message' => __('FTP settings cleared.', 'wp-to-html')]);
            }
            return rest_ensure_response(['ok' => false, 'message' => __('Host and username are required.', 'wp-to-html')]);
        }

        // Encrypt password before storing.
        if ($san['pass'] !== '') {
            $san['pass'] = \WpToHtml\FTP_Uploader::encrypt_credential($san['pass']);
        }

        update_option('wp_to_html_ftp_settings', $san, false);
        return rest_ensure_response(['ok' => true]);
    }

    public function ftp_test($request) {
        $params = (array) $request->get_json_params();
        $san = \WpToHtml\FTP_Uploader::sanitize_settings($params);

        $msg = '';
        $ok = \WpToHtml\FTP_Uploader::test_connection($san, $msg);
        if ($ok) {
            return rest_ensure_response(['ok' => true, 'message' => $msg]);
        }
        return rest_ensure_response(['ok' => false, 'message' => $msg ?: __('Connection failed.', 'wp-to-html')]);
    }



    public function ftp_list($request) {
        $params = (array) $request->get_json_params();
        $path = isset($params['path']) ? (string) $params['path'] : '/';
        $path = \WpToHtml\FTP_Uploader::normalize_remote_path($path);
        if ($path === '') $path = '/';

        // Determine settings source.
        $use_saved = !empty($params['use_saved']);
        $saved = get_option('wp_to_html_ftp_settings', []);
        $saved = is_array($saved) ? $saved : [];

        $settings = [];
        if (!$use_saved && !empty($params['settings']) && is_array($params['settings'])) {
            $settings = \WpToHtml\FTP_Uploader::sanitize_settings($params['settings']);

            // If password omitted, try to reuse saved pass when host/user match.
            if (($settings['pass'] === '' || $settings['pass'] === null) && !empty($saved['pass'])) {
                $sh = isset($saved['host']) ? (string)$saved['host'] : '';
                $su = isset($saved['user']) ? (string)$saved['user'] : '';
                if ($sh !== '' && $su !== '' && $settings['host'] === $sh && $settings['user'] === $su) {
                    $settings['pass'] = \WpToHtml\FTP_Uploader::decrypt_credential((string) $saved['pass']);
                }
            }
        } else {
            $settings = $saved;
            // Decrypt password from stored settings.
            if (!empty($settings['pass'])) {
                $settings['pass'] = \WpToHtml\FTP_Uploader::decrypt_credential((string) $settings['pass']);
            }
        }

        $settings = \WpToHtml\FTP_Uploader::sanitize_settings($settings);

        $msg = '';
        $dirs = \WpToHtml\FTP_Uploader::list_directories($settings, $path, $msg);
        if ($dirs === false) {
            return rest_ensure_response([
                'ok' => false,
                'message' => $msg ?: __('Could not list directories.', 'wp-to-html'),
            ]);
        }

        return rest_ensure_response([
            'ok' => true,
            'path' => $path,
            'dirs' => $dirs,
        ]);
    }


}
 