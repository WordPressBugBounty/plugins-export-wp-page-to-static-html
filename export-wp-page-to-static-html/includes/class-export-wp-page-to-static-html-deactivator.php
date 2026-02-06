<?php

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Export_Wp_Page_To_Static_Html
 * @subpackage Export_Wp_Page_To_Static_Html/includes
 * @author     ReCorp <rayhankabir1000@gmail.com>
 */

class Export_Wp_Page_To_Static_Html_Deactivator {

	/**
	 * Plugin deactivation cleanup.
	 *
	 * - Drops plugin tables (safe: table names are fixed, no user input).
	 * - Clears plugin options/transients and cache group.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {

		global $wpdb;

		// Fixed, known table suffixes owned by this plugin.
		$table_suffixes = array(
			'export_urls_logs',
			'export_page_to_html_logs',
			'exportable_urls',
		);

		// Drop tables for the current site.
		self::drop_plugin_tables( $table_suffixes );

		// (Optional) If network-wide, also drop from all blogs.
		if ( is_multisite() && is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
			$blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $blog_ids && is_array( $blog_ids ) ) {
				$current_blog_id = get_current_blog_id();
				foreach ( $blog_ids as $bid ) {
					switch_to_blog( (int) $bid );
					self::drop_plugin_tables( $table_suffixes );
				}
				switch_to_blog( $current_blog_id );
			}
		}

		// Clean up plugin options / transients you may have set.
		// (Add/remove keys as your plugin uses.)
		delete_option( 'wpptsh_notices' );
		delete_option( 'wpptsh_notices_clicked_data' );
		delete_option( 'wpptsh_user_roles' );
		delete_option( 'ewptshp_worker_token' );

		// Clear any cron you scheduled.
		wp_clear_scheduled_hook( 'wpptsh_daily_schedules' );

		// If you used a persistent cache group, clear keys here.
		// Avoid wp_cache_flush() as it affects everything; target your group if used.
		// Example: wp_cache_delete( 'some_key', 'wpptsh' );
	}

	/**
	 * Drop the plugin tables for the current (switched) blog.
	 *
	 * @param array $suffixes List of table suffixes to drop.
	 */
	private static function drop_plugin_tables( array $suffixes ) {
		global $wpdb;

		foreach ( $suffixes as $suffix ) {
			// Build fully-qualified table name safely (no user input).
			$suffix     = sanitize_key( $suffix );
			$table_name = $wpdb->prefix . $suffix;

			// Schema operations are intentionally direct and uncached.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" );
		}
	}
}
