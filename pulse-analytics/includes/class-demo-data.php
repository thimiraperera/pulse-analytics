<?php
/**
 * Realistic sample data so the dashboard can be explored right after installation.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates and removes sample data. Sample sessions are marked with a "demo" visitor-hash prefix
 * (real hashes are hexadecimal, so they can never collide).
 */
class Demo_Data {

	const MARKER = 'demo';

	/**
	 * Whether sample data exists.
	 *
	 * @return bool
	 */
	public static function exists() {
		global $wpdb;
		$t = Plugin::tables();
		return (bool) $wpdb->get_var( "SELECT id FROM {$t['sessions']} WHERE visitor_hash LIKE 'demo%' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Removes all sample data.
	 */
	public static function delete() {
		global $wpdb;
		$t = Plugin::tables();
		$wpdb->query( "DELETE p FROM {$t['pageviews']} p INNER JOIN {$t['sessions']} s ON s.id = p.session_id WHERE s.visitor_hash LIKE 'demo%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE e FROM {$t['events']} e INNER JOIN {$t['sessions']} s ON s.id = e.session_id WHERE s.visitor_hash LIKE 'demo%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$t['sessions']} WHERE visitor_hash LIKE 'demo%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Cron::delete_orphan_pages();
		Cache::flush();
	}

	/**
	 * Picks a key from a weight map.
	 *
	 * @param array $weights key => weight.
	 * @return mixed
	 */
	private static function pick( array $weights ) {
		$total = array_sum( $weights );
		$roll  = mt_rand() / mt_getrandmax() * $total;
		foreach ( $weights as $key => $weight ) {
			$roll -= $weight;
			if ( $roll <= 0 ) {
				return $key;
			}
		}
		return key( array_slice( $weights, -1, 1, true ) );
	}

	/**
	 * Random float in a range.
	 *
	 * @param float $min Min.
	 * @param float $max Max.
	 * @return float
	 */
	private static function rand( $min, $max ) {
		return $min + mt_rand() / mt_getrandmax() * ( $max - $min );
	}

	/**
	 * Generates sample data for the last N days.
	 *
	 * @param int $days Number of days.
	 * @return int Sessions created.
	 */
	public static function generate( $days = 60 ) {
		global $wpdb;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		mt_srand( 20261013 );
		$t = Plugin::tables();

		$pages = self::pages();
		$geo   = self::locations();
		$tech  = array(
			'desktop' => array(
				'weight'   => 52,
				'browsers' => array(
					'Chrome|Windows|11|1920x1080'          => 30,
					'Chrome|Windows|10|1536x864'           => 14,
					'Chrome|macOS|26|1440x900'             => 8,
					'Edge|Windows|11|1920x1080'            => 12,
					'Safari|macOS|26|2560x1440'            => 12,
					'Firefox|Windows|11|1366x768'          => 7,
					'Firefox|Linux||1920x1080'             => 3,
					'Opera|Windows|10|1366x768'            => 2,
					'Brave|macOS|26|1440x900'              => 2,
				),
			),
			'mobile'  => array(
				'weight'   => 42,
				'browsers' => array(
					'Chrome|Android|16|412x915'            => 32,
					'Chrome|Android|15|360x800'            => 14,
					'Safari|iOS|26.4|390x844'              => 26,
					'Safari|iOS|27.0|430x932'              => 10,
					'Samsung Internet|Android|16|384x854'  => 8,
					'Facebook App|Android|15|393x873'      => 5,
					'Instagram App|iOS|26.4|390x844'       => 3,
				),
			),
			'tablet'  => array(
				'weight'   => 6,
				'browsers' => array(
					'Safari|iPadOS|26|820x1180'            => 60,
					'Chrome|Android|15|800x1280'           => 40,
				),
			),
		);
		$sources = array(
			'direct||'                                                 => 22,
			'organic_search|Google|google.com'                         => 30,
			'organic_search|Bing|bing.com'                             => 4,
			'organic_search|DuckDuckGo|duckduckgo.com'                 => 1.5,
			'social|Facebook|facebook.com'                             => 8,
			'social|Instagram|instagram.com'                           => 4,
			'social|LinkedIn|linkedin.com'                             => 3,
			'social|X (Twitter)|t.co'                                  => 2,
			'social|YouTube|youtube.com'                               => 2,
			'social|Reddit|reddit.com'                                 => 1,
			'ai|ChatGPT|chatgpt.com'                                   => 4,
			'ai|Perplexity|perplexity.ai'                              => 1.5,
			'ai|Gemini|gemini.google.com'                              => 1,
			'ai|Claude|claude.ai'                                      => 1,
			'email|Newsletter|'                                        => 4,
			'paid_search|Google|'                                      => 5,
			'paid_social|Facebook|'                                    => 3,
			'referral|partner-blog.com|partner-blog.com'               => 2,
			'referral|GitHub|github.com'                               => 1,
			'referral|businessdirectory.lk|businessdirectory.lk'       => 2,
			'social|Product Hunt|producthunt.com'                      => 1,
		);
		$hour_weights = array( 1, 0.6, 0.4, 0.3, 0.3, 0.5, 1, 2, 3.5, 5, 5.5, 5.5, 5, 4.8, 4.6, 4.4, 4.2, 4, 4.2, 4.8, 5, 4.4, 3, 1.8 );

		$session_cols = array( 'visitor_hash', 'created_at', 'updated_at', 'duration', 'pageviews', 'events', 'is_new', 'entry_page_id', 'exit_page_id', 'channel', 'source', 'referrer_host', 'referrer_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'country', 'region', 'city', 'latitude', 'longitude', 'device', 'browser', 'browser_version', 'os', 'os_version', 'screen', 'language' );

		$now_ts   = strtotime( Helpers::now() );
		$created  = 0;
		$returners = array();
		$pv_rows  = array();
		$ev_rows  = array();

		$wpdb->query( 'START TRANSACTION' );
		for ( $d = $days - 1; $d >= 0; $d-- ) {
			$date    = Helpers::today( -$d );
			$weekday = (int) gmdate( 'w', strtotime( $date . ' 12:00:00 UTC' ) );
			$factor  = array( 0.62, 1.05, 1.12, 1.1, 1.06, 0.95, 0.7 );
			$count   = (int) round( 95 * $factor[ $weekday ] * ( 1 + ( $days - $d ) * 0.007 ) * self::rand( 0.85, 1.15 ) );
			// Campaign spike two weeks ago.
			if ( $d >= 12 && $d <= 14 ) {
				$count = (int) ( $count * 1.6 );
			}
			for ( $i = 0; $i < $count; $i++ ) {
				$hour = (int) self::pick( $hour_weights );
				$ts   = strtotime( $date . ' 00:00:00' ) + $hour * 3600 + mt_rand( 0, 3599 );
				if ( $ts > $now_ts ) {
					continue;
				}

				// Visitor.
				$returning = $returners && mt_rand( 1, 100 ) <= 28;
				if ( $returning ) {
					$visitor = $returners[ array_rand( $returners ) ];
				} else {
					$country = self::pick( array_map( static function ( $c ) { return $c['weight']; }, $geo ) );
					$cities  = $geo[ $country ]['cities'];
					$city    = $cities[ array_rand( $cities ) ];
					$device  = self::pick( array_map( static function ( $x ) { return $x['weight']; }, $tech ) );
					list( $browser, $os, $os_version, $screen ) = explode( '|', self::pick( $tech[ $device ]['browsers'] ) );
					$langs   = $geo[ $country ]['lang'];
					$visitor = array(
						'hash'     => self::MARKER . substr( md5( uniqid( '', true ) . mt_rand() ), 0, 12 ),
						'country'  => $country,
						'region'   => $city[1],
						'city'     => $city[0],
						'lat'      => $city[2],
						'lon'      => $city[3],
						'device'   => $device,
						'browser'  => $browser,
						'bver'     => (string) self::pick( array( '153' => 5, '152' => 3, '151' => 1 ) ),
						'os'       => $os,
						'osver'    => $os_version,
						'screen'   => $screen,
						'language' => $langs[ array_rand( $langs ) ],
					);
					if ( 'Safari' === $browser ) {
						$visitor['bver'] = in_array( $os, array( 'iOS', 'iPadOS' ), true ) ? substr( $os_version, 0, 2 ) : '26';
					} elseif ( 'Firefox' === $browser ) {
						$visitor['bver'] = (string) self::pick( array( '155' => 4, '154' => 2, '140' => 1 ) );
					} elseif ( 'Samsung Internet' === $browser ) {
						$visitor['bver'] = '29';
					} elseif ( in_array( $browser, array( 'Facebook App', 'Instagram App' ), true ) ) {
						$visitor['bver'] = '';
					}
					if ( count( $returners ) < 3000 ) {
						$returners[] = $visitor;
					}
				}

				// Source.
				list( $channel, $source, $ref_host ) = explode( '|', self::pick( $sources ) );
				$utm = array( '', '', '', '', '' );
				if ( 'email' === $channel ) {
					$utm = array( 'newsletter', 'email', (string) self::pick( array( 'october-newsletter' => 3, 'weekly-digest' => 2 ) ), '', '' );
				} elseif ( 'paid_search' === $channel ) {
					$utm = array( 'google', 'cpc', (string) self::pick( array( 'brand-search' => 3, 'services-search' => 2 ) ), (string) self::pick( array( 'digital marketing agency' => 2, 'seo services' => 2, 'website analytics' => 1 ) ), '' );
				} elseif ( 'paid_social' === $channel ) {
					$utm = array( 'facebook', 'paid_social', 'spring-promo', '', (string) self::pick( array( 'video-ad' => 3, 'carousel' => 2 ) ) );
				} elseif ( 'social' === $channel && mt_rand( 1, 100 ) <= 30 ) {
					$utm = array( strtolower( $source ), 'social', 'organic-posts', '', '' );
				}

				// Pages viewed.
				$n_pages = mt_rand( 1, 100 ) <= 44 ? 1 : (int) self::pick( array( 2 => 30, 3 => 22, 4 => 14, 5 => 9, 6 => 6, 7 => 4, 9 => 3 ) );
				$entry   = in_array( $channel, array( 'paid_search', 'paid_social', 'email' ), true ) && isset( $pages['landing'] ) ? $pages['landing'] : self::pick( $pages['weights'] );
				$visited = array( $entry );
				for ( $k = 1; $k < $n_pages; $k++ ) {
					$visited[] = self::pick( $pages['weights'] );
				}

				$cursor   = $ts;
				$views    = array();
				$duration = 0;
				foreach ( $visited as $idx => $page_id ) {
					$is_last  = $idx === count( $visited ) - 1;
					$time     = 1 === $n_pages ? (int) self::rand( 3, 95 ) : (int) min( 900, pow( self::rand( 1.8, 4.2 ), 3 ) );
					$scroll   = (int) min( 100, max( 10, self::rand( 15, 115 ) ) );
					$views[]  = array( $page_id, $cursor, $time, $scroll );
					if ( ! $is_last ) {
						$cursor += $time + mt_rand( 1, 6 );
					}
				}
				$duration = $cursor - $ts + $views[ count( $views ) - 1 ][2];

				// Events.
				$events = array();
				$roll   = mt_rand( 1, 1000 );
				$ev_ts  = $views[ count( $views ) - 1 ][1] + 2;
				$ev_pg  = $views[ count( $views ) - 1 ][0];
				if ( $roll <= 80 ) {
					$host     = (string) self::pick( array( 'wordpress.org' => 3, 'facebook.com' => 2, 'youtube.com' => 2, 'maps.google.com' => 2, 'wa.me' => 1 ) );
					$events[] = array( 'outbound', $host, 'https://' . $host . '/' );
				} elseif ( $roll <= 125 ) {
					$file     = (string) self::pick( array( 'brochure.pdf' => 3, 'price-list-2026.pdf' => 2, 'company-profile.pdf' => 1 ) );
					$events[] = array( 'download', $file, Helpers::site_origin() . '/wp-content/uploads/' . $file );
				} elseif ( $roll <= 185 ) {
					$events[] = array( 'form', (string) self::pick( array( 'contact-form' => 3, 'newsletter-signup' => 2, 'quote-request' => 1 ) ), '/contact/' );
				} elseif ( $roll <= 210 ) {
					$events[] = array( 'phone', '+94 11 234 5678', '/contact/' );
				} elseif ( $roll <= 225 ) {
					$events[] = array( 'email', 'hello@example.com', '/contact/' );
				} elseif ( $roll <= 290 ) {
					$events[] = array( 'custom', 'CTA Click', (string) self::pick( array( 'Hero button' => 3, 'Pricing page button' => 2, 'Sticky header' => 1 ) ) );
				}
				if ( mt_rand( 1, 100 ) <= 4 ) {
					$events[] = array( 'search', (string) self::pick( array( 'pricing' => 4, 'seo tips' => 3, 'marketing plan' => 3, 'contact' => 2, 'social media' => 2, 'analytics' => 1 ) ), (string) mt_rand( 0, 12 ) );
				}
				if ( mt_rand( 1, 100 ) <= 2 ) {
					$events[] = array( '404', (string) self::pick( array( '/old-services/' => 3, '/promo-2024/' => 2, '/wp-content/uploads/old.pdf' => 1 ) ), 'https://google.com/' );
				}

				$exit_page = $views[ count( $views ) - 1 ][0];
				$session   = array(
					$visitor['hash'],
					gmdate( 'Y-m-d H:i:s', $ts ),
					gmdate( 'Y-m-d H:i:s', min( $now_ts, $ts + $duration ) ),
					$duration,
					count( $views ),
					count( $events ),
					$returning ? 0 : 1,
					$entry,
					$exit_page,
					$channel,
					'' !== $utm[0] && 'email' === $channel ? 'Newsletter' : $source,
					$ref_host,
					$ref_host,
					$utm[0],
					$utm[1],
					$utm[2],
					$utm[3],
					$utm[4],
					$visitor['country'],
					$visitor['region'],
					$visitor['city'],
					$visitor['lat'],
					$visitor['lon'],
					$visitor['device'],
					$visitor['browser'],
					$visitor['bver'],
					$visitor['os'],
					$visitor['osver'],
					$visitor['screen'],
					$visitor['language'],
				);
				$wpdb->insert( $t['sessions'], array_combine( $session_cols, $session ) );
				$session_id = (int) $wpdb->insert_id;
				if ( ! $session_id ) {
					continue;
				}
				++$created;

				$prev = 0;
				foreach ( $views as $view ) {
					$pv_rows[] = $wpdb->prepare( '(%d,%d,%d,%s,%d,%d)', $session_id, $view[0], $prev, gmdate( 'Y-m-d H:i:s', $view[1] ), $view[2], $view[3] );
					$prev      = $view[0];
				}
				foreach ( $events as $event ) {
					$ev_rows[] = $wpdb->prepare( '(%d,%d,%s,%s,%s,%s)', $session_id, $ev_pg, gmdate( 'Y-m-d H:i:s', min( $now_ts, $ev_ts ) ), $event[0], $event[1], $event[2] );
				}
				if ( count( $pv_rows ) >= 500 ) {
					self::flush_rows( $t['pageviews'], '(session_id, page_id, prev_page_id, created_at, duration, scroll_depth)', $pv_rows );
				}
				if ( count( $ev_rows ) >= 500 ) {
					self::flush_rows( $t['events'], '(session_id, page_id, created_at, category, name, label)', $ev_rows );
				}
			}
		}
		self::flush_rows( $t['pageviews'], '(session_id, page_id, prev_page_id, created_at, duration, scroll_depth)', $pv_rows );
		self::flush_rows( $t['events'], '(session_id, page_id, created_at, category, name, label)', $ev_rows );
		$wpdb->query( 'COMMIT' );

		self::sample_goals();
		Cache::flush();
		return $created;
	}

	/**
	 * Writes buffered rows with one multi-row INSERT.
	 *
	 * @param string $table   Table.
	 * @param string $columns Column list.
	 * @param array  $rows    Prepared value tuples (emptied).
	 */
	private static function flush_rows( $table, $columns, array &$rows ) {
		global $wpdb;
		if ( $rows ) {
			$wpdb->query( "INSERT INTO {$table} {$columns} VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = array();
		}
	}

	/**
	 * Creates the page rows used by the sample (real published content when available).
	 *
	 * @return array{weights:array,landing:int}
	 */
	private static function pages() {
		global $wpdb;
		$t     = Plugin::tables();
		$items = array(
			array( '/', get_bloginfo( 'name' ), 0, 'front' ),
		);
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => 'publish',
				'posts_per_page' => 25,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ( $posts as $post ) {
			$path = (string) wp_parse_url( get_permalink( $post ), PHP_URL_PATH );
			$qs   = (string) wp_parse_url( get_permalink( $post ), PHP_URL_QUERY );
			if ( '' === $path ) {
				continue;
			}
			if ( '' !== $qs ) {
				$path .= '?' . $qs;
			}
			$items[] = array( $path, get_the_title( $post ), $post->ID, $post->post_type );
		}
		if ( count( $items ) < 6 ) {
			foreach ( array( '/services/' => 'Services', '/pricing/' => 'Pricing', '/contact/' => 'Contact', '/about/' => 'About Us', '/blog/' => 'Blog' ) as $path => $title ) {
				$items[] = array( $path, $title, 0, 'page' );
			}
		}
		$items[] = array( '/?s=', __( 'Search results', 'pulse-analytics' ), 0, 'search' );

		$weights = array();
		$landing = 0;
		$now     = Helpers::now();
		foreach ( array_values( $items ) as $rank => $item ) {
			list( $path, $title, $post_id, $type ) = $item;
			$hash = md5( $path );
			$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['pages']} WHERE hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $id ) {
				$wpdb->insert(
					$t['pages'],
					array(
						'hash'       => $hash,
						'path'       => $path,
						'title'      => Helpers::truncate( wp_strip_all_tags( $title ), 255 ),
						'post_id'    => $post_id,
						'post_type'  => $type,
						'created_at' => $now,
					)
				);
				$id = (int) $wpdb->insert_id;
			}
			if ( ! $id ) {
				continue;
			}
			$weights[ $id ] = 'search' === $type ? 1.5 : 100 / pow( $rank + 1, 0.85 );
			if ( ! $landing && false !== stripos( $path, 'pric' ) ) {
				$landing = $id;
			}
		}
		if ( ! $landing && $weights ) {
			$keys    = array_keys( $weights );
			$landing = $keys[ min( 1, count( $keys ) - 1 ) ];
		}
		return array(
			'weights' => $weights,
			'landing' => $landing,
		);
	}

	/**
	 * Visitor locations: weight, languages and cities (name, region, lat, lon).
	 *
	 * @return array
	 */
	private static function locations() {
		return array(
			'US' => array( 'weight' => 28, 'lang' => array( 'en-us' ), 'cities' => array( array( 'New York', 'New York', 40.7128, -74.006 ), array( 'Los Angeles', 'California', 34.0522, -118.2437 ), array( 'Chicago', 'Illinois', 41.8781, -87.6298 ), array( 'Austin', 'Texas', 30.2672, -97.7431 ), array( 'Seattle', 'Washington', 47.6062, -122.3321 ) ) ),
			'LK' => array( 'weight' => 18, 'lang' => array( 'en-us', 'si-lk', 'ta-lk', 'en-gb' ), 'cities' => array( array( 'Colombo', 'Western Province', 6.9271, 79.8612 ), array( 'Kandy', 'Central Province', 7.2906, 80.6337 ), array( 'Galle', 'Southern Province', 6.0535, 80.221 ), array( 'Negombo', 'Western Province', 7.2083, 79.8358 ), array( 'Jaffna', 'Northern Province', 9.6615, 80.0255 ) ) ),
			'GB' => array( 'weight' => 10, 'lang' => array( 'en-gb' ), 'cities' => array( array( 'London', 'England', 51.5074, -0.1278 ), array( 'Manchester', 'England', 53.4808, -2.2426 ), array( 'Edinburgh', 'Scotland', 55.9533, -3.1883 ) ) ),
			'IN' => array( 'weight' => 12, 'lang' => array( 'en-in', 'hi-in', 'en-us' ), 'cities' => array( array( 'Mumbai', 'Maharashtra', 19.076, 72.8777 ), array( 'Bengaluru', 'Karnataka', 12.9716, 77.5946 ), array( 'Chennai', 'Tamil Nadu', 13.0827, 80.2707 ), array( 'Delhi', 'Delhi', 28.6139, 77.209 ) ) ),
			'DE' => array( 'weight' => 6, 'lang' => array( 'de-de', 'en-us' ), 'cities' => array( array( 'Berlin', 'Berlin', 52.52, 13.405 ), array( 'Munich', 'Bavaria', 48.1351, 11.582 ) ) ),
			'AU' => array( 'weight' => 6, 'lang' => array( 'en-au' ), 'cities' => array( array( 'Sydney', 'New South Wales', -33.8688, 151.2093 ), array( 'Melbourne', 'Victoria', -37.8136, 144.9631 ) ) ),
			'CA' => array( 'weight' => 5, 'lang' => array( 'en-ca', 'fr-ca' ), 'cities' => array( array( 'Toronto', 'Ontario', 43.6532, -79.3832 ), array( 'Vancouver', 'British Columbia', 49.2827, -123.1207 ) ) ),
			'AE' => array( 'weight' => 3, 'lang' => array( 'en-us', 'ar-ae' ), 'cities' => array( array( 'Dubai', 'Dubai', 25.2048, 55.2708 ) ) ),
			'SG' => array( 'weight' => 3, 'lang' => array( 'en-sg' ), 'cities' => array( array( 'Singapore', 'Singapore', 1.3521, 103.8198 ) ) ),
			'FR' => array( 'weight' => 3, 'lang' => array( 'fr-fr' ), 'cities' => array( array( 'Paris', 'Île-de-France', 48.8566, 2.3522 ), array( 'Lyon', 'Auvergne-Rhône-Alpes', 45.764, 4.8357 ) ) ),
			'JP' => array( 'weight' => 2, 'lang' => array( 'ja-jp' ), 'cities' => array( array( 'Tokyo', 'Tokyo', 35.6762, 139.6503 ) ) ),
			'BR' => array( 'weight' => 2, 'lang' => array( 'pt-br' ), 'cities' => array( array( 'São Paulo', 'São Paulo', -23.5505, -46.6333 ) ) ),
			'NL' => array( 'weight' => 2, 'lang' => array( 'nl-nl', 'en-us' ), 'cities' => array( array( 'Amsterdam', 'North Holland', 52.3676, 4.9041 ) ) ),
			'MV' => array( 'weight' => 1, 'lang' => array( 'en-us' ), 'cities' => array( array( 'Malé', 'Malé', 4.1755, 73.5093 ) ) ),
			'ZA' => array( 'weight' => 1, 'lang' => array( 'en-za' ), 'cities' => array( array( 'Johannesburg', 'Gauteng', -26.2041, 28.0473 ) ) ),
			'NG' => array( 'weight' => 1, 'lang' => array( 'en-ng' ), 'cities' => array( array( 'Lagos', 'Lagos', 6.5244, 3.3792 ) ) ),
			'MY' => array( 'weight' => 1, 'lang' => array( 'en-my', 'ms-my' ), 'cities' => array( array( 'Kuala Lumpur', 'Kuala Lumpur', 3.139, 101.6869 ) ) ),
			'IT' => array( 'weight' => 1, 'lang' => array( 'it-it' ), 'cities' => array( array( 'Milan', 'Lombardy', 45.4642, 9.19 ) ) ),
		);
	}

	/**
	 * Adds two example goals when none exist yet.
	 */
	private static function sample_goals() {
		if ( Goals::all() ) {
			return;
		}
		Goals::save(
			array(
				'name'     => __( 'Contact form submitted', 'pulse-analytics' ),
				'type'     => 'event',
				'category' => 'form',
				'match'    => 'contains',
				'value'    => 'contact',
			)
		);
		Goals::save(
			array(
				'name'     => __( 'Clicked a call-to-action', 'pulse-analytics' ),
				'type'     => 'event',
				'category' => 'custom',
				'match'    => 'equals',
				'value'    => 'CTA Click',
			)
		);
	}
}
