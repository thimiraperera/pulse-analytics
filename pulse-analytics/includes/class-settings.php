<?php
/**
 * Settings storage.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, writes and sanitizes plugin settings (stored as one array option).
 */
class Settings {

	const OPTION = 'pulse_analytics_settings';

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Tracking.
			'tracking_enabled'    => 1,
			'tracking_mode'       => 'cookieless',
			'exclude_roles'       => array( 'administrator' ),
			'store_user_id'       => 0,
			'respect_dnt'         => 1,
			'require_consent'     => 0,
			'exclude_ips'         => '',
			'exclude_paths'       => '',
			'track_outbound'      => 1,
			'track_downloads'     => 1,
			'download_extensions' => 'pdf,zip,rar,7z,gz,tar,doc,docx,xls,xlsx,csv,ppt,pptx,txt,rtf,odt,ods,epub,mp3,mp4,mov,avi,wav,dmg,exe,msi,apk,iso',
			'track_forms'         => 1,
			'track_links'         => 1,
			'track_scroll'        => 1,
			'track_search'        => 1,
			'track_404'           => 1,
			'track_spa'           => 0,
			'keep_query_params'   => 'p,page_id,cat,m',
			'session_timeout'     => 30,
			'allowed_hosts'       => '',
			'ip_header'           => 'auto',
			// Geolocation.
			'geoip_headers'       => 1,
			'geoip_source'        => 'dbip_country',
			'maxmind_key'         => '',
			'geoip_custom_path'   => '',
			'geoip_auto_update'   => 1,
			// Privacy & data.
			'store_ip'            => 'none',
			'retention_months'    => 24,
			'delete_on_uninstall' => 0,
			'blocked_referrers'   => '',
			// Display & access.
			'view_roles'          => array(),
			'dashboard_widget'    => 1,
			'admin_bar'           => 1,
			'post_columns'        => 1,
			'post_metabox'        => 1,
			'external_assets'     => 1,
			// Email reports.
			'email_enabled'       => 0,
			'email_frequency'     => 'weekly',
			'email_recipients'    => '',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Merges and saves values.
	 *
	 * @param array $values New values (already sanitized).
	 */
	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all );
		self::$cache = $all;
	}

	/**
	 * Clears the request cache (after external updates).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitizes a submitted settings array. Only keys that were submitted are returned,
	 * so each settings tab can be saved independently.
	 *
	 * @param array $input Raw input (already unslashed).
	 * @return array
	 */
	public static function sanitize( array $input ) {
		$defaults = self::defaults();
		$out      = array();

		$booleans = array( 'tracking_enabled', 'store_user_id', 'respect_dnt', 'require_consent', 'track_outbound', 'track_downloads', 'track_forms', 'track_links', 'track_scroll', 'track_search', 'track_404', 'track_spa', 'geoip_headers', 'geoip_auto_update', 'delete_on_uninstall', 'dashboard_widget', 'admin_bar', 'post_columns', 'post_metabox', 'external_assets', 'email_enabled' );
		foreach ( $booleans as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			}
		}

		$choices = array(
			'tracking_mode'   => array( 'cookieless', 'cookie' ),
			'ip_header'       => array( 'auto', 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP' ),
			'geoip_source'    => array( 'none', 'dbip_country', 'dbip_city', 'maxmind_country', 'maxmind_city', 'custom' ),
			'store_ip'        => array( 'none', 'anonymized', 'full' ),
			'email_frequency' => array( 'weekly', 'monthly' ),
		);
		foreach ( $choices as $key => $allowed ) {
			if ( isset( $input[ $key ] ) ) {
				$value       = (string) $input[ $key ];
				$out[ $key ] = in_array( $value, $allowed, true ) ? $value : $defaults[ $key ];
			}
		}

		$textareas = array( 'exclude_ips', 'exclude_paths', 'allowed_hosts', 'blocked_referrers' );
		foreach ( $textareas as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = implode( "\n", Helpers::lines( sanitize_textarea_field( (string) $input[ $key ] ) ) );
			}
		}

		if ( isset( $input['download_extensions'] ) ) {
			$ext                        = array_filter( array_map( 'sanitize_key', Helpers::lines( (string) $input['download_extensions'] ) ) );
			$out['download_extensions'] = implode( ',', array_unique( $ext ) );
		}
		if ( isset( $input['keep_query_params'] ) ) {
			$params                   = array_filter( array_map( array( __CLASS__, 'sanitize_param' ), Helpers::lines( (string) $input['keep_query_params'] ) ) );
			$out['keep_query_params'] = implode( ',', array_unique( $params ) );
		}
		if ( isset( $input['session_timeout'] ) ) {
			$out['session_timeout'] = min( 240, max( 5, absint( $input['session_timeout'] ) ) );
		}
		if ( isset( $input['retention_months'] ) ) {
			$out['retention_months'] = min( 240, absint( $input['retention_months'] ) );
		}
		if ( isset( $input['maxmind_key'] ) ) {
			$out['maxmind_key'] = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $input['maxmind_key'] );
		}
		if ( isset( $input['geoip_custom_path'] ) ) {
			$out['geoip_custom_path'] = trim( sanitize_text_field( (string) $input['geoip_custom_path'] ) );
		}
		if ( isset( $input['email_recipients'] ) ) {
			$emails                  = array_filter( array_map( 'sanitize_email', Helpers::lines( (string) $input['email_recipients'] ) ), 'is_email' );
			$out['email_recipients'] = implode( ', ', array_unique( $emails ) );
		}

		$roles = array_keys( wp_roles()->get_names() );
		foreach ( array( 'exclude_roles', 'view_roles' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = array_values( array_intersect( array_map( 'sanitize_key', (array) $input[ $key ] ), $roles ) );
			}
		}

		return $out;
	}

	/**
	 * Keeps query parameter names safe.
	 *
	 * @param string $param Name.
	 * @return string
	 */
	public static function sanitize_param( $param ) {
		return preg_replace( '/[^A-Za-z0-9_\-\[\]]/', '', (string) $param );
	}
}
