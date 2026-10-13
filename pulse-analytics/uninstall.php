<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 * Data is only removed when "Delete all statistics and settings when the plugin is deleted" is enabled.
 *
 * @package PulseAnalytics
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes the plugin data for the current site.
 */
function pulse_analytics_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'pulse_analytics_settings', array() );
	if ( empty( $settings['delete_on_uninstall'] ) ) {
		// Keep statistics; only remove scheduled tasks.
		wp_clear_scheduled_hook( 'pulse_analytics_daily' );
		wp_clear_scheduled_hook( 'pulse_analytics_geoip_update' );
		return;
	}

	foreach ( array( 'pulse_sessions', 'pulse_pageviews', 'pulse_pages', 'pulse_events' ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	$options = array( 'pulse_analytics_settings', 'pulse_analytics_goals', 'pulse_analytics_db_version', 'pulse_analytics_secret', 'pulse_analytics_salt', 'pulse_analytics_geoip', 'pulse_analytics_installed', 'pulse_analytics_email_last', 'pulse_analytics_cache_v' );
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Cached reports.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_pulse\\_%' OR option_name LIKE '\\_transient\\_timeout\\_pulse\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	wp_clear_scheduled_hook( 'pulse_analytics_daily' );
	wp_clear_scheduled_hook( 'pulse_analytics_geoip_update' );

	// Downloaded geolocation databases and cached map data.
	$uploads = wp_upload_dir( null, false );
	$dir     = trailingslashit( $uploads['basedir'] ) . 'pulse-analytics';
	if ( is_dir( $dir ) ) {
		foreach ( array_merge( (array) glob( $dir . '/*' ), array( $dir . '/.htaccess' ) ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pulse_site_id ) {
		switch_to_blog( $pulse_site_id );
		pulse_analytics_uninstall_site();
		restore_current_blog();
	}
} else {
	pulse_analytics_uninstall_site();
}
