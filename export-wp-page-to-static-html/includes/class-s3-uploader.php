<?php
namespace WpToHtml;

/**
 * Minimal AWS S3 uploader (no external SDK).
 *
 * Supports:
 * - Saving S3 settings in wp_options
 * - Testing credentials against a bucket
 * - Uploading the generated ZIP file to S3
 *
 * This implementation uses AWS Signature Version 4.
 */
class S3_Uploader {

    public static function sanitize_settings($params): array {
        $params = is_array($params) ? $params : [];

        $bucket = isset($params['bucket']) ? sanitize_text_field((string) $params['bucket']) : '';
        $bucket = trim($bucket);

        $region = isset($params['region']) ? sanitize_text_field((string) $params['region']) : 'us-east-1';
        $region = trim($region) ?: 'us-east-1';

        $access_key = isset($params['access_key']) ? sanitize_text_field((string) $params['access_key']) : '';
        $access_key = trim($access_key);

        // Secret key is sensitive; allow most printable chars.
        $secret_key = isset($params['secret_key']) ? (string) $params['secret_key'] : '';
        $secret_key = trim($secret_key);

        $prefix = isset($params['prefix']) ? sanitize_text_field((string) $params['prefix']) : '';
        $prefix = trim($prefix);
        $prefix = ltrim($prefix, '/');
        $prefix = $prefix !== '' ? rtrim($prefix, '/') . '/' : '';

        $endpoint = isset($params['endpoint']) ? esc_url_raw((string) $params['endpoint']) : '';
        $endpoint = trim($endpoint);

        $use_path_style = !empty($params['use_path_style']) ? 1 : 0;

        $acl = isset($params['acl']) ? sanitize_text_field((string) $params['acl']) : 'private';
        $acl = in_array($acl, ['private','public-read'], true) ? $acl : 'private';

        $storage_class = isset($params['storage_class']) ? sanitize_text_field((string) $params['storage_class']) : '';
        $allowed_sc = ['','STANDARD','STANDARD_IA','ONEZONE_IA','INTELLIGENT_TIERING','GLACIER_IR','GLACIER','DEEP_ARCHIVE'];
        $storage_class = in_array($storage_class, $allowed_sc, true) ? $storage_class : '';

        return [
            'bucket'         => $bucket,
            'region'         => $region,
            'access_key'     => $access_key,
            'secret_key'     => $secret_key,
            'prefix'         => $prefix,
            'endpoint'       => $endpoint,
            'use_path_style' => $use_path_style,
            'acl'            => $acl,
            'storage_class'  => $storage_class,
        ];
    }

    /**
     * Build the S3 URL components.
     */
    private static function build_s3_request_target(array $settings, string $object_key): array {
        $bucket = (string) ($settings['bucket'] ?? '');
        $region = (string) ($settings['region'] ?? 'us-east-1');
        $endpoint = (string) ($settings['endpoint'] ?? '');
        $use_path_style = !empty($settings['use_path_style']);

        $object_key = ltrim($object_key, '/');

        // Default AWS S3 endpoint.
        $host = '';
        $scheme = 'https';
        $path = '';
        $base = '';

        if ($endpoint !== '') {
            $p = wp_parse_url($endpoint);
            if (is_array($p) && !empty($p['host'])) {
                $scheme = !empty($p['scheme']) ? $p['scheme'] : 'https';
                $host = $p['host'];
                $base_path = isset($p['path']) ? rtrim((string)$p['path'], '/') : '';
                $base = $scheme . '://' . $host . $base_path;
            }
        }

        if ($base === '') {
            // Region-specific host rules.
            if ($region === 'us-east-1') {
                $host = $use_path_style ? 's3.amazonaws.com' : ($bucket . '.s3.amazonaws.com');
            } else {
                $host = $use_path_style ? ('s3.' . $region . '.amazonaws.com') : ($bucket . '.s3.' . $region . '.amazonaws.com');
            }
            $base = $scheme . '://' . $host;
        }

        if ($use_path_style) {
            $path = '/' . rawurlencode($bucket) . '/' . self::encode_key_path($object_key);
        } else {
            $path = '/' . self::encode_key_path($object_key);
        }

        return [
            'base' => $base,
            'host' => $host,
            'path' => $path,
        ];
    }

    private static function encode_key_path(string $key): string {
        // rawurlencode each segment but preserve '/'
        $segs = array_map('rawurlencode', explode('/', $key));
        return implode('/', $segs);
    }

