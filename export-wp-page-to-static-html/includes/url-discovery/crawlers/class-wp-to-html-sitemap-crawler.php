<?php
namespace WpToHtml\UrlDiscovery\Crawlers;

use WpToHtml\UrlDiscovery\Url_Crawler_Interface;

if (!defined('ABSPATH')) exit;

class Sitemap_Crawler implements Url_Crawler_Interface {

    public function get_urls(array $ctx): array {
        $log = $ctx['logger'] ?? null;
        $max_urls = isset($ctx['max_urls']) ? (int) $ctx['max_urls'] : 20000;

        // Prefer SEO plugin sitemap endpoints when detected.
        // Fallback to WP core sitemap and common conventional locations.
        $candidates = $this->detect_sitemap_candidates();
        if (empty($candidates)) {
            $candidates = [
                home_url('/wp-sitemap.xml'),
                home_url('/sitemap.xml'),
                home_url('/sitemap_index.xml'),
                home_url('/sitemaps.xml'), // SEOPress
            ];
        }
        $urls = [];

        $xml = '';
        $root_url = '';
        foreach ($candidates as $cand) {
            $cand = is_string($cand) ? trim($cand) : '';
            if ($cand === '') continue;
            $xml = $this->fetch_xml($cand);
            if ($xml !== '') {
                $root_url = $cand;
                break;
            }
        }

        if ($xml === '') {
            if (is_callable($log)) call_user_func($log, 'Sitemap crawler: no sitemap found');
            return [];
        }

        $doc = $this->load_xml($xml);
        if (!$doc) return [];

        $root = $doc->getName();
        if ($root === 'sitemapindex') {
            foreach ($doc->sitemap as $sm) {
                $loc = trim((string) $sm->loc);
                if ($loc === '') continue;
                $child = $this->fetch_xml($loc);
                if ($child === '') continue;
                $child_doc = $this->load_xml($child);
                if (!$child_doc) continue;
                foreach ($child_doc->url as $u) {
                    $loc2 = trim((string) $u->loc);
                    if ($loc2 !== '') $urls[] = $loc2;
                    if (count($urls) >= $max_urls) break 2;
                }
            }
        } else {
            foreach ($doc->url as $u) {
                $loc = trim((string) $u->loc);
                if ($loc !== '') $urls[] = $loc;
                if (count($urls) >= $max_urls) break;
            }
        }

        if (is_callable($log)) call_user_func($log, 'Sitemap crawler: root=' . ($root_url ?: 'unknown') . ' found ' . count($urls) . ' URLs');
        return $urls;
    }

    /**
     * Try to detect sitemap URLs for common SEO plugins.
     * Returns a prioritized list of sitemap index locations.
     */
    private function detect_sitemap_candidates(): array {
        $out = [];

        // Yoast
        if (class_exists('WPSEO_Sitemaps_Router')) {
            try {
                $out[] = \WPSEO_Sitemaps_Router::get_base_url('sitemap_index.xml');
                $out[] = \WPSEO_Sitemaps_Router::get_base_url('sitemap.xml');
            } catch (\Throwable $e) {}
        }

        // Rank Math
        if (class_exists('\\RankMath\\Sitemap\\Router')) {
            try {
                $out[] = \RankMath\Sitemap\Router::get_base_url('sitemap_index.xml');
            } catch (\Throwable $e) {}
        }

        // SEOPress
        if (defined('SEOPRESS_VERSION') || defined('SEOPRESS_PRO_VERSION') || function_exists('seopress_init')) {
            $out[] = home_url('/sitemaps.xml');
        }

        // AIOSEO
        if (function_exists('aioseo') || defined('AIOSEO_VERSION')) {
            $out[] = home_url('/sitemap.xml');
        }

        // Core + common fallbacks (append at end so plugin-specific are tried first)
        $out[] = home_url('/wp-sitemap.xml');
        $out[] = home_url('/sitemap_index.xml');
        $out[] = home_url('/sitemap.xml');
        $out[] = home_url('/sitemaps.xml');

        // De-dupe
        $seen = [];
        $uniq = [];
        foreach ($out as $u) {
            if (!is_string($u) || $u === '') continue;
            $k = strtolower($u);
            if (isset($seen[$k])) continue;
            $seen[$k] = 1;
            $uniq[] = $u;
        }
        return $uniq;
    }

    private function fetch_xml(string $url): string {
        $resp = wp_remote_get($url, [
            'timeout' => 20,
            'redirection' => 5,
            'sslverify' => false,
            'headers' => ['User-Agent' => 'WP to HTML URL Discovery'],
        ]);
        if (is_wp_error($resp)) return '';
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) return '';
        $body = (string) wp_remote_retrieve_body($resp);
        return $body;
    }

    private function load_xml(string $xml) {
        if ($xml === '') return null;
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) return null;
        return $doc;
    }
}
