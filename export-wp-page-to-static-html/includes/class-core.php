<?php
namespace WpToHtml;

class Core {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Ensure DB schema is up to date even when plugin is updated without re-activation.
        try {
            $last = (int) get_option('wp_to_html_tables_checked', 0);
            if ($last < (time() - DAY_IN_SECONDS)) {
                if (function_exists('wp_to_html_ensure_tables')) {
                    wp_to_html_ensure_tables();
                }
                update_option('wp_to_html_tables_checked', time(), false);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        $this->create_export_dir();
        add_action('wp_to_html_process_event', [$this, 'process_background']);
        // Build queue in small background ticks (prevents long-running REST requests/timeouts).
        add_action('wp_to_html_build_queue_event', [$this, 'build_queue_background']);
        new Admin();
        new REST();
        new Quick_Export();

        // Advanced debugger: capture fatal errors + last progress marker.
        if (Advanced_Debugger::enabled()) {
            register_shutdown_function(['\\WpToHtml\\Advanced_Debugger', 'shutdown_capture']);
        }
    }

    /**
     * Background queue builder tick.
     * Builds the URL queue in small batches to reduce peak load.
     */
    public function build_queue_background() {
        try {
            $exporter = new Exporter();
            if (method_exists($exporter, 'build_queue_tick')) {
                // 50 URLs per tick (requested)
                $exporter->build_queue_tick(50);
            }
        } catch (\Throwable $e) {
            // Best-effort: write to export log if available
            try {
                $ex = new Exporter();
                if (method_exists($ex, 'log_public')) {
                    $ex->log_public('Queue build tick failed: ' . $e->getMessage());
                }
            } catch (\Throwable $e2) {
                // ignore
            }
        }
    }

    private function create_export_dir() {
        if (!file_exists(WP_TO_HTML_EXPORT_DIR)) {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
        }
    }

    private function update_status(array $data) {
        $this->log(json_encode($data));

        global $wpdb;

        $table = $wpdb->prefix . 'wp_to_html_status';

        // Guard against accidental downgrades to idle while an export is actually running.
        // Some hosting/caching/auth edge cases can cause a default/empty payload to be written;
        // we keep the existing running/paused state unless the transition is explicit.
        $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", 1));
        if ($current) {
            $current_state = (string) ($current->state ?? '');
            $current_running = (int) ($current->is_running ?? 0);

            // If caller tries to set state=idle while we are running, ignore the downgrade.
            if (isset($data['state']) && (string)$data['state'] === 'idle' && $current_state === 'running' && $current_running === 1) {
                $data['state'] = 'running';
                $data['is_running'] = 1;
            }

            // If state says running but is_running is falsey, fix it (don't flip to idle).
            if (isset($data['state']) && (string)$data['state'] === 'running' && empty($data['is_running'])) {
                $data['is_running'] = 1;
            }
        }

        // Ensure defaults
        // $defaults = [
        //     'state'            => 'idle',
        //     'is_running'       => 0,
        //     'total_urls'       => 0,
        //     'processed_urls'   => 0,
        //     'total_assets'     => 0,
        //     'processed_assets' => 0,
        //     'updated_at'       => current_time('mysql'),
        // ];

        //$row = array_merge($defaults, $data);
        $row = $data;

        // Normalize types
        $row['state']            = (string) $row['state'];
        $row['is_running']       = (int) ($row['is_running'] ? 1 : 0);
        $row['total_urls']       = (int) $row['total_urls'];
        $row['processed_urls']   = (int) $row['processed_urls'];
        $row['total_assets']     = (int) $row['total_assets'];
        $row['processed_assets'] = (int) $row['processed_assets'];
        $row['updated_at']       = (string) $row['updated_at'];

        // Update row id=1 (create if missing)
        $updated = $wpdb->update(
            $table,
            [
                'state'            => $row['state'],
                'is_running'       => $row['is_running'],
                'total_urls'       => $row['total_urls'],
                'processed_urls'   => $row['processed_urls'],
                'total_assets'     => $row['total_assets'],
                'processed_assets' => $row['processed_assets'],
                'updated_at'       => $row['updated_at'],
            ],
            ['id' => 1],
            ['%s','%d','%d','%d','%d','%d','%s'],
            ['%d']
        );

        // If update didn’t affect a row, try INSERT (covers missing row)
        if ($updated === 0) {
            $wpdb->insert(
                $table,
                [
                    'id'               => 1,
                    'state'            => $row['state'],
                    'is_running'       => $row['is_running'],
                    'total_urls'       => $row['total_urls'],
                    'processed_urls'   => $row['processed_urls'],
                    'total_assets'     => $row['total_assets'],
                    'processed_assets' => $row['processed_assets'],
                    'updated_at'       => $row['updated_at'],
                ],
                ['%d','%s','%d','%d','%d','%d','%d','%s']
            );
            $updated = $wpdb->rows_affected;
        }

        // 🔥 HARD LOGGING (this will reveal the real problem)
        if (!empty($wpdb->last_error)) {
            $this->log('DB ERROR update_status: ' . $wpdb->last_error);
            $this->log('Last query: ' . $wpdb->last_query);
        } else {
            $this->log('update_status OK. rows_affected=' . (int) $updated);
        }

        return $updated;
    }
    public function process_background() {

        global $wpdb;

        if (WP_TO_HTML_DEBUG) { 
            $this->log('CRON blog_id=' . get_current_blog_id() . ' prefix=' . $wpdb->prefix . ' table=' . ($wpdb->prefix . 'wp_to_html_status'));

            $this->log('Start process bg event');
        }

        if (Advanced_Debugger::enabled()) {
            Advanced_Debugger::mark('tick_start', [
                'blog_id' => get_current_blog_id(),
                'ts_micro' => microtime(true),
            ]);
        }
        // --- Prevent concurrent runs (short lock + self-healing reschedule) ---
        // Why: REST start can run one tick inline while WP-Cron spawns in parallel.
        // If the spawned cron hits the lock and returns WITHOUT rescheduling, exports can stall.
        $lock_key = 'wp_to_html_export_lock';
        $lock_ttl = (int) apply_filters('wp_to_html_bg_lock_ttl_seconds', 45); // keep short; each tick is time-budgeted
        $lock_val = get_transient($lock_key);
        if ($lock_val) {
            if (WP_TO_HTML_DEBUG) { 
                $this->log('process_background: skipped (lock exists)');
            }

            // Ensure there is a next tick queued soon (self-heal "stuck after batch")
            $delay = (int) apply_filters('wp_to_html_bg_tick_delay_seconds', 1);
            $delay = max(1, $delay);
            $want  = time() + $delay;
            $next  = wp_next_scheduled('wp_to_html_process_event');

            // If nothing scheduled, overdue, or scheduled too far away, force a near-future tick.
            if (!$next || $next <= time() || $next > (time() + 5)) {
                wp_clear_scheduled_hook('wp_to_html_process_event');
                wp_schedule_single_event($want, 'wp_to_html_process_event');

                if (WP_TO_HTML_DEBUG) { 
                    $this->log('Rescheduled wp_to_html_process_event (lock)');
                }
            }

            // Nudge WP-Cron runner (non-blocking)
            wp_remote_post(site_url('/wp-cron.php?doing_wp_cron=' . time()), [
                'timeout'   => 0.01,
                'blocking'  => false,
                'sslverify' => false,
            ]);

            return;
        }
        // Store a timestamp so we can debug/inspect lock age in logs if needed.
        set_transient($lock_key, time(), $lock_ttl);

        $status_table = $wpdb->prefix . 'wp_to_html_status';
        $queue_table  = $wpdb->prefix . 'wp_to_html_queue';
        $assets_table = $wpdb->prefix . 'wp_to_html_assets';

        // Fired in finally (after lock release) to kick off the next tick in background.
        $fire_bg_nudge = false;

        try {

            // Load current status row
            $status = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$status_table} WHERE id=%d", 1));
            if (!$status) {
                $this->log('No status row found (id=1)');
                return;
            }

            // ✅ Pause support: if paused, do not process any batches and do not keep scheduling.
            // This is critical because WP-Cron can still fire even when the admin UI stops polling.
            if ((string)($status->state ?? '') === 'paused') {
                if (WP_TO_HTML_DEBUG) {
                    $this->log('process_background: paused (no work)');
                }
                wp_clear_scheduled_hook('wp_to_html_process_event');
                return;
            }

            // ✅ Stopped: no work should run.
            if ((string)($status->state ?? '') === 'stopped') {
                if (WP_TO_HTML_DEBUG) {
                    $this->log('process_background: stopped (no work)');
                }
                wp_clear_scheduled_hook('wp_to_html_process_event');
                return;
            }

            // ✅ Building queue: queue is still being populated — do not process yet.
            // Processing an empty queue would see total_urls=0 and mark the export as completed.
            if ((string)($status->state ?? '') === 'building_queue') {
                if (WP_TO_HTML_DEBUG) {
                    $this->log('process_background: skipped (queue still building)');
                }
                // Reschedule so processing starts once build_queue_tick transitions state to 'running'.
                $delay = max(1, (int) apply_filters('wp_to_html_bg_tick_delay_seconds', 2));
                if (!wp_next_scheduled('wp_to_html_process_event')) {
                    wp_schedule_single_event(time() + $delay, 'wp_to_html_process_event');
                }
                return;
            }

            // If an export is running but markers haven't changed for a while, write a stuck snapshot.
            if (Advanced_Debugger::enabled()) {
                Advanced_Debugger::stuck_check((array) $status, (int) apply_filters('wp_to_html_advanced_stuck_threshold_seconds', 120));
            }

            // Watchdog: reclaim stuck processing rows (URLs/assets) so exports never stall silently.
            // IMPORTANT: this must run regardless of Advanced_Debugger settings.
            $this->watchdog_repair($status, $queue_table, $assets_table, $status_table);

            $exporter = new \WpToHtml\Exporter();

            // ---------------- Adaptive batches-per-tick (auto) ----------------
            // Goal: go faster on strong servers (e.g., 3 batches/tick) but stay safe on slow/shared hosting (1 batch/tick).
            $stats_key = 'wp_to_html_adaptive_stats';
            $stats = get_option($stats_key, []);
            if (!is_array($stats)) $stats = [];

            $avg_batch_s = isset($stats['avg_batch_s']) ? (float) $stats['avg_batch_s'] : 2.5;
            $alpha       = (float) apply_filters('wp_to_html_bg_adaptive_alpha', 0.2);
            $budget_s    = (float) apply_filters('wp_to_html_bg_tick_time_budget_seconds', 18.0);
            $deadline    = microtime(true) + $budget_s;

            // memory_limit can be -1 (unlimited)
            $mem_limit_raw = (string) ini_get('memory_limit');
            $mem_limit = $this->parse_bytes($mem_limit_raw);
            $peak_mem  = (int) memory_get_peak_usage(true);

            $target_batches = $this->choose_batches($avg_batch_s, $peak_mem, $mem_limit);
            $target_batches = (int) apply_filters('wp_to_html_bg_target_batches', $target_batches, $stats, $status);
            $target_batches = max(1, min($target_batches, (int) apply_filters('wp_to_html_bg_target_batches_cap', 6)));

            if (WP_TO_HTML_DEBUG) { 
                $this->log('Adaptive tick: target_batches=' . $target_batches . ' avg_batch_s=' . round($avg_batch_s, 3) . ' mem_limit=' . $mem_limit_raw);
            }
            $loops = 0;
            while ($loops < $target_batches && microtime(true) < $deadline) {

                $t0 = microtime(true);

                $did_url   = $exporter->process_batch(10);
                $did_asset = $exporter->process_asset_batch(30);

                // If nothing left to do, stop looping.
                if (!$did_url && !$did_asset) {
                    break;
                }

                $sample = microtime(true) - $t0;

                // Update EWMA only when we actually did work.
                if ($sample > 0) {
                    $avg_batch_s = ($avg_batch_s <= 0)
                        ? $sample
                        : ((1 - $alpha) * $avg_batch_s + $alpha * $sample);
                }

                $peak_mem = max($peak_mem, (int) memory_get_peak_usage(true));

                $loops++;

                // Mid-loop DB update: write live counts after each batch so polling
                // reads fresh progress even when this tick holds the lock.
                $wpdb->update($status_table, [
                    'processed_urls'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status='done'"),
                    'failed_urls'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status='failed'"),
                    'total_urls'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table}"),
                    'processed_assets' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assets_table} WHERE status='done'"),
                    'failed_assets'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assets_table} WHERE status='failed'"),
                    'total_assets'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assets_table}"),
                    'updated_at'       => current_time('mysql'),
                ], ['id' => 1]);

                // Mid-tick stop/pause check: the user may have clicked Stop or Pause
                // while this tick was running. Re-read state from DB after each completed
                // batch and bail immediately so the next batch never starts.
                // This is the earliest safe exit point — the current batch is fully done
                // and counts are already written to DB.
                $live_state = (string) $wpdb->get_var(
                    $wpdb->prepare("SELECT state FROM {$status_table} WHERE id=%d", 1)
                );
                if (in_array($live_state, ['stopped', 'paused'], true)) {
                    break;
                }
            }

            if (Advanced_Debugger::enabled()) {
                Advanced_Debugger::mark('tick_work_done', [
                    'loops' => $loops,
                    'target_batches' => $target_batches,
                    'avg_batch_s' => $avg_batch_s,
                    'peak_mem' => $peak_mem,
                    'mem_limit_raw' => $mem_limit_raw,
                ]);
            }

            // Persist stats for next ticks (only for current blog/site).
            $stats['avg_batch_s']     = $avg_batch_s;
            $stats['peak_mem']        = $peak_mem;
            $stats['last_tick_loops'] = $loops;
            $stats['updated_at']      = time();
            update_option($stats_key, $stats, false);

            // Post-loop stopped/paused guard.
            // Without this, the status DB update below would blindly overwrite
            // 'stopped' → 'running' (and is_running → 1), re-arm the cron, fire
            // the bg nudge, and send a second API completion report when the
            // rescheduled tick eventually finishes — causing duplicate rows in the
            // API dashboard. The return is inside the try block so the finally
            // clause still fires: it releases the transient lock and, since
            // $fire_bg_nudge is never set, skips the bg nudge entirely.
            $post_loop_state = (string) $wpdb->get_var(
                $wpdb->prepare("SELECT state FROM {$status_table} WHERE id=%d", 1)
            );
            if (in_array($post_loop_state, ['stopped', 'paused'], true)) {
                return;
            }

            // ------------------------------------------------------------------
            // Recalculate counters from DB
            $total_urls       = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table}");
            $processed_urls   = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status='done'");
            $failed_urls      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status='failed'");

            $total_assets     = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assets_table}");
            $processed_assets = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assets_table} WHERE status='done'");
            $failed_assets    = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$assets_table} WHERE status='failed'");

            // Pipeline stage (quality-of-life): makes progress more interpretable.
            $pipeline_stage = 'fetch_urls';
            $stage_total    = $total_urls;
            $stage_done     = ($processed_urls + $failed_urls);

            if ($total_urls > 0 && ($processed_urls + $failed_urls) >= $total_urls) {
                $pipeline_stage = 'fetch_assets';
                $stage_total    = $total_assets;
                $stage_done     = ($processed_assets + $failed_assets);
            }
            if ($total_urls > 0 && ($processed_urls + $failed_urls) >= $total_urls && $total_assets > 0 && ($processed_assets + $failed_assets) >= $total_assets) {
                $pipeline_stage = 'wrapup';
                $stage_total    = 1;
                $stage_done     = 1;
            }

            // --------------------------------------------------------------
            // Progress log heartbeat
            // When assets succeed quietly (only failures are logged), the UI log can look "stuck"
            // even though background work is still running.
            // Emit lightweight periodic progress markers for URL + asset phases.
            // --------------------------------------------------------------
            try {
                $meta_key = 'wp_to_html_progress_log_meta';
                $meta = get_option($meta_key, []);
                if (!is_array($meta)) $meta = [];

                $prev_stage = isset($meta['stage']) ? (string) $meta['stage'] : '';
                $prev_done  = isset($meta['done']) ? (int) $meta['done'] : -1;
                $prev_ts    = isset($meta['ts']) ? (int) $meta['ts'] : 0;

                $now_ts   = time();
                $cur_stage = (string) $pipeline_stage;
                $cur_done  = (int) $stage_done;
                $cur_total = (int) $stage_total;

                // Stage change marker
                if ($cur_stage !== '' && $cur_stage !== $prev_stage) {
                    if (method_exists($exporter, 'log_public')) {
                        if ($cur_stage === 'fetch_assets') {
                            $exporter->log_public('URL export finished. Downloading assets...');
                        } elseif ($cur_stage === 'wrapup') {
                            $exporter->log_public('Assets finished. Finalizing export...');
                        }
                    }
                    // Reset counters so we log soon after stage switch.
                    $prev_done = -1;
                    $prev_ts   = 0;
                }

                // Rate limits: URLs log less often; assets log more often but still capped.
                $min_interval = ($cur_stage === 'fetch_assets') ? 6 : 12; // seconds
                $min_delta    = ($cur_stage === 'fetch_assets') ? 25 : 10; // items

                $should_log = false;
                if ($cur_total > 0) {
                    if ($prev_done < 0) {
                        $should_log = true;
                    } elseif (($cur_done - $prev_done) >= $min_delta) {
                        $should_log = true;
                    } elseif (($now_ts - $prev_ts) >= $min_interval && $cur_done !== $prev_done) {
                        $should_log = true;
                    }
                }

                if ($should_log && method_exists($exporter, 'log_public')) {
                    if ($cur_stage === 'fetch_assets') {
                        $exporter->log_public('Assets progress: ' . ($processed_assets + $failed_assets) . '/' . $total_assets . ' (failed ' . $failed_assets . ')');
                    } else {
                        $exporter->log_public('URLs progress: ' . ($processed_urls + $failed_urls) . '/' . $total_urls . ' (failed ' . $failed_urls . ')');
                    }
                    $meta['stage'] = $cur_stage;
                    $meta['done']  = $cur_done;
                    $meta['ts']    = $now_ts;
                    update_option($meta_key, $meta, false);
                } elseif ($cur_stage !== '' && $cur_stage !== $prev_stage) {
                    // Persist stage even if we didn't log (should be rare).
                    $meta['stage'] = $cur_stage;
                    $meta['done']  = $cur_done;
                    $meta['ts']    = $now_ts;
                    update_option($meta_key, $meta, false);
                }
            } catch (\Throwable $elog) {
                // Never fail the export due to logging.
            }

            // Decide completion based on DONE + FAILED counts (failed is still "terminal").
