<?php
/**
 * Plugin core (hardened).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activate: schedule daily job.
 */
function rc_static_html_task_events_activate() {
	if ( ! wp_next_scheduled( 'wpptsh_daily_schedules' ) ) {
		wp_schedule_event( time(), 'daily', 'wpptsh_daily_schedules' );
	}
}
register_activation_hook( __FILE__, 'rc_static_html_task_events_activate' );

/**
 * Deactivate: clear cron.
 */
function rc_static_html_task_events_deactivate() {
	wp_clear_scheduled_hook( 'wpptsh_daily_schedules' );
}
register_deactivation_hook( __FILE__, 'rc_static_html_task_events_deactivate' );

/**
 * Cron task: fetch notices via wp_remote_get (replaces file_get_contents).
 */
add_action( 'wpptsh_daily_schedules', 'wpptsh_active_cron_job_after_five_second', 10, 0 );
function wpptsh_active_cron_job_after_five_second() {
	$home_url = home_url(); // already sanitized by WP; escape only on output.
	$endpoint = 'https://api.myrecorp.com/wpptsh_notices.php';

	$url = add_query_arg(
		array(
			'version' => 'free',
			'url'     => rawurlencode( $home_url ),
		),
		$endpoint
	);

	$args  = array(
		'timeout'     => 10,
		'redirection' => 3,
		'sslverify'   => true,
		'user-agent'  => 'WPPTSH/1.0; ' . $home_url,
	);
	$response = wp_remote_get( $url, $args );

	if ( is_wp_error( $response ) ) {
		// Keep previous value; optionally log.
		return;
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return;
	}

	// Validate JSON before saving.
	$decoded = json_decode( $body );
	if ( json_last_error() === JSON_ERROR_NONE ) {
		update_option( 'wpptsh_notices', wp_json_encode( $decoded ) );
	}
}

/**
 * Utility: roles/caps gate.
 */
