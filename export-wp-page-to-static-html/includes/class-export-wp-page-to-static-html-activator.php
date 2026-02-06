<?php

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Export_Wp_Page_To_Static_Html
 * @subpackage Export_Wp_Page_To_Static_Html/includes
 * @author     ReCorp <rayhankabir1000@gmail.com>
 */

class Export_Wp_Page_To_Static_Html_Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * Creates/updates DB tables via dbDelta (idempotent), and deactivates
	 * conflicting premium versions if active.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		global $wpdb;

		// Make sure dbDelta and is_plugin_active are available.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$charset_collate = $wpdb->get_charset_collate();

		// 1) export_page_to_html_logs
		// Keep wide fields as TEXT, but ensure we have a PRIMARY KEY for dbDelta.
		$table_logs = $wpdb->prefix . 'export_page_to_html_logs';
		$sql_logs = "CREATE TABLE {$table_logs} (
			id MEDIUMINT(9) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id INT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(50) NOT NULL DEFAULT '',
			path TEXT NOT NULL,
			comment TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY order_id (order_id)
		) {$charset_collate};";
		dbDelta( $sql_logs );

		// 2) export_urls_logs
		// Use VARCHAR(191) for url so we can add a UNIQUE key (utf8mb4 index-safe).
		$table_urls_logs = $wpdb->prefix . 'export_urls_logs';
		$sql_urls_logs = "CREATE TABLE {$table_urls_logs} (
			id MEDIUMINT(9) UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(191) NOT NULL DEFAULT '',
			new_file_name TEXT NOT NULL,
			found_on TEXT NOT NULL,
			type VARCHAR(50) NOT NULL DEFAULT '',
			exported TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(50) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_url (url)
		) {$charset_collate};";
		dbDelta( $sql_urls_logs );

		// 3) exportable_urls
		$table_exportable = $wpdb->prefix . 'exportable_urls';
		$sql_exportable = "CREATE TABLE {$table_exportable} (
			id MEDIUMINT(9) UNSIGNED NOT NULL AUTO_INCREMENT,
			url VARCHAR(191) NOT NULL DEFAULT '',
			found_on TEXT NOT NULL,
			type VARCHAR(50) NOT NULL DEFAULT '',
			status VARCHAR(50) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_url (url)
		) {$charset_collate};";
		dbDelta( $sql_exportable );

		// If a premium variant is installed, deactivate it to avoid conflicts.
		if ( is_plugin_active( 'export-wp-page-to-static-html-pro-premium/export-wp-page-to-static-html.php' ) ) {
			deactivate_plugins( 'export-wp-page-to-static-html-pro-premium/export-wp-page-to-static-html.php' );
		} elseif ( is_plugin_active( 'export-wp-page-to-static-html-pro/export-wp-page-to-static-html.php' ) ) {
			deactivate_plugins( 'export-wp-page-to-static-html-pro/export-wp-page-to-static-html.php' );
		}
	}
}