    private static function hash_payload_file(string $file_path): string {
        $h = @hash_file('sha256', $file_path);
        return $h ? $h : hash('sha256', '');
    }

    private static function hmac(string $key, string $msg, bool $raw = true): string {
        return hash_hmac('sha256', $msg, $key, $raw);
    }

    private static function aws_signing_key(string $secret_key, string $date, string $region, string $service): string {
        $kDate    = self::hmac('AWS4' . $secret_key, $date, true);
        $kRegion  = self::hmac($kDate, $region, true);
        $kService = self::hmac($kRegion, $service, true);
        $kSigning = self::hmac($kService, 'aws4_request', true);
        return $kSigning;
    }

    private static function build_authorization_header(
        string $access_key,
        string $secret_key,
        string $region,
        string $service,
        string $amz_date,
        string $date_stamp,
        string $method,
        string $canonical_uri,
        string $canonical_query,
        array $headers_lower, // lowercased header => value
        string $payload_hash
    ): array {

        ksort($headers_lower);

        $canonical_headers = '';
        $signed_headers_arr = [];
        foreach ($headers_lower as $k => $v) {
            $k = strtolower(trim($k));
            $v = preg_replace('/\s+/', ' ', trim((string)$v));
            $canonical_headers .= $k . ':' . $v . "\n";
            $signed_headers_arr[] = $k;
        }
        $signed_headers = implode(';', $signed_headers_arr);

        $canonical_request = implode("\n", [
            strtoupper($method),
            $canonical_uri,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ]);

        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
        $string_to_sign = implode("\n", [
            $algorithm,
            $amz_date,
            $credential_scope,
            hash('sha256', $canonical_request),
        ]);

        $signing_key = self::aws_signing_key($secret_key, $date_stamp, $region, $service);
        $signature = hash_hmac('sha256', $string_to_sign, $signing_key);

        $auth = $algorithm
            . ' Credential=' . $access_key . '/' . $credential_scope
            . ', SignedHeaders=' . $signed_headers
            . ', Signature=' . $signature;

        return [$auth, $signed_headers];
    }

    /**
     * Test connection by calling GetBucketLocation (GET ?location).
     */
    public static function test_connection(array $settings, string &$msg): bool {
        $settings = self::sanitize_settings($settings);

        if (($settings['bucket'] ?? '') === '' || ($settings['access_key'] ?? '') === '' || ($settings['secret_key'] ?? '') === '') {
            $msg = 'Bucket, access key, and secret key are required.';
            return false;
        }

        $bucket = $settings['bucket'];
        $region = $settings['region'];
        $access = $settings['access_key'];
        $secret = $settings['secret_key'];

        $target = self::build_s3_request_target($settings, '');
        // Bucket-level request: if virtual-hosted style, object_key '' still yields '/'
        $url = $target['base'] . rtrim($target['path'], '/') . '/?location';

        $t = time();
        $amz_date = gmdate('Ymd\THis\Z', $t);
        $date_stamp = gmdate('Ymd', $t);

        $canonical_uri = rtrim($target['path'], '/') . '/';
        $canonical_query = 'location=';

        $headers = [
            'host' => $target['host'],
            'x-amz-date' => $amz_date,
            'x-amz-content-sha256' => hash('sha256', ''),
        ];

        [$auth, ] = self::build_authorization_header(
            $access,
            $secret,
            $region,
            's3',
            $amz_date,
            $date_stamp,
            'GET',
            $canonical_uri,
            $canonical_query,
            $headers,
            hash('sha256', '')
        );

        $resp = wp_remote_get($url, [
            'timeout' => 20,
            'headers' => [
                'Host' => $target['host'],
                'x-amz-date' => $amz_date,
                'x-amz-content-sha256' => hash('sha256', ''),
                'Authorization' => $auth,
            ],
        ]);

        if (is_wp_error($resp)) {
            $msg = $resp->get_error_message();
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);

        if ($code >= 200 && $code < 300) {
            $msg = 'S3 connection OK (bucket reachable and credentials accepted).';
            return true;
        }

        // Common codes: 403 (bad creds or no access), 404 (bucket not found), 301 (wrong region)
        $short = $body;
        if (strlen($short) > 300) $short = substr($short, 0, 300) . '…';
        $msg = 'S3 test failed (HTTP ' . $code . '). ' . trim(strip_tags($short));
        return false;
    }