$all_urls_done   = ($total_urls <= 0) ? true : (($processed_urls + $failed_urls) >= $total_urls);
$all_assets_done = ($total_assets <= 0) ? true : (($processed_assets + $failed_assets) >= $total_assets);
$is_finished     = ($all_urls_done && $all_assets_done);

// Create zip + mark completed once everything is terminal.
$zip_info = null;
if ($is_finished) {
    // Signal wrapup state BEFORE starting ZIP so concurrent polls can show
    // "Creating ZIP" notice in the UI while this tick holds the lock.
    $wpdb->update($status_table, [
        'pipeline_stage' => 'wrapup',
        'stage_total'    => 1,
        'stage_done'     => 0,
        'updated_at'     => current_time('mysql'),
    ], ['id' => 1]);

    // Always emit a completion summary before zipping so the admin log
    // definitively shows terminal counts even if the UI stops polling immediately.
    if (method_exists($exporter, 'log_public')) {
        $exporter->log_public('URLs exported: ' . ($processed_urls + $failed_urls) . '/' . $total_urls . ' (failed ' . $failed_urls . ')');
        $exporter->log_public('Assets downloaded: ' . ($processed_assets + $failed_assets) . '/' . $total_assets . ' (failed ' . $failed_assets . ')');
    }
    // "Super smart" ZIP behavior:
    // - Auto-ZIP is great for small/medium sites.
    // - On huge sites, zipping can be the single heaviest step and may hang PHP-FPM/Apache.
    // We therefore auto-skip ZIP above a work threshold and let the user download
    // group zips on-demand (html/images/css/js...) via REST.
    $auto_zip_enabled = (bool) apply_filters('wp_to_html_auto_zip_enabled', true);
    $auto_zip_max_work = (int) apply_filters('wp_to_html_auto_zip_max_work', 5000);
    $total_work = (int) $total_urls + (int) $total_assets;

    // Reset marker for this run.
    update_option('wp_to_html_zip_skipped', 0, false);

    if ($auto_zip_enabled && ($auto_zip_max_work <= 0 || $total_work <= $auto_zip_max_work)) {
        // Don't attempt ZIP if nothing was exported — avoids a misleading failure message.
        if ($total_work === 0) {
            if (method_exists($exporter, 'log_public')) {
                $exporter->log_public('ZIP skipped: no content was exported. Check your export scope and selected items.');
            }
        } else {
            try {
                $zip_info = $this->create_export_zip();
                if ($zip_info) {
                    update_option('wp_to_html_last_zip', $zip_info, false);
                    if (method_exists($exporter, 'log_public')) {
                        // $zip_info is now an array of zip parts
                        if (is_array($zip_info) && isset($zip_info[0]) && is_array($zip_info[0])) {
                            $total_parts = count($zip_info);
                            $exporter->log_public('ZIP created: ' . $total_parts . ' part(s) ready for download.');
                            foreach ($zip_info as $zi) {
                                $bytes = (int)($zi['size'] ?? 0);
                                if ($bytes >= 1073741824) { $size_str = round($bytes / 1073741824, 2) . ' GB'; }
                                elseif ($bytes >= 1048576)  { $size_str = round($bytes / 1048576, 2) . ' MB'; }
                                else                         { $size_str = round($bytes / 1024, 1) . ' KB'; }
                                $exporter->log_public('ZIP part ' . (int)$zi['part'] . '/' . (int)$zi['total_parts'] . ': ' . (string)$zi['file'] . ' (' . (int)$zi['file_count'] . ' files, ' . $size_str . ')');
                            }
                        } else {
                            $bytes = (int)($zip_info['size'] ?? 0);
                            if ($bytes >= 1073741824) { $size_str = round($bytes / 1073741824, 2) . ' GB'; }
                            elseif ($bytes >= 1048576)  { $size_str = round($bytes / 1048576, 2) . ' MB'; }
                            else                         { $size_str = round($bytes / 1024, 1) . ' KB'; }
                            $file_name  = (string)($zip_info['file'] ?? '');
                            $file_count = (int)($zip_info['file_count'] ?? 0);
                            $meta = ($file_count ? $file_count . ' files, ' : '') . $size_str;
                            $exporter->log_public('ZIP created: ' . $file_name . ($meta ? ' (' . $meta . ')' : ''));
                        }
                    }
                } else {
                    if (method_exists($exporter, 'log_public')) {
                        $exporter->log_public('ZIP creation failed (no file returned). Check that PHP ZipArchive is enabled and the export directory is writable.');
                    }
                }
            } catch (\Throwable $ezip) {
                if (method_exists($exporter, 'log_public')) {
                    $exporter->log_public('ZIP creation failed: ' . $ezip->getMessage());
                }
            }
        }
    } else {
        // Skip ZIP for huge exports.
        delete_option('wp_to_html_last_zip');
        update_option('wp_to_html_zip_skipped', 1, false);
        if (method_exists($exporter, 'log_public')) {
            $exporter->log_public('ZIP skipped (large export). Use grouped downloads (HTML/images/css/js) or FTP/S3 upload.');
        }
    }
}

