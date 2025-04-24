<?php


namespace ExportHtmlAdmin\EWPPTH_AjaxRequests\increamentPdfCount;

class initAjax extends \ExportHtmlAdmin\Export_Wp_Page_To_Static_Html_Admin
{
    private $ajax;

    public function __construct($ajax)
    {
        /*Initialize Ajax rc_search_posts*/
        add_action('wp_ajax_ewpptsh_increment_pdf_count', array( $this, 'ewpptsh_increment_pdf_count' ));
        add_action('wp_ajax_nopriv_ewpptsh_increment_pdf_count', array( $this, 'ewpptsh_increment_pdf_count' ));
        $this->ajax = $ajax;
    }

    function ewpptsh_increment_pdf_count() {

        if(!$this->ajax->nonceCheck()){
            wp_send_json_error('Invalid nonce.');
        }
        $key = 'ewpptsh_global_pdf_limit';
        $today = date('Y-m-d');
    
        $data = get_transient($key);
        if (!$data || $data['date'] !== $today) {
            $data = array('count' => 1, 'date' => $today);
        } else {
            $data['count'] += 1;
        }
    
        set_transient($key, $data, 24 * HOUR_IN_SECONDS);
        wp_send_json_success();
    }


}