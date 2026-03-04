<?php
namespace WpToHtml\UrlDiscovery\Crawlers;

use WpToHtml\UrlDiscovery\Url_Crawler_Interface;

if (!defined('ABSPATH')) exit;

class Taxonomy_Crawler implements Url_Crawler_Interface {

    public function get_urls(array $ctx): array {
        $log = $ctx['logger'] ?? null;
        $urls = [];

        $taxes = get_taxonomies(['public' => true], 'objects');
        if (empty($taxes)) return [];

        foreach ($taxes as $tax) {
            if (empty($tax->name)) continue;

            $terms = get_terms([
                'taxonomy'   => $tax->name,
                'hide_empty' => false,
                'fields'     => 'all',
                'number'     => 0,
            ]);

            if (is_wp_error($terms) || empty($terms)) continue;

            foreach ($terms as $t) {
                $link = get_term_link($t);
                if (!is_wp_error($link) && is_string($link) && $link !== '') {
                    $urls[] = $link;
                }
            }
        }

        if (is_callable($log)) call_user_func($log, 'Taxonomy crawler: found ' . count($urls) . ' term archive URLs');
        return $urls;
    }
}
