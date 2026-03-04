<?php
namespace WpToHtml;

class Asset_Extractor {

    /**
     * Query-string params that typically bust caches and should not create
     * separate asset download entries.
     */
    private const DEDUPE_QUERY_PARAMS = ['ver', 'v', 'version', 'cb', 'cachebust', 'cache_bust'];

    public function extract_and_enqueue($html, $page_url) : array {
        // De-dupe by normalized URL + asset type
        $assets_map = [];

        // Use DOMDocument (no external dependency)
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // 1) <img> + common lazy attrs + srcset variants
        foreach ($xpath->query('//img') as $img) {
            foreach (['src','data-src','data-lazyload','data-original','data-lazy-src','data-background-image','data-bg'] as $attr) {
                $v = $img->getAttribute($attr);
                // Best-effort for single URLs.
                $this->add_asset($assets_map, $this->clean_url($v, $page_url), 'image', $page_url);
            }
            $srcset = $img->getAttribute('srcset');
            foreach ($this->parse_srcset($srcset, $page_url) as $u) {
                $this->add_asset($assets_map, $u, 'image', $page_url);
            }

            // data-srcset / data-lazy-srcset
            foreach (['data-srcset','data-lazy-srcset'] as $ss_attr) {
                $ss = $img->getAttribute($ss_attr);
                foreach ($this->parse_srcset($ss, $page_url) as $u) {
                    $this->add_asset($assets_map, $u, 'image', $page_url);
                }
            }
        }

        // 1b) Any element with srcset (eg <source srcset="...">)
        foreach ($xpath->query('//*[@srcset]') as $n) {
            $srcset = $n->getAttribute('srcset');
            foreach ($this->parse_srcset($srcset, $page_url) as $u) {
                $this->add_asset($assets_map, $u, 'image', $page_url);
            }
        }

        // 2) <link rel=stylesheet/icon/manifest>
        foreach ($xpath->query('//link[@href]') as $link) {
            $rel = strtolower(trim($link->getAttribute('rel')));
            $href = $this->clean_url($link->getAttribute('href'), $page_url);

            if (!$href) continue;

            if (strpos($rel, 'stylesheet') !== false) {
                $this->add_asset($assets_map, $href, 'css', $page_url);
            } elseif (
                strpos($rel, 'icon') !== false ||
                strpos($rel, 'shortcut icon') !== false ||
                strpos($rel, 'apple-touch-icon') !== false ||
                strpos($rel, 'manifest') !== false
            ) {
                $this->add_asset($assets_map, $href, 'icon', $page_url);
            } else {
                // other link assets (preload, etc.)
                $this->add_asset($assets_map, $href, 'asset', $page_url);
            }
        }

        // 3) <script src=...>
        foreach ($xpath->query('//script[@src]') as $s) {
            $this->add_asset($assets_map, $this->clean_url($s->getAttribute('src'), $page_url), 'js', $page_url);
        }

        // 4) <video>/<audio>/<source> src
        foreach ($xpath->query('//*[@src]') as $n) {
            $tag = strtolower($n->nodeName);
            if (in_array($tag, ['video','audio','source'], true)) {
                $this->add_asset($assets_map, $this->clean_url($n->getAttribute('src'), $page_url), $tag, $page_url);
            }
        }

        // 5) <a href=...> documents (pdf/doc/zip etc)
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = $this->clean_url($a->getAttribute('href'), $page_url);
            if (!$href) continue;
            $ext = strtolower(pathinfo(parse_url($href, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            // Match the older plugin's doc coverage + common archives
            if (in_array($ext, ['doc','docx','odt','pdf','xls','xlsx','ods','ppt','pptx','txt','zip','rar','7z'], true)) {
                $this->add_asset($assets_map, $href, 'document', $page_url);
            }
        }

        // 5b) Social/meta images (og:image, twitter:image, etc.)
        foreach ($xpath->query('//meta[@content]') as $m) {
            $prop = strtolower(trim($m->getAttribute('property')));
            $name = strtolower(trim($m->getAttribute('name')));
            $key  = $prop ?: $name;
            if ($key === '') continue;
            if (in_array($key, ['og:image','og:image:url','twitter:image','twitter:image:src','msapplication-tileimage'], true)) {
                $u = $this->clean_url($m->getAttribute('content'), $page_url);
                $this->add_asset($assets_map, $u, 'image', $page_url);
            }
        }

        // 6) inline <style> url(...)
        foreach ($xpath->query('//style') as $style) {
            $css = $style->textContent;
            foreach ($this->extract_css_urls($css, $page_url) as $u) {
                $this->add_asset($assets_map, $u, 'css_asset', $page_url);
            }
        }

        // 7) style="" attributes url(...)
        foreach ($xpath->query('//*[@style]') as $n) {
            $css = $n->getAttribute('style');
            foreach ($this->extract_css_urls($css, $page_url) as $u) {
                $this->add_asset($assets_map, $u, 'css_asset', $page_url);
            }
        }

        return array_values($assets_map);
    }

    private function add_asset(array &$assets_map, $url, $type, $found_on) : void {
        if (!$url) return;
        if (stripos($url, 'data:') === 0) return;
        if (!$this->is_internal($url)) return;

        $url = $this->normalize_asset_url($url);
        if (!$url) return;

        $key = $type . '|' . $url;
        $assets_map[$key] = ['url' => $url, 'asset_type' => $type, 'found_on' => $found_on];
    }

    private function is_internal($url) : bool {
        $u = wp_parse_url($url);
        if (empty($u['host'])) return false;

        $home = wp_parse_url(home_url('/'));
        $hosts = [];
        if (!empty($home['host'])) $hosts[] = strtolower((string) $home['host']);

        // Upload base URL can be on a different host (CDN-like) even when still “internal”.
        $up = wp_upload_dir();
        if (!empty($up['baseurl'])) {
            $uph = wp_parse_url($up['baseurl']);
            if (!empty($uph['host'])) $hosts[] = strtolower((string) $uph['host']);
        }

        $hosts = array_values(array_unique(array_filter($hosts)));

        /**
         * Allow extensions to add additional internal hosts.
         *
         * @param string[] $hosts
         */
        $hosts = apply_filters('wp_to_html_allowed_hosts', $hosts);

        $h = strtolower((string) $u['host']);
        return in_array($h, $hosts, true);
    }

    private function clean_url($raw, $base) {
        if (!is_string($raw) || $raw === '') return null;

        $raw = html_entity_decode(trim($raw), ENT_QUOTES);

        // protocol-relative
        if (strpos($raw, '//') === 0) {
            $raw = (is_ssl() ? 'https:' : 'http:') . $raw;
        }

        // ignore fragments-only
        if ($raw[0] === '#') return null;

        // absolute already
        if (preg_match('~^https?://~i', $raw)) return $raw;

        // Query-only reference like "?ver=123"
        if ($raw[0] === '?') {
            $bp = wp_parse_url($base);
            if (!$bp) return null;
            $scheme = $bp['scheme'] ?? (is_ssl() ? 'https' : 'http');
            $host   = $bp['host'] ?? '';
            if ($host === '') return null;
            $path   = $bp['path'] ?? '/';
            return $scheme . '://' . $host . $path . $raw;
        }

        // Relative -> absolute based on page URL (robust RFC3986 resolver)
        $abs = \wp_to_html_url_to_absolute($base, $raw);
        return $abs === false ? null : $abs;
    }

    private function parse_srcset($srcset, $base_url) : array {
        if (!is_string($srcset) || trim($srcset) === '') return [];
        $out = [];
        foreach (explode(',', $srcset) as $part) {
            $u = trim(preg_split('/\s+/', trim($part))[0] ?? '');
            $u = $this->clean_url($u, $base_url);
            if ($u) $out[] = $u;
        }
        return $out;
    }

    private function extract_css_urls($css, $base_url) : array {
        if (!is_string($css) || $css === '') return [];

        $urls = [];

        // url(...)
        preg_match_all('~url\(([^)]+)\)~i', $css, $m);
        foreach (($m[1] ?? []) as $raw) {
            $raw = trim($raw, " \t\n\r\0\x0B\"'");
            $u = $this->clean_url($raw, $base_url);
            if ($u) $urls[] = $u;
        }

        // @import url(...) and @import "..."
        preg_match_all('~@import\s+(?:url\()?\s*["\']?([^"\')\s;]+)["\']?\s*\)?\s*;~i', $css, $im);
        foreach (($im[1] ?? []) as $raw) {
            $raw = trim($raw, " \t\n\r\0\x0B\"'");
            $u = $this->clean_url($raw, $base_url);
            if ($u) $urls[] = $u;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Normalize assets for de-duping:
     * - Drop fragments
     * - Remove common cache-busting query params (ver, v, cb, ...)
     */
    private function normalize_asset_url(string $url) : ?string {
        $p = wp_parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return null;

        // Remove fragment
        unset($p['fragment']);

        // Remove common cache-busting params
        if (!empty($p['query'])) {
            parse_str($p['query'], $q);
            if (is_array($q) && !empty($q)) {
                foreach (self::DEDUPE_QUERY_PARAMS as $k) {
                    if (array_key_exists($k, $q)) unset($q[$k]);
                }
                $p['query'] = http_build_query($q);
                if ($p['query'] === '') unset($p['query']);
            }
        }

        $out = $p['scheme'] . '://' . $p['host'];
        if (!empty($p['port'])) $out .= ':' . $p['port'];
        $out .= $p['path'] ?? '/';
        if (!empty($p['query'])) $out .= '?' . $p['query'];
        return $out;
    }


}