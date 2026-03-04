<?php
namespace WpToHtml\UrlDiscovery\Crawlers;

use WpToHtml\UrlDiscovery\Url_Crawler_Interface;

if (!defined('ABSPATH')) exit;

class Author_Crawler implements Url_Crawler_Interface {

    public function get_urls(array $ctx): array {
        $log = $ctx['logger'] ?? null;
        $urls = [];

        // Get authors who have at least one published post.
        $authors = get_users([
            'who'      => 'authors',
            'fields'   => ['ID'],
            'orderby'  => 'ID',
            'order'    => 'ASC',
            'number'   => 0,
        ]);

        if (empty($authors)) return [];

        foreach ($authors as $u) {
            $id = is_object($u) ? (int) ($u->ID ?? 0) : (int) $u;
            if ($id <= 0) continue;
            $link = get_author_posts_url($id);
            if (is_string($link) && $link !== '') $urls[] = $link;
        }

        if (is_callable($log)) call_user_func($log, 'Author crawler: found ' . count($urls) . ' author archive URLs');
        return $urls;
    }
}
