<?php
/**
 * join_url
 *
 * Ported from David R. Nadeau's RFC3986 URL utilities (BSD).
 *
 * IMPORTANT: Functions are prefixed to avoid collisions with other plugins.
 */

if (!function_exists('wp_to_html_join_url')) {

    function wp_to_html_join_url($parts, $encode = true)
    {
        if (!is_array($parts)) return '';

        if ($encode) {
            if (isset($parts['user'])) $parts['user'] = rawurlencode($parts['user']);
            if (isset($parts['pass'])) $parts['pass'] = rawurlencode($parts['pass']);

            if (isset($parts['host']) &&
                !preg_match('!^(\[[\da-f.:]+\]])|([\da-f.:]+)$!ui', $parts['host'])) {
                $parts['host'] = rawurlencode($parts['host']);
            }

            if (!empty($parts['path'])) {
                $parts['path'] = preg_replace('!%2F!ui', '/', rawurlencode($parts['path']));
            }

            if (isset($parts['query'])) $parts['query'] = rawurlencode($parts['query']);
            if (isset($parts['fragment'])) $parts['fragment'] = rawurlencode($parts['fragment']);
        }

        $url = '';
        if (!empty($parts['scheme'])) $url .= $parts['scheme'] . ':';

        if (isset($parts['host'])) {
            $url .= '//';
            if (isset($parts['user'])) {
                $url .= $parts['user'];
                if (isset($parts['pass'])) $url .= ':' . $parts['pass'];
                $url .= '@';
            }

            // IPv6
            if (preg_match('!^[\da-f]*:[\da-f.:]+$!ui', $parts['host'])) {
                $url .= '[' . $parts['host'] . ']';
            } else {
                $url .= $parts['host'];
            }

            if (isset($parts['port'])) $url .= ':' . $parts['port'];
            if (!empty($parts['path']) && $parts['path'][0] !== '/') $url .= '/';
        }

        if (!empty($parts['path'])) $url .= $parts['path'];
        if (isset($parts['query'])) $url .= '?' . $parts['query'];
        if (isset($parts['fragment'])) $url .= '#' . $parts['fragment'];

        return $url;
    }
}
