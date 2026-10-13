<?php
/**
 * Shared helper functions.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utilities: time, IP addresses, URLs, text.
 */
class Helpers {

	/**
	 * Query parameters that never become part of a page path.
	 */
	const TRACKING_PARAMS = array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid', 'gbraid', 'wbraid', 'dclid', 'msclkid', 'fbclid', 'ttclid', 'twclid', 'li_fat_id', 'yclid', 'epik', 'mc_cid', 'mc_eid', '_ga', '_gl', 'ref', 'source' );

	/**
	 * Click identifiers recognised for paid traffic.
	 */
	const CLICK_IDS = array( 'gclid', 'gbraid', 'wbraid', 'dclid', 'msclkid', 'fbclid', 'ttclid', 'twclid', 'li_fat_id', 'yclid', 'epik' );

	/* ------------------------------------------------------------------ *
	 * Time (all stored dates use the site timezone).
	 * ------------------------------------------------------------------ */

	/**
	 * Local "Y-m-d H:i:s", optionally shifted by seconds.
	 *
	 * @param int $offset Seconds to add (negative for the past).
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		$date = new \DateTime( 'now', wp_timezone() );
		if ( $offset ) {
			$date->modify( ( $offset > 0 ? '+' : '' ) . (int) $offset . ' seconds' );
		}
		return $date->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Local date "Y-m-d", optionally shifted by days.
	 *
	 * @param int $days Days to add.
	 * @return string
	 */
	public static function today( $days = 0 ) {
		$date = new \DateTime( 'now', wp_timezone() );
		if ( $days ) {
			$date->modify( ( $days > 0 ? '+' : '' ) . (int) $days . ' days' );
		}
		return $date->format( 'Y-m-d' );
	}

	/**
	 * Adds days to a Y-m-d date string.
	 *
	 * @param string $date Date.
	 * @param int    $days Days (may be negative).
	 * @return string
	 */
	public static function add_days( $date, $days ) {
		$d = \DateTime::createFromFormat( '!Y-m-d', $date, new \DateTimeZone( 'UTC' ) );
		if ( ! $d ) {
			return $date;
		}
		$d->modify( ( $days >= 0 ? '+' : '' ) . (int) $days . ' days' );
		return $d->format( 'Y-m-d' );
	}

	/* ------------------------------------------------------------------ *
	 * Secrets & hashing.
	 * ------------------------------------------------------------------ */

