<?php
/**
 * Report query parameters: date range, comparison and filters.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable-ish value object describing what a report covers.
 */
class Query {

	const OPS = array( 'is', 'is_not', 'contains', 'not_contains' );

	const DIMENSIONS = array(
		'country',
		'continent',
		'region',
		'city',
		'device',
		'browser',
		'browser_version',
		'os',
		'os_version',
		'screen',
		'language',
		'channel',
		'source',
		'referrer',
		'referrer_url',
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'visitor_type',
		'user_type',
		'entry_page',
		'exit_page',
		'page',
		'post_type',
		'event_category',
		'event_name',
		'event_label',
		'goal',
	);

	/**
	 * First day (Y-m-d).
	 *
	 * @var string
	 */
	public $start;

	/**
	 * Last day (Y-m-d).
	 *
	 * @var string
	 */
	public $end;

	/**
	 * Normalized filters: list of array( dim, op, value ).
	 *
	 * @var array[]
	 */
	public $filters = array();

	/**
	 * Comparison mode: '', 'previous' or 'year'.
	 *
	 * @var string
	 */
	public $compare = '';

	/**
	 * Constructor.
	 *
	 * @param string       $start   Start date.
	 * @param string       $end     End date.
	 * @param array|string $filters Filters (array or JSON).
	 * @param string       $compare Comparison mode.
	 */
	public function __construct( $start = '', $end = '', $filters = array(), $compare = '' ) {
		$today       = Helpers::today();
		$this->end   = self::valid_date( $end ) ? $end : $today;
		$this->start = self::valid_date( $start ) ? $start : Helpers::add_days( $this->end, -29 );
		if ( $this->start > $this->end ) {
			list( $this->start, $this->end ) = array( $this->end, $this->start );
		}
		$this->filters = self::sanitize_filters( $filters );
		$this->compare = in_array( $compare, array( 'previous', 'year' ), true ) ? $compare : '';
	}

	/**
	 * Builds a query from request parameters.
	 *
	 * @param array $params Parameters.
	 * @return Query
	 */
	public static function from_array( array $params ) {
		return new self(
			isset( $params['start'] ) ? (string) $params['start'] : '',
			isset( $params['end'] ) ? (string) $params['end'] : '',
			isset( $params['filters'] ) ? $params['filters'] : array(),
			isset( $params['compare'] ) ? (string) $params['compare'] : ''
		);
	}

