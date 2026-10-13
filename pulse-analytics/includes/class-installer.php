<?php
/**
 * Installation, upgrades and table management.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and upgrades the custom tables.
 */
class Installer {

	/**
	 * Bump when the table schema changes; dbDelta() runs again on the next load.
	 */
	const DB_VERSION = '1';

	/**
	 * Option names owned by the plugin (removed on uninstall).
	 */
	const OPTIONS = array(
		'pulse_analytics_settings',
		'pulse_analytics_goals',
		'pulse_analytics_db_version',
		'pulse_analytics_secret',
		'pulse_analytics_salt',
		'pulse_analytics_geoip',
		'pulse_analytics_installed',
		'pulse_analytics_email_last',
		'pulse_analytics_cache_v',
	);

	/**
	 * Runtime hooks.
	 */
	public function hooks() {
		add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 200 );
	}

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether the plugin is network-activated.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::install_site();
				restore_current_blog();
			}
		} else {
			self::install_site();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of the core bulk-activation flag.
		if ( ! is_network_admin() && ! isset( $_GET['activate-multi'] ) ) {
			set_transient( 'pulse_analytics_activation_redirect', 1, 60 );
		}
	}

	/**
	 * Deactivation callback. Data is kept; only scheduled tasks are removed.
	 */
	public static function deactivate() {
		Cron::unschedule();
	}

	/**
	 * Creates tables and default options for the current site.
	 */
	public static function install_site() {
		self::create_tables();

		add_option( Settings::OPTION, Settings::defaults() );
		add_option( 'pulse_analytics_goals', array(), '', false );
		add_option( 'pulse_analytics_installed', time(), '', false );
		if ( ! get_option( 'pulse_analytics_secret' ) ) {
			update_option( 'pulse_analytics_secret', wp_generate_password( 64, true, true ), true );
		}
		update_option( 'pulse_analytics_db_version', self::DB_VERSION );

		Cron::schedule();

		// Fetch the free geolocation database in the background shortly after activation.
		if ( GeoIP::is_downloadable_source( Settings::get( 'geoip_source' ) ) && ! GeoIP::database_exists() ) {
			wp_schedule_single_event( time() + 20, Cron::GEOIP_HOOK );
		}
	}

	/**
	 * Runs installation again when the stored schema version is outdated.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'pulse_analytics_db_version' ) !== self::DB_VERSION ) {
			self::install_site();
		}
	}

	/**
	 * Installs the plugin on sites created after a network activation.
	 *
	 * @param \WP_Site $site New site.
	 */
	public static function on_new_site( $site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( PULSE_ANALYTICS_FILE ) ) ) {
			switch_to_blog( $site->blog_id );
			self::install_site();
			restore_current_blog();
		}
	}

	/**
	 * Creates or updates the custom tables with dbDelta().
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$t       = Plugin::tables();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$t['sessions']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  visitor_hash char(16) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  duration int(10) unsigned NOT NULL DEFAULT 0,
  pageviews smallint(5) unsigned NOT NULL DEFAULT 0,
  events smallint(5) unsigned NOT NULL DEFAULT 0,
  is_new tinyint(1) unsigned NOT NULL DEFAULT 1,
  entry_page_id int(10) unsigned NOT NULL DEFAULT 0,
  exit_page_id int(10) unsigned NOT NULL DEFAULT 0,
  channel varchar(20) NOT NULL DEFAULT '',
  source varchar(100) NOT NULL DEFAULT '',
  referrer_host varchar(191) NOT NULL DEFAULT '',
  referrer_url varchar(255) NOT NULL DEFAULT '',
  utm_source varchar(100) NOT NULL DEFAULT '',
  utm_medium varchar(100) NOT NULL DEFAULT '',
  utm_campaign varchar(150) NOT NULL DEFAULT '',
  utm_term varchar(150) NOT NULL DEFAULT '',
  utm_content varchar(150) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  region varchar(100) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  latitude decimal(8,5) DEFAULT NULL,
  longitude decimal(8,5) DEFAULT NULL,
  device varchar(10) NOT NULL DEFAULT '',
  browser varchar(40) NOT NULL DEFAULT '',
  browser_version varchar(20) NOT NULL DEFAULT '',
  os varchar(40) NOT NULL DEFAULT '',
  os_version varchar(20) NOT NULL DEFAULT '',
  screen varchar(11) NOT NULL DEFAULT '',
  language varchar(12) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ip varchar(45) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY updated_at (updated_at),
  KEY visitor (visitor_hash,updated_at)
) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$t['pageviews']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page_id int(10) unsigned NOT NULL DEFAULT 0,
  prev_page_id int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  duration int(10) unsigned NOT NULL DEFAULT 0,
  scroll_depth tinyint(3) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY session_id (session_id),
  KEY page_id (page_id,created_at),
  KEY prev_page_id (prev_page_id,created_at)
) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$t['pages']} (
  id int(10) unsigned NOT NULL AUTO_INCREMENT,
  hash char(32) NOT NULL DEFAULT '',
  path varchar(1000) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL DEFAULT '',
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  post_type varchar(32) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY hash (hash),
  KEY post_id (post_id)
) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$t['events']} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page_id int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  category varchar(32) NOT NULL DEFAULT '',
  name varchar(191) NOT NULL DEFAULT '',
  label varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY session_id (session_id),
  KEY category (category,created_at)
) {$collate};"
		);
	}

	/**
	 * Drops all custom tables (used by uninstall and the "delete all data" tool).
	 */
	public static function drop_tables() {
		global $wpdb;
		foreach ( Plugin::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Empties all tables but keeps them in place.
	 */
	public static function truncate_tables() {
		global $wpdb;
		foreach ( Plugin::tables() as $table ) {
			$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		Cache::flush();
	}
}
