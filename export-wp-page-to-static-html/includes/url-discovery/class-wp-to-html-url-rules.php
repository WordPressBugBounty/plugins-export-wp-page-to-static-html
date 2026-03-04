<?php
namespace WpToHtml\UrlDiscovery;

if (!defined('ABSPATH')) exit;

/**
 * Include/Exclude rule matcher for URLs.
 * Rules are represented as arrays:
 *  - [ 'type' => 'exact'|'prefix'|'regex', 'value' => '...' ]
 */
class Url_Rules {

    /** @var array<int,array{type:string,value:string}> */
    private $include_rules = [];

    /** @var array<int,array{type:string,value:string}> */
    private $exclude_rules = [];

    /** @var string */
    private $home_host;

    public function __construct(array $include_rules, array $exclude_rules) {
        $this->include_rules = $this->normalize_rules($include_rules);
        $this->exclude_rules = $this->normalize_rules($exclude_rules);
        $this->home_host = (string) parse_url(home_url('/'), PHP_URL_HOST);
    }

    /**
     * Check if a URL should be included.
     */
    public function allow(string $url): bool {
        $url = $this->normalize_url($url);
        if ($url === '') return false;

        // Prevent exporting off-site URLs.
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $this->home_host === '' || strcasecmp($host, $this->home_host) !== 0) {
            return false;
        }

        // Exclude rules always win.
        foreach ($this->exclude_rules as $r) {
            if ($this->match($r, $url)) return false;
        }

        // If includes exist, require at least one match.
        if (!empty($this->include_rules)) {
            foreach ($this->include_rules as $r) {
                if ($this->match($r, $url)) return true;
            }
            return false;
        }

        return true;
    }

    public static function parse_rules_text(string $text): array {
        $rules = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, '#') === 0) continue;

            // Accept: prefix:..., exact:..., regex:...
            if (preg_match('/^(prefix|exact|regex)\s*:\s*(.+)$/i', $line, $m)) {
                $type = strtolower($m[1]);
                $value = trim($m[2]);
                if ($value !== '') $rules[] = ['type' => $type, 'value' => $value];
                continue;
            }

            // Fallback: treat a raw URL/pattern as prefix.
            $rules[] = ['type' => 'prefix', 'value' => $line];
        }
        return $rules;
    }

    private function normalize_rules(array $rules): array {
        $out = [];
        foreach ($rules as $r) {
            if (!is_array($r)) continue;
            $type = isset($r['type']) ? strtolower((string) $r['type']) : '';
            $value = isset($r['value']) ? trim((string) $r['value']) : '';
            if ($value === '') continue;
            if (!in_array($type, ['exact','prefix','regex'], true)) $type = 'prefix';
            $out[] = ['type' => $type, 'value' => $value];
        }
        return $out;
    }

    private function match(array $rule, string $url): bool {
        $type = $rule['type'] ?? 'prefix';
        $value = (string) ($rule['value'] ?? '');
        if ($value === '') return false;

        if ($type === 'exact') {
            return $this->normalize_url($value) === $url;
        }

        if ($type === 'prefix') {
            // Normalize rule to absolute if it looks like a path.
            if (strpos($value, 'http://') !== 0 && strpos($value, 'https://') !== 0 && strpos($value, '/') === 0) {
                $value = home_url($value);
            }
            $value = $this->normalize_url($value);
            return $value !== '' && strpos($url, $value) === 0;
        }

        // regex
        $pattern = $value;
        // Allow users to provide /.../ delimiters or raw patterns.
        if (@preg_match($pattern, '') === false) {
            $pattern = '/' . str_replace('/', '\\/', $pattern) . '/';
        }
        return @preg_match($pattern, $url) === 1;
    }

    private function normalize_url(string $url): string {
        $url = trim($url);
        if ($url === '') return '';

        // Normalize scheme+host to WP home scheme/host.
        $url = esc_url_raw($url);
        if ($url === '') return '';

        // Make sure home_url('/') and home_url('') normalize consistently.
        $p = wp_parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return '';

        $path = isset($p['path']) ? (string) $p['path'] : '/';
        $query = isset($p['query']) ? ('?' . $p['query']) : '';

        // Keep trailing slash preference from WP.
        $base = $p['scheme'] . '://' . $p['host'];
        $norm = $base . $path . $query;

        // Remove fragment.
        $norm = preg_replace('/#.*/', '', $norm);
        return (string) $norm;
    }
}
