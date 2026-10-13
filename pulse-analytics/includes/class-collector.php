<?php
/**
 * Receives tracking hits from the front-end script and stores them.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hit collector (REST endpoint with an admin-ajax fallback).
 */
class Collector {

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( 'wp_ajax_pulse_collect', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_pulse_collect', array( $this, 'ajax' ) );
	}

	/**
	 * Registers the public collection route.
	 */
	public function register_route() {
		register_rest_route(
			Plugin::REST_NS,
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest( \WP_REST_Request $request ) {
		$data     = self::decode( (string) $request->get_body() );
		$response = new \WP_REST_Response( $this->handle( $data ), 200 );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'X-Robots-Tag', 'noindex' );
		return $response;
	}

	/**
	 * admin-ajax handler (used when the REST API is blocked).
	 */
	public function ajax() {
		$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		nocache_headers();
		wp_send_json( $this->handle( self::decode( (string) $raw ) ) );
	}

	/**
	 * Decodes the JSON payload (tolerating invalid UTF-8 sequences).
	 *
	 * @param string $raw Request body.
	 * @return array
	 */
	private static function decode( $raw ) {
		if ( '' === $raw || strlen( $raw ) > 16384 ) {
			return array();
		}
		$data = json_decode( $raw, true, 8, defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ? JSON_INVALID_UTF8_SUBSTITUTE : 0 );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Validates and dispatches a hit.
	 *
	 * @param array $d Payload.
	 * @return array
	 */
	public function handle( array $d ) {
		if ( ! Settings::get( 'tracking_enabled' ) ) {
			return array( 'ok' => 0 );
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
		if ( User_Agent::is_bot( $ua ) ) {
			return array( 'ok' => 0 );
		}
		$ip = Helpers::client_ip();
		if ( Helpers::ip_in_list( $ip, (string) Settings::get( 'exclude_ips', '' ) ) ) {
			return array( 'ok' => 0 );
		}
		if ( is_user_logged_in() && Helpers::user_is_excluded( wp_get_current_user() ) ) {
			return array( 'ok' => 0 );
		}

		/**
		 * Allows other code to reject a hit (return false).
		 *
		 * @param bool  $track Whether to record the hit.
		 * @param array $d     Raw payload.
		 */
		if ( ! apply_filters( 'pulse_analytics_track_hit', true, $d ) ) {
			return array( 'ok' => 0 );
		}

		$action = isset( $d['a'] ) ? (string) $d['a'] : 'pv';
		try {
			switch ( $action ) {
				case 'pv':
					return $this->pageview( $d, $ua, $ip );
				case 'en':
					return $this->engagement( $d );
				case 'ev':
					return $this->event( $d, $ua, $ip );
			}
		} catch ( \Exception $e ) {
			return array( 'ok' => 0 );
		}
		return array( 'ok' => 0 );
	}

	/**
	 * Records a pageview (creating the session when needed).
	 *
	 * @param array  $d  Payload.
	 * @param string $ua User agent.
	 * @param string $ip IP address.
	 * @return array
	 */
	private function pageview( array $d, $ua, $ip ) {
		global $wpdb;
		$t = Plugin::tables();

		$parsed = Helpers::parse_page_url( isset( $d['u'] ) ? (string) $d['u'] : '' );
		if ( ! $parsed || Helpers::path_excluded( $parsed['path'] ) ) {
			return array( 'ok' => 0 );
		}

		$now       = Helpers::now();
		$params    = $parsed['params'];
		$post_type = isset( $d['pt'] ) ? substr( sanitize_key( (string) $d['pt'] ), 0, 32 ) : '';
		$post_id   = isset( $d['pid'] ) ? absint( $d['pid'] ) : 0;
		$title     = Helpers::clean_text( isset( $d['t'] ) ? $d['t'] : '', 255 );
		$path      = $parsed['path'];

		// All search result pages are grouped; the terms are reported separately.
		if ( 'search' === $post_type ) {
			$path  = '/?s=';
			$title = __( 'Search results', 'pulse-analytics' );
		}

		$page_id = $this->page_id( $path, $title, $post_id, $post_type, $now );
		if ( ! $page_id ) {
			return array( 'ok' => 0 );
		}

		$visitor = $this->visitor_hash( $d, $ip, $ua );
		$utm     = array();
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $key ) {
			$utm[ $key ] = isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ? Helpers::clean_text( $params[ $key ], 'utm_source' === $key || 'utm_medium' === $key ? 100 : 150 ) : '';
		}
		if ( '' === $utm['utm_source'] ) {
			foreach ( array( 'ref', 'source' ) as $alias ) {
				if ( isset( $params[ $alias ] ) && is_scalar( $params[ $alias ] ) && '' !== trim( (string) $params[ $alias ] ) ) {
					$utm['utm_source'] = Helpers::clean_text( $params[ $alias ], 100 );
					break;
				}
			}
		}

		$timeout = max( 5, (int) Settings::get( 'session_timeout', 30 ) ) * MINUTE_IN_SECONDS;
		$session = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, exit_page_id, utm_source, utm_campaign FROM {$t['sessions']} WHERE visitor_hash = %s AND updated_at >= %s ORDER BY updated_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$visitor,
				Helpers::now( -$timeout )
			)
		);

		// A new campaign click starts a new session (same as Google Analytics).
		if ( $session && ( '' !== $utm['utm_source'] || '' !== $utm['utm_campaign'] ) && ( $session->utm_source !== $utm['utm_source'] || $session->utm_campaign !== $utm['utm_campaign'] ) ) {
			$session = null;
		}

		if ( $session ) {
			$session_id = (int) $session->id;
			// More than 30 pageviews a minute in one visit is a bot or a reload loop.
			if ( $this->too_many( $t['pageviews'], $session_id, 30 ) ) {
				return array( 'ok' => 0 );
			}
			$wpdb->insert(
				$t['pageviews'],
				array(
					'session_id'   => $session_id,
					'page_id'      => $page_id,
					'prev_page_id' => (int) $session->exit_page_id,
					'created_at'   => $now,
				),
				array( '%d', '%d', '%d', '%s' )
			);
			$pageview_id = (int) $wpdb->insert_id;
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$t['sessions']} SET pageviews = pageviews + 1, exit_page_id = %d, updated_at = %s, duration = GREATEST(duration, TIMESTAMPDIFF(SECOND, created_at, %s)) WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$page_id,
					$now,
					$now,
					$session_id
				)
			);
		} else {
			$referrer = isset( $d['r'] ) && is_string( $d['r'] ) ? substr( $d['r'], 0, 2000 ) : '';
			$source   = Referrer::classify( $referrer, array_merge( $params, $utm ) );
			if ( $source['blocked'] ) {
				return array( 'ok' => 0 );
			}

			$hints = array();
			if ( isset( $d['ch'] ) && is_array( $d['ch'] ) ) {
				$hints['mobile']           = ! empty( $d['ch']['m'] );
				$hints['platform_version'] = isset( $d['ch']['pv'] ) ? substr( sanitize_text_field( (string) $d['ch']['pv'] ), 0, 20 ) : '';
				$hints['brands']           = isset( $d['ch']['b'] ) ? substr( sanitize_text_field( (string) $d['ch']['b'] ), 0, 200 ) : '';
			}
			$hints['touch'] = isset( $d['tp'] ) ? absint( $d['tp'] ) : 0;

			$agent = User_Agent::parse( $ua, $hints );
			$geo   = GeoIP::lookup( $ip );

			$width  = isset( $d['sw'] ) ? min( 20000, absint( $d['sw'] ) ) : 0;
			$height = isset( $d['sh'] ) ? min( 20000, absint( $d['sh'] ) ) : 0;
			$lang   = isset( $d['l'] ) ? strtolower( substr( preg_replace( '/[^A-Za-z\-]/', '', (string) $d['l'] ), 0, 12 ) ) : '';

			$is_new = (int) ! $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$t['sessions']} WHERE visitor_hash = %s LIMIT 1", $visitor ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);

			$store_ip = Settings::get( 'store_ip', 'none' );
			$ip_value = '';
			if ( 'full' === $store_ip ) {
				$ip_value = $ip;
			} elseif ( 'anonymized' === $store_ip ) {
				$ip_value = Helpers::anonymize_ip( $ip );
			}

			$row = array(
				'visitor_hash'    => $visitor,
				'created_at'      => $now,
				'updated_at'      => $now,
				'duration'        => 0,
				'pageviews'       => 1,
				'events'          => 0,
				'is_new'          => $is_new,
				'entry_page_id'   => $page_id,
				'exit_page_id'    => $page_id,
				'channel'         => $source['channel'],
				'source'          => $source['source'],
				'referrer_host'   => $source['referrer_host'],
				'referrer_url'    => $source['referrer_url'],
				'utm_source'      => $utm['utm_source'],
				'utm_medium'      => $utm['utm_medium'],
				'utm_campaign'    => $utm['utm_campaign'],
				'utm_term'        => $utm['utm_term'],
				'utm_content'     => $utm['utm_content'],
				'country'         => $geo['country'],
				'region'          => $geo['region'],
				'city'            => $geo['city'],
				'device'          => $agent['device'],
				'browser'         => Helpers::truncate( $agent['browser'], 40 ),
				'browser_version' => $agent['browser_version'],
				'os'              => Helpers::truncate( $agent['os'], 40 ),
				'os_version'      => $agent['os_version'],
				'screen'          => $width && $height ? $width . 'x' . $height : '',
				'language'        => $lang,
				'user_id'         => Settings::get( 'store_user_id' ) ? get_current_user_id() : 0,
				'ip'              => $ip_value,
			);
			if ( null !== $geo['latitude'] && null !== $geo['longitude'] ) {
				$row['latitude']  = $geo['latitude'];
				$row['longitude'] = $geo['longitude'];
			}

			/**
			 * Filters a new session row before it is stored.
			 *
			 * @param array $row Session data.
			 * @param array $d   Raw payload.
			 */
			$row = (array) apply_filters( 'pulse_analytics_session_data', $row, $d );

			$int_keys = array( 'duration', 'pageviews', 'events', 'is_new', 'entry_page_id', 'exit_page_id', 'user_id' );
			$formats  = array();
			foreach ( array_keys( $row ) as $key ) {
				if ( in_array( $key, $int_keys, true ) ) {
					$formats[] = '%d';
				} elseif ( 'latitude' === $key || 'longitude' === $key ) {
					$formats[] = '%f';
				} else {
					$formats[] = '%s';
				}
			}

			if ( ! $wpdb->insert( $t['sessions'], $row, $formats ) ) {
				return array( 'ok' => 0 );
			}
			$session_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				$t['pageviews'],
				array(
					'session_id'   => $session_id,
					'page_id'      => $page_id,
					'prev_page_id' => 0,
					'created_at'   => $now,
				),
				array( '%d', '%d', '%d', '%s' )
			);
			$pageview_id = (int) $wpdb->insert_id;
		}

		// Site search terms and 404 errors are recorded with the pageview.
		if ( 'search' === $post_type && Settings::get( 'track_search' ) && isset( $d['q'] ) ) {
			$term = strtolower( Helpers::clean_text( $d['q'], 191 ) );
			if ( '' !== $term ) {
				$this->insert_event( $session_id, $page_id, 'search', $term, isset( $d['qn'] ) ? (string) absint( $d['qn'] ) : '', $now );
			}
		}
		if ( '404' === $post_type && Settings::get( 'track_404' ) ) {
			$referrer = isset( $d['r'] ) && is_string( $d['r'] ) ? esc_url_raw( substr( $d['r'], 0, 255 ) ) : '';
			$this->insert_event( $session_id, $page_id, '404', Helpers::truncate( $parsed['path'], 191 ), $referrer, $now );
		}

		return array(
			'ok'  => 1,
			'pv'  => $pageview_id,
			'sig' => self::sign( $pageview_id ),
		);
	}

	/**
	 * Updates engagement time and scroll depth for a pageview.
	 *
	 * @param array $d Payload.
	 * @return array
	 */
	private function engagement( array $d ) {
		global $wpdb;
		$t  = Plugin::tables();
		$pv = isset( $d['pv'] ) ? absint( $d['pv'] ) : 0;
		if ( ! $pv || ! isset( $d['sig'] ) || ! hash_equals( self::sign( $pv ), (string) $d['sig'] ) ) {
			return array( 'ok' => 0 );
		}
		$seconds = isset( $d['d'] ) ? min( 6 * HOUR_IN_SECONDS, absint( $d['d'] ) ) : 0;
		$scroll  = isset( $d['sc'] ) ? min( 100, absint( $d['sc'] ) ) : 0;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t['pageviews']} SET duration = GREATEST(duration, %d), scroll_depth = GREATEST(scroll_depth, %d) WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$seconds,
				$scroll,
				$pv
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$t['sessions']} s INNER JOIN {$t['pageviews']} p ON p.session_id = s.id SET s.duration = GREATEST(s.duration, TIMESTAMPDIFF(SECOND, s.created_at, p.created_at) + %d), s.updated_at = GREATEST(s.updated_at, %s) WHERE p.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$seconds,
				Helpers::now(),
				$pv
			)
		);
		return array( 'ok' => 1 );
	}

	/**
	 * Records an interaction event (outbound link, download, form, custom...).
	 *
	 * @param array  $d  Payload.
	 * @param string $ua User agent.
	 * @param string $ip IP address.
	 * @return array
	 */
	private function event( array $d, $ua, $ip ) {
		global $wpdb;
		$t = Plugin::tables();

		$category = isset( $d['c'] ) ? sanitize_key( (string) $d['c'] ) : 'custom';
		if ( ! in_array( $category, array( 'outbound', 'download', 'email', 'phone', 'form', 'custom' ), true ) ) {
			$category = 'custom';
		}
		$name  = Helpers::clean_text( isset( $d['n'] ) ? $d['n'] : '', 191 );
		$label = Helpers::clean_text( isset( $d['lb'] ) ? $d['lb'] : '', 255 );
		if ( '' === $name ) {
			return array( 'ok' => 0 );
		}

		$row = null;
		$pv  = isset( $d['pv'] ) ? absint( $d['pv'] ) : 0;
		if ( $pv && isset( $d['sig'] ) && hash_equals( self::sign( $pv ), (string) $d['sig'] ) ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT session_id, page_id FROM {$t['pageviews']} WHERE id = %d", $pv ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( ! $row ) {
			$timeout = max( 5, (int) Settings::get( 'session_timeout', 30 ) ) * MINUTE_IN_SECONDS;
			$row     = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id AS session_id, exit_page_id AS page_id FROM {$t['sessions']} WHERE visitor_hash = %s AND updated_at >= %s ORDER BY updated_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$this->visitor_hash( $d, $ip, $ua ),
					Helpers::now( -$timeout )
				)
			);
		}
		if ( ! $row || $this->too_many( $t['events'], (int) $row->session_id, 60 ) ) {
			return array( 'ok' => 0 );
		}

		$this->insert_event( (int) $row->session_id, (int) $row->page_id, $category, $name, $label, Helpers::now() );
		return array( 'ok' => 1 );
	}

	/**
	 * Whether a session already produced too many rows in the last minute.
	 *
	 * @param string $table      Table (pageviews or events).
	 * @param int    $session_id Session.
	 * @param int    $limit      Allowed rows per minute.
	 * @return bool
	 */
	private function too_many( $table, $session_id, $limit ) {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE session_id = %d AND created_at >= %s", $session_id, Helpers::now( -MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $count >= $limit;
	}

	/**
	 * Inserts an event row and bumps the session counter.
	 *
	 * @param int    $session_id Session.
	 * @param int    $page_id    Page.
	 * @param string $category   Category.
	 * @param string $name       Name.
	 * @param string $label      Label.
	 * @param string $now        Local datetime.
	 */
	private function insert_event( $session_id, $page_id, $category, $name, $label, $now ) {
		global $wpdb;
		$t = Plugin::tables();
		$wpdb->insert(
			$t['events'],
			array(
				'session_id' => $session_id,
				'page_id'    => $page_id,
				'created_at' => $now,
				'category'   => $category,
				'name'       => $name,
				'label'      => $label,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		$wpdb->query( $wpdb->prepare( "UPDATE {$t['sessions']} SET events = events + 1, updated_at = GREATEST(updated_at, %s) WHERE id = %d", $now, $session_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Finds or creates the page row and returns its id.
	 *
	 * @param string $path      Normalized path.
	 * @param string $title     Document title.
	 * @param int    $post_id   Post id (0 when not singular).
	 * @param string $post_type Post type or page context.
	 * @param string $now       Local datetime.
	 * @return int
	 */
	private function page_id( $path, $title, $post_id, $post_type, $now ) {
		global $wpdb;
		$t    = Plugin::tables();
		$hash = md5( $path );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$t['pages']} (hash, path, title, post_id, post_type, created_at) VALUES (%s, %s, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), title = IF(%s <> '', %s, title), post_id = IF(%d > 0, %d, post_id), post_type = IF(%s <> '', %s, post_type)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash,
				$path,
				$title,
				$post_id,
				$post_type,
				$now,
				$title,
				$title,
				$post_id,
				$post_id,
				$post_type,
				$post_type
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['pages']} WHERE hash = %s", $hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $id;
	}

	/**
	 * Visitor hash: a persistent random id (cookie mode) or a daily-rotating hash of IP + user agent (cookieless).
	 *
	 * @param array  $d  Payload.
	 * @param string $ip IP.
	 * @param string $ua User agent.
	 * @return string 16 hex characters.
	 */
	private function visitor_hash( array $d, $ip, $ua ) {
		if ( 'cookie' === Settings::get( 'tracking_mode' ) && ! empty( $d['vid'] ) && is_string( $d['vid'] ) && preg_match( '/^[a-f0-9]{16,64}$/', $d['vid'] ) ) {
			return substr( hash_hmac( 'sha256', 'vid|' . $d['vid'], Helpers::secret() ), 0, 16 );
		}
		return substr( hash_hmac( 'sha256', $ip . '|' . $ua . '|' . home_url(), Helpers::daily_salt() ), 0, 16 );
	}

	/**
	 * Signature that lets the browser update only its own pageview.
	 *
	 * @param int $pageview_id Pageview id.
	 * @return string
	 */
	public static function sign( $pageview_id ) {
		return substr( hash_hmac( 'sha256', 'pv|' . (int) $pageview_id, Helpers::secret() ), 0, 16 );
	}
}
