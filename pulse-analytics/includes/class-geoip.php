<?php
/**
 * IP geolocation: CDN headers and local MaxMind-format databases.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves country / region / city for an IP address.
 */
class GeoIP {

	const STATUS_OPTION = 'pulse_analytics_geoip';

	/**
	 * Lazily opened reader (false = not attempted yet).
	 *
	 * @var MMDB_Reader|null|false
	 */
	private static $reader = false;

	/**
	 * Selectable database sources.
	 *
	 * @return array<string,string>
	 */
	public static function sources() {
		return array(
			'dbip_country'    => __( 'DB-IP Lite Country (free, about 4 MB, recommended)', 'pulse-analytics' ),
			'dbip_city'       => __( 'DB-IP Lite City (free, about 60 MB download and 130 MB on disk, adds regions, cities and map bubbles)', 'pulse-analytics' ),
			'maxmind_country' => __( 'MaxMind GeoLite2 Country (needs a free license key)', 'pulse-analytics' ),
			'maxmind_city'    => __( 'MaxMind GeoLite2 City (needs a free license key)', 'pulse-analytics' ),
			'custom'          => __( 'Custom .mmdb file on this server', 'pulse-analytics' ),
			'none'            => __( 'No database (CDN headers only)', 'pulse-analytics' ),
		);
	}

	/**
	 * Whether a source can be downloaded automatically.
	 *
	 * @param string $source Source key.
	 * @return bool
	 */
	public static function is_downloadable_source( $source ) {
		return in_array( $source, array( 'dbip_country', 'dbip_city', 'maxmind_country', 'maxmind_city' ), true );
	}

	/**
	 * Geolocates an IP address.
	 *
	 * @param string $ip IP address.
	 * @return array{country:string,region:string,city:string,latitude:float|null,longitude:float|null}
	 */
	public static function lookup( $ip ) {
		$geo = array(
			'country'   => '',
			'region'    => '',
			'city'      => '',
			'latitude'  => null,
			'longitude' => null,
		);

		if ( Settings::get( 'geoip_headers' ) ) {
			$geo = self::from_headers( $geo );
		}

		if ( ( '' === $geo['country'] || '' === $geo['city'] ) && Helpers::is_public_ip( $ip ) ) {
			$reader = self::reader();
			if ( $reader ) {
				try {
					$record = $reader->get( $ip );
				} catch ( \Exception $e ) {
					$record = null;
				}
				if ( is_array( $record ) ) {
					$country = '';
					if ( ! empty( $record['country']['iso_code'] ) ) {
						$country = $record['country']['iso_code'];
					} elseif ( ! empty( $record['registered_country']['iso_code'] ) ) {
						$country = $record['registered_country']['iso_code'];
					}
					$country = strtoupper( (string) $country );
					if ( '' === $geo['country'] ) {
						$geo['country'] = $country;
					}
					// Only take city details when they agree with the country already known.
					if ( $geo['country'] === $country ) {
						if ( '' === $geo['region'] && ! empty( $record['subdivisions'][0]['names'] ) ) {
							$geo['region'] = self::localized_name( $record['subdivisions'][0]['names'] );
						}
						if ( '' === $geo['city'] && ! empty( $record['city']['names'] ) ) {
							$geo['city'] = self::localized_name( $record['city']['names'] );
						}
						if ( null === $geo['latitude'] && isset( $record['location']['latitude'], $record['location']['longitude'] ) ) {
							$geo['latitude']  = (float) $record['location']['latitude'];
							$geo['longitude'] = (float) $record['location']['longitude'];
						}
					}
				}
			}
		}

		/**
		 * Filters the geolocation result for a visitor.
		 *
		 * @param array  $geo Location data.
		 * @param string $ip  Visitor IP address.
		 */
		$geo = (array) apply_filters( 'pulse_analytics_geolocation', $geo, $ip );

		$country = strtoupper( isset( $geo['country'] ) ? (string) $geo['country'] : '' );
		return array(
			'country'   => preg_match( '/^([A-Z]{2}|T1)$/', $country ) && 'XX' !== $country ? $country : '',
			'region'    => Helpers::clean_text( isset( $geo['region'] ) ? $geo['region'] : '', 100 ),
			'city'      => Helpers::clean_text( isset( $geo['city'] ) ? $geo['city'] : '', 100 ),
			'latitude'  => isset( $geo['latitude'] ) && is_numeric( $geo['latitude'] ) && abs( $geo['latitude'] ) <= 90 ? round( (float) $geo['latitude'], 4 ) : null,
			'longitude' => isset( $geo['longitude'] ) && is_numeric( $geo['longitude'] ) && abs( $geo['longitude'] ) <= 180 ? round( (float) $geo['longitude'], 4 ) : null,
		);
	}

