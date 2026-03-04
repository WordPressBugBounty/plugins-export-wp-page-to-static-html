<?php
namespace WpToHtml;

class Asset_Manager {

    private $group;

    /**
     * Persistent mapping used in grouped-assets mode to prevent filename collisions
     * when different source paths share the same basename.
     *
     * Map key format: "{folder}|{source_path}" => "filename.ext"
     */
    private static $group_map = null;
    private static $group_map_loaded = false;
    private static $group_map_dirty = false;
    private static $group_map_file = null;

    public function __construct($group_assets = false) {
        $this->group = $group_assets;

        if ($this->group) {
            $this->init_group_map();
        }
    }

    /**
     * Initialize grouped-assets collision map from disk.
     */
    private function init_group_map() {
        if (self::$group_map_loaded) {
            return;
        }
        self::$group_map_loaded = true;
        self::$group_map_file = rtrim(WP_TO_HTML_EXPORT_DIR, '/\\') . '/wp-to-html-asset-map.json';
        self::$group_map = [];

        if (file_exists(self::$group_map_file)) {
            $raw = @file_get_contents(self::$group_map_file);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    self::$group_map = $decoded;
                }
            }
        }
    }

    /**
     * Persist grouped-assets map if changes were made.
     */
    private function flush_group_map() {
        if (!$this->group) return;
        if (!self::$group_map_dirty) return;

        $file = self::$group_map_file;
        if (!$file) return;

        $dir = dirname($file);
        if (!file_exists($dir)) {
            @wp_mkdir_p($dir);
        }

        // Best-effort atomic write.
        $tmp = $file . '.tmp';
        $json = json_encode(self::$group_map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;
        @file_put_contents($tmp, $json, LOCK_EX);
        @rename($tmp, $file);
        self::$group_map_dirty = false;
    }

    public function __destruct() {
        // Persist map at end of request.
        $this->flush_group_map();
    }

    private function dbg($msg) {
        // Disabled by default. Enable by defining WP_TO_HTML_DEBUG as true.
        if (!defined('WP_TO_HTML_DEBUG') || !WP_TO_HTML_DEBUG) {
            return;
        }
        // Lightweight debug logger (no dependency on Exporter instance).
        $log_file = rtrim(WP_TO_HTML_EXPORT_DIR, '/\\') . '/export-log.txt';
        $log_dir  = dirname($log_file);
        if (!file_exists($log_dir)) {
            @wp_mkdir_p($log_dir);
        }
        $time = date('H:i:s');
        @file_put_contents($log_file, "[{$time}] {$msg}\n", FILE_APPEND);
    }

    /**
     * Copy and rewrite asset URLs in an HTML document.
     *
     * @param string $html
     * @param string $page_output_dir Absolute directory where the page's index.html will be written.
     */
    public function process($html, $page_output_dir) {

        // Relative prefix from this page folder to export root.
        // Root page => './'
        // One level deep => '../'
        // Two levels deep => '../../'
        $dot_to_root = $this->dot_to_export_root($page_output_dir);
        if ($this->group) {
            $this->dbg('process(): grouped=1 page_dir=' . (string)$page_output_dir);
        }

        // 1) src/href
        preg_match_all('/(src|href)=["\']([^"\']+)["\']/', $html, $matches);
        foreach (($matches[2] ?? []) as $asset_url) {
            if (!$this->is_internal_or_local($asset_url)) continue;
            $new_path = $this->copy_asset($asset_url, $page_output_dir, $dot_to_root);
            if ($new_path) $html = str_replace($asset_url, $new_path, $html);
        }

        // 2) srcset
        preg_match_all('/\bsrcset=["\']([^"\']+)["\']/', $html, $srcsetMatches);
        foreach (($srcsetMatches[1] ?? []) as $srcset) {
            $orig_srcset = $srcset;
            $candidates = array_map('trim', explode(',', $srcset));
            $rebuilt = [];
            foreach ($candidates as $cand) {
                if ($cand === '') continue;
                $parts = preg_split('/\s+/', $cand);
                $u = $parts[0] ?? '';
                $descriptor = isset($parts[1]) ? (' ' . $parts[1]) : '';

                if ($this->is_internal_or_local($u)) {
                    $new_u = $this->copy_asset($u, $page_output_dir, $dot_to_root);
                    if ($new_u) {
                        $u = $new_u;
                    }
                }
                $rebuilt[] = $u . $descriptor;
            }
            if (!empty($rebuilt)) {
                $new_srcset = implode(', ', $rebuilt);
                $html = str_replace($orig_srcset, $new_srcset, $html);
            }
        }

        // 3) Inline CSS url(...) in style attributes and <style> blocks
        // Note: we keep this conservative; it only rewrites url(...) that points to wp-content/wp-includes.
        preg_match_all('~url\(([^)]+)\)~i', $html, $urlMatches);
        foreach (($urlMatches[1] ?? []) as $raw) {
            $raw_trim = trim($raw, " \t\n\r\0\x0B\"'");
            if ($raw_trim === '' || stripos($raw_trim, 'data:') === 0) continue;

            if (!$this->is_internal_or_local($raw_trim)) continue;
            $new_u = $this->copy_asset($raw_trim, $page_output_dir, $dot_to_root);
            if (!$new_u) continue;

            // Replace only the exact url(...) token content we matched.
            $html = str_replace($raw_trim, $new_u, $html);
        }

        return $html;
    }

    private function is_internal_or_local($url) {
        if (!is_string($url) || $url === '') return false;

        // Protocol-relative: treat as internal only if host matches.
        if (strpos($url, '//') === 0) {
            $url = (is_ssl() ? 'https:' : 'http:') . $url;
        }

        // Absolute same-origin / allowed internal hosts
        if (preg_match('#^https?://#i', $url)) {
            $u = wp_parse_url($url);
            $home = wp_parse_url(home_url('/'));
            $hosts = [];
            if (!empty($home['host'])) $hosts[] = strtolower((string) $home['host']);

            $up = wp_upload_dir();
            if (!empty($up['baseurl'])) {
                $uph = wp_parse_url($up['baseurl']);
                if (!empty($uph['host'])) $hosts[] = strtolower((string) $uph['host']);
            }

            $hosts = array_values(array_unique(array_filter($hosts)));
            $hosts = apply_filters('wp_to_html_allowed_hosts', $hosts);

            $h = !empty($u['host']) ? strtolower((string) $u['host']) : '';
            if ($h !== '' && in_array($h, $hosts, true)) return true;
            return false;
        }

        // Root-relative or dot-relative (common after rewrite_html_with_dot_path)
        if ($url[0] === '/' || strpos($url, './') === 0 || strpos($url, '../') === 0) {
            // Only treat WP local paths as internal to avoid rewriting site-relative links (/about/ etc).
            // Expand this allowlist if you need other local asset roots.
            return (strpos($url, 'wp-content/') !== false)
                || (strpos($url, 'wp-includes/') !== false)
                || (strpos($url, 'uploads/') !== false);
        }

        return false;
    }

    private function copy_asset($url, $page_output_dir, $dot_to_root) {

        $path = $this->extract_path($url);
        if ($path === '') return false;

        // Skip obvious non-file references (anchors, mailto, tel, data URIs)
        if ($path[0] === '#') return false;

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Decide target subfolder.
        $folder = '';
        if ($this->group) {
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
                case 'mov': case 'm4v': case 'ogv': case 'ogg':
                    $folder = 'videos';
                    break;
                case 'mp3': case 'wav': case 'm4a': case 'aac': case 'flac': case 'oga':
                    $folder = 'audios';
                    break;
                default:
                    $folder = 'assets';
            }

            // Grouped assets live at export root (images/, css/, js/, ...)
            $target_dir = rtrim(WP_TO_HTML_EXPORT_DIR, '/\\') . '/' . $folder;
            $mk = wp_mkdir_p($target_dir);
            //$this->dbg('mkdir ' . $target_dir . ' => ' . ($mk ? 'OK' : 'FAIL'));

            // --- Collision-safe filename selection ---
            // Keyed by folder + source path (not URL), so different directories with same basename don't clash.
            $source_key = $folder . '|' . $path;
            $basename = basename($path);
            $ext2 = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
            $stem = pathinfo($basename, PATHINFO_FILENAME);

            // Normalize extension based on actual path, fall back to earlier $ext.
            $final_ext = $ext2 !== '' ? $ext2 : $ext;

            // If we already mapped this exact source path, reuse it.
            if (isset(self::$group_map[$source_key]) && is_string(self::$group_map[$source_key])) {
                $filename = self::$group_map[$source_key];
            } else {
                // Prefer original basename unless it is already taken by a different source.
                $candidate = $stem . ($final_ext !== '' ? ('.' . $final_ext) : '');

                // Detect collision against existing map entries (same folder) or on-disk file.
                $collision = false;
                foreach (self::$group_map as $k => $v) {
                    if (!is_string($v) || $v === '') continue;
                    if ($v !== $candidate) continue;
                    // Same candidate used already; if it's not the same source, it's a collision.
                    if ($k !== $source_key && strpos($k, $folder . '|') === 0) {
                        $collision = true;
                        break;
                    }
                }
                if (!$collision && file_exists($target_dir . '/' . $candidate)) {
                    // File exists but not mapped to this source => treat as collision.
                    $collision = true;
                }

                if ($collision) {
                    // Deterministic suffix so repeated exports produce the same names.
                    $suffix = substr(md5($path), 0, 6);
                    $candidate = $stem . '_' . $suffix . ($final_ext !== '' ? ('.' . $final_ext) : '');
                }

                $filename = $candidate;
                self::$group_map[$source_key] = $filename;
                self::$group_map_dirty = true;
            }

            $target = $target_dir . '/' . $filename;

            // Best effort: copy from local FS if present (helps previews before asset batch finishes).
            $source = ABSPATH . ltrim($path, '/');
            if (file_exists($source)) {
                $copied = @copy($source, $target);
                
                $this->dbg('copy ' . $source . ' -> ' . $target . ' => ' . ($copied ? 'OK' : 'FAIL'));
            }

            // Always rewrite HTML to the grouped location.
            // Use root-absolute paths so nested HTML pages can reference a shared asset pool.
            //return '/' . $folder . '/' . $filename;
            // Grouped assets live at export root, so reference them relative to the page location.
            return $dot_to_root . $folder . '/' . $filename;
        }

        // Non-grouped: mirror original directory structure under export root.
        $base_folder = dirname($path);
        $target_dir = WP_TO_HTML_EXPORT_DIR . $base_folder;
        wp_mkdir_p($target_dir);

        $filename = basename($path);
        $target = $target_dir . '/' . $filename;

        $source = ABSPATH . ltrim($path, '/');
        if (file_exists($source)) {
            @copy($source, $target);
            // Keep paths relative (./...) so exported HTML works as static files.
            return './' . ltrim($base_folder, '/') . '/' . $filename;
        }

        return false;
    }

    /**
     * Compute the relative prefix from a page directory to the export root.
     *
     * @param string $page_output_dir Absolute directory where page is written.
     * @return string './' or '../' repeated.
     */
    private function dot_to_export_root($page_output_dir) {
        $export_root = rtrim(WP_TO_HTML_EXPORT_DIR, '/\\');
        $page_dir = rtrim((string) $page_output_dir, '/\\');

        if ($page_dir === $export_root) return './';

        // If page dir isn't under export root, fall back.
        if (strpos($page_dir, $export_root . DIRECTORY_SEPARATOR) !== 0) {
            return './';
        }

        $rel = substr($page_dir, strlen($export_root . DIRECTORY_SEPARATOR));
        $rel = trim(str_replace('\\', '/', $rel), '/');
        if ($rel === '') return './';

        $depth = substr_count($rel, '/') + 1;
        return str_repeat('../', $depth);
    }

    /**
     * Extract a filesystem-like path from a URL or local reference.
     * Returns a path starting with '/'.
     */
    private function extract_path($url) {
        $url = (string) $url;

        // Full URL
        if (preg_match('#^https?://#i', $url)) {
            $parsed = wp_parse_url($url);
            return !empty($parsed['path']) ? (string) $parsed['path'] : '';
        }

        // Root-relative
        if (isset($url[0]) && $url[0] === '/') {
            // Strip query/fragment for file ops
            $p = wp_parse_url($url);
            return !empty($p['path']) ? (string) $p['path'] : '';
        }

        // Dot-relative: ./wp-content/... or ../wp-content/...
        if (strpos($url, './') === 0 || strpos($url, '../') === 0) {
            // Remove leading ./ and ../ segments (we only support wp-content/wp-includes allowlist anyway)
            $clean = $url;
            while (strpos($clean, '../') === 0) {
                $clean = substr($clean, 3);
            }
            if (strpos($clean, './') === 0) {
                $clean = substr($clean, 2);
            }
            $p = wp_parse_url($clean);
            $path = !empty($p['path']) ? (string) $p['path'] : '';
            return $path !== '' ? '/' . ltrim($path, '/') : '';
        }

        return '';
    }
}
