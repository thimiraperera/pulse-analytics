<?php
/**
 * Scheduled maintenance tasks.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Daily housekeeping, geolocation updates and email reports.
 */
class Cron {

	const DAILY_HOOK = 'pulse_analytics_daily';
	const GEOIP_HOOK = 'pulse_analytics_geoip_update';

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( self::DAILY_HOOK, array( $this, 'daily' ) );
		add_action( self::GEOIP_HOOK, array( 'PulseAnalytics\\GeoIP', 'maybe_update' ) );
		// Re-create the schedule if it was lost (e.g. after a migration).
		add_action( 'admin_init', array( __CLASS__, 'schedule' ) );
	}

	/**
	 * Schedules recurring events.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			$tomorrow = new \DateTime( 'tomorrow 03:15', wp_timezone() );
			wp_schedule_event( $tomorrow->getTimestamp(), 'daily', self::DAILY_HOOK );
		}
	}

	/**
	 * Removes scheduled events.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::DAILY_HOOK );
		wp_clear_scheduled_hook( self::GEOIP_HOOK );
	}

	/**
	 * Daily tasks.
	 */
	public function daily() {
		self::purge_old_data();
		GeoIP::maybe_update();
		Email_Reports::maybe_send();
		// Rotate the cookieless salt even on days without traffic.
		Helpers::daily_salt();
	}

	/**
	 * Deletes data older than the retention period (in batches to avoid long table locks).
	 *
	 * @return int Number of deleted sessions.
	 */
	public static function purge_old_data() {
		global $wpdb;
		$months = (int) Settings::get( 'retention_months', 24 );
		if ( $months <= 0 ) {
			return 0;
		}
		$t       = Plugin::tables();
		$date    = new \DateTime( 'now', wp_timezone() );
		$date->modify( '-' . $months . ' months' );
		$cutoff  = $date->format( 'Y-m-d 00:00:00' );
		$deleted = 0;

		for ( $i = 0; $i < 500; $i++ ) {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t['sessions']} WHERE created_at < %s LIMIT 5000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $ids ) {
				break;
			}
			$list = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( "DELETE FROM {$t['pageviews']} WHERE session_id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$t['events']} WHERE session_id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$t['sessions']} WHERE id IN ({$list})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted += count( $ids );
		}

		if ( $deleted ) {
			self::delete_orphan_pages();
			Cache::flush();
		}
		return $deleted;
	}

	/**
	 * Removes page rows no longer referenced by any pageview or session.
	 */
	public static function delete_orphan_pages() {
		global $wpdb;
		$t = Plugin::tables();
		$wpdb->query( "DELETE pg FROM {$t['pages']} pg LEFT JOIN {$t['pageviews']} p ON p.page_id = pg.id WHERE p.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
