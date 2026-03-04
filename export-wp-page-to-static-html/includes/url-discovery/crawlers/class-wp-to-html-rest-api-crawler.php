<?php
namespace WpToHtml\UrlDiscovery\Crawlers;

use WpToHtml\UrlDiscovery\Url_Crawler_Interface;

if (!defined('ABSPATH')) exit;

/**
 * Adds REST API endpoints to the export queue.
 *
 * Notes:
 * - This is optional; many users don't need JSON exported.
 * - Endpoints are added with per_page=100&page=N to encourage complete coverage.
 */
class Rest_Api_Crawler implements Url_Crawler_Interface {

    public function get_urls(array $ctx): array {
        $log = $ctx['logger'] ?? null;
        $max_pages_cap = isset($ctx['max_pages']) ? (int) $ctx['max_pages'] : 50;
        $urls = [];

        $base = trailingslashit(home_url('/')) . 'wp-json/wp/v2/';
        $pts = get_post_types(['public' => true, 'show_in_rest' => true], 'objects');

        foreach ($pts as $pt) {
            $rest_base = !empty($pt->rest_base) ? $pt->rest_base : $pt->name;
            if (!$rest_base) continue;

            // Page 1..N; we don't know total pages until fetched.
            // We add a capped set of pages to be safe.
            for ($p = 1; $p <= $max_pages_cap; $p++) {
                $urls[] = $base . $rest_base . '?per_page=100&page=' . $p;
            }
        }

        // Taxonomies in rest
        $taxes = get_taxonomies(['public' => true, 'show_in_rest' => true], 'objects');
        foreach ($taxes as $tax) {
            $rest_base = !empty($tax->rest_base) ? $tax->rest_base : $tax->name;
            if (!$rest_base) continue;
            for ($p = 1; $p <= $max_pages_cap; $p++) {
                $urls[] = $base . $rest_base . '?per_page=100&page=' . $p;
            }
        }

        if (is_callable($log)) call_user_func($log, 'REST API crawler: added ' . count($urls) . ' endpoints (capped pages=' . $max_pages_cap . ')');
        return $urls;
    }
}
