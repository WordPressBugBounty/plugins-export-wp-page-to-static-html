<?php
/**
 * Export HTML Button — adds a frontend shortcode, admin-bar button, and post
 * list row action that let users download the current page as a static HTML file.
 *
 * Free tier  : users can export up to 3 HTML files per day (per account).
 * Pro tier   : unlimited HTML exports (daily limit is bypassed).
 *
 * How it works
 * ────────────
 * Visiting any frontend page with ?export-html=true triggers a server-side
 * fetch of that page, injects a <base> tag so relative URLs resolve correctly,
 * strips the recursive export links, and sends the result as a .html download.
 *
 * Shortcode usage:
 *   [export_html_button]
 *   [export_html_button name="Save as HTML"]
 *
 * Settings are stored in wp_options:
 *   wp_to_html_export_html_btn_roles — array of WP role slugs allowed to export.
 *
 * @package WpToHtml
 * @since   6.1.0
 */

namespace WpToHtml;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExportHtmlButton {

	/** Free-tier daily export limit per user. */
	const FREE_DAILY_LIMIT = 3;

	public function __construct() {
		add_action( 'admin_bar_menu',     [ $this, 'add_admin_bar_button' ], 100 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
		add_action( 'template_redirect',  [ $this, 'maybe_export' ], 1 );

		// AJAX: save HTML button settings (admin only).
		add_action( 'wp_ajax_wp_to_html_save_export_html_btn_settings', [ $this, 'ajax_save_settings' ] );

		// Shortcode.
		add_shortcode( 'export_html_button', [ $this, 'shortcode' ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Admin Bar Button
	// ─────────────────────────────────────────────────────────────────────────

	public function add_admin_bar_button( $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() || is_admin() ) {
			return;
		}

		if ( ! $this->current_user_can_export() ) {
			return;
		}

		$wp_admin_bar->add_node( [
			'id'    => 'wp-to-html-export-html-btn',
			'title' => '<span class="wth-ab-html-inner">'
			           . '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5Z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>'
			           . esc_html__( 'HTML', 'wp-to-html' )
			           . '</span>',
			'href'  => add_query_arg( 'export-html', 'true' ),
			'meta'  => [
				'class' => 'wp-to-html-html-export-btn',
				'title' => esc_attr__( 'Download this page as a static HTML file', 'wp-to-html' ),
			],
		] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Frontend Styles
	// ─────────────────────────────────────────────────────────────────────────

	public function enqueue_styles() {
		if ( is_admin_bar_showing() ) {
			wp_add_inline_style( 'admin-bar', '
				#wpadminbar .wp-to-html-html-export-btn > .ab-item {
					padding: 0 !important;
				}
				#wpadminbar .wth-ab-html-inner {
					display: inline-flex;
					align-items: center;
					gap: 5px;
					background: #0ea5e9;
					color: #fff;
					border-radius: 20px;
					font-size: 12px;
					font-weight: 600;
					letter-spacing: .4px;
					text-transform: uppercase;
					padding: 0 12px;
					height: 26px;
					position: relative;
					top: 4px;
					box-shadow: 0 2px 8px rgba(14,165,233,.45), inset 0 1px 0 rgba(255,255,255,.18);
					transition: background .18s ease, box-shadow .18s ease, transform .15s ease;
				}
				#wpadminbar .wp-to-html-html-export-btn > .ab-item:hover .wth-ab-html-inner {
					background: #0284c7;
					box-shadow: 0 4px 14px rgba(14,165,233,.6), inset 0 1px 0 rgba(255,255,255,.18);
					transform: translateY(-1px);
				}
				#wpadminbar .wp-to-html-html-export-btn > .ab-item:active .wth-ab-html-inner {
					transform: translateY(0);
					box-shadow: 0 1px 4px rgba(14,165,233,.4);
				}
			' );
		}

		wp_add_inline_style( 'wp-block-library', '
			.wth-export-html-shortcode-btn {
				display: inline-flex;
				align-items: center;
				gap: 6px;
				background: #0ea5e9;
				color: #fff !important;
				text-decoration: none !important;
				padding: 9px 20px;
				border-radius: 6px;
				font-size: 14px;
				font-weight: 600;
				box-shadow: 0 2px 6px rgba(14,165,233,.35);
				transition: background .18s ease, transform .15s ease, box-shadow .18s ease;
				cursor: pointer;
			}
			.wth-export-html-shortcode-btn:hover {
				background: #0284c7;
				transform: translateY(-1px);
				box-shadow: 0 4px 12px rgba(14,165,233,.5);
			}
			.wth-export-html-shortcode-btn svg {
				flex-shrink: 0;
			}
		' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Main Export Handler  (fires on template_redirect)
	// ─────────────────────────────────────────────────────────────────────────

	public function maybe_export() {
		$trigger = isset( $_GET['export-html'] ) ? sanitize_text_field( wp_unslash( $_GET['export-html'] ) ) : '';
		if ( $trigger !== 'true' ) {
			return;
		}

		// Permission check.
		if ( ! $this->current_user_can_export() ) {
			wp_die(
				esc_html__( 'You do not have permission to export this page.', 'wp-to-html' ),
				esc_html__( 'Access Denied', 'wp-to-html' ),
				[ 'response' => 403 ]
			);
		}

		// Daily limit (free installs only).
		if ( ! $this->is_pro_active() && ! $this->can_export_today() ) {
			wp_die(
				esc_html__( 'You have reached your daily HTML export limit (3/day). Come back tomorrow or upgrade to Pro for unlimited exports.', 'wp-to-html' ),
				esc_html__( 'Daily Limit Reached', 'wp-to-html' ),
				[
					'response'  => 429,
					'link_url'  => 'https://myrecorp.com/export-wp-page-to-static-html-pro?clk=wp&a=html-limit',
					'link_text' => __( 'Upgrade to Pro', 'wp-to-html' ),
				]
			);
		}

		// Current URL without the export-html parameter.
		$page_url = remove_query_arg( 'export-html' );
		if ( strpos( $page_url, 'http' ) !== 0 ) {
			$page_url = home_url( $page_url );
		}

		// Fetch the fully-rendered page HTML.
		$response = wp_remote_get( $page_url, [
			'timeout'   => 30,
			'sslverify' => false,
			'cookies'   => $this->get_current_cookies(),
		] );

		if ( is_wp_error( $response ) ) {
			wp_die(
				esc_html__( 'Could not fetch page HTML: ', 'wp-to-html' ) . esc_html( $response->get_error_message() )
			);
		}

		$html = wp_remote_retrieve_body( $response );

		if ( empty( $html ) ) {
			wp_die( esc_html__( 'The page returned empty HTML. Nothing to export.', 'wp-to-html' ) );
		}

		// Transform: inject <base> tag so relative URLs resolve when opened locally.
		$html = $this->inject_base_tag( $html );

		// Remove recursive export-html links from the downloaded file.
		$html = $this->strip_export_param( $html );

		// Track usage for free-tier limiting.
		if ( ! $this->is_pro_active() ) {
			$this->increment_export_count();
		}

		// Serve as .html download.
		$filename = $this->page_filename();

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $html ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $html;
		exit;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Shortcode  [export_html_button name="Download HTML"]
	// ─────────────────────────────────────────────────────────────────────────

	public function shortcode( $atts ) {
		if ( ! $this->current_user_can_export() ) {
			return '';
		}

		$atts = shortcode_atts(
			[ 'name' => __( 'Download as HTML', 'wp-to-html' ) ],
			$atts,
			'export_html_button'
		);

		$url  = add_query_arg( 'export-html', 'true' );
		$text = esc_html( $atts['name'] );
		$icon = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5Z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>';

		return '<a class="wth-export-html-shortcode-btn" href="' . esc_url( $url ) . '">' . $icon . $text . '</a>';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX: save HTML button settings (admin only)
	// ─────────────────────────────────────────────────────────────────────────

	public function ajax_save_settings() {
		check_ajax_referer( 'wp_rest', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		$roles_raw = isset( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : '[]';
		if ( is_array( $roles_raw ) ) {
			$decoded = $roles_raw;
		} else {
			$decoded = json_decode( sanitize_text_field( $roles_raw ), true );
			if ( ! is_array( $decoded ) ) {
				$decoded = [];
			}
		}
		$roles = array_map( 'sanitize_key', $decoded );

		update_option( 'wp_to_html_export_html_btn_roles', $roles );

		wp_send_json_success( [ 'roles' => $roles ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Private Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Whether the current user (or guest) is allowed to use the export button.
	 */
	private function current_user_can_export(): bool {
		$allowed   = (array) get_option( 'wp_to_html_export_html_btn_roles', [] );
		$allowed[] = 'administrator';

		if ( in_array( 'guest', $allowed, true ) ) {
			return true;
		}

		$user = wp_get_current_user();
		foreach ( $user->roles as $role ) {
			if ( in_array( $role, $allowed, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the current user is within the free daily limit.
	 */
	private function can_export_today(): bool {
		$today   = current_time( 'Y-m-d' );
		$user_id = get_current_user_id();

		if ( $user_id ) {
			$log = get_user_meta( $user_id, 'wth_export_html_log', true );
			if ( is_array( $log ) && ( $log['date'] ?? '' ) === $today && $log['count'] >= self::FREE_DAILY_LIMIT ) {
				return false;
			}
		} else {
			$key  = 'wth_exphtml_guest_' . md5( $this->client_ip() );
			$data = get_transient( $key );
			if ( is_array( $data ) && $data['date'] === $today && $data['count'] >= self::FREE_DAILY_LIMIT ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Record one export against today's free-tier counter.
	 */
	private function increment_export_count(): void {
		$today   = current_time( 'Y-m-d' );
		$user_id = get_current_user_id();

		if ( $user_id ) {
			$log = get_user_meta( $user_id, 'wth_export_html_log', true );
			if ( ! is_array( $log ) || ( $log['date'] ?? '' ) !== $today ) {
				$log = [ 'date' => $today, 'count' => 0 ];
			}
			$log['count']++;
			update_user_meta( $user_id, 'wth_export_html_log', $log );
		} else {
			$key  = 'wth_exphtml_guest_' . md5( $this->client_ip() );
			$data = get_transient( $key );
			if ( ! is_array( $data ) || $data['date'] !== $today ) {
				$data = [ 'date' => $today, 'count' => 0 ];
			}
			$data['count']++;
			set_transient( $key, $data, DAY_IN_SECONDS );
		}
	}

	/**
	 * Inject / replace a <base> tag in <head> so that relative URLs in the
	 * downloaded file resolve correctly when opened locally or from another server.
	 */
	private function inject_base_tag( string $html ): string {
		$base = trailingslashit( home_url( '/' ) );

		// Replace an existing <base> tag.
		if ( preg_match( '/<base\b[^>]*>/i', $html ) ) {
			return preg_replace( '/<base\b[^>]*>/i', '<base href="' . esc_attr( $base ) . '">', $html, 1 );
		}

		// Inject immediately after the opening <head> tag.
		return preg_replace(
			'/(<head\b[^>]*>)/i',
			'$1' . "\n\t" . '<base href="' . esc_attr( $base ) . '">',
			$html,
			1
		);
	}

	/**
	 * Remove the ?export-html=true parameter from all href attributes so the
	 * downloaded file doesn't contain recursive self-download links.
	 */
	private function strip_export_param( string $html ): string {
		return preg_replace_callback(
			'/\bhref=(["\'])([^"\']+)\1/i',
			static function ( $m ) {
				$href = remove_query_arg( 'export-html', $m[2] );
				return 'href=' . $m[1] . $href . $m[1];
			},
			$html
		);
	}

	/**
	 * Build a safe filename for the exported HTML file.
	 * Result example: "example-com-my-page.html"
	 */
	private function page_filename(): string {
		$site = preg_replace(
			'/[^a-zA-Z0-9\-]/', '-',
			str_replace( [ 'http://', 'https://', 'www.' ], '', get_site_url() )
		);

		$slug = get_post_field( 'post_name', get_queried_object_id() );

		if ( empty( $slug ) ) {
			if ( is_front_page() || is_home() ) {
				$slug = 'homepage';
			} else {
				$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				$path = parse_url( $uri, PHP_URL_PATH ) ?? '';
				$slug = preg_replace( '/[^a-zA-Z0-9\-]/', '-', trim( $path, '/' ) );
				if ( empty( $slug ) ) {
					$slug = 'page';
				}
			}
		}

		return $site . '-' . $slug . '.html';
	}

	/**
	 * Forward the current browser cookies to the loopback request so the
	 * fetched HTML reflects the logged-in user's view of the page.
	 */
	private function get_current_cookies(): array {
		$cookies = [];
		foreach ( $_COOKIE as $name => $value ) {
			$cookies[] = new \WP_Http_Cookie( [
				'name'  => sanitize_key( $name ),
				'value' => sanitize_text_field( wp_unslash( $value ) ),
			] );
		}
		return $cookies;
	}

	/**
	 * Best-effort client IP for guest rate-limiting.
	 */
	private function client_ip(): string {
		foreach ( [ 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				return trim( explode( ',', $ip )[0] );
			}
		}
		return 'unknown';
	}

	/**
	 * Whether the Pro add-on is active.
	 */
	private function is_pro_active(): bool {
		return function_exists( 'wp_to_html_is_pro_active' ) && wp_to_html_is_pro_active();
	}
}
