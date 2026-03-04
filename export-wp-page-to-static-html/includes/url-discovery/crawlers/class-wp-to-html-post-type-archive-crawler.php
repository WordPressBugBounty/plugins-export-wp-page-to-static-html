<?php
namespace WpToHtml\UrlDiscovery\Crawlers;

use WpToHtml\UrlDiscovery\Url_Crawler_Interface;

if (!defined('ABSPATH')) exit;

class Post_Type_Archive_Crawler implements Url_Crawler_Interface {

    public function get_urls(array $ctx): array {
        $log = $ctx['logger'] ?? null;
        $urls = [];

        $pts = get_post_types(['public' => true], 'objects');
        foreach ($pts as $pt) {
            if (empty($pt->name)) continue;
            if (empty($pt->has_archive)) continue;
            $link = get_post_type_archive_link($pt->name);
            if (is_string($link) && $link !== '') $urls[] = $link;
        }

        if (is_callable($log)) call_user_func($log, 'Post-type archive crawler: found ' . count($urls) . ' archive URLs');
        return $urls;
    }
}
