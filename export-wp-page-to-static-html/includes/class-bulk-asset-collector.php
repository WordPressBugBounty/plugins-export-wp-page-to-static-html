<?php
namespace WpToHtml;

/**
 * Bulk Asset Collector
 *
 * Purpose: Provide Simply-Static-like "asset coverage beyond what HTML references".
 *
 * This collector enqueues additional asset URLs into the wp_to_html_assets table so the
 * existing asset downloader can fetch/copy them.
 *
 * Modes:
 *  - strict: current behavior (only referenced assets)
 *  - hybrid: referenced + media discovered from DB (attachments + common meta URL strings)
 *  - full:   hybrid + bulk filesystem crawl (uploads + theme + plugin + wp-includes + vendor/static)
 */
class Bulk_Asset_Collector {

    /** @var callable|null */
    private $logger;

    /** @var int */
    private $debug_asset_log_count = 0;

    /** @var int */
    private $debug_asset_log_cap = 200;

    public function __construct($logger = null) {
        $this->logger = is_callable($logger) ? $logger : null;

        // Prevent runaway logs in debug mode.
        $cap = (int) apply_filters('wp_to_html_debug_hybrid_asset_log_cap', 200);
        $this->debug_asset_log_cap = max(0, min(5000, $cap));
    }

    private function log(string $msg): void {
        if ($this->logger) {
            call_user_func($this->logger, $msg);
            return;
        }
        if (defined('WP_TO_HTML_DEBUG') && WP_TO_HTML_DEBUG) {
            $log_file = rtrim(WP_TO_HTML_EXPORT_DIR, '/\\') . '/export-log.txt';
            @wp_mkdir_p(dirname($log_file));
            @file_put_contents($log_file, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
        }
    }

    /**
     * Enqueue assets for a given collection mode.
     * Returns number of unique URLs inserted.
     */
    public function enqueue_for_mode(string $mode): int {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['strict', 'hybrid', 'full'], true)) {
            $mode = 'strict';
        }

        if ($mode === 'strict') {
            $this->log('Asset collection mode: strict (no bulk enqueue)');
            return 0;
        }

        $inserted = 0;
        // Determine Hybrid scope based on export context.
        $ctx = get_option('wp_to_html_export_context', []);
        $scope = is_array($ctx) && !empty($ctx['scope']) ? strtolower((string) $ctx['scope']) : '';
        $hybrid_scope = is_array($ctx) && !empty($ctx['hybrid_scope']) ? strtolower((string)$ctx['hybrid_scope']) : 'sitewide';
        if (!in_array($hybrid_scope, ['selected','sitewide'], true)) $hybrid_scope = 'sitewide';

        $selected_post_ids = [];
        if (is_array($ctx) && !empty($ctx['selected_post_ids']) && is_array($ctx['selected_post_ids'])) {
            $selected_post_ids = array_values(array_unique(array_filter(array_map('intval', $ctx['selected_post_ids']))));
        }

        /**
         * IMPORTANT SCOPE RULE:
         * If the export scope is NOT full_site, Hybrid MUST NOT behave site-wide.
         * Otherwise it will enqueue ALL attachments/postmeta URLs and download “everything”.
         *
         * For all_posts / all_pages / selected, we enforce queue-scoped Hybrid by:
         *  - forcing hybrid_scope=selected
         *  - deriving post IDs from the current queue when selected_post_ids is empty
         */
        if ($mode === 'hybrid' && $scope !== 'full_site') {
            $hybrid_scope = 'selected';

            if (empty($selected_post_ids)) {
                try {
                    global $wpdb;
                    $table_queue = $wpdb->prefix . 'wp_to_html_queue';

                    // Only pull URL column; queue is already scope-filtered.
                    $urls = $wpdb->get_col("SELECT url FROM {$table_queue}");
                    if (is_array($urls) && !empty($urls)) {
                        foreach ($urls as $u) {
                            $pid = url_to_postid($u);
                            if ($pid > 0) $selected_post_ids[] = (int) $pid;
                        }
                        $selected_post_ids = array_values(array_unique(array_filter(array_map('intval', $selected_post_ids))));
                    }
                } catch (\Throwable $e) {
                    $this->log('Hybrid scope enforcement: failed to derive selected_post_ids from queue: ' . $e->getMessage());
                }
            }
        }