    /**
     * Upload a local file to S3 as a single PUT object.
     */
    public static function upload_file(array $settings, string $local_file_path, string $object_key, string &$msg): bool {
        $settings = self::sanitize_settings($settings);

        if (($settings['bucket'] ?? '') === '' || ($settings['access_key'] ?? '') === '' || ($settings['secret_key'] ?? '') === '') {
            $msg = 'S3 upload failed: settings incomplete (bucket/access/secret required).';
            return false;
        }
        if (!file_exists($local_file_path) || !is_readable($local_file_path)) {
            $msg = 'S3 upload failed: local file not found/readable.';
            return false;
        }

        $region = $settings['region'];
        $access = $settings['access_key'];
        $secret = $settings['secret_key'];

        $acl = $settings['acl'] ?? 'private';
        $storage_class = $settings['storage_class'] ?? '';

        $target = self::build_s3_request_target($settings, $object_key);
        $url = $target['base'] . $target['path'];

        $t = time();
        $amz_date = gmdate('Ymd\THis\Z', $t);
        $date_stamp = gmdate('Ymd', $t);

        $payload_hash = self::hash_payload_file($local_file_path);

        $headers_lower = [
            'host' => $target['host'],
            'x-amz-date' => $amz_date,
            'x-amz-content-sha256' => $payload_hash,
        ];
        if ($acl !== '') {
            $headers_lower['x-amz-acl'] = $acl;
        }
        if ($storage_class !== '') {
            $headers_lower['x-amz-storage-class'] = $storage_class;
        }

        [$auth, ] = self::build_authorization_header(
            $access,
            $secret,
            $region,
            's3',
            $amz_date,
            $date_stamp,
            'PUT',
            $target['path'],
            '',
            $headers_lower,
            $payload_hash
        );

        // Prefer streaming upload via cURL to avoid loading the whole ZIP into memory.
        if (function_exists('curl_init')) {
            $fp = fopen($local_file_path, 'rb');
            if (!$fp) {
                $msg = 'S3 upload failed: could not open local file.';
                return false;
            }

            $size = (int) filesize($local_file_path);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $fp);
            curl_setopt($ch, CURLOPT_INFILESIZE, $size);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $hdrs = [
                'Host: ' . $target['host'],
                'x-amz-date: ' . $amz_date,
                'x-amz-content-sha256: ' . $payload_hash,
                'Authorization: ' . $auth,
                'Content-Type: application/zip',
            ];
            if ($acl !== '') $hdrs[] = 'x-amz-acl: ' . $acl;
            if ($storage_class !== '') $hdrs[] = 'x-amz-storage-class: ' . $storage_class;
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);

            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            fclose($fp);

            if ($resp === false) {
                $msg = 'S3 upload failed: ' . ($err ?: 'Unknown cURL error');
                return false;
            }

            $code = (int) ($info['http_code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                $msg = 'Uploaded to S3: s3://' . $settings['bucket'] . '/' . $object_key;
                return true;
            }

            // Body is after headers; keep it short.
            $header_size = (int) ($info['header_size'] ?? 0);
            $body = (string) substr((string)$resp, $header_size);
            if (strlen($body) > 300) $body = substr($body, 0, 300) . '…';
            $msg = 'S3 upload failed (HTTP ' . $code . '): ' . trim(strip_tags($body));
            return false;
        }

        // Fallback: WP HTTP API (loads file into memory).
        $body = file_get_contents($local_file_path);
        if ($body === false) {
            $msg = 'S3 upload failed: could not read local file.';
            return false;
        }

        $resp = wp_remote_request($url, [
            'method'  => 'PUT',
            'timeout' => 60,
            'headers' => [
                'Host' => $target['host'],
                'x-amz-date' => $amz_date,
                'x-amz-content-sha256' => $payload_hash,
                'Authorization' => $auth,
                'Content-Type' => 'application/zip',
                'x-amz-acl' => $acl,
            ],
            'body'    => $body,
        ]);

        if (is_wp_error($resp)) {
            $msg = $resp->get_error_message();
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $resp_body = (string) wp_remote_retrieve_body($resp);
        if ($code >= 200 && $code < 300) {
            $msg = 'Uploaded to S3: s3://' . $settings['bucket'] . '/' . $object_key;
            return true;
        }

        if (strlen($resp_body) > 300) $resp_body = substr($resp_body, 0, 300) . '…';
        $msg = 'S3 upload failed (HTTP ' . $code . '): ' . trim(strip_tags($resp_body));
        return false;
    }
}
