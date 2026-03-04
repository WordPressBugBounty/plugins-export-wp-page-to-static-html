<?php
/**
 * split_url
 *
 * Ported from David R. Nadeau's RFC3986 URL utilities (BSD).
 *
 * IMPORTANT: Functions are prefixed to avoid collisions with other plugins.
 */

if (!function_exists('wp_to_html_split_url')) {

    function wp_to_html_split_url($url)
    {
        $url = (string) $url;
        if ($url === '') return false;

        $parts = parse_url($url);
        if ($parts === false) {
            // parse_url can fail for some UTF-8 URLs. Try a conservative fallback.
            $regex = '!^(([^:/?#]+):)?(//(([^/?#@]*@)?([^/?#:]*)?(:(\d*))?))?([^?#]*)(\?([^#]*))?(#(.*))?!u';
            if (!preg_match($regex, $url, $m)) {
                return false;
            }

            $parts = [];
            if (!empty($m[2])) $parts['scheme'] = $m[2];
            if (!empty($m[4])) {
                // userinfo
                if (!empty($m[6])) {
                    $ui = $m[6];
                    $ui = rtrim($ui, '@');
                    $uip = explode(':', $ui, 2);
                    if (!empty($uip[0])) $parts['user'] = $uip[0];
                    if (!empty($uip[1])) $parts['pass'] = $uip[1];
                }
                if (!empty($m[7])) $parts['host'] = $m[7];
                if (!empty($m[9])) $parts['port'] = $m[9];
            }
            if (isset($m[10])) $parts['path'] = $m[10];
            if (isset($m[12]) && $m[12] !== '') $parts['query'] = $m[12];
            if (isset($m[14]) && $m[14] !== '') $parts['fragment'] = $m[14];
        }

        return $parts;
    }
}
