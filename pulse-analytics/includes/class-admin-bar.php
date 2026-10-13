<?php
/**
 * Admin bar view counter.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows today's and 30-day pageviews of the current page in the admin bar.
 */
class Admin_Bar {

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'admin_bar_menu', array( $this, 'node' ), 100 );
	}

	/**
	 * Adds the node.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function node( $bar ) {
		if ( is_admin() || ! Settings::get( 'admin_bar' ) || ! Helpers::can_view() ) {
			return;
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$parsed  = Helpers::parse_page_url( Helpers::site_origin() . $request );
		if ( ! $parsed ) {
			return;
		}
		$path   = is_search() ? '/?s=' : $parsed['path'];
		$counts = Cache::remember(
			'adminbar|' . $path . '|' . Helpers::today(),
			5 * MINUTE_IN_SECONDS,
			static function () use ( $path ) {
				global $wpdb;
				$t  = Plugin::tables();
				$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['pages']} WHERE hash = %s", md5( $path ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! $id ) {
					return array( 0, 0 );
				}
				$today = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['pageviews']} WHERE page_id = %d AND created_at >= %s", $id, Helpers::today() . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$month = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t['pageviews']} WHERE page_id = %d AND created_at >= %s", $id, Helpers::today( -29 ) . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( $today, $month );
			}
		);
		$url = admin_url( 'admin.php?page=' . Admin::PAGE_SLUG . '#/page?path=' . rawurlencode( $path ) );
		$bar->add_node(
			array(
				'id'    => 'pulse-analytics',
				/* translators: %s: number of pageviews today */
				'title' => '<span class="ab-icon dashicons dashicons-chart-area" style="top:2px"></span><span class="ab-label">' . esc_html( sprintf( _n( '%s view today', '%s views today', $counts[0], 'pulse-analytics' ), number_format_i18n( $counts[0] ) ) ) . '</span>',
				'href'  => $url,
				'meta'  => array(
					/* translators: %s: number of pageviews in 30 days */
					'title' => sprintf( __( '%s views in the last 30 days. Click to open the page report.', 'pulse-analytics' ), number_format_i18n( $counts[1] ) ),
				),
			)
		);
	}
}