// Update status (include failed counts so UI can finish even with 404s).
$wpdb->update($status_table, [
    'state'            => $is_finished ? 'completed' : 'running',
    'is_running'       => $is_finished ? 0 : 1,
    'total_urls'       => $total_urls,
    'processed_urls'   => $processed_urls,
    'total_assets'     => $total_assets,
    'processed_assets' => $processed_assets,
    'failed_assets'    => (int) $failed_assets,
    'pipeline_stage'   => $pipeline_stage,
    'stage_total'      => (int) $stage_total,
    'stage_done'       => (int) $stage_done,
    'failed_urls'      => (int) $failed_urls,
    'updated_at'       => current_time('mysql'),
], ['id' => 1]);

// Scheduling:
// - If finished: stop background work.
// - Else: schedule the next tick (best-effort) to keep WP-Cron flowing even if /status polling stops.
if ($is_finished) {
    wp_clear_scheduled_hook('wp_to_html_process_event');

    // Completion email (optional)
    $no_failures = (($failed_urls + $failed_assets) === 0);
    $this->maybe_send_completion_email($no_failures, $zip_info);

    // Send export report to ReCorp API server (non-blocking).
    $this->send_export_report_to_api($no_failures, [
        'total_urls'       => $total_urls,
        'processed_urls'   => $processed_urls,
        'failed_urls'      => $failed_urls,
        'total_assets'     => $total_assets,
        'processed_assets' => $processed_assets,
        'failed_assets'    => $failed_assets,
        'zip_info'         => $zip_info,
    ]);
} else {
    // Schedule immediately so the bg nudge (fired after lock release) can pick it up.
    wp_clear_scheduled_hook('wp_to_html_process_event');
    wp_schedule_single_event(time(), 'wp_to_html_process_event');
    $fire_bg_nudge = true;
}} catch (\Throwable $e) {

            // Unexpected exception: report as 'failed' so it's visible in the API dashboard,
            // then re-throw so the error still propagates normally.
            try {
                $this->send_export_report_to_api(false, ['exception' => $e->getMessage()]);
            } catch (\Throwable $e2) {
                // Never let the report call swallow the original exception.
            }
            throw $e;

        } finally {

            if (Advanced_Debugger::enabled()) {
                Advanced_Debugger::mark('tick_end', [
                    'ts_micro' => microtime(true),
                ]);
            }

            delete_transient($lock_key);

            // Fire WP-Cron in the background AFTER releasing the lock.
            // This lets the next tick run as a separate PHP process so subsequent
            // polls return quickly with live DB values instead of blocking on inline drive.
            if (!empty($fire_bg_nudge)) {
                wp_remote_post(site_url('/wp-cron.php?doing_wp_cron=' . time()), [
                    'timeout'   => 0.01,
                    'blocking'  => false,
                    'sslverify' => false,
                ]);
            }

        }
    }

    
    /**
     * Create zip archives of the export directory, splitting files into groups.
     *
     * Files per zip is controlled by the filter `wp_to_html_zip_files_per_part` (default: 1000).
     * Returns an array of zip info objects:
     *   [ ['file'=>'...zip', 'size'=>123, 'created_at'=>'...', 'part'=>1, 'total_parts'=>N], ... ]
     * or null on failure.
     */
    private function create_export_zip() {

        // ZipArchive may be disabled on some hosts
        if (!class_exists('\\ZipArchive')) {
            return null;
        }

        $export_dir = WP_TO_HTML_EXPORT_DIR;
        if (!is_dir($export_dir)) {
            return null;
        }

        $export_dir_real = realpath($export_dir);
        if (!$export_dir_real) {
            return null;
        }

        // --- Collect all files to zip ---
        $all_files = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($export_dir_real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $file) {
            $file_path = $file->getRealPath();
            if (!$file_path) continue;
            if (preg_match('/\\.zip$/i', $file_path)) continue;
            if (basename($file_path) === 'export-log.txt') continue;
            $rel_path = ltrim(str_replace($export_dir_real, '', $file_path), DIRECTORY_SEPARATOR);
            if (!$file->isDir()) {
                $all_files[] = ['abs' => $file_path, 'rel' => $rel_path];
            }
        }

        if (empty($all_files)) {
            return null;
        }

        // --- Determine part size (files per zip) ---
        // Default: 1000 files per zip part (safe for most hosts). Use filter 'wp_to_html_zip_files_per_part' to override.
        $files_per_part = (int) apply_filters('wp_to_html_zip_files_per_part', 1000);
        if ($files_per_part <= 0) $files_per_part = 1000;

        $total_files = count($all_files);
        $total_parts = (int) ceil($total_files / $files_per_part);
        if ($total_parts < 1) $total_parts = 1;

        // --- Naming ---
        $site        = sanitize_title(get_bloginfo('name'));
        $ts          = current_time('Ymd-His');
        $ctx         = get_option('wp_to_html_export_context', []);
        $single_slug = '';
        if (is_array($ctx) && !empty($ctx['single_root_index']) && !empty($ctx['single_slug'])) {
            $single_slug = sanitize_title((string) $ctx['single_slug']);
        }
        $base_name = $single_slug ? "wp-to-html-{$site}-{$single_slug}-{$ts}" : "wp-to-html-{$site}-{$ts}";

        // --- Create zip parts ---
        $zips_info = [];
        $chunks    = array_chunk($all_files, $files_per_part);

        foreach ($chunks as $part_idx => $chunk) {
            $part_num = $part_idx + 1;
            $suffix   = ($total_parts > 1) ? "-part{$part_num}of{$total_parts}" : '';
            $zip_fn   = "{$base_name}{$suffix}.zip";
            $zip_fp   = trailingslashit($export_dir) . $zip_fn;

            $zip = new \ZipArchive();
            if ($zip->open($zip_fp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                continue;
            }

            foreach ($chunk as $f) {
                $zip->addFile($f['abs'], $f['rel']);
            }

            $zip->close();

            if (!file_exists($zip_fp)) continue;

            $zips_info[] = [
                'file'        => $zip_fn,
                'size'        => (int) filesize($zip_fp),
                'created_at'  => current_time('mysql'),
                'part'        => $part_num,
                'total_parts' => $total_parts,
                'file_count'  => count($chunk),
            ];
        }

        if (empty($zips_info)) {
            return null;
        }

        // Return array of zip info objects (even if just 1 part).
        return $zips_info;
    }
    /**
     * Delete the temporary export user created for "Export as".
     * Only removes users flagged with user meta wp_to_html_temp_export_user=1.
     */
    private function cleanup_temp_export_user() {
        $ctx = get_option('wp_to_html_export_context', []);
        if (!is_array($ctx) || empty($ctx['export_user_id'])) return;
        $uid = (int) $ctx['export_user_id'];
        if ($uid <= 0) return;

        $flag = (int) get_user_meta($uid, 'wp_to_html_temp_export_user', true);
        if ($flag !== 1) return;

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        @wp_delete_user($uid);

        $ctx['export_user_id'] = 0;
        update_option('wp_to_html_export_context', $ctx, false);
    }


private function log($message) {

        $time = date('H:i:s');
        $line = "[$time] " . $message . PHP_EOL;

        $log_file = WP_TO_HTML_EXPORT_DIR . '/export-log.txt';
        $log_dir  = dirname($log_file);

        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        if (!file_exists($log_file)) {
            @touch($log_file);
        }

        @file_put_contents($log_file, $line, FILE_APPEND);
    }

    /**
     * Parse php.ini memory strings (e.g., "128M", "1G", "-1") into bytes.
 (e.g., "128M", "1G", "-1") into bytes.
     * Returns -1 for unlimited.
     */
    private function parse_bytes($val) {
        $val = is_string($val) ? trim($val) : '';
        if ($val === '' ) return 0;
        if ($val === '-1') return -1;

        $last = strtolower(substr($val, -1));
        $num  = (float) $val;

        switch ($last) {
            case 'g': $num *= 1024;
            case 'm': $num *= 1024;
            case 'k': $num *= 1024;
        }
        return (int) $num;
    }

    /**
     * Choose batches per tick based on observed performance.
     */
    private function choose_batches($avg_batch_s, $peak_mem, $mem_limit_bytes) {
        $avg_batch_s = (float) $avg_batch_s;
        $peak_mem    = (int) $peak_mem;
        $mem_limit_bytes = (int) $mem_limit_bytes;

        // Unlimited memory: treat as OK.
        $mem_ok = true;
        if ($mem_limit_bytes > 0) {
            $mem_ok = ($peak_mem < (0.6 * $mem_limit_bytes));
        }

        if ($avg_batch_s <= 1.8 && $mem_ok) return 3;
        if ($avg_batch_s <= 3.5 && $mem_ok) return 2;
        return 1;
    }

    // ---------------------------------------------------------------------
    // Delivery options (FTP + email)

    private function maybe_upload_zip_to_ftp($zip_info) {
        $ctx = get_option('wp_to_html_export_context', []);
        if (!is_array($ctx) || empty($ctx['upload_to_ftp'])) return;

        $remote_path = isset($ctx['ftp_remote_path']) ? (string) $ctx['ftp_remote_path'] : '';
        $remote_path = \WpToHtml\FTP_Uploader::normalize_remote_path($remote_path);
        if ($remote_path === '') {
            $this->log('FTP upload skipped: remote path is empty.');
            return;
        }

        $settings = get_option('wp_to_html_ftp_settings', []);
        $settings = is_array($settings) ? $settings : [];
        // Decrypt stored password.
        if (!empty($settings['pass'])) {
            $settings['pass'] = \WpToHtml\FTP_Uploader::decrypt_credential((string) $settings['pass']);
        }
        if (empty($settings['host']) || empty($settings['user'])) {
            $this->log('FTP upload skipped: FTP settings not configured (Settings tab).');
            return;
        }

        $zip_fn = isset($zip_info['file']) ? (string) $zip_info['file'] : '';

        // Handle new multi-part format: upload each part
        if ($zip_fn === '' && is_array($zip_info) && isset($zip_info[0]) && is_array($zip_info[0])) {
            foreach ($zip_info as $zi) {
                $this->maybe_upload_zip_to_ftp($zi);
            }
            return;
        }

        if ($zip_fn === '') {
            $this->log('FTP upload skipped: ZIP filename missing.');
            return;
        }

        $local_zip_fp = trailingslashit(WP_TO_HTML_EXPORT_DIR) . $zip_fn;
        $this->log('FTP upload: starting upload to ' . $remote_path . ' …');

        $msg = '';
        $ok = \WpToHtml\FTP_Uploader::upload_zip($settings, $local_zip_fp, $remote_path, $msg);
        if ($ok) {
            $this->log('FTP upload: ' . $msg);
        } else {
            $this->log('FTP upload failed: ' . $msg);
        }
    }

    private function maybe_send_completion_email($success, $zip_info = null) {
        $ctx = get_option('wp_to_html_export_context', []);
        if (!is_array($ctx) || empty($ctx['notify_complete'])) return;

        $recipients = [];

        // Always include the initiating admin if available.
        $initiator_id = isset($ctx['initiator_user_id']) ? (int) $ctx['initiator_user_id'] : 0;
        if ($initiator_id > 0) {
            $u = get_user_by('id', $initiator_id);
            if ($u && !empty($u->user_email)) {
                $recipients[] = $u->user_email;
            }
        } else {
            // Fallback: current user (may be 0 in cron). Use admin email.
            $admin_email = get_option('admin_email');
            if ($admin_email) $recipients[] = $admin_email;
        }

        $extra = isset($ctx['notify_emails']) ? (string) $ctx['notify_emails'] : '';
        if ($extra !== '') {
            foreach (explode(',', $extra) as $e) {
                $e = sanitize_email(trim($e));
                if ($e) $recipients[] = $e;
            }
        }

        $recipients = array_values(array_unique(array_filter($recipients)));
        if (empty($recipients)) return;

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $status_txt = $success ? __('completed', 'wp-to-html') : __('failed', 'wp-to-html');
        /* translators: 1: site name, 2: status text (completed/failed) */
        $subject = sprintf(__('[%1\$s] WP to HTML export %2\$s', 'wp-to-html'), $site, $status_txt);

        $admin_url = admin_url('tools.php?page=wp-to-html');
        $lines = [];
        /* translators: %s: export status */
        $lines[] = sprintf(__('WP to HTML export %s.', 'wp-to-html'), $status_txt);
        $lines[] = '';
        /* translators: %s: site name */
        $lines[] = sprintf(__('Site: %s', 'wp-to-html'), $site);
        /* translators: %s: time string */
        $lines[] = sprintf(__('Time: %s', 'wp-to-html'), current_time('mysql'));

        if ($zip_info && !empty($zip_info['file'])) {
            /* translators: %s: zip filename */
            $lines[] = sprintf(__('ZIP: %s', 'wp-to-html'), (string) $zip_info['file']);
        }

        $lines[] = '';
        $lines[] = __('Open Export WP Pages to Static HTML to download/view logs:', 'wp-to-html');
        $lines[] = $admin_url;

        $body = implode("\n", $lines);

        // Best-effort mail.
        @wp_mail($recipients, $subject, $body);
    

    }

    /**
     * Send export report to ReCorp API server after export completes, fails, or is stopped.
     *
     * API endpoint: https://api.myrecorp.com/wpptsh-report.php?type=error_log
     *
     * On SUCCESS (no failures): sends outcome + stats only — no log text.
     * On FAILURE / STOP: sends outcome + stats + full export log text so the
     * API server can store it for debugging.
     *
     * Non-blocking: uses wp_remote_post with 0.5s timeout (slightly higher than
     * fire-and-forget to ensure the body reaches the server on large payloads).
     *
     * @param bool  $success  Whether the export completed without any errors.
     * @param array $stats    Export statistics (urls, assets, zip info, etc.).
     */
    public function send_export_report_to_api(bool $success, array $stats = []): void {

        $api_url = 'https://api.myrecorp.com/wpptsh-report.php?type=error_log';

        /**
         * Filter the API endpoint for export reports.
         * Return empty string to disable reporting entirely.
         *
         * @param string $api_url  The API endpoint URL.
         */
        $api_url = (string) apply_filters('wp_to_html_export_report_api_url', $api_url);
        if ($api_url === '') {
            return;
        }

        // Determine outcome.
        $outcome = 'completed';
        if (!$success && !empty($stats['exception'])) {
            $outcome = 'stopped_with_error';
        } elseif (!$success && !empty($stats['stopped'])) {
            $outcome = 'stopped_by_user';
        } elseif (!$success) {
            $outcome = 'completed_with_failures';
        }

        $ctx = get_option('wp_to_html_export_context', []);
        if (!is_array($ctx)) $ctx = [];

        $scope      = isset($ctx['scope']) ? (string) $ctx['scope'] : 'unknown';
        $asset_mode = isset($ctx['asset_collection_mode']) ? (string) $ctx['asset_collection_mode'] : 'strict';

        $t_urls   = (int) ($stats['total_urls']       ?? 0);
        $p_urls   = (int) ($stats['processed_urls']   ?? 0);
        $f_urls   = (int) ($stats['failed_urls']      ?? 0);
        $t_assets = (int) ($stats['total_assets']     ?? 0);
        $p_assets = (int) ($stats['processed_assets'] ?? 0);
        $f_assets = (int) ($stats['failed_assets']    ?? 0);

        $is_pro = defined('WP_TO_HTML_PRO_ACTIVE') && WP_TO_HTML_PRO_ACTIVE;

        // Build compact status summary (backward compat with old API column).
        $status_parts = [
            $outcome,
            'scope=' . $scope,
            'urls=' . $p_urls . '/' . $t_urls . ($f_urls > 0 ? '(f' . $f_urls . ')' : ''),
            'assets=' . $p_assets . '/' . $t_assets . ($f_assets > 0 ? '(f' . $f_assets . ')' : ''),
            $is_pro ? 'pro' : 'free',
        ];
        $status_string = implode(' | ', $status_parts);

        // Build structured payload.
        $payload = [
            'site_url'         => home_url('/'),
            'outcome'          => $outcome,
            'status'           => $status_string,
            'plugin_version'   => WP_TO_HTML_VERSION,
            'wp_version'       => get_bloginfo('version'),
            'php_version'      => phpversion(),
            'scope'            => $scope,
            'total_urls'       => $t_urls,
            'processed_urls'   => $p_urls,
            'failed_urls'      => $f_urls,
            'total_assets'     => $t_assets,
            'processed_assets' => $p_assets,
            'failed_assets'    => $f_assets,
        ];

        // On failure or stop: include the full export log text so the API
        // server can store it for remote debugging.
        // On clean completion: no log text needed (saves bandwidth).
        if ($outcome !== 'completed') {
            $log_text = $this->read_export_log_for_report();
            if ($log_text !== '') {
                $payload['error_log'] = $log_text;
            }
        }

        /**
         * Filter the export report payload before sending.
         *
         * @param array  $payload  The report data.
         * @param bool   $success  Whether export was successful.
         * @param array  $stats    Raw statistics.
         * @param string $outcome  One of: completed, completed_with_failures, stopped, failed.
         */
        $payload = (array) apply_filters('wp_to_html_export_report_payload', $payload, $success, $stats, $outcome);

        // Blocking=true with a reasonable timeout ensures the TCP+TLS handshake
        // and the full request body actually reach the server before PHP moves on.
        // Non-blocking with tiny timeouts (< TLS handshake latency) silently drops
        // the connection before any data is transmitted.
        $has_log = !empty($payload['error_log']);

        @wp_remote_post($api_url, [
            'timeout'    => 15,
            'blocking'   => true,
            'sslverify'  => true,
            'headers'    => [
                'Content-Type' => 'application/json',
                'User-Agent'   => 'WpToHtml/' . WP_TO_HTML_VERSION . '; ' . home_url('/'),
            ],
            'body'       => wp_json_encode($payload),
        ]);

        $this->log('Export report sent to API (' . $outcome . ($has_log ? ', with log' : '') . ').');
    }

    /**
     * Read the export log file for inclusion in the API report.
     * Returns the last 64 KB of the log (tail) to keep payloads reasonable.
     * The most useful debugging info is at the end of the log.
     *
     * @return string Log text or empty string.
     */
    private function read_export_log_for_report(): string {
        $log_file = WP_TO_HTML_EXPORT_DIR . '/export-log.txt';

        if (!file_exists($log_file) || !is_readable($log_file)) {
            return '';
        }

        $max_bytes = 64 * 1024; // 64 KB cap
        $size = (int) @filesize($log_file);

        if ($size <= 0) {
            return '';
        }

        // If file is small enough, read it all.
        if ($size <= $max_bytes) {
            $content = @file_get_contents($log_file);
            return is_string($content) ? $content : '';
        }

        // File is larger than cap: read the last 64 KB (tail).
        $fp = @fopen($log_file, 'rb');
        if (!$fp) {
            return '';
        }

        fseek($fp, -$max_bytes, SEEK_END);
        $content = @fread($fp, $max_bytes);
        fclose($fp);

        if (!is_string($content) || $content === '') {
            return '';
        }

        // Skip the first partial line (we likely landed mid-line).
        $nl = strpos($content, "\n");
        if ($nl !== false && $nl < 200) {
            $content = substr($content, $nl + 1);
        }

        return "... (truncated, last 64KB) ...\n" . $content;
    }


/**
 * Watchdog: reclaim stuck rows left in `processing` due to PHP fatals/timeouts/network hangs.
 * This prevents silent stalls.
 */
private function watchdog_repair($status_row, string $queue_table, string $assets_table, string $status_table): void {
    global $wpdb;

    // Only run watchdog when export is running.
    if ((int) ($status_row->is_running ?? 0) !== 1) return;

    $ttl_url   = (int) apply_filters('wp_to_html_watchdog_url_ttl_seconds', 120);
    $ttl_asset = (int) apply_filters('wp_to_html_watchdog_asset_ttl_seconds', 180);

    $repairs = 0;

    $repairs += $this->watchdog_reclaim_table(
        $queue_table,
        'url',
        $ttl_url,
        (int) apply_filters('wp_to_html_url_max_retries', 3),
        (int) apply_filters('wp_to_html_url_retry_backoff_base_seconds', 5),
        (int) apply_filters('wp_to_html_url_retry_backoff_cap_seconds', 120)
    );

    $repairs += $this->watchdog_reclaim_table(
        $assets_table,
        'asset',
        $ttl_asset,
        (int) apply_filters('wp_to_html_asset_max_retries', 3),
        (int) apply_filters('wp_to_html_asset_retry_backoff_base_seconds', 5),
        (int) apply_filters('wp_to_html_asset_retry_backoff_cap_seconds', 180)
    );

    // Update watchdog counters in status.
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$status_table} SET watchdog_runs = watchdog_runs + 1, watchdog_repairs = watchdog_repairs + %d WHERE id=%d",
            (int) $repairs,
            1
        )
    );

    if ($repairs > 0) {
        $this->log('WATCHDOG: reclaimed ' . (int)$repairs . ' stuck item(s) from processing.');
    }
}

