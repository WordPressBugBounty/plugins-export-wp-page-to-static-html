<?php


namespace ExportHtmlAdmin\EWPPTH_AjaxRequests\assetsExporter;

use function ExportHtmlAdmin\EWPPTH_AjaxRequests\rcCheckNonce;

class initAjax extends \ExportHtmlAdmin\Export_Wp_Page_To_Static_Html_Admin
{

    public function __construct()
    {
        /*Initialize Ajax assets_exporter*/
        add_action('wp_ajax_wpptsh_assets_exporter', array( $this, 'assets_exporter' ));
    }


    /**
     * Ajax action name: assets_exporter
     * @since    1.0.0
     * @access   public
     * @return json
     */

    public function assets_exporter() {

        \rcCheckNonce();
        $asset_type = isset( $_POST['asset_type'] )
            ? sanitize_text_field( wp_unslash( $_POST['asset_type'] ) )
            : '';


        include __DIR__ . '/../class-ExtractorHelpers.php';
        $extractorHelpers = new \ExtractorHelpers();

        $asset_type = isset($_POST['asset_type']) ? sanitize_text_field(wp_unslash($_POST['asset_type'])) : null;
        $limit      = isset($_POST['limit']) ? (int) $_POST['limit'] : 1;

        $assets = $extractorHelpers->get_next_export_asset($asset_type, $limit);

        // Normalize to array
        if ($limit === 1) {
            $assets = $assets ? [$assets] : [];
        }

        $results = [
            'css'     => [],
            'js'      => [],
            'image'   => [],
            'url'     => [],
            'skipped' => [],
        ];

        foreach ($assets as $asset) {

            $id       = isset($asset['id']) ? (int) $asset['id'] : 0;
            $url      = isset($asset['url']) ? $asset['url'] : '';
            $found_on = isset($asset['found_on']) ? $asset['found_on'] : '';
            $type     = isset($asset['type']) ? $asset['type'] : '';
            $status   = isset($asset['status']) ? $asset['status'] : '';
            $new_name = isset($asset['new_file_name']) ? $asset['new_file_name'] : '';

            if (!$id || !$url || !$type) {
                $results['skipped'][] = ['id' => $id, 'reason' => 'missing_required_fields'];
                continue;
            }

            if ($status === 'processing') {
                $results[$type][] = [
                    'id'           => $id,
                    'url'          => $url,
                    'asset_status' => $status,
                    'handled'      => false,
                    'message'      => 'Already processing',
                ];
                continue;
            }

            $extractorHelpers->update_asset_url_status($url, 'processing');

            try {
                switch ($type) {
                    case 'css':
                        $extractorHelpers->save_stylesheet($url, $found_on, $new_name);
                        break;

                    case 'js':
                        $extractorHelpers->save_scripts($url, $found_on, $new_name);
                        break;

                    case 'url':
                        $endpoint = rest_url('ewptshp/v1/run');
                        $token    = get_option('ewptshp_worker_token');

                        wp_remote_post($endpoint, [
                            'timeout'   => 5,
                            'blocking'  => false,
                            'sslverify' => false,
                            'body'      => [
                                'token' => $token,
                                'url'   => $url,
                            ],
                        ]);
                        
                        //error_log('[URL DOne] onAssetsExporter'. $url);
                        break;

                    case 'image':
                        if (method_exists($extractorHelpers, 'save_image')) {
                            $extractorHelpers->save_image($url, $found_on, $new_name);
                        } else {
                        }
                        break;

                    default:
                        $results['skipped'][] = ['id' => $id, 'reason' => 'unknown_type', 'type' => $type];
                        continue 2;
                }

                $results[$type][] = [
                    'id'           => $id,
                    'url'          => $url,
                    'asset_status' => 'processed',
                    'handled'      => true,
                ];

            } catch (Exception $e) {
                $extractorHelpers->update_asset_url_status($url, 'failed');

                $results[$type][] = [
                    'id'           => $id,
                    'url'          => $url,
                    'asset_status' => 'failed',
                    'handled'      => false,
                    'error'        => $e->getMessage(),
                ];
            }
        }


        echo wp_json_encode([
            'success' => true,
            'status'  => 'success',
            'fetched' => count($assets),
            'grouped' => [
                'css'   => $results['css'],
                'js'    => $results['js'],
                'image' => $results['image'],
                'url'   => $results['url'],
            ],
            'skipped' => $results['skipped'],
        ], JSON_UNESCAPED_SLASHES);

        $status = (string) $this->getSettings('creating_zip');
        $proc   = (string) $this->getSettings('creating_zip_process');
        $creatingHtmlProcess = $this->getSettings('creating_html_process', 'running');


        if ($creatingHtmlProcess === 'completed' && $this->are_all_assets_exported() && $status !== 'running') {
            $this->setSettings('creating_zip', 'running');
        }
        elseif ($status === 'running' && $proc !== 'running' && $proc !== 'completed') {
            do_action('assets_files_exporting_completed');
        }


        wp_die();
    }


    public function are_all_assets_exported() {
        global $wpdb;

        $table = $wpdb->prefix . 'export_urls_logs';

        // Determine which asset types to check
        $skip  = (array) $this->getSettings( 'skipAssetsFiles', array() );
        $types = array();

        if ( ! array_key_exists( 'stylesheets', $skip ) ) { $types[] = 'css'; }
        if ( ! array_key_exists( 'scripts', $skip ) )     { $types[] = 'js';  }

        // Nothing to check → everything is fine
        if ( empty( $types ) ) {
            return true;
        }

        // Cache
        $cache_group = 'wpptsh_assets';
        $cache_key   = 'all_exported_' . md5( $table . '|' . implode( ',', $types ) );

        $found  = null;
        $cached = wp_cache_get( $cache_key, $cache_group, false, $found );
        if ( $found ) {
            return (bool) $cached;
        }

        // Prepare IN() clause
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->prepare(
                "
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN exported = 1 THEN 1 ELSE 0 END) AS exported
                FROM `{$table}`
                WHERE type IN ({$placeholders})
                ",
                ...$types
            ),
            ARRAY_A
        );

        $total    = (int) ( $row['total']    ?? 0 );
        $exported = (int) ( $row['exported'] ?? 0 );

        // ✔ Your rule: if nothing exists, return true
        $all_done = ( $total === 0 ) || ( $total === $exported );

        wp_cache_set( $cache_key, (int) $all_done, $cache_group, 60 );

        return $all_done;
    }


}