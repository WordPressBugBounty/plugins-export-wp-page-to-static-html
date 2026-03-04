<?php
namespace WpToHtml\UrlDiscovery\Crawlers;

use WpToHtml\UrlDiscovery\Url_Crawler_Interface;

if (!defined('ABSPATH')) exit;

class Date_Archive_Crawler implements Url_Crawler_Interface {

    public function get_urls(array $ctx): array {
        global $wpdb;
        $log = $ctx['logger'] ?? null;
        $urls = [];

        // Distinct year+month where there are published posts.
        $post_type_in = "'post'";
        $rows = $wpdb->get_results(
            "SELECT DISTINCT YEAR(post_date) y, MONTH(post_date) m
             FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type IN ({$post_type_in})
             ORDER BY y DESC, m DESC"
        );

        if (empty($rows)) return [];

        foreach ($rows as $r) {
            $y = (int) ($r->y ?? 0);
            $m = (int) ($r->m ?? 0);
            if ($y <= 0 || $m <= 0) continue;
            $link = get_month_link($y, $m);
            if (is_string($link) && $link !== '') $urls[] = $link;
        }

        if (is_callable($log)) call_user_func($log, 'Date archive crawler: found ' . count($urls) . ' month archive URLs');
        return $urls;
    }
}
