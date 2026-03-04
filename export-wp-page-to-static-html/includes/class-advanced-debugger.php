<?php
namespace WpToHtml;

/**
 * Advanced debugger / super debugger.
 *
 * When WP_TO_HTML_ADVANCED_DEBUG is true, this writes a small JSON snapshot
 * to the export directory so a site owner can see where/why exports are stuck.
 */
class Advanced_Debugger {

    const OPTION_KEY = 'wp_to_html_advanced_debug_state';

    public static function enabled(): bool {
        return (defined('WP_TO_HTML_ADVANCED_DEBUG') && WP_TO_HTML_ADVANCED_DEBUG);
    }

    /**
     * Record a progress marker.
     *
     * @param string $phase
     * @param array  $data
     */
    public static function mark(string $phase, array $data = []): void {
        if (!self::enabled()) return;

        $now = time();

        $state = [
            'ts'    => $now,
            'phase' => $phase,
            'data'  => $data,
        ];

        // Persist for “stuck” detection.
        update_option(self::OPTION_KEY, $state, false);

        // Also write to file for human reading.
        self::write_file($state);
    }

    public static function get_state(): array {
        $s = get_option(self::OPTION_KEY, []);
        return is_array($s) ? $s : [];
    }

    public static function stuck_check(array $status_row, int $threshold_seconds = 120): void {
        if (!self::enabled()) return;
        if ($threshold_seconds < 30) $threshold_seconds = 30;

        $state = self::get_state();
        $last_ts = isset($state['ts']) ? (int) $state['ts'] : 0;
        if ($last_ts <= 0) return;

        $age = time() - $last_ts;
        if ($age < $threshold_seconds) return;

        $snapshot = [
            'stuck' => true,
            'stuck_age_s' => $age,
            'last'  => $state,
            'status' => [
                'state'            => $status_row['state'] ?? null,
                'is_running'       => $status_row['is_running'] ?? null,
                'processed_urls'   => $status_row['processed_urls'] ?? null,
                'total_urls'       => $status_row['total_urls'] ?? null,
                'processed_assets' => $status_row['processed_assets'] ?? null,
                'total_assets'     => $status_row['total_assets'] ?? null,
            ],
        ];

        self::write_file($snapshot);
    }

    /**
     * Capture fatal errors to the advanced debug file.
     */
    public static function shutdown_capture(): void {
        if (!self::enabled()) return;

        $err = error_get_last();
        if (!$err || !is_array($err)) return;

        $type = (int) ($err['type'] ?? 0);
        // Fatal-ish types
        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($type, $fatal_types, true)) return;

        $state = self::get_state();

        $snapshot = [
            'fatal' => true,
            'error' => [
                'type'    => $type,
                'message' => (string) ($err['message'] ?? ''),
                'file'    => (string) ($err['file'] ?? ''),
                'line'    => (int) ($err['line'] ?? 0),
            ],
            'last' => $state,
            'ts'   => time(),
        ];

        self::write_file($snapshot);
    }

    private static function write_file(array $payload): void {
        if (!defined('WP_TO_HTML_EXPORT_DIR')) return;

        if (!file_exists(WP_TO_HTML_EXPORT_DIR)) {
            wp_mkdir_p(WP_TO_HTML_EXPORT_DIR);
        }

        $path = rtrim(WP_TO_HTML_EXPORT_DIR, '/\\') . '/advanced-debug.json';
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }

        // Best-effort write.
        @file_put_contents($path, $json);
    }
}
