<?php
namespace WpToHtml\UrlDiscovery;

use WpToHtml\UrlDiscovery\Crawlers\Sitemap_Crawler;
use WpToHtml\UrlDiscovery\Crawlers\Taxonomy_Crawler;
use WpToHtml\UrlDiscovery\Crawlers\Author_Crawler;
use WpToHtml\UrlDiscovery\Crawlers\Post_Type_Archive_Crawler;
use WpToHtml\UrlDiscovery\Crawlers\Date_Archive_Crawler;
use WpToHtml\UrlDiscovery\Crawlers\Pagination_Crawler;
use WpToHtml\UrlDiscovery\Crawlers\Rss_Crawler;
use WpToHtml\UrlDiscovery\Crawlers\Rest_Api_Crawler;

if (!defined('ABSPATH')) exit;

/**
 * Orchestrates smarter URL discovery for queue building.
 */
class Url_Discovery {

    /** @var callable|null */
    private $logger;

    public function __construct(callable $logger = null) {
        $this->logger = $logger;
    }

    /**
     * Discover URLs based on toggles + include/exclude rules.
     *
     * @param array $args Export args from REST (scope, statuses, url_sources, etc.)
     * @return string[]
     */
    public function discover(array $args): array {

        $sources = isset($args['url_sources']) && is_array($args['url_sources']) ? $args['url_sources'] : [];
        $rules   = isset($args['url_rules']) && is_array($args['url_rules']) ? $args['url_rules'] : [];

        $include_rules = $rules['include'] ?? [];
        $exclude_rules = $rules['exclude'] ?? [];

        $rule_engine = new Url_Rules(is_array($include_rules) ? $include_rules : [], is_array($exclude_rules) ? $exclude_rules : []);

        $ctx = [
            'args'          => $args,
            'url_sources'   => $this->normalize_sources($sources),
            'rule_engine'   => $rule_engine,
            'max_pages'     => isset($args['url_discovery_max_pages']) ? max(1, (int) $args['url_discovery_max_pages']) : 50,
            'max_urls'      => isset($args['url_discovery_max_urls']) ? max(100, (int) $args['url_discovery_max_urls']) : 20000,
            'home'          => home_url('/'),
            'logger'        => $this->logger,
        ];

        $urls = [];

        $crawlers = $this->build_crawlers($ctx['url_sources']);
        foreach ($crawlers as $crawler) {
            try {
                $found = $crawler->get_urls($ctx);
                if (!is_array($found) || empty($found)) continue;
                foreach ($found as $u) {
                    $u = is_string($u) ? trim($u) : '';
                    if ($u === '') continue;
                    if ($rule_engine->allow($u)) {
                        $urls[] = $u;
                    }
                    if (count($urls) >= $ctx['max_urls']) break 2;
                }
            } catch (\Throwable $e) {
                $this->log('URL discovery crawler error: ' . get_class($crawler) . ' — ' . $e->getMessage());
            }
        }

        $urls = array_values(array_unique(array_filter($urls)));
        $this->log('URL discovery: collected ' . count($urls) . ' URLs after rules');
        return $urls;
    }

    private function build_crawlers(array $sources): array {
        $out = [];

        // Order matters: use sitemap first to quickly capture most URLs.
        if (!empty($sources['sitemap'])) {
            $out[] = new Sitemap_Crawler();
        }
        if (!empty($sources['taxonomy'])) {
            $out[] = new Taxonomy_Crawler();
        }
        if (!empty($sources['author'])) {
            $out[] = new Author_Crawler();
        }
        if (!empty($sources['post_type_archive'])) {
            $out[] = new Post_Type_Archive_Crawler();
        }
        if (!empty($sources['date_archive'])) {
            $out[] = new Date_Archive_Crawler();
        }
        if (!empty($sources['pagination'])) {
            $out[] = new Pagination_Crawler();
        }
        if (!empty($sources['rss'])) {
            $out[] = new Rss_Crawler();
        }
        if (!empty($sources['rest_api'])) {
            $out[] = new Rest_Api_Crawler();
        }

        return $out;
    }

    private function normalize_sources(array $sources): array {
        $defaults = [
            // Home + posts/pages permalinks are handled in Exporter::build_queue.
            'taxonomy'          => true,
            'author'            => true,
            'post_type_archive' => true,
            'date_archive'      => false,
            'pagination'        => true,
            'sitemap'           => true,
            'rss'               => false,
            'rest_api'          => false,
        ];

        // Accept either {key:bool} or list of enabled keys.
        $normalized = $defaults;
        if (empty($sources)) return $normalized;

        $is_assoc = array_keys($sources) !== range(0, count($sources) - 1);
        if ($is_assoc) {
            foreach ($defaults as $k => $v) {
                if (array_key_exists($k, $sources)) {
                    $normalized[$k] = !empty($sources[$k]);
                }
            }
            return $normalized;
        }

        // Numeric list: treat as list of enabled keys.
        $enabled = array_map('strval', $sources);
        foreach ($defaults as $k => $v) {
            $normalized[$k] = in_array($k, $enabled, true);
        }
        return $normalized;
    }

    private function log(string $msg): void {
        if (is_callable($this->logger)) {
            call_user_func($this->logger, $msg);
        }
    }
}