	/**
	 * Validates a Y-m-d date.
	 *
	 * @param mixed $date Date.
	 * @return bool
	 */
	public static function valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $date ) );
		return checkdate( $m, $d, $y );
	}

	/**
	 * Normalizes filters from arrays like array( 'dim' => 'country', 'op' => 'is', 'value' => 'US' )
	 * (short keys d / o / v are accepted too).
	 *
	 * @param array|string $filters Raw filters.
	 * @return array[]
	 */
	public static function sanitize_filters( $filters ) {
		if ( is_string( $filters ) ) {
			$filters = json_decode( $filters, true );
		}
		$out = array();
		foreach ( (array) $filters as $filter ) {
			if ( ! is_array( $filter ) ) {
				continue;
			}
			$dim = isset( $filter['dim'] ) ? $filter['dim'] : ( isset( $filter['d'] ) ? $filter['d'] : '' );
			$op  = isset( $filter['op'] ) ? $filter['op'] : ( isset( $filter['o'] ) ? $filter['o'] : 'is' );
			$val = isset( $filter['value'] ) ? $filter['value'] : ( isset( $filter['v'] ) ? $filter['v'] : null );
			if ( ! in_array( $dim, self::DIMENSIONS, true ) || ! in_array( $op, self::OPS, true ) || null === $val ) {
				continue;
			}
			$values = array();
			foreach ( (array) $val as $v ) {
				if ( is_scalar( $v ) ) {
					$values[] = Helpers::truncate( trim( wp_check_invalid_utf8( (string) $v ) ), 500 );
				}
			}
			$values = array_slice( array_values( array_unique( $values ) ), 0, 50 );
			if ( ! $values || ( in_array( $op, array( 'contains', 'not_contains' ), true ) && '' === implode( '', $values ) ) ) {
				continue;
			}
			$out[] = array(
				'dim'   => $dim,
				'op'    => $op,
				'value' => 1 === count( $values ) ? $values[0] : $values,
			);
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Start of the range as a datetime.
	 *
	 * @return string
	 */
	public function start_dt() {
		return $this->start . ' 00:00:00';
	}

	/**
	 * End of the range as a datetime.
	 *
	 * @return string
	 */
	public function end_dt() {
		return $this->end . ' 23:59:59';
	}

	/**
	 * Number of days covered.
	 *
	 * @return int
	 */
	public function days() {
		return (int) round( ( strtotime( $this->end . ' 00:00:00 UTC' ) - strtotime( $this->start . ' 00:00:00 UTC' ) ) / DAY_IN_SECONDS ) + 1;
	}

	/**
	 * The comparison period, or null.
	 *
	 * @return Query|null
	 */
	public function previous() {
		if ( '' === $this->compare ) {
			return null;
		}
		if ( 'year' === $this->compare ) {
			$start = ( (int) substr( $this->start, 0, 4 ) - 1 ) . substr( $this->start, 4 );
			$end   = ( (int) substr( $this->end, 0, 4 ) - 1 ) . substr( $this->end, 4 );
			$start = self::valid_date( $start ) ? $start : Helpers::add_days( $start . '', -1 );
			$end   = self::valid_date( $end ) ? $end : substr( $end, 0, 8 ) . '28';
		} else {
			$end   = Helpers::add_days( $this->start, -1 );
			$start = Helpers::add_days( $end, -( $this->days() - 1 ) );
		}
		return new self( $start, $end, $this->filters, '' );
	}

	/**
	 * Copy with other dates.
	 *
	 * @param string $start Start.
	 * @param string $end   End.
	 * @return Query
	 */
	public function with_range( $start, $end ) {
		$copy        = clone $this;
		$copy->start = $start;
		$copy->end   = $end;
		return $copy;
	}

	/**
	 * Copy with additional filters.
	 *
	 * @param array $filters Filters.
	 * @return Query
	 */
	public function with_filters( array $filters ) {
		$copy          = clone $this;
		$copy->filters = array_merge( $this->filters, self::sanitize_filters( $filters ) );
		return $copy;
	}

	/**
	 * Copy without filters on the given dimensions.
	 *
	 * @param string[] $dims Dimensions to drop.
	 * @return Query
	 */
	public function without( array $dims ) {
		$copy          = clone $this;
		$copy->filters = array_values(
			array_filter(
				$this->filters,
				static function ( $f ) use ( $dims ) {
					return ! in_array( $f['dim'], $dims, true );
				}
			)
		);
		return $copy;
	}

	/**
	 * Filters on one dimension.
	 *
	 * @param string $dim Dimension.
	 * @return array[]
	 */
	public function get( $dim ) {
		return array_values(
			array_filter(
				$this->filters,
				static function ( $f ) use ( $dim ) {
					return $f['dim'] === $dim;
				}
			)
		);
	}

	/**
	 * Whether a dimension is filtered.
	 *
	 * @param string $dim Dimension.
	 * @return bool
	 */
	public function has( $dim ) {
		return (bool) $this->get( $dim );
	}

	/**
	 * Page-level filters switch metrics to "page mode" (pageviews of the matching pages).
	 *
	 * @return bool
	 */
	public function page_mode() {
		return $this->has( 'page' ) || $this->has( 'post_type' );
	}

	/**
	 * Whether the range includes today.
	 *
	 * @return bool
	 */
	public function includes_today() {
		return $this->end >= Helpers::today();
	}

	/**
	 * Cache lifetime: short while data is still coming in.
	 *
	 * @return int
	 */
	public function cache_ttl() {
		return $this->includes_today() ? 60 : 6 * HOUR_IN_SECONDS;
	}

	/**
	 * Unique key for caching.
	 *
	 * @param mixed $extra Extra discriminator.
	 * @return string
	 */
	public function cache_key( $extra = '' ) {
		return wp_json_encode( array( $this->start, $this->end, $this->filters, $this->compare, $extra ) );
	}
}