        $is_scoped = ($hybrid_scope === 'selected' && !empty($selected_post_ids));


        // Hybrid always includes DB-discovered media.
        if ($is_scoped) {
            $inserted += $this->enqueue_selected_media_for_posts($selected_post_ids);
        } else {
            $inserted += $this->enqueue_attachment_files();
            $inserted += $this->enqueue_upload_urls_from_postmeta();
        }

        // Builder-aware asset discovery (Elementor/Divi/Beaver Builder).
        // These builders commonly store generated CSS/JS in cache directories that are NOT attachments,
        // and therefore are missed by strict HTML extraction and by Hybrid attachment scanning.
        $inserted += $this->enqueue_builder_generated_assets($is_scoped ? $selected_post_ids : []);

        if ($mode === 'full') {
            $inserted += $this->enqueue_filesystem_assets();
        }

        
        // Helpful summary so users can verify whether Hybrid was scoped or site-wide.
        if ($mode === 'hybrid') {
            if ($is_scoped) {
                $this->log('Hybrid scope: selected-only (found_on prefix: hybrid:selected:post:...)');
            } else {
                $this->log('Hybrid scope: site-wide (found_on prefixes: hybrid:sitewide:attachments / hybrid:sitewide:postmeta)');
            }
        }

$this->log('Bulk asset enqueue complete. inserted=' . (int)$inserted . ' mode=' . $mode);
        return (int) $inserted;
    }

    /**
     * HYBRID: enqueue popular builder generated assets that frequently fall outside normal HTML parsing.
     *
     * - Elementor: wp-content/uploads/elementor/** (post-*.css, global.css, etc) and optional URL refs in _elementor_data.
     * - Divi: wp-content/et-cache/** (generated cache files).
     * - Beaver Builder: uploads/bb-plugin/cache/** (generated cache files).
     *
     * When exporting "selected" scope, we still enqueue the cache directories (lightweight, safe),
     * and we additionally scan Elementor postmeta only for the selected posts (to avoid site-wide DB scans).
     */
    private function enqueue_builder_generated_assets(array $selected_post_ids = []): int {
        $inserted = 0;

        // 1) Elementor
        if (defined('ELEMENTOR_VERSION')) {
            $inserted += $this->enqueue_elementor_generated_assets($selected_post_ids);
        }

        // 2) Divi theme cache (only when Divi THEME is active; matches Simply Static's behavior)
        if (function_exists('get_template') && get_template() === 'Divi') {
            $inserted += $this->enqueue_divi_generated_assets();
        }

        // 3) Beaver Builder
        if (defined('FL_BUILDER_VERSION')) {
            $inserted += $this->enqueue_beaver_builder_generated_assets();
        }

        if ($inserted > 0) {
            $this->log('Hybrid builder assets: inserted=' . (int)$inserted);
        }
        return $inserted;
    }

    private function enqueue_elementor_generated_assets(array $selected_post_ids = []): int {
        $this->log('Hybrid builder assets: Elementor detected; scanning generated assets…');

        $count = 0;

        // Elementor writes generated CSS/JSON into uploads/elementor/ (not attachments).
        $up = wp_upload_dir();
        $base_dir = !empty($up['basedir']) ? (string)$up['basedir'] : '';
        $base_url = !empty($up['baseurl']) ? (string)$up['baseurl'] : '';
        if ($base_dir && $base_url) {
            $targets = [
                trailingslashit($base_dir) . 'elementor',
                trailingslashit($base_dir) . 'elementor/css',
            ];
            foreach ($targets as $dir) {
                $count += $this->enqueue_directory_assets_as_urls(
                    $dir,
                    // filesystem_path_to_url() already knows how to map uploads paths to URLs.
                    'uploads',
                    'hybrid:builder:elementor:filesystem'
                );
            }
        }

        // Elementor Pro / some widgets store external URLs (e.g., Lottie library JSON) inside _elementor_data.
        // We only enqueue internal URLs (same allowed host logic in Asset_Extractor is not available here),
        // so this is best-effort and safe.
        $count += $this->enqueue_elementor_urls_from_postmeta($selected_post_ids);

        return $count;
    }

    private function enqueue_divi_generated_assets(): int {
        $this->log('Hybrid builder assets: Divi theme detected; scanning et-cache…');
        $dir = trailingslashit(ABSPATH) . 'wp-content/et-cache';
        return $this->enqueue_directory_assets_as_urls($dir, 'theme', 'hybrid:builder:divi:et-cache');
    }

    private function enqueue_beaver_builder_generated_assets(): int {
        $this->log('Hybrid builder assets: Beaver Builder detected; scanning uploads/bb-plugin/cache…');
        $up = wp_upload_dir();
        $base_dir = !empty($up['basedir']) ? (string)$up['basedir'] : '';
        if (!$base_dir) return 0;
        $dir = trailingslashit($base_dir) . 'bb-plugin/cache';
        return $this->enqueue_directory_assets_as_urls($dir, 'uploads', 'hybrid:builder:beaver:cache');
    }

    /**
     * Enqueue all eligible files within a directory by mapping paths to public URLs.
     * This is a "mini" version of FULL mode crawling, limited to a specific builder directory.
     */
    private function enqueue_directory_assets_as_urls(string $dir, string $asset_type, string $found_on): int {
        if (!is_dir($dir)) return 0;

        $exts = (array) apply_filters('wp_to_html_builder_asset_extensions', [
            'css','js','map','json','xml','txt','webmanifest',
            'jpg','jpeg','png','gif','webp','svg','ico',
            'woff','woff2','ttf','otf','eot',
        ]);
        $exts = array_values(array_unique(array_filter(array_map(function($e){
            return strtolower(trim((string)$e));
        }, $exts))));

        $max_files = (int) apply_filters('wp_to_html_builder_assets_max_files', 50000);
        $max_files = max(1000, $max_files);

        $max_size_bytes = (int) apply_filters('wp_to_html_builder_assets_max_size_bytes', 20 * 1024 * 1024);
        $max_size_bytes = max(1024 * 1024, $max_size_bytes);

        $seen = 0;
        $batch = [];
        $inserted = 0;

        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) continue;
                $seen++;
                if ($seen > $max_files) break;

                $path = $file->getPathname();
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext === '' || !in_array($ext, $exts, true)) continue;

                $size = (int) $file->getSize();
                if ($size > $max_size_bytes) continue;

                $url = $this->filesystem_path_to_url($path);
                if (!$url) continue;
                $batch[] = $url;
                if (count($batch) >= 1500) {
                    $inserted += $this->insert_asset_urls($batch, $asset_type, $found_on);
                    $batch = [];
                }
            }
        } catch (\Throwable $e) {
            $this->log('Builder asset scan error: dir=' . $dir . ' msg=' . $e->getMessage());
        }

        if (!empty($batch)) {
            $inserted += $this->insert_asset_urls($batch, $asset_type, $found_on);
        }

        return $inserted;
    }

    /**
     * Best-effort discovery of internal URLs stored in Elementor's _elementor_data JSON.
     *
     * @param int[] $selected_post_ids When provided, restricts scanning to these post IDs.
     */
    private function enqueue_elementor_urls_from_postmeta(array $selected_post_ids = []): int {
        global $wpdb;
        $table = $wpdb->postmeta;

        $selected_post_ids = array_values(array_unique(array_filter(array_map('intval', $selected_post_ids))));

        // Safety: limit rows to avoid giant exports. Adjustable via filter.
        $row_limit = (int) apply_filters('wp_to_html_elementor_postmeta_row_limit', 50000);
        $row_limit = max(1000, $row_limit);

        $sql = "SELECT post_id, meta_value FROM {$table} WHERE meta_key = %s";
        $params = ['_elementor_data'];

        if (!empty($selected_post_ids)) {
            $in = implode(',', array_fill(0, count($selected_post_ids), '%d'));
            $sql .= " AND post_id IN ($in)";
            $params = array_merge($params, $selected_post_ids);
        }
        $sql .= " LIMIT " . (int)$row_limit;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        if (empty($rows)) return 0;

        $urls = [];
        foreach ($rows as $row) {
            $raw = $row['meta_value'] ?? '';
            if (!is_string($raw) || $raw === '') continue;
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) continue;

            // Flatten and extract any URL-like strings.
            $this->collect_urls_from_mixed($decoded, $urls);
        }

        // Filter to internal URLs only.
        $urls = array_values(array_unique(array_filter($urls)));
        $urls = array_values(array_filter($urls, function($u){
            return $this->is_internal_url_string($u);
        }));

        if (empty($urls)) return 0;
        return $this->insert_asset_urls($urls, 'asset', 'hybrid:builder:elementor:postmeta');
    }

    /**
     * Recursively traverse mixed arrays/objects and collect URL-like strings.
     * This is intentionally permissive; we later filter by allowed hosts.
     */
    private function collect_urls_from_mixed($data, array &$out): void {
        if (is_string($data)) {
            $s = trim($data);
            if ($s === '') return;
            if (preg_match('~^https?://~i', $s)) {
                $out[] = $s;
            }
            return;
        }
        if (!is_array($data)) return;
        foreach ($data as $v) {
            if (is_array($v)) {
                $this->collect_urls_from_mixed($v, $out);
            } elseif (is_string($v)) {
                $this->collect_urls_from_mixed($v, $out);
            }
        }
    }

    /**
     * Minimal internal-host check for URLs, aligned with Asset_Extractor::is_internal().
     */
    private function is_internal_url_string(string $url): bool {
        $u = wp_parse_url($url);
        if (empty($u['host'])) return false;

        $hosts = [];
        $home = wp_parse_url(home_url('/'));
        if (!empty($home['host'])) $hosts[] = strtolower((string)$home['host']);

        $up = wp_upload_dir();
        if (!empty($up['baseurl'])) {
            $uph = wp_parse_url($up['baseurl']);
            if (!empty($uph['host'])) $hosts[] = strtolower((string)$uph['host']);
        }

        $hosts = array_values(array_unique(array_filter($hosts)));
        $hosts = apply_filters('wp_to_html_allowed_hosts', $hosts);

        $h = strtolower((string)$u['host']);
        return in_array($h, $hosts, true);
    }

    /**
     * Insert URLs into the assets table (INSERT IGNORE) and return count inserted.
     */
    private function insert_asset_urls(array $urls, string $asset_type = 'asset', string $found_on = ''): int {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_to_html_assets';

        $urls = array_values(array_unique(array_filter(array_map('strval', $urls))));
        if (empty($urls)) return 0;

        $inserted = 0;
        foreach ($urls as $u) {
            if ($u === '') continue;
            $r = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (url, status, asset_type, found_on) VALUES (%s, %s, %s, %s)",
                    $u,
                    'pending',
                    $asset_type,
                    $found_on
                )
            );

            // INSERT IGNORE returns 1 when a new row is inserted.
            if ($r === 1) {
                $inserted++;

                // If in Hybrid/Full mode, show which extra assets were added beyond Strict.
                // Only in debug mode, and only for the hybrid/full discovered assets.
                if (defined('WP_TO_HTML_DEBUG') && WP_TO_HTML_DEBUG && $this->debug_asset_log_cap > 0) {
                    $is_hybrid_found = (substr($found_on, 0, 7) === 'hybrid:');
                    $is_full_found   = (substr($found_on, 0, 5) === 'full:');
                    if ($is_hybrid_found || $is_full_found) {
                        if ($this->debug_asset_log_count < $this->debug_asset_log_cap) {
                            $this->debug_asset_log_count++;
                            $this->log('Extra asset (vs strict): ' . $u . ' | found_on=' . $found_on);
                        } elseif ($this->debug_asset_log_count === $this->debug_asset_log_cap) {
                            // Log the truncation once.
                            $this->debug_asset_log_count++;
                            $this->log('Extra asset logging truncated at ' . (int)$this->debug_asset_log_cap . ' items (adjust via wp_to_html_debug_hybrid_asset_log_cap).');
                        }
                    }
                }
            }
        }
        return $inserted;
    }

    /**
     * HYBRID: enqueue all files represented by attachments (incl. intermediate sizes).
     */
    private function enqueue_attachment_files(): int {
        $this->log('Hybrid assets: scanning attachments…');

        $batch = (int) apply_filters('wp_to_html_bulk_assets_attachment_batch', 500);
        $batch = max(50, min(2000, $batch));

        $inserted = 0;
        $paged = 1;
        do {
            $q = new \WP_Query([
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $batch,
                'paged'          => $paged,
                'fields'         => 'ids',
                'no_found_rows'  => false,
            ]);

            $ids = $q->posts;
            if (empty($ids)) break;

            $urls = [];
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) continue;

                $main = wp_get_attachment_url($id);
                if (is_string($main) && $main !== '') {
                    $urls[] = $main;
                }

                $meta = wp_get_attachment_metadata($id);
                if (is_array($meta)) {
                    // Original file (relative to uploads)
                    if (!empty($meta['file']) && is_string($meta['file'])) {
                        $urls[] = $this->uploads_rel_to_url($meta['file']);
                    }
                    // Intermediate sizes
                    if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                        $base_dir = '';
                        if (!empty($meta['file']) && is_string($meta['file'])) {
                            $base_dir = trailingslashit(dirname($meta['file']));
                            if ($base_dir === './' || $base_dir === '.\\') $base_dir = '';
                        }
                        foreach ($meta['sizes'] as $size) {
                            if (empty($size['file']) || !is_string($size['file'])) continue;
                            $urls[] = $this->uploads_rel_to_url($base_dir . $size['file']);
                        }
                    }
                }
            }

            $urls = array_values(array_filter($urls));
            $inserted += $this->insert_asset_urls($urls, 'uploads', 'hybrid:sitewide:attachments');

            $paged++;

            // Stop if we hit a safety max pages.
            $max_pages = (int) apply_filters('wp_to_html_bulk_assets_attachment_max_pages', 2000);
            if ($paged > $max_pages) {
                $this->log('Hybrid assets: reached max_pages=' . $max_pages . ' while scanning attachments');
                break;
            }
        } while (true);

        $this->log('Hybrid assets: attachments enqueue inserted=' . (int)$inserted);
        return $inserted;
    }

    private function uploads_rel_to_url(string $rel): string {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $up = wp_upload_dir();
        $baseurl = !empty($up['baseurl']) ? (string) $up['baseurl'] : '';
        if ($baseurl === '') return '';
        return rtrim($baseurl, '/') . '/' . $rel;
    }

    /**
     * HYBRID (SCOPED): enqueue media related to specific posts/pages only.
     * This prevents Hybrid from pulling the entire Media Library when exporting selected content.
     */
    private function enqueue_selected_media_for_posts(array $post_ids): int {
        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) return 0;

        $this->log('Hybrid mode: scanning media for ' . count($post_ids) . ' selected posts/pages…');

        $inserted = 0;

        // 1) Featured images
        $featured_ids = [];
        foreach ($post_ids as $pid) {
            $tid = (int) get_post_thumbnail_id($pid);
            if ($tid > 0) $featured_ids[$pid] = $tid;
        }
        foreach ($featured_ids as $pid => $att_id) {
            $inserted += $this->enqueue_attachment_by_id($att_id, 'uploads', 'hybrid:selected:post:' . (int)$pid . ':featured');
        }

        // 2) Attachments where post_parent is selected post
        $attached = $this->get_attachment_ids_by_parents($post_ids);
        foreach ($attached as $pid => $att_ids) {
            foreach ($att_ids as $att_id) {
                $inserted += $this->enqueue_attachment_by_id((int)$att_id, 'uploads', 'hybrid:selected:post:' . (int)$pid . ':attached');
            }
        }

        // 3) URLs in post_content (uploads URLs)
        foreach ($post_ids as $pid) {
            $content_urls = $this->extract_upload_urls_from_post_content($pid);
            if (!empty($content_urls)) {
                $inserted += $this->insert_asset_urls($content_urls, 'uploads', 'hybrid:selected:post:' . (int)$pid . ':content');
            }
        }

        // 4) URLs in postmeta for selected posts only
        $inserted += $this->enqueue_upload_urls_from_postmeta_scoped($post_ids);

        $this->log('Hybrid (selected) enqueue complete. inserted=' . (int)$inserted);
        return (int) $inserted;
    }

    /**
     * Enqueue one attachment (main file + intermediate sizes).
     * Returns number of new URLs inserted (0..n).
     */
    private function enqueue_attachment_by_id(int $attachment_id, string $asset_type, string $found_on): int {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return 0;

        $urls = [];

        $main = wp_get_attachment_url($attachment_id);
        if (is_string($main) && $main !== '') $urls[] = $main;

        $meta = wp_get_attachment_metadata($attachment_id);
        if (is_array($meta)) {
            if (!empty($meta['file']) && is_string($meta['file'])) {
                $urls[] = $this->uploads_rel_to_url($meta['file']);
            }
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                $base_dir = '';
                if (!empty($meta['file']) && is_string($meta['file'])) {
                    $base_dir = trailingslashit(dirname($meta['file']));
                    if ($base_dir === './' || $base_dir === '.\\') $base_dir = '';
                }
                foreach ($meta['sizes'] as $size) {
                    if (empty($size['file']) || !is_string($size['file'])) continue;
                    $urls[] = $this->uploads_rel_to_url($base_dir . $size['file']);
                }
            }
        }

        $urls = array_values(array_filter($urls));
        return $this->insert_asset_urls($urls, $asset_type, $found_on);
    }

    /**
     * Get attachment IDs grouped by parent post_id.
     */
    private function get_attachment_ids_by_parents(array $parent_ids): array {
        $out = [];
        $parent_ids = array_values(array_unique(array_filter(array_map('intval', $parent_ids))));
        if (empty($parent_ids)) return $out;

        $q = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_parent__in'=> $parent_ids,
            'no_found_rows'  => true,
        ]);

        if (!empty($q->posts)) {
            foreach ($q->posts as $att_id) {
                $p = (int) wp_get_post_parent_id((int)$att_id);
                if ($p > 0) {
                    if (!isset($out[$p])) $out[$p] = [];
                    $out[$p][] = (int) $att_id;
                }
            }
        }
        return $out;
    }

    /**
     * Extract uploads URLs from a selected post's content.
     */
    private function extract_upload_urls_from_post_content(int $post_id): array {
        $p = get_post($post_id);
        if (!$p || is_wp_error($p)) return [];
        $content = is_string($p->post_content) ? $p->post_content : '';
        if ($content === '') return [];

        $up = wp_upload_dir();
        $baseurl = !empty($up['baseurl']) ? (string) $up['baseurl'] : '';
        if ($baseurl === '') return [];

        $urls = [];
        if (preg_match_all('~' . preg_quote(rtrim($baseurl, '/'), '~') . '/[^\s"\'>)]+~i', $content, $m)) {
            foreach (($m[0] ?? []) as $u) {
                $u = trim((string)$u);
                if ($u !== '') $urls[] = $u;
            }
        }
        return array_values(array_unique($urls));
    }

    /**
     * HYBRID (SCOPED): scan postmeta for selected post IDs only.
     */
    private function enqueue_upload_urls_from_postmeta_scoped(array $post_ids): int {
        global $wpdb;

        $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
        if (empty($post_ids)) return 0;

        $up = wp_upload_dir();
        $baseurl = !empty($up['baseurl']) ? (string) $up['baseurl'] : '';
        if ($baseurl === '') return 0;

        $this->log('Hybrid (selected): scanning postmeta for uploads URLs…');

        $table = $wpdb->postmeta;
        $inserted = 0;

        // Chunk IN() lists to avoid giant queries.
        $chunks = array_chunk($post_ids, 200);
        foreach ($chunks as $ids_chunk) {
            $placeholders = implode(',', array_fill(0, count($ids_chunk), '%d'));
            $sql = "SELECT post_id, meta_value FROM {$table} WHERE post_id IN ({$placeholders})";
            $prepared = $wpdb->prepare($sql, ...$ids_chunk);
            $rows = $wpdb->get_results($prepared);

            if (empty($rows)) continue;

            $bucket = [];
            foreach ($rows as $r) {
                $pid = (int) ($r->post_id ?? 0);
                $v = is_string($r->meta_value) ? $r->meta_value : '';
                if ($pid <= 0 || $v === '') continue;

                if (preg_match_all('~' . preg_quote(rtrim($baseurl, '/'), '~') . '/[^\s"\'>)]+~i', $v, $m)) {
                    foreach (($m[0] ?? []) as $u) {
                        $u = trim((string)$u);
                        if ($u !== '') {
                            if (!isset($bucket[$pid])) $bucket[$pid] = [];
                            $bucket[$pid][] = $u;
                        }
                    }
                }
            }

            foreach ($bucket as $pid => $urls) {
                $inserted += $this->insert_asset_urls(array_values(array_unique($urls)), 'uploads', 'hybrid:selected:post:' . (int)$pid . ':meta');
            }
        }

        $this->log('Hybrid (selected): postmeta scan inserted=' . (int)$inserted);
        return (int) $inserted;
    }
    /**
     * HYBRID: scan postmeta values for uploads URLs (Elementor/ACF/custom fields often store URLs).
     * We keep this conservative (string search + regex URL extraction) and bounded.
     */
    private function enqueue_upload_urls_from_postmeta(): int {
        global $wpdb;

        $max_rows = (int) apply_filters('wp_to_html_bulk_assets_meta_scan_max_rows', 50000);
        $chunk    = (int) apply_filters('wp_to_html_bulk_assets_meta_scan_chunk', 2000);
        $chunk    = max(200, min(5000, $chunk));

        $up = wp_upload_dir();
        $baseurl = !empty($up['baseurl']) ? (string) $up['baseurl'] : '';
        if ($baseurl === '') return 0;
        $baseurl_esc = esc_sql($wpdb->esc_like($baseurl));

        $this->log('Hybrid assets: scanning postmeta for uploads URLs…');

        $table = $wpdb->postmeta;
        $offset = 0;
        $scanned = 0;
        $inserted = 0;

        while ($scanned < $max_rows) {
            // Pull only rows that look like they contain uploads baseurl.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$table} WHERE meta_value LIKE %s LIMIT %d OFFSET %d",
                    '%' . $baseurl_esc . '%',
                    $chunk,
                    $offset
                )
            );

            if (empty($rows)) break;

            $urls = [];
            foreach ($rows as $r) {
                $v = is_string($r->meta_value) ? $r->meta_value : '';
                if ($v === '') continue;
                // Extract URLs pointing to uploads baseurl.
                if (preg_match_all('~' . preg_quote(rtrim($baseurl, '/'), '~') . '/[^\s"\'>)]+~i', $v, $m)) {
                    foreach (($m[0] ?? []) as $u) {
                        $u = trim($u);
                        if ($u !== '') $urls[] = $u;
                    }
                }
            }

            $inserted += $this->insert_asset_urls($urls, 'uploads', 'hybrid:sitewide:attachments');

            $got = count($rows);
            $scanned += $got;
            $offset += $got;

            if ($got < $chunk) break;
        }

        $this->log('Hybrid assets: postmeta scan scanned_rows=' . (int)$scanned . ' inserted=' . (int)$inserted);
        return $inserted;
    }

    /**
     * FULL: filesystem crawl for common static asset extensions.
     */
    private function enqueue_filesystem_assets(): int {
        $this->log('Full assets: filesystem crawl starting…');

        $exts = (array) apply_filters('wp_to_html_bulk_asset_extensions', [
            'css','js','map','json','xml','txt','webmanifest',
            'jpg','jpeg','png','gif','webp','svg','ico',
            'woff','woff2','ttf','otf','eot',
            'mp4','webm','m4v','mov','mp3','wav','m4a','ogg','oga','ogv',
            'pdf',
        ]);
        $exts = array_values(array_unique(array_filter(array_map(function($e){
            return strtolower(trim((string)$e));
        }, $exts))));

        $max_files = (int) apply_filters('wp_to_html_bulk_assets_full_max_files', 200000);
        $max_files = max(1000, $max_files);

        $max_size_bytes = (int) apply_filters('wp_to_html_bulk_assets_full_max_size_bytes', 50 * 1024 * 1024);
        $max_size_bytes = max(1024 * 1024, $max_size_bytes);

        $targets = $this->get_full_mode_directories();
        $inserted = 0;
        $seen = 0;

        foreach ($targets as $t) {
            $dir = $t['dir'];
            $type = $t['type'];
            if (!is_dir($dir)) continue;
            $this->log('Full assets: scanning ' . $type . ' dir=' . $dir);

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            $urls = [];
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile()) continue;

                $seen++;
                if ($seen > $max_files) {
                    $this->log('Full assets: reached max_files=' . $max_files . ' stopping crawl');
                    break 2;
                }

                $path = $file->getPathname();
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if ($ext === '' || !in_array($ext, $exts, true)) continue;

                // Skip too-large files (video libraries can explode export size).
                $size = (int) $file->getSize();
                if ($size > $max_size_bytes) continue;

                $u = $this->filesystem_path_to_url($path);
                if ($u) $urls[] = $u;

                // Batch insert to reduce memory.
                if (count($urls) >= 1500) {
                    $inserted += $this->insert_asset_urls($urls, $type, 'full:filesystem');
                    $urls = [];
                }
            }

            if (!empty($urls)) {
                $inserted += $this->insert_asset_urls($urls, $type, 'full:filesystem');
            }
        }

        $this->log('Full assets: filesystem crawl inserted=' . (int)$inserted . ' scanned_files=' . (int)$seen);
        return $inserted;
    }

    /**
     * Resolve a set of directories to scan for FULL mode.
     */
    private function get_full_mode_directories(): array {
        $dirs = [];

        // Uploads (all media)
        $up = wp_upload_dir();
        if (!empty($up['basedir'])) {
            $dirs[] = ['dir' => (string)$up['basedir'], 'type' => 'uploads'];
        }

        // Themes (active + parent)
        if (function_exists('get_stylesheet_directory')) {
            $dirs[] = ['dir' => (string) get_stylesheet_directory(), 'type' => 'theme'];
        }
        if (function_exists('get_template_directory')) {
            $tpl = (string) get_template_directory();
            if ($tpl && (!function_exists('get_stylesheet_directory') || $tpl !== (string)get_stylesheet_directory())) {
                $dirs[] = ['dir' => $tpl, 'type' => 'theme'];
            }
        }

        // Plugins (wp-content/plugins)
        if (defined('WP_PLUGIN_DIR')) {
            $dirs[] = ['dir' => (string) WP_PLUGIN_DIR, 'type' => 'plugin'];
        }
        // MU plugins
        if (defined('WPMU_PLUGIN_DIR')) {
            $dirs[] = ['dir' => (string) WPMU_PLUGIN_DIR, 'type' => 'plugin'];
        }

        // wp-includes static assets
        $dirs[] = ['dir' => trailingslashit(ABSPATH) . 'wp-includes', 'type' => 'wp-includes'];

        // "Vendor/static" (best-effort conventions)
        $wp_content = defined('WP_CONTENT_DIR') ? (string) WP_CONTENT_DIR : '';
        if ($wp_content) {
            foreach (['vendor', 'static', 'cache'] as $maybe) {
                $p = rtrim($wp_content, '/\\') . '/' . $maybe;
                if (is_dir($p)) {
                    $dirs[] = ['dir' => $p, 'type' => 'vendor'];
                }
            }
        }

        // Allow devs to adjust/extend.
        $dirs = (array) apply_filters('wp_to_html_bulk_assets_full_directories', $dirs);

        // De-dupe.
        $seen = [];
        $out = [];
        foreach ($dirs as $d) {
            if (empty($d['dir']) || empty($d['type'])) continue;
            $key = rtrim((string)$d['dir'], '/\\');
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = 1;
            $out[] = ['dir' => $key, 'type' => (string)$d['type']];
        }
        return $out;
    }

    /**
     * Map a filesystem path to its public URL.
     */
    private function filesystem_path_to_url(string $path): string {
        $path = str_replace('\\', '/', $path);

        // uploads
        $up = wp_upload_dir();
        if (!empty($up['basedir']) && !empty($up['baseurl'])) {
            $basedir = str_replace('\\', '/', (string) $up['basedir']);
            $basedir = rtrim($basedir, '/');
            if (strpos($path, $basedir . '/') === 0) {
                $rel = ltrim(substr($path, strlen($basedir)), '/');
                return rtrim((string)$up['baseurl'], '/') . '/' . $rel;
            }
        }

        // wp-content
        if (defined('WP_CONTENT_DIR')) {
            $content_dir = str_replace('\\', '/', (string) WP_CONTENT_DIR);
            $content_dir = rtrim($content_dir, '/');
            if (strpos($path, $content_dir . '/') === 0) {
                $rel = ltrim(substr($path, strlen($content_dir)), '/');
                return content_url('/' . $rel);
            }
        }

        // wp-includes
        $includes_dir = str_replace('\\', '/', trailingslashit(ABSPATH) . 'wp-includes');
        $includes_dir = rtrim($includes_dir, '/');
        if (strpos($path, $includes_dir . '/') === 0) {
            $rel = ltrim(substr($path, strlen($includes_dir)), '/');
            return includes_url('/' . $rel);
        }

        return '';
    }
}
