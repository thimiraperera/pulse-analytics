<?php
/**
 * Admin REST API used by the statistics dashboard.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes: /report (batched widgets), /goals, /map.
 */
class REST_Controller {

	/**
	 * World map geometry (Natural Earth 1:110m, ISC-licensed world-atlas package).
	 */
	const MAP_URL = 'https://cdn.jsdelivr.net/npm/world-atlas@2.0.2/countries-110m.json';

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		register_rest_route(
			Plugin::REST_NS,
			'/report',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'report' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/goals',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_goals' ),
					'permission_callback' => array( $this, 'can_view' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_goal' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/goals/(?P<id>[a-z0-9]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'delete_goal' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			Plugin::REST_NS,
			'/map',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'map' ),
				'permission_callback' => array( $this, 'can_view' ),
			)
		);
	}

	/**
	 * Permission: view statistics.
	 *
	 * @return bool
	 */
	public function can_view() {
		return Helpers::can_view();
	}

	/**
	 * Permission: manage settings and goals.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Batched report endpoint. Body: { start, end, compare, filters, nocache, widgets: { id: { type, ... } } }.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function report( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}
		$query   = Query::from_array( $params );
		$widgets = isset( $params['widgets'] ) && is_array( $params['widgets'] ) ? array_slice( $params['widgets'], 0, 25, true ) : array();
		$nocache = ! empty( $params['nocache'] );
		$reports = new Reports();
		$out     = array();

		foreach ( $widgets as $id => $widget ) {
			$id = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $id );
			if ( '' === $id || ! is_array( $widget ) ) {
				continue;
			}
			try {
				$out[ $id ] = $this->widget( $reports, $query, $widget, $nocache );
			} catch ( \Throwable $e ) {
				$out[ $id ] = array( 'error' => __( 'This report could not be loaded.', 'pulse-analytics' ) );
			}
		}

		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Computes one widget (cached).
	 *
	 * @param Reports $reports Report engine.
	 * @param Query   $query   Base query.
	 * @param array   $w       Widget definition.
	 * @param bool    $nocache Skip the cache.
	 * @return array
	 */
	private function widget( Reports $reports, Query $query, array $w, $nocache ) {
		$type = isset( $w['type'] ) ? sanitize_key( $w['type'] ) : '';
		$q    = $query;
		if ( ! empty( $w['filters'] ) && is_array( $w['filters'] ) ) {
			$q = $q->with_filters( $w['filters'] );
		}
		if ( isset( $w['compare'] ) && false === $w['compare'] ) {
			$q          = clone $q;
			$q->compare = '';
		}
		$ttl = $nocache ? 0 : $q->cache_ttl();
		$key = $type . '|' . $q->cache_key( $w );

		switch ( $type ) {
			case 'summary':
				return Cache::remember(
					$key,
					$ttl,
					static function () use ( $reports, $q ) {
						return $reports->summary( $q );
					}
				);

			case 'timeseries':
				$interval = isset( $w['interval'] ) ? sanitize_key( $w['interval'] ) : '';
				return Cache::remember(
					$key,
					$ttl,
					static function () use ( $reports, $q, $interval ) {
						return $reports->timeseries( $q, $interval );
					}
				);

			case 'breakdown':
				$dimension = isset( $w['dimension'] ) ? sanitize_key( $w['dimension'] ) : '';
				if ( ! $reports->is_dimension( $dimension ) ) {
					return array( 'error' => __( 'Unknown dimension.', 'pulse-analytics' ) );
				}
				$args = array(
					'limit'         => isset( $w['limit'] ) ? absint( $w['limit'] ) : 10,
					'offset'        => isset( $w['offset'] ) ? absint( $w['offset'] ) : 0,
					'sort'          => isset( $w['sort'] ) ? sanitize_key( $w['sort'] ) : '',
					'order'         => isset( $w['order'] ) && 'asc' === $w['order'] ? 'asc' : 'desc',
					'search'        => isset( $w['search'] ) ? sanitize_text_field( (string) $w['search'] ) : '',
					'with_previous' => ! empty( $w['with_previous'] ),
					'with_total'    => ! empty( $w['with_total'] ),
				);
				return Cache::remember(
					$key,
					$ttl,
					static function () use ( $reports, $q, $dimension, $args ) {
						return $reports->breakdown( $q, $dimension, $args );
					}
				);

			case 'stacked':
				$dimension = isset( $w['dimension'] ) ? sanitize_key( $w['dimension'] ) : 'channel';
				$interval  = isset( $w['interval'] ) ? sanitize_key( $w['interval'] ) : '';
				$top       = isset( $w['top'] ) ? absint( $w['top'] ) : 5;
				return Cache::remember(
					$key,
					$ttl,
					static function () use ( $reports, $q, $dimension, $interval, $top ) {
						return $reports->stacked( $q, $dimension, $interval, $top );
					}
				);

			case 'realtime':
				return $reports->realtime( $q );

			case 'live':
				return array( 'active' => $reports->active_visitors() );

			case 'sessions':
				$limit  = isset( $w['limit'] ) ? absint( $w['limit'] ) : 25;
				$offset = isset( $w['offset'] ) ? absint( $w['offset'] ) : 0;
				return Cache::remember(
					$key,
					$nocache ? 0 : 30,
					static function () use ( $reports, $q, $limit, $offset ) {
						return $reports->sessions( $q, $limit, $offset );
					}
				);

			case 'session':
				$detail = $reports->session_detail( isset( $w['id'] ) ? absint( $w['id'] ) : 0 );
				return $detail ? $detail : array( 'error' => __( 'Session not found.', 'pulse-analytics' ) );

			case 'heatmap':
				return Cache::remember(
					$key,
					$ttl,
					static function () use ( $reports, $q ) {
						return $reports->heatmap( $q );
					}
				);

			case 'scroll':
				return Cache::remember(
					$key,
					$ttl,
					static function () use ( $reports, $q ) {
						return $reports->scroll_depth( $q );
					}
				);

			case 'goals':
				return Cache::remember(
					$key,
					$ttl,
					static function () use ( $reports, $q ) {
						return $reports->goals( $q );
					}
				);

			case 'page_info':
				return $reports->page_info( isset( $w['path'] ) ? Helpers::truncate( (string) $w['path'], 1000 ) : '/' );

			case 'suggest':
				$dimension = isset( $w['dimension'] ) ? sanitize_key( $w['dimension'] ) : '';
				$search    = isset( $w['search'] ) ? sanitize_text_field( (string) $w['search'] ) : '';
				return array( 'values' => $reports->suggest( $q, $dimension, $search ) );
		}
		return array( 'error' => __( 'Unknown report type.', 'pulse-analytics' ) );
	}

	/**
	 * Lists goals.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_goals() {
		return rest_ensure_response( Goals::all() );
	}

	/**
	 * Creates or updates a goal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_goal( \WP_REST_Request $request ) {
		$data = $request->get_json_params();
		$goal = Goals::save( is_array( $data ) ? $data : array() );
		if ( is_wp_error( $goal ) ) {
			return $goal;
		}
		return rest_ensure_response(
			array(
				'goal'  => $goal,
				'goals' => Goals::all(),
			)
		);
	}

	/**
	 * Deletes a goal.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_goal( \WP_REST_Request $request ) {
		Goals::delete( (string) $request['id'] );
		return rest_ensure_response( array( 'goals' => Goals::all() ) );
	}

	/**
	 * World map geometry, cached in uploads after the first download.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function map() {
		$dir  = Helpers::data_dir( true );
		$file = '' !== $dir ? $dir . '/world-110m.json' : '';
		$json = '';
		if ( '' !== $file && is_readable( $file ) && filesize( $file ) > 10000 ) {
			$json = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} else {
			if ( ! Settings::get( 'external_assets' ) ) {
				return new \WP_Error( 'pulse_map_disabled', __( 'Map data download is disabled in the settings.', 'pulse-analytics' ), array( 'status' => 503 ) );
			}
			$response = wp_remote_get( self::MAP_URL, array( 'timeout' => 20 ) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return new \WP_Error( 'pulse_map_unavailable', __( 'The map data could not be downloaded by the server.', 'pulse-analytics' ), array( 'status' => 503 ) );
			}
			$json = (string) wp_remote_retrieve_body( $response );
			$test = json_decode( $json, true );
			if ( ! is_array( $test ) || empty( $test['objects'] ) || empty( $test['arcs'] ) ) {
				return new \WP_Error( 'pulse_map_invalid', __( 'The map data was invalid.', 'pulse-analytics' ), array( 'status' => 503 ) );
			}
			if ( '' !== $file ) {
				file_put_contents( $file, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
		$response = new \WP_REST_Response( json_decode( $json ) );
		$response->header( 'Cache-Control', 'private, max-age=604800' );
		return $response;
	}
}
