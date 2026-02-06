<?php

namespace ExportHtmlAdmin\EWPPTH_AjaxRequests\checkFtpConnectionStatus;

use function ExportHtmlAdmin\EWPPTH_AjaxRequests\rcCheckNonce;

class initAjax extends \ExportHtmlAdmin\Export_Wp_Page_To_Static_Html_Admin {

    public function __construct() {
        // Initialize Ajax rc_check_ftp_connection_status
        add_action(
            'wp_ajax_rc_check_ftp_connection_status',
            array( $this, 'rc_check_ftp_connection_status' )
        );
    }

    /**
     * Ajax action name: rc_check_ftp_connection_status
     *
     * @since 1.0.0
     * @access public
     * @return void
     */
    public function rc_check_ftp_connection_status() {

        // 1) Nonce verification FIRST
        rcCheckNonce(); //

        // 2) Get raw data, UNSLASH it, then decode
        $raw_ftp_data = isset( $_POST['ftp_data'] ) ? wp_unslash( $_POST['ftp_data'] ) : '';

        // If it is JSON, decode to array
        $ftp_data = json_decode( $raw_ftp_data, true );

        // Make sure it's an array
        if ( ! is_array( $ftp_data ) ) {
            wp_send_json_success(
                array(
                    'status'   => 'error',
                    'response' => false,
                    'message'  => __( 'Invalid FTP data', 'export-wp-page-to-static-html' ),
                )
            );
        }

        // 3) Sanitize each field
        $host = isset( $ftp_data['host'] ) ? sanitize_text_field( $ftp_data['host'] ) : '';
        $user = isset( $ftp_data['user'] ) ? sanitize_text_field( $ftp_data['user'] ) : '';
        $pass = isset( $ftp_data['pass'] ) ? sanitize_text_field( $ftp_data['pass'] ) : '';
        $path = isset( $ftp_data['path'] ) ? sanitize_text_field( $ftp_data['path'] ) : '';

        $connected = false;

        // 4) Try FTP
        if ( function_exists( 'ftp_connect' ) && function_exists( 'ftp_login' ) ) {

            if ( ! empty( $host ) && ! empty( $user ) && ! empty( $pass ) ) {

                // connect
                $ftp_conn = @ftp_connect( $host );
                if ( $ftp_conn ) {
                    $login = @ftp_login( $ftp_conn, $user, $pass );

                    if ( $login ) {
                        $connected = true;

                        // Store only sanitized data
                        update_option(
                            'rc_export_html_ftp_connection_status',
                            'connected'
                        );

                        update_option(
                            'rc_export_html_ftp_data',
                            array(
                                'host' => $host,
                                'user' => $user,
                                'pass' => $pass,
                                'path' => $path,
                            )
                        );
                    } else {
                        update_option(
                            'rc_export_html_ftp_connection_status',
                            'not_connected'
                        );
                    }

                    // close connection
                    @ftp_close( $ftp_conn );
                } else {
                    update_option(
                        'rc_export_html_ftp_connection_status',
                        'not_connected'
                    );
                }
            }
        }

        // 5) Send a clean JSON response
        wp_send_json_success(
            array(
                'status'   => $connected ? 'success' : 'error',
                'response' => $connected,
            )
        );
    }
}