	/**
	 * Reads location headers added by CDNs and hosting platforms (Cloudflare, CloudFront, Vercel, App Engine, mod_geoip ...).
	 *
	 * @param array $geo Current result.
	 * @return array
	 */
	private static function from_headers( array $geo ) {
		$first = static function ( array $keys ) {
			foreach ( $keys as $key ) {
				if ( isset( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
					return trim( rawurldecode( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) );
				}
			}
			return '';
		};

		$country = strtoupper( $first( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_VERCEL_IP_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'HTTP_X_GEOIP_COUNTRY', 'HTTP_GEOIP_COUNTRY_CODE', 'GEOIP_COUNTRY_CODE', 'HTTP_X_APPENGINE_COUNTRY', 'MM_COUNTRY_CODE' ) ) );
		if ( preg_match( '/^([A-Z]{2}|T1)$/', $country ) && 'XX' !== $country ) {
			$geo['country'] = $country;
			$geo['region']  = $first( array( 'HTTP_CF_REGION', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY_REGION_NAME', 'HTTP_X_APPENGINE_REGION', 'GEOIP_REGION_NAME', 'MM_REGION_NAME' ) );
			$geo['city']    = $first( array( 'HTTP_CF_IPCITY', 'HTTP_CLOUDFRONT_VIEWER_CITY', 'HTTP_X_VERCEL_IP_CITY', 'HTTP_X_APPENGINE_CITY', 'GEOIP_CITY', 'MM_CITY_NAME' ) );
			$lat            = $first( array( 'HTTP_CF_IPLATITUDE', 'HTTP_CLOUDFRONT_VIEWER_LATITUDE', 'HTTP_X_VERCEL_IP_LATITUDE', 'GEOIP_LATITUDE' ) );
			$lon            = $first( array( 'HTTP_CF_IPLONGITUDE', 'HTTP_CLOUDFRONT_VIEWER_LONGITUDE', 'HTTP_X_VERCEL_IP_LONGITUDE', 'GEOIP_LONGITUDE' ) );
			$latlong        = $first( array( 'HTTP_X_APPENGINE_CITYLATLONG' ) );
			if ( '' === $lat && '' !== $latlong && false !== strpos( $latlong, ',' ) ) {
				list( $lat, $lon ) = explode( ',', $latlong, 2 );
			}
			if ( is_numeric( $lat ) && is_numeric( $lon ) && '' !== $geo['city'] ) {
				$geo['latitude']  = (float) $lat;
				$geo['longitude'] = (float) $lon;
			}
		}
		return $geo;
	}

	/**
	 * Picks the English (or first) name from a names map.
	 *
	 * @param array $names Names keyed by language.
	 * @return string
	 */
	private static function localized_name( array $names ) {
		if ( isset( $names['en'] ) ) {
			return (string) $names['en'];
		}
		$first = reset( $names );
		return is_string( $first ) ? $first : '';
	}

	/**
	 * Opens the configured database.
	 *
	 * @return MMDB_Reader|null
	 */
	public static function reader() {
		if ( false === self::$reader ) {
			self::$reader = null;
			$path         = self::database_path();
			if ( '' !== $path && is_readable( $path ) ) {
				try {
					self::$reader = new MMDB_Reader( $path );
				} catch ( \Exception $e ) {
					self::$reader = null;
				}
			}
		}
		return self::$reader;
	}

	/**
	 * Path of the active database file.
	 *
	 * @param string|null $source Source (defaults to the setting).
	 * @return string
	 */
	public static function database_path( $source = null ) {
		$source = null === $source ? Settings::get( 'geoip_source' ) : $source;
		if ( 'none' === $source ) {
			return '';
		}
		if ( 'custom' === $source ) {
			return (string) Settings::get( 'geoip_custom_path', '' );
		}
		$dir = Helpers::data_dir();
		return '' === $dir ? '' : $dir . '/' . self::file_name( $source );
	}

	/**
	 * Local file name per source.
	 *
	 * @param string $source Source key.
	 * @return string
	 */
	private static function file_name( $source ) {
		$names = array(
			'dbip_country'    => 'dbip-country-lite.mmdb',
			'dbip_city'       => 'dbip-city-lite.mmdb',
			'maxmind_country' => 'GeoLite2-Country.mmdb',
			'maxmind_city'    => 'GeoLite2-City.mmdb',
		);
		return isset( $names[ $source ] ) ? $names[ $source ] : 'geoip.mmdb';
	}

	/**
	 * Whether the configured database file exists.
	 *
	 * @return bool
	 */
	public static function database_exists() {
		$path = self::database_path();
		return '' !== $path && is_readable( $path );
	}

	/**
	 * Whether the active database contains city-level data.
	 *
	 * @return bool
	 */
	public static function has_city_data() {
		$reader = self::reader();
		if ( ! $reader ) {
			return false;
		}
		$meta = $reader->metadata();
		return isset( $meta['database_type'] ) && false !== stripos( (string) $meta['database_type'], 'city' );
	}

	/**
	 * Information about the active database for the settings screen.
	 *
	 * @return array
	 */
	public static function status() {
		$path   = self::database_path();
		$status = get_option( self::STATUS_OPTION, array() );
		$info   = array(
			'source'   => Settings::get( 'geoip_source' ),
			'path'     => $path,
			'exists'   => '' !== $path && is_readable( $path ),
			'size'     => 0,
			'modified' => 0,
			'type'     => '',
			'built'    => 0,
			'error'    => is_array( $status ) && ! empty( $status['error'] ) ? (string) $status['error'] : '',
			'checked'  => is_array( $status ) && ! empty( $status['checked'] ) ? (int) $status['checked'] : 0,
		);
		if ( $info['exists'] ) {
			$info['size']     = (int) filesize( $path );
			$info['modified'] = (int) filemtime( $path );
			$reader           = self::reader();
			if ( $reader ) {
				$meta          = $reader->metadata();
				$info['type']  = isset( $meta['database_type'] ) ? (string) $meta['database_type'] : '';
				$info['built'] = isset( $meta['build_epoch'] ) ? (int) $meta['build_epoch'] : 0;
			}
		}
		return $info;
	}

	/**
	 * Downloads (or refreshes) a database.
	 *
	 * @param string|null $source Source key (defaults to the setting).
	 * @return true|\WP_Error
	 */
	public static function download( $source = null ) {
		$source = null === $source ? Settings::get( 'geoip_source' ) : $source;
		if ( ! self::is_downloadable_source( $source ) ) {
			return new \WP_Error( 'pulse_geoip_source', __( 'The selected geolocation source cannot be downloaded automatically.', 'pulse-analytics' ) );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'gzopen' ) ) {
			return self::fail( new \WP_Error( 'pulse_geoip_zlib', __( 'The PHP zlib extension is required to unpack the database.', 'pulse-analytics' ) ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$dir = Helpers::data_dir( true );
		if ( '' === $dir ) {
			return self::fail( new \WP_Error( 'pulse_geoip_dir', __( 'Could not create the data folder in wp-content/uploads.', 'pulse-analytics' ) ) );
		}
		$target = $dir . '/' . self::file_name( $source );
		$temp   = $target . '.download';

		if ( 0 === strpos( $source, 'dbip_' ) ) {
			$type   = 'dbip_city' === $source ? 'city' : 'country';
			$months = array( gmdate( 'Y-m' ), gmdate( 'Y-m', strtotime( 'first day of last month' ) ) );
			$file   = null;
			$error  = null;
			foreach ( $months as $month ) {
				$url  = sprintf( 'https://download.db-ip.com/free/dbip-%s-lite-%s.mmdb.gz', $type, $month );
				$file = download_url( $url, 900 );
				if ( ! is_wp_error( $file ) ) {
					break;
				}
				$error = $file;
				$file  = null;
			}
			if ( null === $file ) {
				return self::fail( $error ? $error : new \WP_Error( 'pulse_geoip_download', __( 'Download failed.', 'pulse-analytics' ) ) );
			}
			$result = self::gunzip( $file, $temp );
		} else {
			$key = trim( (string) Settings::get( 'maxmind_key', '' ) );
			if ( '' === $key ) {
				return self::fail( new \WP_Error( 'pulse_geoip_key', __( 'Enter your MaxMind license key first.', 'pulse-analytics' ) ) );
			}
			$url  = add_query_arg(
				array(
					'edition_id'  => 'maxmind_city' === $source ? 'GeoLite2-City' : 'GeoLite2-Country',
					'license_key' => $key,
					'suffix'      => 'tar.gz',
				),
				'https://download.maxmind.com/app/geoip_download'
			);
			$file = download_url( $url, 900 );
			if ( is_wp_error( $file ) ) {
				return self::fail( new \WP_Error( 'pulse_geoip_download', __( 'MaxMind download failed. Check your license key.', 'pulse-analytics' ) . ' (' . $file->get_error_message() . ')' ) );
			}
			$result = self::extract_from_tar_gz( $file, $temp );
		}
		wp_delete_file( $file );

		if ( is_wp_error( $result ) ) {
			wp_delete_file( $temp );
			return self::fail( $result );
		}

		try {
			$test = new MMDB_Reader( $temp );
			$test->close();
		} catch ( \Exception $e ) {
			wp_delete_file( $temp );
			return self::fail( new \WP_Error( 'pulse_geoip_invalid', __( 'The downloaded file is not a valid geolocation database.', 'pulse-analytics' ) ) );
		}

		if ( self::$reader instanceof MMDB_Reader ) {
			self::$reader->close();
		}
		self::$reader = false;

		if ( file_exists( $target ) ) {
			wp_delete_file( $target );
		}
		if ( ! @rename( $temp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! @copy( $temp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return self::fail( new \WP_Error( 'pulse_geoip_move', __( 'Could not move the database into place.', 'pulse-analytics' ) ) );
			}
			wp_delete_file( $temp );
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'source'  => $source,
				'updated' => time(),
				'checked' => time(),
				'error'   => '',
			),
			false
		);
		return true;
	}

	/**
	 * Refreshes the database when it is missing or outdated (run by cron).
	 */
	public static function maybe_update() {
		$source = Settings::get( 'geoip_source' );
		if ( ! self::is_downloadable_source( $source ) ) {
			return;
		}
		$path = self::database_path();
		if ( '' !== $path && is_readable( $path ) ) {
			if ( ! Settings::get( 'geoip_auto_update' ) ) {
				return;
			}
			$age = time() - (int) filemtime( $path );
			// DB-IP publishes monthly; MaxMind twice a week. Refresh after ~32 / 7 days.
			$max = 0 === strpos( $source, 'dbip_' ) ? 32 * DAY_IN_SECONDS : 7 * DAY_IN_SECONDS;
			if ( $age < $max ) {
				return;
			}
		}
		$status = get_option( self::STATUS_OPTION, array() );
		// After a failure, wait 12 hours before retrying.
		if ( is_array( $status ) && ! empty( $status['error'] ) && ! empty( $status['checked'] ) && time() - (int) $status['checked'] < 12 * HOUR_IN_SECONDS ) {
			return;
		}
		self::download( $source );
	}

	/**
	 * Records a failure and returns the error.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_Error
	 */
	private static function fail( \WP_Error $error ) {
		$status            = get_option( self::STATUS_OPTION, array() );
		$status            = is_array( $status ) ? $status : array();
		$status['error']   = $error->get_error_message();
		$status['checked'] = time();
		update_option( self::STATUS_OPTION, $status, false );
		return $error;
	}

	/**
	 * Streams a .gz file to disk.
	 *
	 * @param string $source      Compressed file.
	 * @param string $destination Output file.
	 * @return true|\WP_Error
	 */
	private static function gunzip( $source, $destination ) {
		$in = gzopen( $source, 'rb' );
		if ( ! $in ) {
			return new \WP_Error( 'pulse_geoip_gzip', __( 'Could not open the downloaded archive.', 'pulse-analytics' ) );
		}
		$out = fopen( $destination, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $out ) {
			gzclose( $in );
			return new \WP_Error( 'pulse_geoip_write', __( 'Could not write the database file.', 'pulse-analytics' ) );
		}
		while ( ! gzeof( $in ) ) {
			$chunk = gzread( $in, 1048576 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		}
		gzclose( $in );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return filesize( $destination ) > 1024 ? true : new \WP_Error( 'pulse_geoip_empty', __( 'The downloaded archive was empty.', 'pulse-analytics' ) );
	}

	/**
	 * Extracts the first .mmdb file from a .tar.gz archive (as shipped by MaxMind).
	 *
	 * @param string $source      Archive path.
	 * @param string $destination Output file.
	 * @return true|\WP_Error
	 */
	private static function extract_from_tar_gz( $source, $destination ) {
		$in = gzopen( $source, 'rb' );
		if ( ! $in ) {
			return new \WP_Error( 'pulse_geoip_gzip', __( 'Could not open the downloaded archive.', 'pulse-analytics' ) );
		}
		while ( ! gzeof( $in ) ) {
			$header = gzread( $in, 512 );
			if ( false === $header || strlen( $header ) < 512 || '' === trim( $header, "\0" ) ) {
				break;
			}
			$name   = rtrim( substr( $header, 0, 100 ), "\0" );
			$prefix = rtrim( substr( $header, 345, 155 ), "\0" );
			if ( '' !== $prefix && 'ustar' === substr( $header, 257, 5 ) ) {
				$name = $prefix . '/' . $name;
			}
			$size   = (int) octdec( trim( substr( $header, 124, 12 ), "\0 " ) );
			$type   = substr( $header, 156, 1 );
			$padded = (int) ( ceil( $size / 512 ) * 512 );

			if ( ( '0' === $type || "\0" === $type ) && '.mmdb' === substr( $name, -5 ) ) {
				$out = fopen( $destination, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( ! $out ) {
					gzclose( $in );
					return new \WP_Error( 'pulse_geoip_write', __( 'Could not write the database file.', 'pulse-analytics' ) );
				}
				$remaining = $size;
				while ( $remaining > 0 ) {
					$chunk = gzread( $in, (int) min( 1048576, $remaining ) );
					if ( false === $chunk || '' === $chunk ) {
						break;
					}
					fwrite( $out, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					$remaining -= strlen( $chunk );
				}
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				gzclose( $in );
				return 0 === $remaining ? true : new \WP_Error( 'pulse_geoip_tar', __( 'The archive ended unexpectedly.', 'pulse-analytics' ) );
			}

			$skip = $padded;
			while ( $skip > 0 ) {
				$chunk = gzread( $in, (int) min( 1048576, $skip ) );
				if ( false === $chunk || '' === $chunk ) {
					break 2;
				}
				$skip -= strlen( $chunk );
			}
		}
		gzclose( $in );
		return new \WP_Error( 'pulse_geoip_tar', __( 'No database file was found in the downloaded archive.', 'pulse-analytics' ) );
	}
}
