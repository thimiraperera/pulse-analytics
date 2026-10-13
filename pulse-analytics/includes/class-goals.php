<?php
/**
 * Conversion goals.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Goals are stored in a dedicated option and evaluated at query time,
 * so new goals work retroactively on existing data.
 */
class Goals {

	const OPTION = 'pulse_analytics_goals';

	const MATCHES = array( 'equals', 'contains', 'starts', 'ends', 'regex' );

	const EVENT_CATEGORIES = array( '', 'custom', 'outbound', 'download', 'form', 'email', 'phone', 'search', '404' );

	/**
	 * All goals.
	 *
	 * @return array[]
	 */
	public static function all() {
		$goals = get_option( self::OPTION, array() );
		return is_array( $goals ) ? array_values( array_filter( $goals, 'is_array' ) ) : array();
	}

	/**
	 * One goal by id.
	 *
	 * @param string $id Goal id.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $goal ) {
			if ( isset( $goal['id'] ) && (string) $goal['id'] === (string) $id ) {
				return $goal;
			}
		}
		return null;
	}

	/**
	 * Creates or updates a goal.
	 *
	 * @param array $data Raw goal data.
	 * @return array|\WP_Error Saved goal.
	 */
	public static function save( array $data ) {
		$goal = self::sanitize( $data );
		if ( '' === $goal['name'] || '' === $goal['value'] ) {
			return new \WP_Error( 'pulse_goal_invalid', __( 'A goal needs a name and a value to match.', 'pulse-analytics' ), array( 'status' => 400 ) );
		}
		if ( 'regex' === $goal['match'] && false === @preg_match( '#' . str_replace( '#', '\#', $goal['value'] ) . '#', '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return new \WP_Error( 'pulse_goal_regex', __( 'The regular expression is not valid.', 'pulse-analytics' ), array( 'status' => 400 ) );
		}

		$goals = self::all();
		$found = false;
		foreach ( $goals as $i => $existing ) {
			if ( '' !== $goal['id'] && $existing['id'] === $goal['id'] ) {
				$goals[ $i ] = $goal;
				$found       = true;
			}
		}
		if ( ! $found ) {
			$goal['id'] = substr( md5( uniqid( '', true ) ), 0, 10 );
			$goals[]    = $goal;
		}
		update_option( self::OPTION, array_values( $goals ), false );
		Cache::flush();
		return $goal;
	}

	/**
	 * Deletes a goal.
	 *
	 * @param string $id Goal id.
	 * @return bool
	 */
	public static function delete( $id ) {
		$goals    = self::all();
		$filtered = array_values(
			array_filter(
				$goals,
				static function ( $goal ) use ( $id ) {
					return (string) $goal['id'] !== (string) $id;
				}
			)
		);
		if ( count( $filtered ) === count( $goals ) ) {
			return false;
		}
		update_option( self::OPTION, $filtered, false );
		Cache::flush();
		return true;
	}

	/**
	 * Normalizes goal data.
	 *
	 * @param array $data Raw data.
	 * @return array
	 */
	public static function sanitize( array $data ) {
		$type     = isset( $data['type'] ) && 'event' === $data['type'] ? 'event' : 'page';
		$match    = isset( $data['match'] ) && in_array( $data['match'], self::MATCHES, true ) ? $data['match'] : ( 'page' === $type ? 'equals' : 'equals' );
		$category = isset( $data['category'] ) && in_array( $data['category'], self::EVENT_CATEGORIES, true ) ? $data['category'] : '';
		$value    = Helpers::truncate( trim( (string) ( isset( $data['value'] ) ? wp_strip_all_tags( $data['value'] ) : '' ) ), 255 );
		// Accept full URLs for page goals and keep only the path (+ query).
		if ( 'page' === $type && 'regex' !== $match && preg_match( '#^https?://#i', $value ) ) {
			$parts = wp_parse_url( $value );
			$value = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		}
		return array(
			'id'       => isset( $data['id'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $data['id'] ) ) : '',
			'name'     => Helpers::clean_text( isset( $data['name'] ) ? $data['name'] : '', 100 ),
			'type'     => $type,
			'match'    => $match,
			'value'    => $value,
			'category' => 'event' === $type ? $category : '',
		);
	}
}
