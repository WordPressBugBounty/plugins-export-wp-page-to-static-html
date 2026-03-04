<?php
namespace WpToHtml\UrlDiscovery;

if (!defined('ABSPATH')) exit;

/**
 * URL Crawler interface.
 * Each crawler returns an array of absolute URLs (strings).
 */
interface Url_Crawler_Interface {

    /**
     * @param array $ctx Context for discovery (options, statuses, logger, etc.)
     * @return string[]
     */
    public function get_urls(array $ctx): array;
}
