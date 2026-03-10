<?php
namespace WpToHtml;
 
/**
 * System diagnostics + preflight "can I run?" checks.
 *
 * Design goals:
 * - Safe (no destructive operations beyond a temporary test table).
 * - Fast (cache results; allow forced refresh).
 * - Actionable (each check includes a fix tip).
 */
class Diagnostic {

    const OPT_CACHE   = 'wp_to_html_diagnostics_cache';
    const CACHE_TTL_S = 15 * 60; // 15 minutes

    /**
     * Return cached report unless force is true or cache expired.
     */
    public function get_report(bool $force = false): array {
        $cached = get_option(self::OPT_CACHE, null);
        $now    = time();

        if (!$force && is_array($cached) && !empty($cached['generated_at']) && isset($cached['checks']) && is_array($cached['checks'])) {
            $age = $now - (int) $cached['generated_at'];
            if ($age >= 0 && $age <= self::CACHE_TTL_S) {
                return $cached;
            }
        }

        $report = [
            'generated_at' => $now,
            'checks'       => $this->run_all_checks(),
        ];

        // Derive summary.
        $fails = 0;
        $warns = 0;
        foreach ($report['checks'] as $c) {
            $status = isset($c['status']) ? (string) $c['status'] : 'fail';
            if ($status === 'fail') $fails++;
            if ($status === 'warn') $warns++;
        }
        $report['summary'] = [
            'fails' => $fails,
            'warns' => $warns,
            'ok'    => max(0, count($report['checks']) - $fails - $warns),
            'can_run' => ($fails === 0) ? 1 : 0,
        ];

        update_option(self::OPT_CACHE, $report, false);
        return $report;
    }

    public function reset_cache(): bool {
        return delete_option(self::OPT_CACHE);
    }

    private function run_all_checks(): array {
        $checks = [];

        $checks[] = $this->check_wp_php_versions();
        $checks[] = $this->check_php_zip_extension();
        $checks[] = $this->check_export_dir_writable();
        $checks[] = $this->check_filesystem_ftp_status();
        $checks[] = $this->check_db_permissions();
        $checks[] = $this->check_loopback_http();

        return $checks;
    }

