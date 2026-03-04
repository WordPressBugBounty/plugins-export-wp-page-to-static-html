<?php
namespace WpToHtml\UrlDiscovery\Crawlers;

use WpToHtml\UrlDiscovery\Url_Crawler_Interface;

if (!defined('ABSPATH')) exit;

/**
 * Generates /page/N/ URLs for common archive types.
 *
 * This crawler does not “crawl” HTML. Instead it computes max_num_pages
 * using WP_Query for each archive source.
 */
class Pagination_Crawler implements Url_Crawler_Interface {

    public function get_urls(array $ctx): array {
        $log = $ctx['logger'] ?? null;
        $max_pages_cap = isset($ctx['max_pages']) ? (int) $ctx['max_pages'] : 50;
        $ppp = (int) get_option('posts_per_page');
        if ($ppp <= 0) $ppp = 10;

        $urls = [];

        // 1) Home blog posts pagination (if front is posts).
        if (get_option('show_on_front') === 'posts') {
            $q = new \WP_Query(['post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => $ppp]);
            $urls = array_merge($urls, $this->pages_for_url(home_url('/'), (int) $q->max_num_pages, $max_pages_cap));
        }

        // 2) Post type archives.
        $pts = get_post_types(['public' => true], 'objects');
        foreach ($pts as $pt) {
            if (empty($pt->name) || empty($pt->has_archive)) continue;
            $base = get_post_type_archive_link($pt->name);
            if (!is_string($base) || $base === '') continue;
            $q = new \WP_Query(['post_type' => $pt->name, 'post_status' => 'publish', 'posts_per_page' => $ppp]);
            $urls = array_merge($urls, $this->pages_for_url($base, (int) $q->max_num_pages, $max_pages_cap));
        }

        // 3) Taxonomy term archives.
        $taxes = get_taxonomies(['public' => true], 'objects');
        foreach ($taxes as $tax) {
            if (empty($tax->name)) continue;
            $terms = get_terms(['taxonomy' => $tax->name, 'hide_empty' => true, 'fields' => 'all', 'number' => 0]);
            if (is_wp_error($terms) || empty($terms)) continue;
            foreach ($terms as $t) {
                $base = get_term_link($t);
                if (is_wp_error($base) || !is_string($base) || $base === '') continue;

                // Query posts in this term to compute max pages.
                $q = new \WP_Query([
                    'post_type' => 'any',
                    'post_status' => 'publish',
                    'posts_per_page' => $ppp,
                    'tax_query' => [[
                        'taxonomy' => $tax->name,
                        'field' => 'term_id',
                        'terms' => (int) $t->term_id,
                    ]],
                ]);
                $urls = array_merge($urls, $this->pages_for_url($base, (int) $q->max_num_pages, $max_pages_cap));
            }
        }

        // 4) Author archives.
        $authors = get_users(['who' => 'authors', 'fields' => ['ID'], 'number' => 0]);
        foreach ($authors as $u) {
            $id = is_object($u) ? (int) ($u->ID ?? 0) : (int) $u;
            if ($id <= 0) continue;
            $base = get_author_posts_url($id);
            if (!is_string($base) || $base === '') continue;
            $q = new \WP_Query(['author' => $id, 'post_type' => 'any', 'post_status' => 'publish', 'posts_per_page' => $ppp]);
            $urls = array_merge($urls, $this->pages_for_url($base, (int) $q->max_num_pages, $max_pages_cap));
        }

        if (is_callable($log)) call_user_func($log, 'Pagination crawler: generated ' . count($urls) . ' paginated URLs');
        return $urls;
    }

    private function pages_for_url(string $base_url, int $max_pages, int $cap): array {
        $out = [];
        if ($max_pages <= 1) return $out;
        $max_pages = min($max_pages, $cap);
        for ($p = 2; $p <= $max_pages; $p++) {
            // Using WP's pagination format: /page/2/
            $out[] = trailingslashit($base_url) . 'page/' . $p . '/';
        }
        return $out;
    }
}