function EWPPTSH_HasAccess() {
	require_once ABSPATH . WPINC . '/pluggable.php';
	$capabilities = get_option( 'wpptsh_user_roles', array( 'administrator' ) );

	if ( ! empty( $capabilities ) ) {
		foreach ( $capabilities as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
	}
	return current_user_can( 'administrator' );
}

/**
 * Right sidebar renderer (front/admin sidebars).
 */
function wpptsh_right_side_notice() {
	$raw     = get_option( 'wpptsh_notices' );
	$notices = json_decode( $raw );
	$out     = '';

	if ( ! empty( $notices ) && is_array( $notices ) ) {
		$now = time();

		foreach ( $notices as $notice ) {
			$title           = isset( $notice->title ) ? $notice->title : '';
			$key             = isset( $notice->key ) ? $notice->key : '';
			$publishing_date = isset( $notice->publishing_date ) ? (int) strtotime( $notice->publishing_date ) : 0;
			$auto_hide_date  = isset( $notice->auto_hide_date ) ? (int) strtotime( $notice->auto_hide_date ) : PHP_INT_MAX;
			$is_right_sidebar= ! empty( $notice->is_right_sidebar );
			$content         = isset( $notice->content ) ? $notice->content : '';
			$status          = ! empty( $notice->status );
			$version         = isset( $notice->version ) && is_array( $notice->version ) ? $notice->version : array();
			$styles          = isset( $notice->styles ) ? (string) $notice->styles : '';

			if ( $status && $is_right_sidebar && $now > $publishing_date && $now < $auto_hide_date && in_array( 'free', $version, true ) ) {
				// Sanitize output: title -> text; content -> limited HTML; styles -> strip tags to prevent </style><script> injection.
				$safe_title   = esc_html( $title );
				$safe_content = wp_kses_post( $content );
				$safe_styles  = wp_strip_all_tags( $styles ); // keeps CSS content, strips any tags.

				$out .= '<div class="sidebar_notice_section">';
				$out .= '<div class="right_notice_title">' . $safe_title . '</div>';
				$out .= '<div class="right_notice_details">' . $safe_content . '</div>';
				$out .= '</div>';

				if ( ! empty( $safe_styles ) ) {
					$out .= '<style>' . $safe_styles . '</style>';
				}
			}
		}
	}

	// Echo sanitized buffer.
	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buffer built from escaped/sanitized pieces above.
}
add_action( 'wpptsh_right_side_notice', 'wpptsh_right_side_notice' );

/**
 * Admin notices (dismissible).
 */
function wpptsh_admin_notices() {
	$raw     = get_option( 'wpptsh_notices' );
	$notices = json_decode( $raw );
	$out     = '';

	if ( ! current_user_can( 'read' ) ) {
		return;
	}

	if ( ! empty( $notices ) && is_array( $notices ) ) {
		$now          = time();
		$clicked_data = (array) get_option( 'wpptsh_notices_clicked_data', array() );

		foreach ( $notices as $notice ) {
			$title           = isset( $notice->title ) ? $notice->title : '';
			$key             = isset( $notice->key ) ? (string) $notice->key : '';
			$publishing_date = isset( $notice->publishing_date ) ? (int) strtotime( $notice->publishing_date ) : 0;
			$auto_hide_date  = isset( $notice->auto_hide_date ) ? (int) strtotime( $notice->auto_hide_date ) : PHP_INT_MAX;
			$is_right_sidebar= ! empty( $notice->is_right_sidebar );
			$content         = isset( $notice->content ) ? $notice->content : '';
			$status          = ! empty( $notice->status );
			$alert_type      = isset( $notice->alert_type ) ? $notice->alert_type : 'success';
			$version         = isset( $notice->version ) && is_array( $notice->version ) ? $notice->version : array();
			$styles          = isset( $notice->styles ) ? (string) $notice->styles : '';

			if ( $status && ! $is_right_sidebar && $now > $publishing_date && $now < $auto_hide_date && ! in_array( $key, $clicked_data, true ) && in_array( 'free', $version, true ) ) {
				$alert_class  = 'notice-' . sanitize_html_class( $alert_type );
				$safe_key     = esc_attr( $key );
				$safe_content = wp_kses_post( $content );
				$safe_styles  = wp_strip_all_tags( $styles );

				$out .= '<div class="notice ' . esc_attr( $alert_class ) . ' is-dismissible dcim-alert wpptsh" wpptsh_notice_key="' . $safe_key . '">';
				$out .= $safe_content;
				$out .= '</div>';

				if ( ! empty( $safe_styles ) ) {
					$out .= '<style>' . $safe_styles . '</style>';
				}
			}
		}
	}

	echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped/sanitized parts.
}
add_action( 'admin_notices', 'wpptsh_admin_notices' );

/**
 * Track dismiss clicks (AJAX).
 */
add_action( 'wp_ajax_wpptsh_notice_has_clicked', 'wpptsh_notice_has_clicked' );
function wpptsh_notice_has_clicked() {
	check_ajax_referer( 'recorp_different_menu', 'rc_nonce' );

	if ( ! EWPPTSH_HasAccess() ) {
		wp_send_json_error( array( 'status' => 'forbidden' ), 403 );
	}

	$wpptsh_notice_key = isset( $_POST['wpptsh_notice_key'] ) ? sanitize_text_field( wp_unslash( $_POST['wpptsh_notice_key'] ) ) : '';
	set_wpptsh_notices_clicked_data( $wpptsh_notice_key );

	wp_send_json_success( array( 'status' => 'success', 'response' => '' ) );
}

/**
 * Persist clicked notice keys.
 */
function set_wpptsh_notices_clicked_data( $new = '' ) {
	$gop = get_option( 'wpptsh_notices_clicked_data' );

	if ( ! is_array( $gop ) ) {
		$gop = array();
	}

	if ( ! empty( $new ) && ! in_array( $new, $gop, true ) ) {
		$gop[] = $new;
	}

	update_option( 'wpptsh_notices_clicked_data', $gop );
	return $gop;
}

/**
 * Admin JS: handle dismiss clicks using localized variables (no raw echo of nonce/admin_url).
 */
add_action( 'admin_enqueue_scripts', 'rc_wpptsh_enqueue_admin_assets' );
function rc_wpptsh_enqueue_admin_assets() {
	// Minimal inline handler; you could also place this in a separate .js file.
	wp_register_script( 'wpptsh-admin', '', array( 'jquery' ), '1.0', true );

	$vars = array(
		'ajax_url'          => admin_url( 'admin-ajax.php' ),
		'nonce'             => wp_create_nonce( 'recorp_different_menu' ),
		'dismiss_action'    => 'wpptsh_notice_has_clicked',
		'dismiss_export'    => 'dismiss_export_html_notice',
		'rc_nonce_export'   => wp_create_nonce( 'rc-nonce' ),
	);
	wp_localize_script( 'wpptsh-admin', 'wpptshVars', $vars );

	$inline_js = implode("\n", [
	'jQuery(document).on("click", ".wpptsh .notice-dismiss", function(){',
	'  var $p = jQuery(this).parent();',
	'  var k = $p.attr("wpptsh_notice_key") || "";',
	'  if (!k.length) { return; }',
	'  jQuery.post(wpptshVars.ajax_url, {',
	'    action: wpptshVars.dismiss_action,',
	'    rc_nonce: wpptshVars.nonce,',
	'    wpptsh_notice_key: k',
	'  }).done(function(r){',
	'    if (!r || !r.success) {',
	"      console.log('WPPTSH: dismiss failed');",
	'    }',
	'  }).fail(function(){ console.log("WPPTSH: ajax error"); });',
	'});',
	'',
	'// Export notice dismiss',
	'jQuery(document).on("click", ".export-html-notice .notice-dismiss", function(){',
	'  jQuery.post(wpptshVars.ajax_url, {',
	'    action: wpptshVars.dismiss_export,',
	'    rc_nonce: wpptshVars.rc_nonce_export',
	'  }).done(function(r){',
	'    if (!r || !r.success) {',
	"      console.log('WPPTSH: export dismiss failed');",
	'    }',
	'  }).fail(function(){ console.log("WPPTSH: ajax error"); });',
	'});',
	]);

	wp_add_inline_script( 'wpptsh-admin', $inline_js );

	wp_enqueue_script( 'wpptsh-admin' );
}

/**
 * Optional nonce/cap check helper (kept for compatibility in other handlers).
 */
function rcCheckNonce() {
	$nonce = isset( $_POST['rc_nonce'] ) ? sanitize_key( wp_unslash( $_POST['rc_nonce'] ) ) : '';
	if ( ! empty( $nonce ) && ! wp_verify_nonce( $nonce, 'rc-nonce' ) ) {
		wp_send_json_error( array( 'status' => 'nonce_verify_error' ), 403 );
	}
	if ( ! EWPPTSH_HasAccess() ) {
		wp_send_json_error( array( 'status' => 'forbidden' ), 403 );
	}
}

/**
 * Zip extension check.
 */
add_action( 'admin_init', 'rc_check_zip_extension' );
function rc_check_zip_extension() {
	if ( ! extension_loaded( 'zip' ) ) {
		add_action( 'admin_notices', 'rc_display_zip_extension_notice' );
	}
}

/**
 * Admin notice for Zip ext (escaped).
 */
function rc_display_zip_extension_notice() {
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'The Export WP Pages to HTML/CSS plugin requires the Zip extension, which is not installed or enabled on your server. Without the Zip extension, the plugin may not function correctly. Please enable the Zip extension to export a ZIP file of HTML/CSS.', 'export-wp-page-to-static-html' ); ?></p>
	</div>
	<?php
}