    /**
     * Filesystem method + FTP/SSH status (helps users understand whether WordPress can write files).
     *
     * Non-interactive: no credential prompts. If FTP/SSH is configured via constants,
     * we attempt a safe write+delete via WP_Filesystem.
     */
    private function check_filesystem_ftp_status(): array {
        $dir = defined('WP_TO_HTML_EXPORT_DIR') ? (string) WP_TO_HTML_EXPORT_DIR : '';
        if ($dir === '') {
            return $this->row('filesystem', __('Filesystem / FTP status', 'wp-to-html'), 'warn', ['export_dir' => 'undefined'], __('Export directory is undefined, so filesystem method checks are limited.', 'wp-to-html'));
        }

        // Ensure filesystem helpers exist.
        if (!function_exists('get_filesystem_method')) {
            if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
        }

        $method = function_exists('get_filesystem_method') ? (string) get_filesystem_method([], $dir) : 'unknown';

        $details = [
            'filesystem_method' => $method,
            'export_dir' => $dir,
            'export_dir_writable_direct' => is_writable($dir) ? 1 : 0,
            'FS_METHOD_defined' => defined('FS_METHOD') ? 1 : 0,
            'FTP_HOST_defined' => defined('FTP_HOST') ? 1 : 0,
            'FTP_USER_defined' => defined('FTP_USER') ? 1 : 0,
            'FTP_PASS_defined' => defined('FTP_PASS') ? 1 : 0,
            'FTP_SSL_defined'  => defined('FTP_SSL') ? 1 : 0,
            'ftp_ext_available' => function_exists('ftp_connect') ? 1 : 0,
            'ssh2_ext_available' => function_exists('ssh2_connect') ? 1 : 0,
        ];

        // If direct writes are possible, this is always OK.
        if ($method === 'direct') {
            return $this->row('filesystem', __('Filesystem / FTP status', 'wp-to-html'), 'pass', $details, '');
        }

        // If not direct, try WP_Filesystem only if credentials are already configured.
        $has_ftp_creds = (defined('FTP_HOST') && defined('FTP_USER') && defined('FTP_PASS'));
        $has_ssh_creds = (defined('FTP_HOST') && defined('FTP_USER') && (defined('FTP_PUBKEY') || defined('FTP_PASS')));

        $can_attempt = false;
        if (in_array($method, ['ftpext','ftpsockets'], true) && $has_ftp_creds) {
            $can_attempt = true;
        }
        if ($method === 'ssh2' && $has_ssh_creds) {
            $can_attempt = true;
        }

        $fs_write_ok = null;
        if ($can_attempt && function_exists('WP_Filesystem')) {
            global $wp_filesystem;
            $ok = @WP_Filesystem();
            if ($ok && is_object($wp_filesystem)) {
                $tmp = trailingslashit($dir) . 'wp_to_html_diag_fs_' . strtolower(wp_generate_password(6, false, false)) . '.txt';
                $put = @$wp_filesystem->put_contents($tmp, 'ok', defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
                if ($put) {
                    @$wp_filesystem->delete($tmp);
                    $fs_write_ok = true;
                } else {
                    $fs_write_ok = false;
                }
            } else {
                $fs_write_ok = false;
            }
        }

        $details['wp_filesystem_write_test'] = ($fs_write_ok === null) ? 'skipped' : (($fs_write_ok) ? 'ok' : 'failed');

        if ($fs_write_ok === true) {
            /* translators: %s: filesystem method */
            $tip = sprintf(__('WordPress is using a non-direct filesystem method (%s), but a test write via WP_Filesystem succeeded.', 'wp-to-html'), $method);
            return $this->row('filesystem', __('Filesystem / FTP status', 'wp-to-html'), 'pass', $details, $tip);
        }

        $direct_writable = is_writable($dir);
        $status = $direct_writable ? 'warn' : 'fail';

        $tip = '';
        if (!$direct_writable) {
            $tip = __('WordPress cannot write directly to the export directory. Configure direct file permissions/ownership, or configure FTP/SSH credentials (FTP_HOST/FTP_USER/FTP_PASS or SSH2) so WP_Filesystem can write.', 'wp-to-html');
        } else {
            /* translators: %s: filesystem method */
            $tip = sprintf(__('Filesystem method detected as %s. This is usually fine, but if exports fail to write files, confirm FS_METHOD/FTP credentials or switch to direct permissions.', 'wp-to-html'), $method);
        }

        return $this->row('filesystem', __('Filesystem / FTP status', 'wp-to-html'), $status, $details, $tip);
    }

    private function check_wp_php_versions(): array {
        global $wp_version;

        $php = PHP_VERSION;
        $wp  = isset($wp_version) ? (string) $wp_version : 'unknown';

        // Conservative baselines.
        $min_php = '7.4.0';
        $min_wp  = '5.8.0';

        $php_ok = version_compare($php, $min_php, '>=');
        $wp_ok  = ($wp !== 'unknown') ? version_compare($wp, $min_wp, '>=') : true;

        $status = ($php_ok && $wp_ok) ? 'pass' : 'fail';

        $details = [
            'php' => $php,
            'wp'  => $wp,
            'min_php' => $min_php,
            'min_wp'  => $min_wp,
        ];

        $tip = '';
        if (!$php_ok) {
            /* translators: %s: minimum PHP version */
            $tip = sprintf(__('Upgrade PHP to %s+ (or ideally PHP 8.1/8.2) for stability and performance.', 'wp-to-html'), $min_php);
        } elseif (!$wp_ok) {
            /* translators: %s: minimum WP version */
            $tip = sprintf(__('Update WordPress core to %s+ to ensure REST, filesystem, and cron behavior is compatible.', 'wp-to-html'), $min_wp);
        }

        return $this->row('php_wp_versions', __('PHP / WordPress versions', 'wp-to-html'), $status, $details, $tip);
    }

    private function check_php_zip_extension(): array {
        $zip_ok = class_exists('ZipArchive');
        $status = $zip_ok ? 'pass' : 'fail';
        $details = [
            'ZipArchive' => $zip_ok ? 'available' : 'missing',
        ];
        $tip = $zip_ok ? '' : __('Enable the PHP zip extension (ZipArchive). On many hosts this is a toggle in PHP Manager / Select PHP Extensions.', 'wp-to-html');
        return $this->row('php_zip', __('PHP zip extension (ZipArchive)', 'wp-to-html'), $status, $details, $tip);
    }

    private function check_export_dir_writable(): array {
        $dir = defined('WP_TO_HTML_EXPORT_DIR') ? (string) WP_TO_HTML_EXPORT_DIR : '';
        if ($dir === '') {
            return $this->row('export_dir', __('Export directory writable', 'wp-to-html'), 'fail', ['dir' => 'undefined'], __('Define WP_TO_HTML_EXPORT_DIR or reinstall the plugin.', 'wp-to-html'));
        }

        // Attempt create.
        if (!is_dir($dir)) {
            if (function_exists('wp_mkdir_p')) {
                @wp_mkdir_p($dir);
            } else {
                @mkdir($dir, 0755, true);
            }
        }

        $exists   = is_dir($dir);
        $writable = $exists ? is_writable($dir) : false;

        $status = ($exists && $writable) ? 'pass' : 'fail';
        $details = [
            'dir' => $dir,
            'exists' => $exists ? 1 : 0,
            'writable' => $writable ? 1 : 0,
        ];
        $tip = '';
        if (!$exists) {
            /* translators: %s: directory path */
            $tip = sprintf(__('Create the export folder and ensure the web server user can access it: %s', 'wp-to-html'), $dir);
        } elseif (!$writable) {
            /* translators: %s: directory path */
            $tip = sprintf(__('Fix permissions/ownership so WordPress can write to: %s (e.g., correct owner or chmod 755/775 depending on host).', 'wp-to-html'), $dir);
        }

        return $this->row('export_dir', __('Export directory writable', 'wp-to-html'), $status, $details, $tip);
    }

    private function check_db_permissions(): array {
        global $wpdb;

        $prefix = $wpdb->prefix . 'wp_to_html_diag_';
        $suffix = strtolower(wp_generate_password(8, false, false));
        $table  = $prefix . $suffix;

        $charset = $wpdb->get_charset_collate();

        $errs = [];
        $ok = true;

        // 1) CREATE
        $sql_create = "CREATE TABLE {$table} (id INT NOT NULL PRIMARY KEY, val VARCHAR(50) NULL) {$charset};";
        $r = $wpdb->query($sql_create);
        if ($r === false) { $ok = false; $errs[] = 'CREATE failed: ' . (string) $wpdb->last_error; }

        // 2) INSERT
        if ($ok) {
            $r = $wpdb->query($wpdb->prepare("INSERT INTO {$table} (id, val) VALUES (%d, %s)", 1, 'ok'));
            if ($r === false) { $ok = false; $errs[] = 'INSERT failed: ' . (string) $wpdb->last_error; }
        }

        // 3) ALTER
        if ($ok) {
            $r = $wpdb->query("ALTER TABLE {$table} ADD COLUMN extra VARCHAR(10) NULL");
            if ($r === false) { $ok = false; $errs[] = 'ALTER failed: ' . (string) $wpdb->last_error; }
        }

        // 4) TRUNCATE
        if ($ok) {
            $r = $wpdb->query("TRUNCATE TABLE {$table}");
            if ($r === false) { $ok = false; $errs[] = 'TRUNCATE failed: ' . (string) $wpdb->last_error; }
        }

        // 5) DROP (best-effort even if earlier failed)
        $wpdb->query("DROP TABLE IF EXISTS {$table}");

        $status = $ok ? 'pass' : 'fail';
        $details = [
            'tested_table' => $table,
            'errors' => $errs,
        ];
        $tip = $ok ? '' : __('Your DB user likely lacks CREATE/ALTER/TRUNCATE privileges. Ask your host to grant those privileges for this site DB, or use a DB user with full DDL rights.', 'wp-to-html');

        return $this->row('db_permissions', __('Database permissions (create/alter/truncate)', 'wp-to-html'), $status, $details, $tip);
    }

    private function check_loopback_http(): array {
        // Use the WP REST API index (always public) to test loopback connectivity.
        $url = add_query_arg(['_'=>time()], rest_url('/'));

        $args = [
            'timeout' => 10,
            'redirection' => 2,
            'sslverify' => false,
            'headers' => [
                'User-Agent' => 'WpToHtml/diagnostic',
            ],
        ];

        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) {
            $status = 'fail';
            $details = [
                'url' => $url,
                'error' => $resp->get_error_message(),
            ];
            $tip = __('Loopback request failed. This can break background processing on some hosts. Ask your host to allow loopback requests, or disable security rules blocking wp_remote_get/wp_remote_post to your own domain.', 'wp-to-html');
            return $this->row('loopback', __('Loopback HTTP (server can request itself)', 'wp-to-html'), $status, $details, $tip);
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);

        $ok = ($code >= 200 && $code < 400) && (strpos($body, 'namespaces') !== false || strpos($body, 'wp_to_html') !== false);
        $status = $ok ? 'pass' : 'warn';

        $details = [
            'url' => $url,
            'http_code' => $code,
            'body_snippet' => substr($body, 0, 200),
        ];

        $tip = $ok ? '' : __('Loopback returned an unexpected response. If exports stall, check firewall/WAF rules, BASIC auth, or forced redirects that block REST loopback.', 'wp-to-html');

        return $this->row('loopback', __('Loopback HTTP (server can request itself)', 'wp-to-html'), $status, $details, $tip);
    }

    private function row(string $id, string $label, string $status, array $details = [], string $tip = ''): array {
        $status = in_array($status, ['pass','fail','warn'], true) ? $status : 'fail';
        return [
            'id'      => $id,
            'label'   => $label,
            'status'  => $status,
            'details' => $details,
            'tip'     => $tip,
        ];
    }
}
