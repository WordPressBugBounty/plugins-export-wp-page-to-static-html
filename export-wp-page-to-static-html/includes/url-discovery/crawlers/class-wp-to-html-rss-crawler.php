<?php
namespace WpToHtml\UrlDiscovery\Crawlers;

use WpToHtml\UrlDiscovery\Url_Crawler_Interface;

if (!defined('ABSPATH')) exit;

class Rss_Crawler implements Url_Crawler_Interface {

    public function get_urls(array $ctx): array {
        $log = $ctx['logger'] ?? null;
        $max_urls = isset($ctx['max_urls']) ? (int) $ctx['max_urls'] : 20000;
        $urls = [];

        $feed_url = home_url('/feed/');
        $xml = $this->fetch_xml($feed_url);
        if ($xml === '') {
            if (is_callable($log)) call_user_func($log, 'RSS crawler: feed not available');
            return [];
        }

        $doc = $this->load_xml($xml);
        if (!$doc) return [];

        // RSS 2.0: <rss><channel><item><link>
        if (isset($doc->channel) && isset($doc->channel->item)) {
            foreach ($doc->channel->item as $item) {
                $link = trim((string) $item->link);
                if ($link !== '') $urls[] = $link;
                if (count($urls) >= $max_urls) break;
            }
        }

        if (is_callable($log)) call_user_func($log, 'RSS crawler: found ' . count($urls) . ' item URLs');
        return $urls;
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
        return (string) wp_remote_retrieve_body($resp);
    }

    private function load_xml(string $xml) {
        if ($xml === '') return null;
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        if (!$doc) return null;
        return $doc;
    }
}