require_once ABSPATH . 'wp-admin/includes/file.php';

function plugin_fs() {
    global $wp_filesystem;
    if ( ! $wp_filesystem ) {
        WP_Filesystem();
    }
    return $wp_filesystem;
}

/**
 * Remove a directory using WP_Filesystem (recursively by default).
 *
 * @param string $path Absolute path to the directory.
 * @param bool   $recursive Whether to delete files/subdirs too.
 * @return bool  True on success, false on failure.
 */
function remove_dir_wp( $path, $recursive = true ) {
    $fs = plugin_fs();

    // Ensure trailing slash for directories.
    $path = trailingslashit( $path );

    if ( ! $fs->is_dir( $path ) ) {
        return true; // Already gone.
    }

    return $fs->rmdir( $path, $recursive );
}
/**
 * Safely write data to a file using WP_Filesystem.
 *
 * @param string $savePath  Absolute path of the file to save.
 * @param string $data      Data to be written to the file.
 *
 * @return bool True on success, false on failure.
 */
function wpptsh_write_file( $savePath, $data ) {
    global $wp_filesystem;

    // Load WP_Filesystem if not already loaded
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    if ( ! WP_Filesystem() ) {
        wpptsh_error_log( "❌ WP_Filesystem could not be initialized." );
        return false;
    }

    // Attempt to write data to file
    $result = $wp_filesystem->put_contents( $savePath, $data, FS_CHMOD_FILE );

    if ( ! $result ) {
        wpptsh_error_log( "❌ Cannot write data to file: $savePath" );
        return false;
    }

    return true;
}

/**
 * Safely create a directory using the WordPress Filesystem API.
 *
 * @param string $directory The absolute path of the directory to create.
 * @return bool True on success, false on failure.
 */
function wpptsh_maybe_create_dir( $directory ) {
    global $wp_filesystem;

    // Initialize WP_Filesystem if not already available
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();

    // Ensure we have a valid filesystem object
    if ( ! $wp_filesystem ) {
        wpptsh_error_log( '❌ WP_Filesystem initialization failed.' );
        return false;
    }

    // Check if directory already exists
    if ( $wp_filesystem->is_dir( $directory ) ) {
        return true;
    }

    // Try to create it (recursive)
    if ( $wp_filesystem->mkdir( $directory, FS_CHMOD_DIR ) ) {
        wpptsh_error_log( "📁 Created directory: $directory" );
        return true;
    }

    wpptsh_error_log( "❌ Failed to create directory: $directory" );
    return false;
}