	/**
	 * Site-specific secret used for signatures and persistent visitor hashes.
	 *
	 * @return string
	 */
	public static function secret() {
		$secret = get_option( 'pulse_analytics_secret' );
		if ( ! $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'pulse_analytics_secret', $secret, true );
		}
		return (string) $secret;
	}

	/**
	 * Random salt that rotates every day. Old salts are discarded, so cookieless
	 * visitor hashes cannot be linked across days or reversed later.
	 *
	 * @return string
	 */
	public static function daily_salt() {
		$today = self::today();
		$salt  = get_option( 'pulse_analytics_salt' );
		if ( ! is_array( $salt ) || empty( $salt['date'] ) || $salt['date'] !== $today || empty( $salt['salt'] ) ) {
			$salt = array(
				'date' => $today,
				'salt' => wp_generate_password( 64, true, true ),
			);
			update_option( 'pulse_analytics_salt', $salt, true );
		}
		return $salt['salt'];
	}

	/* ------------------------------------------------------------------ *
	 * IP addresses.
	 * ------------------------------------------------------------------ */

	/**
	 * Visitor IP address according to the configured detection method.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$method  = Settings::get( 'ip_header', 'auto' );
		$headers = 'auto' === $method
			? array( 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' )
			: array( $method, 'REMOTE_ADDR' );

		$fallback = '';
		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}
			$raw = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
			foreach ( explode( ',', $raw ) as $candidate ) {
				$candidate = trim( $candidate );
				// Strip a port from "1.2.3.4:5678".
				if ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $candidate, $m ) ) {
					$candidate = $m[1];
				}
				$candidate = trim( $candidate, '[]' );
				if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					continue;
				}
				if ( self::is_public_ip( $candidate ) ) {
					return $candidate;
				}
				if ( '' === $fallback ) {
					$fallback = $candidate;
				}
			}
		}
		return $fallback;
	}

	/**
	 * Whether an IP is a routable public address.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Masks the host part of an IP (IPv4 /24, IPv6 /48).
	 *
	 * @param string $ip IP address.
	 * @return string
	 */
	public static function anonymize_ip( $ip ) {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return '';
		}
		return (string) inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) );
	}

	/**
	 * Matches an IP against a list of addresses, CIDR ranges and wildcards.
	 *
	 * @param string       $ip   IP address.
	 * @param string|array $list Newline/comma separated list.
	 * @return bool
	 */
	public static function ip_in_list( $ip, $list ) {
		if ( '' === $ip ) {
			return false;
		}
		foreach ( is_array( $list ) ? $list : self::lines( (string) $list ) as $rule ) {
			if ( $rule === $ip ) {
				return true;
			}
			if ( false !== strpos( $rule, '*' ) ) {
				$regex = '/^' . str_replace( '\*', '[0-9a-f:.]*', preg_quote( $rule, '/' ) ) . '$/i';
				if ( preg_match( $regex, $ip ) ) {
					return true;
				}
				continue;
			}
			if ( false !== strpos( $rule, '/' ) && self::ip_in_cidr( $ip, $rule ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * CIDR match for IPv4 and IPv6.
	 *
	 * @param string $ip   IP address.
	 * @param string $cidr Range such as 10.0.0.0/8.
	 * @return bool
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		list( $subnet, $bits ) = array_pad( explode( '/', $cidr, 2 ), 2, '' );
		$ip_bin                = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$subnet_bin            = @inet_pton( trim( $subnet ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		$remainder = $bits % 8;
		if ( $remainder ) {
			$mask = chr( ( 0xff << ( 8 - $remainder ) ) & 0xff );
			return ( $ip_bin[ $bytes ] & $mask ) === ( $subnet_bin[ $bytes ] & $mask );
		}
		return true;
	}

	/* ------------------------------------------------------------------ *
	 * Hosts & URLs.
	 * ------------------------------------------------------------------ */

	/**
	 * Lower-cases a host and strips a leading "www.".
	 *
	 * @param string $host Host name.
	 * @return string
	 */
	public static function normalize_host( $host ) {
		$host = strtolower( trim( (string) $host, ". \t\n\r\0\x0B" ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Hosts considered "this website" (for tracking and internal referrers).
	 *
	 * @return string[]
	 */
	public static function site_hosts() {
		static $hosts = null;
		if ( null === $hosts ) {
			$hosts = array(
				self::normalize_host( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				self::normalize_host( (string) wp_parse_url( site_url(), PHP_URL_HOST ) ),
			);
			foreach ( self::lines( (string) Settings::get( 'allowed_hosts', '' ) ) as $extra ) {
				$host    = wp_parse_url( false === strpos( $extra, '//' ) ? 'http://' . $extra : $extra, PHP_URL_HOST );
				$hosts[] = self::normalize_host( (string) $host );
			}
			$hosts = array_values( array_unique( array_filter( $hosts ) ) );
		}
		return $hosts;
	}

	/**
	 * Scheme + host (+ port) of the website, e.g. "https://example.com".
	 * Page paths are stored as full request paths, so links are built from the origin.
	 *
	 * @return string
	 */
	public static function site_origin() {
		$home = wp_parse_url( home_url() );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return '';
		}
		return ( isset( $home['scheme'] ) ? $home['scheme'] : 'https' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
	}

	/**
	 * Splits a page URL sent by the tracker into a normalized path and its query parameters.
	 * Returns null when the URL does not belong to this website.
	 *
	 * @param string $url Full page URL.
	 * @return array{path:string,params:array}|null
	 */
	public static function parse_page_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( ! in_array( self::normalize_host( $parts['host'] ), self::site_hosts(), true ) ) {
			return null;
		}

		$params = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $params );
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$path = '/' . ltrim( preg_replace( '#/{2,}#', '/', $path ), '/' );

		// Keep only whitelisted query parameters in the page path.
		$keep = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'keep_query_params', '' ) ) ) );
		$kept = array();
		foreach ( $keep as $name ) {
			if ( isset( $params[ $name ] ) && is_scalar( $params[ $name ] ) && '' !== (string) $params[ $name ] && ! in_array( $name, self::TRACKING_PARAMS, true ) ) {
				$kept[ $name ] = (string) $params[ $name ];
			}
		}
		if ( $kept ) {
			ksort( $kept );
			$path .= '?' . http_build_query( $kept );
		}

		return array(
			'path'   => self::truncate( $path, 1000 ),
			'params' => $params,
		);
	}

	/**
	 * Whether a path matches the "exclude paths" setting (supports * wildcards).
	 *
	 * @param string $path Page path.
	 * @return bool
	 */
	public static function path_excluded( $path ) {
		foreach ( self::lines( (string) Settings::get( 'exclude_paths', '' ) ) as $rule ) {
			$regex = '#^' . str_replace( '\*', '.*', preg_quote( $rule, '#' ) ) . '$#i';
			if ( preg_match( $regex, $path ) || ( false === strpos( $rule, '*' ) && 0 === stripos( $path, $rule ) ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ *
	 * Users & capabilities.
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the given user's role is excluded from tracking.
	 *
	 * @param \WP_User $user User.
	 * @return bool
	 */
	public static function user_is_excluded( $user ) {
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return false;
		}
		$excluded = (array) Settings::get( 'exclude_roles', array() );
		if ( is_multisite() && is_super_admin( $user->ID ) && in_array( 'administrator', $excluded, true ) ) {
			return true;
		}
		return (bool) array_intersect( $excluded, (array) $user->roles );
	}

	/**
	 * Whether the current user may view statistics.
	 *
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( Plugin::CAP_VIEW );
	}

	/* ------------------------------------------------------------------ *
	 * Text.
	 * ------------------------------------------------------------------ */

	/**
	 * Splits text on new lines and commas, trimming empty entries.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$items = preg_split( '/[\r\n,]+/', (string) $text );
		return array_values( array_filter( array_map( 'trim', (array) $items ), 'strlen' ) );
	}

	/**
	 * Multibyte-safe truncate.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max characters.
	 * @return string
	 */
	public static function truncate( $text, $length ) {
		$text = (string) $text;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, $length, 'UTF-8' );
		}
		return substr( $text, 0, $length );
	}

	/**
	 * Cleans untrusted free text coming from the tracker.
	 *
	 * @param mixed $value  Raw value.
	 * @param int   $length Max length.
	 * @return string
	 */
	public static function clean_text( $value, $length = 191 ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = wp_check_invalid_utf8( (string) $value );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value );
		return self::truncate( trim( preg_replace( '/\s+/u', ' ', (string) $value ) ), $length );
	}

	/* ------------------------------------------------------------------ *
	 * Storage.
	 * ------------------------------------------------------------------ */

	/**
	 * Private data directory in uploads (geolocation databases, cached map data).
	 *
	 * @param bool $create Create it when missing.
	 * @return string Absolute path without trailing slash, or '' on failure.
	 */
	public static function data_dir( $create = false ) {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$dir = trailingslashit( $uploads['basedir'] ) . 'pulse-analytics';
		if ( $create && ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return '';
			}
		}
		if ( $create && is_dir( $dir ) ) {
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		return is_dir( $dir ) ? $dir : '';
	}
}
