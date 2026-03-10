<?php
/** 
 * PDF Generator — adds a "Generate PDF" button to the admin bar and a shortcode.
 *
 * Free tier  : users can generate up to 2 PDFs per day (per user account).
 * Pro tier   : unlimited PDF generation (daily limit is bypassed when the
 *              Export WP Pages to Static HTML Pro add-on is active).
 *
 * How it works
 * ────────────
 * Visiting any frontend page with ?generate-pdf=true enqueues html2pdf.js and
 * triggers an automatic download of the page as a PDF.
 *
 * Settings are stored in the wp_options table:
 *   wp_to_html_pdf_roles  — array of WP role slugs that may generate PDFs.
 *
 * @package WpToHtml
 * @since   6.1.0
 */

namespace WpToHtml;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PdfGenerator {

	public function __construct() {
		add_action( 'admin_bar_menu',   [ $this, 'add_pdf_button' ], 100 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'wp_footer',          [ $this, 'render_modal' ] );

		// Post list row actions (posts + CPTs / pages)
		add_filter( 'post_row_actions',  [ $this, 'add_row_action' ], 10, 2 );
		add_filter( 'page_row_actions',  [ $this, 'add_row_action' ], 10, 2 );

		// Admin styles for row-action badge
		add_action( 'admin_head', [ $this, 'admin_styles' ] );

		// AJAX: check / increment daily limit
		add_action( 'wp_ajax_wp_to_html_check_pdf_limit',    [ $this, 'ajax_check_limit' ] );
		add_action( 'wp_ajax_nopriv_wp_to_html_check_pdf_limit', [ $this, 'ajax_check_limit' ] );
		add_action( 'wp_ajax_wp_to_html_increment_pdf_count',        [ $this, 'ajax_increment_count' ] );
		add_action( 'wp_ajax_nopriv_wp_to_html_increment_pdf_count', [ $this, 'ajax_increment_count' ] );

		// AJAX: save PDF settings (admin only)
		add_action( 'wp_ajax_wp_to_html_save_pdf_settings', [ $this, 'ajax_save_settings' ] );

		// Shortcode
		add_shortcode( 'wp_to_html_pdf_button', [ $this, 'shortcode' ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Admin Bar Button
	// ─────────────────────────────────────────────────────────────────────────

	public function add_pdf_button( $wp_admin_bar ) {
		if ( ! is_admin_bar_showing() || is_admin() ) {
			return;
		}

		if ( ! $this->current_user_can_generate() ) {
			return;
		}

		$wp_admin_bar->add_node( [
			'id'    => 'wp-to-html-generate-pdf',
			'title' => '<span class="wth-ab-pdf-inner">'
			           . '<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5Z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>'
			           . esc_html__( 'PDF', 'wp-to-html' )
			           . '</span>',
			'href'  => add_query_arg( 'generate-pdf', 'true' ),
			'meta'  => [
				'class' => 'wp-to-html-pdf-btn',
				'title' => esc_attr__( 'Download this page as a PDF', 'wp-to-html' ),
			],
		] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Script / Style Enqueue (frontend only)
	// ─────────────────────────────────────────────────────────────────────────

	public function enqueue_scripts() {
		// Style the admin-bar button on every frontend page.
		if ( is_admin_bar_showing() ) {
			wp_add_inline_style( 'admin-bar', '
				#wpadminbar .wp-to-html-pdf-btn > .ab-item {
					padding: 0 !important;
				}
				#wpadminbar .wth-ab-pdf-inner {
					display: inline-flex;
					align-items: center;
					gap: 5px;
					background: #4f46e5;
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
					box-shadow: 0 2px 8px rgba(79,70,229,.45), inset 0 1px 0 rgba(255,255,255,.18);
					transition: background .18s ease, box-shadow .18s ease, transform .15s ease;
				}
				#wpadminbar .wp-to-html-pdf-btn > .ab-item:hover .wth-ab-pdf-inner {
					background: #4338ca;
					box-shadow: 0 4px 14px rgba(79,70,229,.6), inset 0 1px 0 rgba(255,255,255,.18);
					transform: translateY(-1px);
				}
				#wpadminbar .wp-to-html-pdf-btn > .ab-item:active .wth-ab-pdf-inner {
					transform: translateY(0);
					box-shadow: 0 1px 4px rgba(79,70,229,.4);
				}
			' );
		}

		// Only load the heavy pdf libraries when ?generate-pdf=true is present.
		$trigger = isset( $_GET['generate-pdf'] ) ? sanitize_text_field( wp_unslash( $_GET['generate-pdf'] ) ) : '';
		if ( $trigger !== 'true' ) {
			return;
		}

		// Enforce daily limit for free installs (pro bypasses it).
		if ( ! $this->is_pro_active() && ! $this->can_generate_today() ) {
			return; // Modal renders the "limit reached" message.
		}

		$base = WP_TO_HTML_URL . 'assets/pdf-making/';
		$ver  = WP_TO_HTML_VERSION;

		wp_enqueue_script( 'wp-to-html-html2pdf',  $base . 'html2pdf.bundle.min.js', [],       $ver, true );
		wp_enqueue_script( 'wp-to-html-jspdf',     $base . 'jspdf.umd.min.js',       [],       $ver, true );
		wp_enqueue_script( 'wp-to-html-pdf-trigger', WP_TO_HTML_URL . 'assets/pdf-making/pdf-trigger.js',
			[ 'wp-to-html-html2pdf', 'wp-to-html-jspdf' ], $ver, true );

		wp_localize_script( 'wp-to-html-pdf-trigger', 'wpToHtmlPdf', [
			'filename' => $this->clean_page_basename(),
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'wp_to_html_pdf_nonce' ),
		] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Footer Modal
	// ─────────────────────────────────────────────────────────────────────────

	public function render_modal() {
		$trigger = isset( $_GET['generate-pdf'] ) ? sanitize_text_field( wp_unslash( $_GET['generate-pdf'] ) ) : '';
		if ( $trigger !== 'true' ) {
			return;
		}

		$can_generate  = $this->is_pro_active() || $this->can_generate_today();
		$upgrade_url   = 'https://myrecorp.com/export-wp-page-to-static-html-pro?clk=wp&a=pdf-limit';
		?>
		<style>
		.wth-pdf-modal{position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:99999;font-family:'Segoe UI',Tahoma,sans-serif;}
		.wth-pdf-modal-box{background:#fff;padding:32px 28px;border-radius:14px;box-shadow:0 12px 32px rgba(0,0,0,.18);text-align:center;max-width:440px;width:90%;animation:wthFadeIn .25s ease;}
		.wth-pdf-modal-box h2{margin:0 0 10px;font-size:21px;color:#1e1e1e;}
		.wth-pdf-modal-box p{font-size:14px;color:#555;margin:0 0 22px;line-height:1.55;}
		.wth-pdf-modal-actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;}
		.wth-pdf-btn-pro{background:linear-gradient(135deg,#6366f1,#818cf8);color:#fff;text-decoration:none;padding:10px 22px;border-radius:7px;font-weight:600;font-size:14px;}
		.wth-pdf-btn-close{background:#eee;border:none;padding:10px 22px;border-radius:7px;font-weight:600;color:#333;cursor:pointer;font-size:14px;}
		@keyframes wthFadeIn{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}
		</style>

		<div class="wth-pdf-modal" id="wth-pdf-modal">
			<div class="wth-pdf-modal-box">
				<?php if ( $can_generate ) : ?>
					<h2><?php esc_html_e( 'Generating PDF…', 'wp-to-html' ); ?></h2>
					<p><?php esc_html_e( 'Please wait while your file is being prepared.', 'wp-to-html' ); ?></p>
				<?php else : ?>
					<h2><?php esc_html_e( 'Daily Limit Reached', 'wp-to-html' ); ?></h2>
					<p><?php esc_html_e( "You've already generated 2 PDFs today. Come back tomorrow or upgrade to Pro for unlimited downloads.", 'wp-to-html' ); ?></p>
					<div class="wth-pdf-modal-actions">
						<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener noreferrer" class="wth-pdf-btn-pro">
							<?php esc_html_e( 'Upgrade to Pro', 'wp-to-html' ); ?>
						</a>
						<button class="wth-pdf-btn-close" onclick="document.getElementById('wth-pdf-modal').style.display='none'">
							<?php esc_html_e( 'Close', 'wp-to-html' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Admin List: Row Action
	// ─────────────────────────────────────────────────────────────────────────

	public function add_row_action( array $actions, \WP_Post $post ): array {
		if ( ! $this->current_user_can_generate() ) {
			return $actions;
		}

		// Only show for published posts (draft/trash have no useful frontend URL).
		if ( 'publish' !== $post->post_status ) {
			return $actions;
		}

		$url = add_query_arg( 'generate-pdf', 'true', get_permalink( $post->ID ) );

		$actions['wth_generate_pdf'] =
			'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" class="wth-row-pdf-link">'
			. '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:3px"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5Z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><polyline points="9 15 12 18 15 15"/></svg>'
			. esc_html__( 'Generate PDF', 'wp-to-html' )
			. '</a>';

		return $actions;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Admin Styles (row-action badge)
	// ─────────────────────────────────────────────────────────────────────────

	public function admin_styles() {
		if ( ! $this->current_user_can_generate() ) {
			return;
		}
		?>
		<style id="wth-admin-pdf-styles">
		.wth-row-pdf-link {
			color: #4f46e5 !important;
			font-weight: 500;
		}
		.wth-row-pdf-link:hover {
			color: #3730a3 !important;
		}
		</style>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Shortcode  [wp_to_html_pdf_button name="Download PDF"]
	// ─────────────────────────────────────────────────────────────────────────

	public function shortcode( $atts ) {
		$atts = shortcode_atts( [ 'name' => __( 'Generate PDF', 'wp-to-html' ) ], $atts, 'wp_to_html_pdf_button' );
		$url  = add_query_arg( 'generate-pdf', 'true' );
		$text = esc_html( $atts['name'] );

		return '<a class="wth-pdf-shortcode-btn" href="' . esc_url( $url ) . '">' . $text . '</a>';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX: check daily limit
	// ─────────────────────────────────────────────────────────────────────────

	public function ajax_check_limit() {
		check_ajax_referer( 'wp_to_html_pdf_nonce', 'nonce' );

		if ( $this->is_pro_active() ) {
			wp_send_json_success( [ 'allow' => true ] );
		}

		wp_send_json_success( [ 'allow' => $this->can_generate_today() ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX: increment daily count
	// ─────────────────────────────────────────────────────────────────────────

	public function ajax_increment_count() {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_key( $_POST['nonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wp_to_html_pdf_nonce' ) ) {
			wp_send_json_error( 'Invalid nonce.' );
		}

		if ( $this->is_pro_active() ) {
			wp_send_json_success(); // Pro: no tracking needed.
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			// Guest: use a transient keyed to IP (best-effort).
			$key  = 'wth_pdf_guest_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
			$data = get_transient( $key );
			$today = current_time( 'Y-m-d' );
			if ( ! $data || $data['date'] !== $today ) {
				$data = [ 'date' => $today, 'count' => 0 ];
			}
			$data['count']++;
			set_transient( $key, $data, DAY_IN_SECONDS );
		} else {
			// Logged-in user: persist in user meta.
			$today = current_time( 'Y-m-d' );
			$log   = get_user_meta( $user_id, 'wth_pdf_export_log', true );
			if ( ! $log || ! is_array( $log ) || ( $log['date'] ?? '' ) !== $today ) {
				$log = [ 'date' => $today, 'count' => 0 ];
			}
			$log['count']++;
			update_user_meta( $user_id, 'wth_pdf_export_log', $log );
		}

		wp_send_json_success();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// AJAX: save PDF settings  (admin only)
	// ─────────────────────────────────────────────────────────────────────────

	public function ajax_save_settings() {
		check_ajax_referer( 'wp_rest', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		// JS sends roles as a JSON-encoded string (e.g. '["editor","author"]').
		$roles_raw = isset( $_POST['roles'] ) ? wp_unslash( $_POST['roles'] ) : '[]';
		if ( is_array( $roles_raw ) ) {
			$decoded = $roles_raw;
		} else {
			$decoded = json_decode( sanitize_text_field( $roles_raw ), true );
			if ( ! is_array( $decoded ) ) {
				$decoded = [];
			}
		}
		$raw_roles = array_map( 'sanitize_key', $decoded );

		update_option( 'wp_to_html_pdf_roles', $raw_roles );

		wp_send_json_success( [ 'roles' => $raw_roles ] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Whether the current user is allowed to generate PDFs.
	 */
	private function current_user_can_generate(): bool {
		$allowed = (array) get_option( 'wp_to_html_pdf_roles', [] );
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
	 * Whether the current user is within the free daily limit (2/day).
	 */
	private function can_generate_today(): bool {
		$user_id = get_current_user_id();
		$today   = current_time( 'Y-m-d' );

		if ( $user_id ) {
			$log = get_user_meta( $user_id, 'wth_pdf_export_log', true );
			if ( is_array( $log ) && ( $log['date'] ?? '' ) === $today && $log['count'] >= 2 ) {
				return false;
			}
		} else {
			$key  = 'wth_pdf_guest_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
			$data = get_transient( $key );
			if ( $data && $data['date'] === $today && $data['count'] >= 2 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the Pro add-on is active.
	 */
	private function is_pro_active(): bool {
		return function_exists( 'wp_to_html_is_pro_active' ) && wp_to_html_is_pro_active();
	}

	/**
	 * A URL-safe basename for the PDF filename, e.g. "example-com-my-page".
	 */
	private function clean_page_basename(): string {
		$site = preg_replace( '/[^a-zA-Z0-9\-]/', '-',
			str_replace( [ 'http://', 'https://', 'www.' ], '', get_site_url() )
		);

		$slug = get_post_field( 'post_name', get_queried_object_id() );

		if ( is_front_page() || is_home() || empty( $slug ) ) {
			$slug = is_front_page() || is_home() ? 'homepage' : '';
		}

		if ( empty( $slug ) ) {
			$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$slug = preg_replace( '/[^a-zA-Z0-9\-]/', '-', trim( parse_url( $uri, PHP_URL_PATH ), '/' ) );
			if ( empty( $slug ) ) {
				$slug = 'page';
			}
		}

		return $site . '-' . $slug;
	}
}