private function watchdog_reclaim_table(string $table, string $kind, int $ttl_seconds, int $max_retries, int $base_backoff, int $cap_backoff): int {
    global $wpdb;

    $ttl_seconds = max(30, $ttl_seconds);
    $max_retries = max(0, $max_retries);
    $base_backoff = max(1, $base_backoff);
    $cap_backoff  = max($base_backoff, $cap_backoff);

    // Find stuck rows. If started_at is null (older schema), treat as stuck.
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT id, url, retry_count, started_at FROM {$table} WHERE status='processing' AND (started_at IS NULL OR started_at < (NOW() - INTERVAL %d SECOND)) ORDER BY id ASC LIMIT 200",
            $ttl_seconds
        )
    );

    if (!$rows) return 0;

    $reclaimed = 0;

    foreach ($rows as $r) {
        $id = (int) $r->id;
        $url = (string) $r->url;
        $retry = isset($r->retry_count) ? (int) $r->retry_count : 0;
        $retry++;

        $delay = (int) ($base_backoff * pow(2, max(0, $retry - 1)));
        if ($delay > $cap_backoff) $delay = $cap_backoff;
        $next_attempt = gmdate('Y-m-d H:i:s', time() + $delay);

        if ($retry <= $max_retries) {
            $wpdb->update(
                $table,
                [
                    'status'          => 'pending',
                    'retry_count'     => $retry,
                    'last_error'      => 'watchdog_reclaim',
                    'last_attempt_at' => current_time('mysql'),
                    'next_attempt_at' => $next_attempt,
                    'started_at'      => null,
                ],
                ['id' => $id]
            );
            $this->log('WATCHDOG: rescheduled ' . $kind . ' (attempt ' . $retry . '/' . $max_retries . ', +' . $delay . 's): ' . $url);
        } else {
            $wpdb->update(
                $table,
                [
                    'status'          => 'failed',
                    'retry_count'     => $retry,
                    'last_error'      => 'watchdog_reclaim_maxed',
                    'last_attempt_at' => current_time('mysql'),
                    'next_attempt_at' => null,
                    'started_at'      => null,
                ],
                ['id' => $id]
            );
            $this->log('WATCHDOG: marked ' . $kind . ' failed permanently after reclaim (attempt ' . $retry . '): ' . $url);
        }

        $reclaimed++;
    }

    return $reclaimed;
}


}
