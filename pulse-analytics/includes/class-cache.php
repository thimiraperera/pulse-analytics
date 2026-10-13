<?php
/**
 * Small transient-based cache for report results.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Versioned cache: flushing bumps the version so stale entries are simply never read again.
 */
class Cache {

	const VERSION_OPTION = 'pulse_analytics_cache_v';

	/**
	 * Returns a cached value or computes and stores it.
	 *
	 * @param string   $key      Cache key (any string).
	 * @param int      $ttl      Lifetime in seconds. 0 disables caching.
	 * @param callable $callback Producer.
	 * @return mixed
	 */
	public static function remember( $key, $ttl, $callback ) {
		if ( $ttl <= 0 ) {
			return call_user_func( $callback );
		}
		$storage_key = self::storage_key( $key );
		$value       = get_transient( $storage_key );
		if ( false !== $value ) {
			return $value;
		}
		$value = call_user_func( $callback );
		set_transient( $storage_key, $value, $ttl );
		return $value;
	}

	/**
	 * Invalidates every cached report.
	 */
	public static function flush() {
		update_option( self::VERSION_OPTION, (string) microtime( true ), false );
	}

	/**
	 * Builds the transient name.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private static function storage_key( $key ) {
		$version = get_option( self::VERSION_OPTION, '1' );
		return 'pulse_' . substr( md5( $version . '|' . $key ), 0, 24 );
	}
}
