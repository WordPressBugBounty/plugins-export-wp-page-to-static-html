<?php
namespace WpToHtml;

/**
 * FTP uploader for WP to HTML.
 *
 * Uploads the final ZIP to a remote path.
 * Supports FTPS (ftp_ssl_connect) when available.
 */
class FTP_Uploader {

    /**
     * Encrypt a credential for safe storage in wp_options.
     * Returns 'enc:' prefixed string. Falls back to plaintext if openssl unavailable.
     */
    public static function encrypt_credential($plain) {
        $plain = (string) $plain;
        if ($plain === '') return '';
        if (!function_exists('openssl_encrypt')) return $plain;

        $key = substr(hash('sha256', wp_salt('logged_in'), true), 0, 32);
        $iv  = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) return $plain;

        return 'enc:' . base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a credential stored by encrypt_credential().
     * Handles legacy plaintext values (no 'enc:' prefix) transparently.
     */
    public static function decrypt_credential($stored) {
        $stored = (string) $stored;
        if ($stored === '') return '';
        if (strpos($stored, 'enc:') !== 0) return $stored; // legacy plaintext

        if (!function_exists('openssl_decrypt')) return ''; // can't decrypt without openssl

        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) < 17) return '';

        $key = substr(hash('sha256', wp_salt('logged_in'), true), 0, 32);
        $iv  = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return ($decrypted !== false) ? $decrypted : '';
    }

    public static function sanitize_settings($in) {
        $in = is_array($in) ? $in : [];

        $host = isset($in['host']) ? trim((string)$in['host']) : '';
        $port = isset($in['port']) ? (int)$in['port'] : 21;
        if ($port <= 0 || $port > 65535) $port = 21;

        $user = isset($in['user']) ? trim((string)$in['user']) : '';
        $pass = isset($in['pass']) ? (string)$in['pass'] : '';
        $ssl  = !empty($in['ssl']) ? 1 : 0;
        $passive = array_key_exists('passive', $in) ? (!empty($in['passive']) ? 1 : 0) : 1;

        $timeout = isset($in['timeout']) ? (int)$in['timeout'] : 20;
        if ($timeout < 5) $timeout = 5;
        if ($timeout > 120) $timeout = 120;

        $base = isset($in['base_path']) ? trim((string)$in['base_path']) : '';
        $base = self::normalize_remote_path($base);

        $def = isset($in['default_path']) ? trim((string)$in['default_path']) : '';
        $def = self::normalize_remote_path($def);

        return [
            'host' => $host,
            'port' => $port,
            'user' => $user,
            // pass is intentionally stored (requested feature). Consider using wp-config constants if needed.
            'pass' => $pass,
            'ssl'  => $ssl,
            'passive' => $passive,
            'timeout' => $timeout,
            'base_path' => $base,
            'default_path' => $def,
        ];
    }

    public static function normalize_remote_path($p) {
        $p = trim((string)$p);
        if ($p === '') return '';
        $p = str_replace('\\', '/', $p);
        // ensure leading slash
        if ($p[0] !== '/') $p = '/' . $p;
        // remove trailing slash
        $p = rtrim($p, '/');
        return $p;
    }

    public static function connect($settings, &$err = '') {
        $s = self::sanitize_settings($settings);

        if ($s['host'] === '' || $s['user'] === '') {
            $err = 'Missing host/username.';
            return false;
        }

        $conn = false;
        if (!empty($s['ssl'])) {
            if (!function_exists('ftp_ssl_connect')) {
                $err = 'FTPS requested, but ftp_ssl_connect() is not available on this server.';
                return false;
            }
            $conn = @ftp_ssl_connect($s['host'], $s['port'], $s['timeout']);
        } else {
            $conn = @ftp_connect($s['host'], $s['port'], $s['timeout']);
        }

        if (!$conn) {
            $err = 'Could not connect to FTP host.';
            return false;
        }

        if (!@ftp_login($conn, $s['user'], $s['pass'])) {
            @ftp_close($conn);
            $err = 'FTP login failed.';
            return false;
        }

        // Passive mode reduces firewall issues in most shared hosts.
        @ftp_pasv($conn, !empty($s['passive']));
        return $conn;
    }

    public static function test_connection($settings, &$err = '') {
        $conn = self::connect($settings, $err);
        if (!$conn) return false;
        $pwd = @ftp_pwd($conn);
        @ftp_close($conn);
        if ($pwd === false) {
            $err = 'Connected, but could not read remote working directory.';
            return true;
        }
        $err = 'Connected. Remote PWD: ' . $pwd;
        return true;
    }

    private static function ensure_remote_dir($conn, $path) {
        $path = self::normalize_remote_path($path);
        if ($path === '') return true;

        $parts = array_values(array_filter(explode('/', $path)));
        $cur = '';

        foreach ($parts as $p) {
            $cur .= '/' . $p;
            // Try cwd; if fails create.
            if (@ftp_chdir($conn, $cur)) {
                // ok
                continue;
            }
            // attempt mkdir and then chdir
            if (!@ftp_mkdir($conn, $cur)) {
                // Some servers require creating relative to current; fallback:
                @ftp_chdir($conn, '/');
                if (!@ftp_mkdir($conn, $cur)) {
                    return false;
                }
            }
            if (!@ftp_chdir($conn, $cur)) {
                return false;
            }
        }
        return true;
    }

    
    /**
     * List directories within a remote path.
     * Returns array of directory names (not full paths).
     */
    public static function list_directories($settings, $path, &$err = '') {
        $s = self::sanitize_settings($settings);
        $conn = self::connect($s, $err);
        if (!$conn) return false;

        $path = self::normalize_remote_path($path);
        if ($path === '') $path = '/';

        // Try MLSD first (more structured) when available.
        $dirs = [];
        if (function_exists('ftp_mlsd')) {
            $items = @ftp_mlsd($conn, $path);
            if (is_array($items)) {
                foreach ($items as $it) {
                    if (!is_array($it)) continue;
                    $name = isset($it['name']) ? (string)$it['name'] : '';
                    if ($name === '' || $name === '.' || $name === '..') continue;
                    $type = isset($it['type']) ? strtolower((string)$it['type']) : '';
                    if ($type === 'dir') $dirs[] = $name;
                }
            }
        }

        // Fallback to RAWLIST parsing (works on most Unix FTP servers).
        if (!$dirs) {
            $raw = @ftp_rawlist($conn, $path, false);
            if (is_array($raw)) {
                foreach ($raw as $line) {
                    $line = (string)$line;

                    // Windows/IIS style: "02-23-24  03:10PM       <DIR>          dirname"
                    if (stripos($line, '<DIR>') !== false) {
                        $parts = preg_split('/\s+/', trim($line));
                        $name = end($parts);
                        if ($name && $name !== '.' && $name !== '..') $dirs[] = $name;
                        continue;
                    }

                    // Unix style: permissions first char 'd' indicates directory
                    $line = trim($line);
                    if ($line === '') continue;
                    if ($line[0] !== 'd') continue;

                    // name is typically the last column; supports spaces poorly but acceptable for dir browsing
                    $parts = preg_split('/\s+/', $line, 9);
                    $name = isset($parts[8]) ? $parts[8] : '';
                    $name = trim((string)$name);
                    if ($name && $name !== '.' && $name !== '..') $dirs[] = $name;
                }
            }
        }

        @ftp_close($conn);

        $dirs = array_values(array_unique($dirs));
        sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);
        return $dirs;
    }

