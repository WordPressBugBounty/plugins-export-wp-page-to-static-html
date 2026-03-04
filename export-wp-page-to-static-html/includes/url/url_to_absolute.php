<?php
/**
 * url_to_absolute
 *
 * Robust RFC3986 absolutizer for resolving relative asset URLs.
 * Ported from David R. Nadeau's RFC3986 URL utilities (BSD).
 *
 * IMPORTANT: Functions are prefixed to avoid collisions with other plugins.
 */

// Load dependencies (prefixed variants).
require_once __DIR__ . '/split_url.php';
require_once __DIR__ . '/join_url.php';

if (!function_exists('wp_to_html_url_to_absolute')) {

    /**
     * Combine a base URL and a relative URL to produce an absolute URL.
     *
     * @param string $baseUrl
     * @param string $relativeUrl
     * @return string|false
     */
    function wp_to_html_url_to_absolute($baseUrl, $relativeUrl)
    {
        $relativeUrl = (string) $relativeUrl;
        $baseUrl = (string) $baseUrl;

        $r = wp_to_html_split_url($relativeUrl);
        if ($r === false) return false;

        // If relative URL has a scheme, clean path and return.
        if (!empty($r['scheme'])) {
            if (!empty($r['path']) && isset($r['path'][0]) && $r['path'][0] === '/') {
                $r['path'] = wp_to_html_url_remove_dot_segments($r['path']);
            }
            return wp_to_html_join_url($r);
        }

        // Make sure base URL is absolute.
        $b = wp_to_html_split_url($baseUrl);
        if ($b === false || empty($b['scheme']) || empty($b['host'])) return false;
        $r['scheme'] = $b['scheme'];

        // If relative URL has an authority, clean path and return.
        if (isset($r['host'])) {
            if (!empty($r['path'])) {
                $r['path'] = wp_to_html_url_remove_dot_segments($r['path']);
            }
            return wp_to_html_join_url($r);
        }

        unset($r['port'], $r['user'], $r['pass']);

        // Copy base authority.
        $r['host'] = $b['host'];
        if (isset($b['port'])) $r['port'] = $b['port'];
        if (isset($b['user'])) $r['user'] = $b['user'];
        if (isset($b['pass'])) $r['pass'] = $b['pass'];

        // If relative URL has no path, use base path.
        if (empty($r['path'])) {
            if (!empty($b['path'])) $r['path'] = $b['path'];
            if (!isset($r['query']) && isset($b['query'])) $r['query'] = $b['query'];
            return wp_to_html_join_url($r);
        }

        // If relative URL path doesn't start with /, merge with base path.
        if (isset($r['path'][0]) && $r['path'][0] !== '/') {
            $bpath = $b['path'] ?? '';
            $base = mb_strrchr($bpath, '/', true, 'UTF-8');
            if ($base === false) $base = '';
            $r['path'] = $base . '/' . $r['path'];
        }

        $r['path'] = wp_to_html_url_remove_dot_segments($r['path']);
        return wp_to_html_join_url($r);
    }

    /**
     * Remove dot segments per RFC3986.
     *
     * @param string $path
     * @return string
     */
    function wp_to_html_url_remove_dot_segments($path)
    {
        $path = (string) $path;
        if ($path === '') return '';

        $inSegs = preg_split('!/!u', $path);
        $outSegs = [];
        foreach ($inSegs as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') {
                array_pop($outSegs);
                continue;
            }
            $outSegs[] = $seg;
        }

        $outPath = implode('/', $outSegs);
        if (isset($path[0]) && $path[0] === '/') $outPath = '/' . $outPath;

        // Preserve trailing slash.
        if (function_exists('mb_strrpos')) {
            $mb_strrpos = (version_compare(PHP_VERSION, '7.4', '>=')
                ? mb_strrpos($path, '/', 0, 'UTF-8')
                : mb_strrpos($path, '/', 'UTF-8'));
            if ($outPath !== '/' && (mb_strlen($path) - 1) === $mb_strrpos) {
                $outPath .= '/';
            }
        } else {
            if ($outPath !== '/' && substr($path, -1) === '/') {
                $outPath .= '/';
            }
        }

        return $outPath;
    }
}
