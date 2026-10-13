<?php
/**
 * Main plugin bootstrap.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires every component together.
 */
final class Plugin {

	/**
	 * Capability used to view statistics (granted dynamically, see grant_view_cap()).
	 */
	const CAP_VIEW = 'pulse_view_stats';

	/**
	 * REST namespace shared by the collector and the admin API.
	 */
	const REST_NS = 'pulse-analytics/v1';

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns (and boots) the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers all hooks.
	 */
	private function __construct() {
		Installer::maybe_upgrade();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'user_has_cap', array( $this, 'grant_view_cap' ), 10, 4 );

		( new Tracker() )->hooks();
		( new Collector() )->hooks();
		( new REST_Controller() )->hooks();
		( new Cron() )->hooks();
		( new Privacy() )->hooks();
		( new Admin_Bar() )->hooks();
		( new Installer() )->hooks();
		( new Updater() )->hooks();

		if ( is_admin() ) {
			( new Admin() )->hooks();
			( new Settings_Page() )->hooks();
			( new Exporter() )->hooks();
			( new Dashboard_Widget() )->hooks();
			( new Post_Stats() )->hooks();
		}
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'pulse-analytics', false, dirname( plugin_basename( PULSE_ANALYTICS_FILE ) ) . '/languages' );
	}

	/**
	 * Grants the "view statistics" capability to administrators and to the roles chosen in settings.
	 *
	 * @param array    $allcaps All capabilities of the user.
	 * @param array    $caps    Required primitive capabilities.
	 * @param array    $args    Arguments.
	 * @param \WP_User $user    User object.
	 * @return array
	 */
	public function grant_view_cap( $allcaps, $caps, $args, $user ) {
		if ( ! in_array( self::CAP_VIEW, (array) $caps, true ) || ! empty( $allcaps[ self::CAP_VIEW ] ) ) {
			return $allcaps;
		}
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP_VIEW ] = true;
			return $allcaps;
		}
		$roles = (array) Settings::get( 'view_roles', array() );
		if ( $user instanceof \WP_User && array_intersect( $roles, (array) $user->roles ) ) {
			$allcaps[ self::CAP_VIEW ] = true;
		}
		return $allcaps;
	}

	/**
	 * Custom table names.
	 *
	 * @return array{sessions:string,pageviews:string,pages:string,events:string}
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'sessions'  => $wpdb->prefix . 'pulse_sessions',
			'pageviews' => $wpdb->prefix . 'pulse_pageviews',
			'pages'     => $wpdb->prefix . 'pulse_pages',
			'events'    => $wpdb->prefix . 'pulse_events',
		);
	}
}