public static function upload_zip($settings, $local_zip_fp, $remote_dir, &$err = '') {
        $s = self::sanitize_settings($settings);
        $conn = self::connect($s, $err);
        if (!$conn) return false;

        if (!file_exists($local_zip_fp)) {
            @ftp_close($conn);
            $err = 'Local ZIP not found.';
            return false;
        }

        $remote_dir = self::normalize_remote_path($remote_dir);
        $base = self::normalize_remote_path($s['base_path']);

        // Combine base + remote_dir
        $target_dir = '';
        if ($base !== '' && $remote_dir !== '') $target_dir = $base . $remote_dir;
        elseif ($base !== '') $target_dir = $base;
        else $target_dir = $remote_dir;

        if ($target_dir === '') $target_dir = '/';

        if (!self::ensure_remote_dir($conn, $target_dir)) {
            @ftp_close($conn);
            $err = 'Could not create or access remote directory: ' . $target_dir;
            return false;
        }

        $remote_file = rtrim($target_dir, '/') . '/' . basename($local_zip_fp);
        $ok = @ftp_put($conn, $remote_file, $local_zip_fp, FTP_BINARY);
        @ftp_close($conn);

        if (!$ok) {
            $err = 'FTP upload failed for: ' . $remote_file;
            return false;
        }

        $err = 'Uploaded to: ' . $remote_file;
        return true;
    }
}
