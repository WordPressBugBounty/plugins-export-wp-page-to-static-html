<?php


namespace ExportHtmlAdmin\EWPPTH_AjaxRequests\pageExporter;

use function ExportHtmlAdmin\EWPPTH_AjaxRequests\rcCheckNonce;

class initAjax extends \ExportHtmlAdmin\Export_Wp_Page_To_Static_Html_Admin
{

    public function __construct()
    {
        /*Initialize Ajax page_exporter*/
        add_action('wp_ajax_wpptsh_page_exporter', array( $this, 'page_exporter' ));
    }


    /**
     * Ajax action name: page_exporter
     * @since    1.0.0
     * @access   public
     * @return json
     */

    public function page_exporter(){

        \rcCheckNonce();
        
        $endpoint = rest_url('ewptshp/v1/run');
        $token    = get_option('ewptshp_worker_token');

        wp_remote_post($endpoint, [
            'timeout'   => 0.5,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => [
                'token'   => $token,
                //'page_id' => $url,
                'url' => $url,
            ],
        ]);

        //error_log('[URL DOne] onPageExporter'. $url);

        echo json_encode(array('success' => 'true', 'status' => 'success'));

        die();
    }

}