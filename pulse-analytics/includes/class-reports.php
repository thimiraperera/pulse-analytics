<?php
/**
 * Report engine: every number shown in the dashboard is computed here.
 *
 * Two query modes exist:
 *  - session mode (default): metrics come from the sessions table;
 *  - page mode (a page / post type filter is active): metrics describe pageviews of the matching pages.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SQL reports.
 */
class Reports {

	const SESSION_METRICS = 'COUNT(DISTINCT s.visitor_hash) AS visitors, COUNT(*) AS visits, COALESCE(SUM(s.pageviews),0) AS pageviews, COALESCE(SUM(s.pageviews <= 1),0) AS bounces, COALESCE(AVG(s.duration),0) AS avg_duration';

	const PAGE_METRICS = 'COUNT(DISTINCT s.visitor_hash) AS visitors, COUNT(DISTINCT p.session_id) AS visits, COUNT(*) AS pageviews, COALESCE(AVG(NULLIF(p.duration,0)),0) AS avg_duration';

	/**
	 * Database.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Table names.
	 *
	 * @var array
	 */
	private $t;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
		$this->t  = Plugin::tables();
	}

	/* =================================================================== *
	 * Dimension metadata
	 * =================================================================== */

	/**
	 * Session-level breakdown dimensions.
	 *
	 * @return array
	 */
	private function session_dims() {
		$dims = array(
			'country'         => array( 'key' => 's.country' ),
			'continent'       => array( 'key' => 's.country' ),
			'region'          => array(
				'key'      => 's.region',
				'group'    => 's.country, s.region',
				'select'   => 's.country AS country',
				'nonempty' => 's.region',
			),
			'city'            => array(
				'key'      => 's.city',
				'group'    => 's.country, s.region, s.city',
				'select'   => 's.country AS country, s.region AS region, AVG(s.latitude) AS lat, AVG(s.longitude) AS lon',
				'nonempty' => 's.city',
			),
			'device'          => array( 'key' => 's.device' ),
			'browser'         => array( 'key' => 's.browser' ),
			'browser_version' => array(
				'key'    => 's.browser_version',
				'group'  => 's.browser, s.browser_version',
				'select' => 's.browser AS browser',
			),
			'os'              => array( 'key' => 's.os' ),
			'os_version'      => array(
				'key'    => 's.os_version',
				'group'  => 's.os, s.os_version',
				'select' => 's.os AS os',
			),
			'screen'          => array(
				'key'      => 's.screen',
				'nonempty' => 's.screen',
			),
			'language'        => array( 'key' => 's.language' ),
			'channel'         => array( 'key' => 's.channel' ),
			'source'          => array( 'key' => 's.source' ),
			'referrer'        => array(
				'key'      => 's.referrer_host',
				'nonempty' => 's.referrer_host',
			),
			'referrer_url'    => array(
				'key'      => 's.referrer_url',
				'nonempty' => 's.referrer_url',
			),
			'visitor_type'    => array( 'key' => "IF(s.is_new = 1, 'new', 'returning')" ),
			'user_type'       => array( 'key' => "IF(s.user_id > 0, 'logged_in', 'guest')" ),
		);
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ) as $utm ) {
			$dims[ $utm ] = array(
				'key'      => 's.' . $utm,
				'nonempty' => 's.' . $utm,
			);
		}
		return $dims;
	}

	/**
	 * Which engine handles a dimension.
	 *
	 * @param string $dimension Dimension.
	 * @return string
	 */
	private function dimension_type( $dimension ) {
		if ( isset( $this->session_dims()[ $dimension ] ) ) {
			return 'session';
		}
		$map = array(
			'page'           => 'page',
			'entry_page'     => 'entry_page',
			'exit_page'      => 'exit_page',
			'post_type'      => 'content',
			'author'         => 'content',
			'category'       => 'content',
			'prev_page'      => 'transition',
			'next_page'      => 'transition',
			'event_category' => 'event',
			'event_name'     => 'event',
			'event_label'    => 'event',
		);
		return isset( $map[ $dimension ] ) ? $map[ $dimension ] : '';
	}

	/**
	 * Whether a breakdown dimension is supported.
	 *
	 * @param string $dimension Dimension.
	 * @return bool
	 */
	public function is_dimension( $dimension ) {
		return '' !== $this->dimension_type( $dimension );
	}

	/* =================================================================== *
	 * SQL building blocks
	 * =================================================================== */

	/**
	 * "col BETWEEN start AND end" for the query range.
	 *
	 * @param string $column Column.
	 * @param Query  $q      Query.
	 * @return string
	 */
	private function range( $column, Query $q ) {
		return $this->db->prepare( "{$column} BETWEEN %s AND %s", $q->start_dt(), $q->end_dt() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Builds a WHERE clause.
	 *
	 * @param string[] $conditions Conditions.
	 * @return string
	 */
	private function where( array $conditions ) {
		$conditions = array_values( array_filter( $conditions, 'strlen' ) );
		return $conditions ? ' WHERE ' . implode( ' AND ', $conditions ) : '';
	}

	/**
	 * Quoted, comma separated list.
	 *
	 * @param array $values Values.
	 * @return string
	 */
	private function in_list( array $values ) {
		$db = $this->db;
		return implode(
			',',
			array_map(
				static function ( $v ) use ( $db ) {
					return $db->prepare( '%s', (string) $v );
				},
				$values
			)
		);
	}

	/**
	 * Matches a column against a filter operator.
	 *
	 * @param string       $column Column expression.
	 * @param string       $op     Operator.
	 * @param string|array $value  Value(s).
	 * @return string
	 */
	private function match( $column, $op, $value ) {
		$values = array_map( 'strval', (array) $value );
		switch ( $op ) {
			case 'is':
				return 1 === count( $values ) ? $this->db->prepare( "{$column} = %s", $values[0] ) : "{$column} IN (" . $this->in_list( $values ) . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			case 'is_not':
				return 1 === count( $values ) ? $this->db->prepare( "{$column} <> %s", $values[0] ) : "{$column} NOT IN (" . $this->in_list( $values ) . ')'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			case 'contains':
			case 'not_contains':
				$parts = array();
				foreach ( $values as $v ) {
					$parts[] = $this->db->prepare( "{$column} " . ( 'contains' === $op ? 'LIKE' : 'NOT LIKE' ) . ' %s', '%' . $this->db->esc_like( $v ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				return '(' . implode( 'contains' === $op ? ' OR ' : ' AND ', $parts ) . ')';
		}
		return '1=0';
	}

	/**
	 * Splits an operator into its positive form and negation flag.
	 *
	 * @param string $op Operator.
	 * @return array{0:string,1:bool}
	 */
	private function polarity( $op ) {
		if ( 'is_not' === $op ) {
			return array( 'is', true );
		}
		if ( 'not_contains' === $op ) {
			return array( 'contains', true );
		}
		return array( $op, false );
	}

	/**
	 * Sub-select of page ids matching a page filter.
	 *
	 * @param string       $field path or post_type.
	 * @param string       $op    Positive operator.
	 * @param string|array $value Value.
	 * @return string
	 */
	private function page_ids( $field, $op, $value ) {
		return "SELECT id FROM {$this->t['pages']} WHERE " . $this->match( $field, $op, $value );
	}

	/**
	 * Conditions on the sessions table (alias s) for all filters.
	 *
	 * @param Query  $q       Query.
	 * @param string $context session: page/event filters restrict sessions; pageview: page filters are left to the caller;
	 *                        event: event and page filters are left to the caller.
	 * @return string[]
	 */
	private function session_conditions( Query $q, $context = 'session' ) {
		$out = array();
		foreach ( $q->filters as $filter ) {
			$sql = $this->filter_sql( $filter, $q, $context );
			if ( '' !== $sql ) {
				$out[] = $sql;
			}
		}
		return $out;
	}

	/**
	 * SQL for one filter.
	 *
	 * @param array  $f       Filter.
	 * @param Query  $q       Query.
	 * @param string $context Context (see session_conditions()).
	 * @return string
	 */
	private function filter_sql( array $f, Query $q, $context ) {
		$columns = array(
			'country'         => 's.country',
			'region'          => 's.region',
			'city'            => 's.city',
			'device'          => 's.device',
			'browser'         => 's.browser',
			'browser_version' => 's.browser_version',
			'os'              => 's.os',
			'os_version'      => 's.os_version',
			'screen'          => 's.screen',
			'language'        => 's.language',
			'channel'         => 's.channel',
			'source'          => 's.source',
			'referrer'        => 's.referrer_host',
			'referrer_url'    => 's.referrer_url',
			'utm_source'      => 's.utm_source',
			'utm_medium'      => 's.utm_medium',
			'utm_campaign'    => 's.utm_campaign',
			'utm_term'        => 's.utm_term',
			'utm_content'     => 's.utm_content',
		);
		$dim   = $f['dim'];
		$value = $f['value'];
		if ( isset( $columns[ $dim ] ) ) {
			return $this->match( $columns[ $dim ], $f['op'], $value );
		}
		list( $positive, $negative ) = $this->polarity( $f['op'] );
		$in                          = $negative ? ' NOT IN ' : ' IN ';

		switch ( $dim ) {
			case 'continent':
				$codes = array();
				foreach ( (array) $value as $continent ) {
					$codes = array_merge( $codes, Countries::in_continent( strtoupper( (string) $continent ) ) );
				}
				if ( ! $codes ) {
					return $negative ? '' : '1=0';
				}
				return 's.country' . $in . '(' . $this->in_list( $codes ) . ')';

			case 'visitor_type':
			case 'user_type':
				$values = (array) $value;
				$first  = 'visitor_type' === $dim ? 'new' : 'logged_in';
				$second = 'visitor_type' === $dim ? 'returning' : 'guest';
				$has_a  = in_array( $first, $values, true );
				$has_b  = in_array( $second, $values, true );
				if ( $has_a && $has_b ) {
					return $negative ? '1=0' : '';
				}
				if ( 'visitor_type' === $dim ) {
					$cond = $has_a ? 's.is_new = 1' : 's.is_new = 0';
				} else {
					$cond = $has_a ? 's.user_id > 0' : 's.user_id = 0';
				}
				return $negative ? "NOT ({$cond})" : $cond;

			case 'entry_page':
			case 'exit_page':
				$column = 'entry_page' === $dim ? 's.entry_page_id' : 's.exit_page_id';
				return $column . $in . '(' . $this->page_ids( 'path', $positive, $value ) . ')';

			case 'page':
			case 'post_type':
				if ( 'session' !== $context ) {
					return '';
				}
				$field = 'page' === $dim ? 'path' : 'post_type';
				return 's.id' . $in . "(SELECT pf.session_id FROM {$this->t['pageviews']} pf WHERE " . $this->range( 'pf.created_at', $q ) . ' AND pf.page_id IN (' . $this->page_ids( $field, $positive, $value ) . '))';

			case 'event_category':
			case 'event_name':
			case 'event_label':
				if ( 'event' === $context ) {
					return '';
				}
				$column = 'ef.' . substr( $dim, 6 );
				return 's.id' . $in . "(SELECT ef.session_id FROM {$this->t['events']} ef WHERE " . $this->range( 'ef.created_at', $q ) . ' AND ' . $this->match( $column, $positive, $value ) . ')';

			case 'goal':
				$goal_id = is_array( $value ) ? (string) reset( $value ) : (string) $value;
				return 's.id' . $in . '(' . $this->goal_sessions( $goal_id, $q ) . ')';
		}
		return '';
	}

	/**
	 * Page filters applied to a page-id column (page mode).
	 *
	 * @param Query  $q      Query.
	 * @param string $column Column holding a page id.
	 * @return string[]
	 */
	private function page_conditions( Query $q, $column ) {
		$out = array();
		foreach ( $q->filters as $f ) {
			if ( 'page' !== $f['dim'] && 'post_type' !== $f['dim'] ) {
				continue;
			}
			list( $positive, $negative ) = $this->polarity( $f['op'] );
			$out[]                       = $column . ( $negative ? ' NOT IN ' : ' IN ' ) . '(' . $this->page_ids( 'page' === $f['dim'] ? 'path' : 'post_type', $positive, $f['value'] ) . ')';
		}
		return $out;
	}

	/**
	 * Event filters applied directly to the events table.
	 *
	 * @param Query  $q     Query.
	 * @param string $alias Events alias.
	 * @return string[]
	 */
	private function event_conditions( Query $q, $alias = 'e' ) {
		$out = array();
		foreach ( $q->filters as $f ) {
			if ( in_array( $f['dim'], array( 'event_category', 'event_name', 'event_label' ), true ) ) {
				$out[] = $this->match( $alias . '.' . substr( $f['dim'], 6 ), $f['op'], $f['value'] );
			}
		}
		return array_merge( $out, $this->page_conditions( $q, $alias . '.page_id' ) );
	}

	/**
	 * Sub-select of session ids that completed a goal in the range.
	 *
	 * @param string $goal_id Goal id.
	 * @param Query  $q       Query.
	 * @return string
	 */
	private function goal_sessions( $goal_id, Query $q ) {
		$goal = Goals::get( $goal_id );
		if ( ! $goal ) {
			return 'SELECT 0';
		}
		if ( 'event' === $goal['type'] ) {
			return "SELECT gf.session_id FROM {$this->t['events']} gf WHERE " . $this->range( 'gf.created_at', $q ) . ' AND ' . $this->goal_event_condition( $goal, 'gf' );
		}
		return "SELECT gf.session_id FROM {$this->t['pageviews']} gf WHERE " . $this->range( 'gf.created_at', $q ) . " AND gf.page_id IN (SELECT id FROM {$this->t['pages']} WHERE " . $this->goal_value_condition( 'path', $goal ) . ')';
	}

	/**
	 * Matches a column against a goal value.
	 *
	 * @param string $column Column.
	 * @param array  $goal   Goal.
	 * @return string
	 */
	private function goal_value_condition( $column, array $goal ) {
		$v = (string) $goal['value'];
		switch ( $goal['match'] ) {
			case 'equals':
				return $this->db->prepare( "{$column} = %s", $v ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			case 'starts':
				return $this->db->prepare( "{$column} LIKE %s", $this->db->esc_like( $v ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			case 'ends':
				return $this->db->prepare( "{$column} LIKE %s", '%' . $this->db->esc_like( $v ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			case 'regex':
				return $this->db->prepare( "{$column} REGEXP %s", $v ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			default:
				return $this->db->prepare( "{$column} LIKE %s", '%' . $this->db->esc_like( $v ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	/**
	 * Event goal condition.
	 *
	 * @param array  $goal  Goal.
	 * @param string $alias Events alias.
	 * @return string
	 */
	private function goal_event_condition( array $goal, $alias ) {
		$conditions = array( $this->goal_value_condition( "{$alias}.name", $goal ) );
		if ( ! empty( $goal['category'] ) ) {
			$conditions[] = $this->db->prepare( "{$alias}.category = %s", $goal['category'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return implode( ' AND ', $conditions );
	}

	/**
	 * FROM + WHERE for session-based queries.
	 *
	 * @param Query    $q     Query.
	 * @param string[] $extra Extra conditions.
	 * @return string
	 */
	private function session_from( Query $q, array $extra = array() ) {
		return " FROM {$this->t['sessions']} s" . $this->where( array_merge( array( $this->range( 's.created_at', $q ) ), $this->session_conditions( $q, 'session' ), $extra ) );
	}

	/**
	 * FROM + WHERE for pageview-based queries (sessions joined as s).
	 *
	 * @param Query    $q          Query.
	 * @param string[] $extra      Extra conditions.
	 * @param bool     $join_pages Join pages as pg.
	 * @return string
	 */
	private function pageview_from( Query $q, array $extra = array(), $join_pages = false ) {
		$from = " FROM {$this->t['pageviews']} p INNER JOIN {$this->t['sessions']} s ON s.id = p.session_id";
		if ( $join_pages ) {
			$from .= " INNER JOIN {$this->t['pages']} pg ON pg.id = p.page_id";
		}
		return $from . $this->where( array_merge( array( $this->range( 'p.created_at', $q ) ), $this->page_conditions( $q, 'p.page_id' ), $this->session_conditions( $q, 'pageview' ), $extra ) );
	}

	/**
	 * Normalizes an aggregate row.
	 *
	 * @param array|null $row Raw row.
	 * @return array
	 */
	private function finalize( $row ) {
		$row       = is_array( $row ) ? $row : array();
		$visits    = isset( $row['visits'] ) ? (int) $row['visits'] : 0;
		$pageviews = isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0;
		$out       = array(
			'visitors'        => isset( $row['visitors'] ) ? (int) $row['visitors'] : 0,
			'visits'          => $visits,
			'pageviews'       => $pageviews,
			'views_per_visit' => $visits ? round( $pageviews / $visits, 2 ) : 0,
			'bounce_rate'     => null,
			'avg_duration'    => isset( $row['avg_duration'] ) ? (int) round( (float) $row['avg_duration'] ) : 0,
		);
		if ( isset( $row['bounces'] ) ) {
			$base               = isset( $row['entrances'] ) ? (int) $row['entrances'] : $visits;
			$out['bounce_rate'] = $base ? round( (int) $row['bounces'] / $base * 100, 1 ) : null;
		}
		return $out;
	}

	/* =================================================================== *
	 * Summary
	 * =================================================================== */

	/**
	 * Headline metrics with optional comparison.
	 *
	 * @param Query $q Query.
	 * @return array
	 */
	public function summary( Query $q ) {
		$previous = $q->previous();
		return array(
			'mode'       => $q->page_mode() ? 'page' : 'session',
			'current'    => $this->summary_for( $q ),
			'previous'   => $previous ? $this->summary_for( $previous ) : null,
			'range'      => array( $q->start, $q->end ),
			'prev_range' => $previous ? array( $previous->start, $previous->end ) : null,
		);
	}

	/**
	 * Headline metrics for one period.
	 *
	 * @param Query $q Query.
	 * @return array
	 */
	public function summary_for( Query $q ) {
		$memo_key = $q->cache_key( 'summary' );
		if ( ! isset( $this->memo[ $memo_key ] ) ) {
			$this->memo[ $memo_key ] = $this->compute_summary( $q );
		}
		return $this->memo[ $memo_key ];
	}

	/**
	 * Request-level memo for repeated totals.
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Computes headline metrics for one period.
	 *
	 * @param Query $q Query.
	 * @return array
	 */
	private function compute_summary( Query $q ) {
		if ( ! $q->page_mode() ) {
			$row = $this->db->get_row( 'SELECT ' . self::SESSION_METRICS . $this->session_from( $q ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return $this->finalize( $row );
		}

		$row = $this->db->get_row( 'SELECT ' . self::PAGE_METRICS . ', COALESCE(AVG(NULLIF(p.scroll_depth,0)),0) AS avg_scroll' . $this->pageview_from( $q ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = is_array( $row ) ? $row : array();

		$session_conditions = $this->session_conditions( $q, 'pageview' );
		$entries            = $this->db->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT COUNT(*) AS entrances, COALESCE(SUM(s.pageviews <= 1),0) AS bounces FROM {$this->t['sessions']} s" . $this->where( array_merge( array( $this->range( 's.created_at', $q ) ), $this->page_conditions( $q, 's.entry_page_id' ), $session_conditions ) ),
			ARRAY_A
		);
		$exits              = (int) $this->db->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"SELECT COUNT(*) FROM {$this->t['sessions']} s" . $this->where( array_merge( array( $this->range( 's.created_at', $q ) ), $this->page_conditions( $q, 's.exit_page_id' ), $session_conditions ) )
		);

		$row['entrances'] = isset( $entries['entrances'] ) ? (int) $entries['entrances'] : 0;
		$row['bounces']   = isset( $entries['bounces'] ) ? (int) $entries['bounces'] : 0;
		$out              = $this->finalize( $row );

		$out['entrances']  = $row['entrances'];
		$out['exits']      = $exits;
		$out['exit_rate']  = $out['pageviews'] ? round( min( $exits, $out['pageviews'] ) / $out['pageviews'] * 100, 1 ) : null;
		$out['avg_scroll'] = isset( $row['avg_scroll'] ) ? (int) round( (float) $row['avg_scroll'] ) : 0;
		return $out;
	}

	/* =================================================================== *
	 * Time series
	 * =================================================================== */

	/**
	 * Picks a sensible interval for the range.
	 *
	 * @param Query  $q        Query.
	 * @param string $interval Requested interval.
	 * @return string
	 */
	public function resolve_interval( Query $q, $interval ) {
		$days = $q->days();
		if ( ! in_array( $interval, array( 'hour', 'day', 'week', 'month' ), true ) ) {
			if ( $days <= 2 ) {
				$interval = 'hour';
			} elseif ( $days <= 92 ) {
				$interval = 'day';
			} elseif ( $days <= 400 ) {
				$interval = 'week';
			} else {
				$interval = 'month';
			}
		}
		if ( 'hour' === $interval && $days > 31 ) {
			$interval = 'day';
		}
		return $interval;
	}

	/**
	 * SQL expression bucketing a datetime column.
	 *
	 * @param string $column   Column.
	 * @param string $interval Interval.
	 * @return string
	 */
	private function bucket_sql( $column, $interval ) {
		switch ( $interval ) {
			case 'hour':
				return "DATE_FORMAT({$column}, '%Y-%m-%d %H:00')";
			case 'week':
				$sow = (int) get_option( 'start_of_week', 1 );
				return "DATE_FORMAT(DATE_SUB(DATE({$column}), INTERVAL ((DAYOFWEEK({$column}) + 6 - {$sow}) % 7) DAY), '%Y-%m-%d')";
			case 'month':
				return "DATE_FORMAT({$column}, '%Y-%m-01')";
			default:
				return "DATE_FORMAT({$column}, '%Y-%m-%d')";
		}
	}

	/**
	 * Bucket labels for a range (future buckets are omitted).
	 *
	 * @param Query  $q        Query.
	 * @param string $interval Interval.
	 * @return string[]
	 */
	public function buckets( Query $q, $interval ) {
		$labels   = array();
		$utc      = new \DateTimeZone( 'UTC' );
		$cursor   = \DateTime::createFromFormat( '!Y-m-d', $q->start, $utc );
		$end      = \DateTime::createFromFormat( '!Y-m-d', $q->end, $utc );
		$today    = Helpers::today();
		$now_hour = substr( Helpers::now(), 0, 13 ) . ':00';
		if ( ! $cursor || ! $end ) {
			return $labels;
		}
		switch ( $interval ) {
			case 'hour':
				while ( $cursor <= $end ) {
					for ( $h = 0; $h < 24; $h++ ) {
						$label = $cursor->format( 'Y-m-d' ) . sprintf( ' %02d:00', $h );
						if ( $label > $now_hour ) {
							break 2;
						}
						$labels[] = $label;
					}
					$cursor->modify( '+1 day' );
				}
				break;
			case 'week':
				$sow = (int) get_option( 'start_of_week', 1 );
				$dow = (int) $cursor->format( 'w' );
				$cursor->modify( '-' . ( ( $dow - $sow + 7 ) % 7 ) . ' days' );
				while ( $cursor <= $end && $cursor->format( 'Y-m-d' ) <= $today ) {
					$labels[] = $cursor->format( 'Y-m-d' );
					$cursor->modify( '+7 days' );
				}
				break;
			case 'month':
				$cursor->modify( 'first day of this month' );
				while ( $cursor <= $end && $cursor->format( 'Y-m-d' ) <= $today ) {
					$labels[] = $cursor->format( 'Y-m-01' );
					$cursor->modify( 'first day of next month' );
				}
				break;
			default:
				while ( $cursor <= $end ) {
					$label = $cursor->format( 'Y-m-d' );
					if ( $label > $today ) {
						break;
					}
					$labels[] = $label;
					$cursor->modify( '+1 day' );
				}
		}
		return $labels;
	}

	/**
	 * Metrics over time with optional comparison series.
	 *
	 * @param Query  $q        Query.
	 * @param string $interval Interval ('' = automatic).
	 * @return array
	 */
	public function timeseries( Query $q, $interval = '' ) {
		$interval = $this->resolve_interval( $q, $interval );
		$labels   = $this->buckets( $q, $interval );
		$result   = array(
			'interval'    => $interval,
			'mode'        => $q->page_mode() ? 'page' : 'session',
			'labels'      => $labels,
			'current'     => $this->series( $q, $interval, $labels ),
			'previous'    => null,
			'prev_labels' => null,
		);
		$previous = $q->previous();
		if ( $previous ) {
			// Compare full periods: the previous period is never "in the future".
			$prev_labels           = $this->buckets( $previous, $interval );
			$result['prev_labels'] = $prev_labels;
			$result['previous']    = $this->series( $previous, $interval, $prev_labels );
		}
		return $result;
	}

	/**
	 * One metric series set.
	 *
	 * @param Query    $q        Query.
	 * @param string   $interval Interval.
	 * @param string[] $labels   Bucket labels.
	 * @return array
	 */
	private function series( Query $q, $interval, array $labels ) {
		$rows = array();
		if ( ! $q->page_mode() ) {
			$sql = 'SELECT ' . $this->bucket_sql( 's.created_at', $interval ) . ' AS b, ' . self::SESSION_METRICS . $this->session_from( $q ) . ' GROUP BY b';
			foreach ( (array) $this->db->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows[ $row['b'] ] = $row;
			}
		} else {
			$sql = 'SELECT ' . $this->bucket_sql( 'p.created_at', $interval ) . ' AS b, ' . self::PAGE_METRICS . $this->pageview_from( $q ) . ' GROUP BY b';
			foreach ( (array) $this->db->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows[ $row['b'] ] = $row;
			}
			$entries = 'SELECT ' . $this->bucket_sql( 's.created_at', $interval ) . " AS b, COUNT(*) AS entrances, COALESCE(SUM(s.pageviews <= 1),0) AS bounces FROM {$this->t['sessions']} s"
				. $this->where( array_merge( array( $this->range( 's.created_at', $q ) ), $this->page_conditions( $q, 's.entry_page_id' ), $this->session_conditions( $q, 'pageview' ) ) ) . ' GROUP BY b';
			foreach ( (array) $this->db->get_results( $entries, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( ! isset( $rows[ $row['b'] ] ) ) {
					$rows[ $row['b'] ] = array();
				}
				$rows[ $row['b'] ]['entrances'] = $row['entrances'];
				$rows[ $row['b'] ]['bounces']   = $row['bounces'];
			}
		}

		$series = array(
			'visitors'        => array(),
			'visits'          => array(),
			'pageviews'       => array(),
			'views_per_visit' => array(),
			'bounce_rate'     => array(),
			'avg_duration'    => array(),
		);
		foreach ( $labels as $label ) {
			$metrics = $this->finalize( isset( $rows[ $label ] ) ? $rows[ $label ] : null );
			foreach ( $series as $key => $unused ) {
				$series[ $key ][] = $metrics[ $key ];
			}
		}
		return $series;
	}

	/**
	 * Visits per day for the top values of a dimension (e.g. channels over time).
	 *
	 * @param Query  $q         Query.
	 * @param string $dimension Session dimension.
	 * @param string $interval  Interval.
	 * @param int    $top       Number of series.
	 * @return array
	 */
	public function stacked( Query $q, $dimension, $interval = '', $top = 5 ) {
		$dims = $this->session_dims();
		if ( ! isset( $dims[ $dimension ] ) || 'continent' === $dimension ) {
			return array(
				'labels' => array(),
				'series' => array(),
			);
		}
		$interval  = $this->resolve_interval( $q, $interval );
		$labels    = $this->buckets( $q, $interval );
		$breakdown = $this->breakdown(
			$q,
			$dimension,
			array(
				'limit' => max( 1, min( 8, (int) $top ) ),
				'sort'  => 'visits',
			)
		);
		$keys      = wp_list_pluck( $breakdown['rows'], 'key' );
		$series    = array();
		if ( $keys ) {
			$key_sql = $dims[ $dimension ]['key'];
			$sql     = 'SELECT ' . $this->bucket_sql( 's.created_at', $interval ) . " AS b, {$key_sql} AS k, COUNT(*) AS visits" . $this->session_from( $q, array( "{$key_sql} IN (" . $this->in_list( $keys ) . ')' ) ) . ' GROUP BY b, k';
			$data    = array();
			foreach ( (array) $this->db->get_results( $sql, ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$data[ $row['k'] ][ $row['b'] ] = (int) $row['visits'];
			}
			foreach ( $breakdown['rows'] as $row ) {
				$values = array();
				foreach ( $labels as $label ) {
					$values[] = isset( $data[ $row['key'] ][ $label ] ) ? $data[ $row['key'] ][ $label ] : 0;
				}
				$series[] = array(
					'key'    => $row['key'],
					'label'  => $row['label'],
					'values' => $values,
				);
			}
		}
		return array(
			'interval' => $interval,
			'metric'   => 'visits',
			'labels'   => $labels,
			'series'   => $series,
		);
	}

	/* =================================================================== *
	 * Breakdowns
	 * =================================================================== */

	/**
	 * Top values of a dimension with metrics.
	 *
	 * @param Query  $q         Query.
	 * @param string $dimension Dimension.
	 * @param array  $args      limit, offset, sort, order, search, with_previous, with_total.
	 * @return array
	 */
	public function breakdown( Query $q, $dimension, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'         => 10,
				'offset'        => 0,
				'sort'          => '',
				'order'         => 'desc',
				'search'        => '',
				'with_previous' => false,
				'with_total'    => false,
			)
		);

		$args['limit']  = max( 1, min( 1000, (int) $args['limit'] ) );
		$args['offset'] = max( 0, (int) $args['offset'] );
		$args['order']  = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$args['search'] = Helpers::truncate( trim( (string) $args['search'] ), 200 );

		switch ( $this->dimension_type( $dimension ) ) {
			case 'session':
				$result = $this->breakdown_session( $q, $dimension, $args );
				break;
			case 'page':
				$result = $this->breakdown_pages( $q, $args );
				break;
			case 'entry_page':
				$result = $this->breakdown_entry_pages( $q, $args );
				break;
			case 'exit_page':
				$result = $this->breakdown_exit_pages( $q, $args );
				break;
			case 'content':
				$result = $this->breakdown_content( $q, $dimension, $args );
				break;
			case 'transition':
				$result = $this->breakdown_transitions( $q, $dimension, $args );
				break;
			case 'event':
				$result = $this->breakdown_events( $q, $dimension, $args );
				break;
			default:
				return array(
					'dimension' => $dimension,
					'metrics'   => array(),
					'rows'      => array(),
				);
		}
		$result['dimension'] = $dimension;

		if ( $args['with_previous'] ) {
			$previous = $q->previous();
			if ( $previous ) {
				$prev  = $this->breakdown(
					$previous,
					$dimension,
					array(
						'limit'  => 500,
						'search' => $args['search'],
					)
				);
				$index = array();
				foreach ( $prev['rows'] as $row ) {
					$index[ $this->row_id( $row ) ] = $row;
				}
				foreach ( $result['rows'] as &$row ) {
					$id          = $this->row_id( $row );
					$row['prev'] = isset( $index[ $id ] ) ? array_intersect_key( $index[ $id ], array_flip( $result['metrics'] ) ) : null;
				}
				unset( $row );
			}
		}
		return $result;
	}

	/**
	 * Identity of a breakdown row (for matching periods).
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private function row_id( array $row ) {
		return $row['key'] . '|' . ( isset( $row['country'] ) ? $row['country'] : '' ) . '|' . ( isset( $row['browser'] ) ? $row['browser'] : '' ) . '|' . ( isset( $row['os'] ) ? $row['os'] : '' ) . '|' . ( isset( $row['category'] ) ? $row['category'] : '' );
	}

	/**
	 * Converts a search term into a condition for a session dimension.
	 *
	 * @param string $dimension Dimension.
	 * @param string $key_sql   Key expression.
	 * @param string $search    Search term.
	 * @return string
	 */
	private function search_condition( $dimension, $key_sql, $search ) {
		if ( '' === $search ) {
			return '';
		}
		$labels = array();
		if ( 'country' === $dimension ) {
			foreach ( Countries::all() as $code => $data ) {
				$labels[ $code ] = $data[0];
			}
		} elseif ( 'channel' === $dimension ) {
			$labels = Referrer::channels();
		}
		if ( $labels ) {
			$keys = array();
			foreach ( $labels as $key => $label ) {
				if ( false !== stripos( $label, $search ) || false !== stripos( (string) $key, $search ) ) {
					$keys[] = $key;
				}
			}
			return $keys ? "{$key_sql} IN (" . $this->in_list( $keys ) . ')' : '1=0';
		}
		return $this->match( $key_sql, 'contains', $search );
	}

	/**
	 * ORDER BY expression for a metric.
	 *
	 * @param string   $sort    Requested metric.
	 * @param string[] $allowed Metric => SQL expression.
	 * @param string   $default Default metric.
	 * @param string   $order   ASC|DESC.
	 * @return string
	 */
	private function order_by( $sort, array $allowed, $default, $order ) {
		$expr = isset( $allowed[ $sort ] ) ? $allowed[ $sort ] : $allowed[ $default ];
		return " ORDER BY {$expr} {$order}, k ASC";
	}

	/**
	 * Session-dimension breakdown.
	 *
	 * @param Query  $q         Query.
	 * @param string $dimension Dimension.
	 * @param array  $args      Arguments.
	 * @return array
	 */
	private function breakdown_session( Query $q, $dimension, array $args ) {
		$def       = $this->session_dims()[ $dimension ];
		$key       = $def['key'];
		$group     = isset( $def['group'] ) ? $def['group'] : 'k';
		$extra_sel = isset( $def['select'] ) ? ', ' . $def['select'] : '';
		$page_mode = $q->page_mode();
		$goal_mode = $q->has( 'goal' );
		$limit     = $args['limit'];
		$offset    = $args['offset'];

		if ( 'continent' === $dimension ) {
			$limit  = 400;
			$offset = 0;
		}

		$conditions = array();
		if ( ! empty( $def['nonempty'] ) ) {
			$conditions[] = $def['nonempty'] . " <> ''";
		}
		$search = 'continent' === $dimension ? '' : $this->search_condition( $dimension, $key, $args['search'] );
		if ( '' !== $search ) {
			$conditions[] = $search;
		}

		if ( $page_mode ) {
			$metrics = array( 'visitors', 'visits', 'pageviews', 'avg_duration' );
			$from    = $this->pageview_from( $q, $conditions );
			$select  = "{$key} AS k{$extra_sel}, " . self::PAGE_METRICS;
			$sorts   = array(
				'visitors'     => 'visitors',
				'visits'       => 'visits',
				'pageviews'    => 'pageviews',
				'avg_duration' => 'avg_duration',
			);
		} else {
			$metrics = array( 'visitors', 'visits', 'pageviews', 'bounce_rate', 'avg_duration', 'views_per_visit' );
			$from    = $this->session_from( $q, $conditions );
			$select  = "{$key} AS k{$extra_sel}, " . self::SESSION_METRICS;
			$sorts   = array(
				'visitors'        => 'visitors',
				'visits'          => 'visits',
				'pageviews'       => 'pageviews',
				'avg_duration'    => 'avg_duration',
				'bounce_rate'     => '(bounces / visits)',
				'views_per_visit' => '(pageviews / visits)',
			);
		}

		$sql  = "SELECT {$select}{$from} GROUP BY {$group}" . $this->order_by( $args['sort'], $sorts, 'visitors', $args['order'] ) . $this->db->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		$raw  = (array) $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = array();
		foreach ( $raw as $r ) {
			$row = array_merge(
				array(
					'key'   => (string) $r['k'],
					'label' => $this->label( $dimension, $r ),
				),
				$this->finalize( $r )
			);
			foreach ( array( 'country', 'region', 'browser', 'os' ) as $extra ) {
				if ( isset( $r[ $extra ] ) ) {
					$row[ $extra ] = (string) $r[ $extra ];
				}
			}
			if ( isset( $r['lat'] ) && null !== $r['lat'] ) {
				$row['lat'] = round( (float) $r['lat'], 3 );
				$row['lon'] = round( (float) $r['lon'], 3 );
			}
			$rows[] = $row;
		}

		if ( 'continent' === $dimension ) {
			$rows = $this->fold_continents( $rows, $args );
		}

		// Conversion rates per row when a goal filter is active.
		if ( $goal_mode && $rows && 'continent' !== $dimension ) {
			$base       = $q->without( array( 'goal' ) );
			$keys       = wp_list_pluck( $rows, 'key' );
			$conditions = array( "{$key} IN (" . $this->in_list( $keys ) . ')' );
			$base_from  = $page_mode ? $this->pageview_from( $base, $conditions ) : $this->session_from( $base, $conditions );
			$totals     = array();
			foreach ( (array) $this->db->get_results( "SELECT {$key} AS k{$extra_sel}, COUNT(DISTINCT s.visitor_hash) AS visitors{$base_from} GROUP BY {$group}", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$totals[ $this->row_id( array_merge( $r, array( 'key' => (string) $r['k'] ) ) ) ] = (int) $r['visitors'];
			}
			foreach ( $rows as &$row ) {
				$total              = isset( $totals[ $this->row_id( $row ) ] ) ? $totals[ $this->row_id( $row ) ] : $row['visitors'];
				$row['conversions'] = $row['visitors'];
				$row['visitors']    = $total;
				$row['cr']          = $total ? round( $row['conversions'] / $total * 100, 1 ) : 0;
			}
			unset( $row );
			$metrics = array_merge( array( 'visitors', 'conversions', 'cr' ), array_diff( $metrics, array( 'visitors' ) ) );
		}

		$result = array(
			'metrics' => $metrics,
			'rows'    => $rows,
			'totals'  => $this->summary_for( $q ),
		);
		if ( $args['with_total'] ) {
			$group_expr           = 'k' === $group ? $key : $group;
			$result['total_rows'] = 'continent' === $dimension ? count( $rows ) : (int) $this->db->get_var( "SELECT COUNT(*) FROM (SELECT 1{$from} GROUP BY {$group_expr}) x" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $result;
	}

	/**
	 * Aggregates country rows into continents.
	 *
	 * @param array $rows Country rows.
	 * @param array $args Arguments.
	 * @return array
	 */
	private function fold_continents( array $rows, array $args ) {
		$names = Countries::continents();
		$out   = array();
		foreach ( $rows as $row ) {
			$code = Countries::continent( $row['key'] );
			if ( ! isset( $out[ $code ] ) ) {
				$out[ $code ] = array(
					'key'          => $code,
					'label'        => isset( $names[ $code ] ) ? $names[ $code ] : __( 'Unknown', 'pulse-analytics' ),
					'visitors'     => 0,
					'visits'       => 0,
					'pageviews'    => 0,
					'duration_sum' => 0,
					'bounces'      => 0,
				);
			}
			$out[ $code ]['visitors']     += $row['visitors'];
			$out[ $code ]['visits']       += $row['visits'];
			$out[ $code ]['pageviews']    += $row['pageviews'];
			$out[ $code ]['duration_sum'] += $row['avg_duration'] * $row['visits'];
			$out[ $code ]['bounces']      += null === $row['bounce_rate'] ? 0 : $row['bounce_rate'] / 100 * $row['visits'];
		}
		foreach ( $out as &$row ) {
			$row['views_per_visit'] = $row['visits'] ? round( $row['pageviews'] / $row['visits'], 2 ) : 0;
			$row['avg_duration']    = $row['visits'] ? (int) round( $row['duration_sum'] / $row['visits'] ) : 0;
			$row['bounce_rate']     = $row['visits'] ? round( $row['bounces'] / $row['visits'] * 100, 1 ) : null;
			unset( $row['duration_sum'], $row['bounces'] );
		}
		unset( $row );
		$out = array_values( $out );
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['visitors'] - $a['visitors'];
			}
		);
		if ( '' !== $args['search'] ) {
			$out = array_values(
				array_filter(
					$out,
					static function ( $row ) use ( $args ) {
						return false !== stripos( $row['label'], $args['search'] );
					}
				)
			);
		}
		return array_slice( $out, $args['offset'], $args['limit'] );
	}

	/**
	 * Page details keyed by id.
	 *
	 * @param int[] $ids Page ids.
	 * @return array
	 */
	private function pages_by_id( array $ids ) {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		foreach ( (array) $this->db->get_results( "SELECT id, path, title, post_id, post_type FROM {$this->t['pages']} WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Row base for a page.
	 *
	 * @param int   $id    Page id.
	 * @param array $pages Page details.
	 * @return array
	 */
	private function page_row( $id, array $pages ) {
		$page = isset( $pages[ $id ] ) ? $pages[ $id ] : null;
		if ( ! $page ) {
			return array(
				'key'     => '',
				'label'   => __( '(unknown page)', 'pulse-analytics' ),
				'page_id' => (int) $id,
			);
		}
		return array(
			'key'       => (string) $page['path'],
			'label'     => '' !== (string) $page['title'] ? (string) $page['title'] : (string) $page['path'],
			'page_id'   => (int) $page['id'],
			'post_id'   => (int) $page['post_id'],
			'post_type' => (string) $page['post_type'],
		);
	}

	/**
	 * Search condition on a page-id column.
	 *
	 * @param string $column Column.
	 * @param string $search Term.
	 * @return string
	 */
	private function page_search( $column, $search ) {
		if ( '' === $search ) {
			return '';
		}
		$like = '%' . $this->db->esc_like( $search ) . '%';
		return "{$column} IN (SELECT id FROM {$this->t['pages']} WHERE " . $this->db->prepare( 'path LIKE %s OR title LIKE %s', $like, $like ) . ')';
	}

	/**
	 * Top pages.
	 *
	 * @param Query $q    Query.
	 * @param array $args Arguments.
	 * @return array
	 */
	private function breakdown_pages( Query $q, array $args ) {
		$from  = $this->pageview_from( $q, array( $this->page_search( 'p.page_id', $args['search'] ) ) );
		$sorts = array(
			'pageviews'  => 'pageviews',
			'visitors'   => 'visitors',
			'avg_time'   => 'avg_time',
			'avg_scroll' => 'avg_scroll',
		);
		$sql   = 'SELECT p.page_id AS k, COUNT(*) AS pageviews, COUNT(DISTINCT s.visitor_hash) AS visitors, COALESCE(AVG(NULLIF(p.duration,0)),0) AS avg_time, COALESCE(AVG(NULLIF(p.scroll_depth,0)),0) AS avg_scroll'
			. $from . ' GROUP BY p.page_id' . $this->order_by( $args['sort'], $sorts, 'pageviews', $args['order'] ) . $this->db->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		$raw   = (array) $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids   = array_map( 'intval', wp_list_pluck( $raw, 'k' ) );
		$pages = $this->pages_by_id( $ids );

		$entries = array();
		$exits   = array();
		if ( $ids ) {
			$id_list    = implode( ',', $ids );
			$conditions = array_merge( array( $this->range( 's.created_at', $q ) ), $this->session_conditions( $q, 'pageview' ) );
			foreach ( (array) $this->db->get_results( "SELECT s.entry_page_id AS k, COUNT(*) AS entrances, COALESCE(SUM(s.pageviews <= 1),0) AS bounces FROM {$this->t['sessions']} s" . $this->where( array_merge( $conditions, array( "s.entry_page_id IN ({$id_list})" ) ) ) . ' GROUP BY s.entry_page_id', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$entries[ (int) $r['k'] ] = $r;
			}
			foreach ( (array) $this->db->get_results( "SELECT s.exit_page_id AS k, COUNT(*) AS exits FROM {$this->t['sessions']} s" . $this->where( array_merge( $conditions, array( "s.exit_page_id IN ({$id_list})" ) ) ) . ' GROUP BY s.exit_page_id', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$exits[ (int) $r['k'] ] = (int) $r['exits'];
			}
		}

		$rows = array();
		foreach ( $raw as $r ) {
			$id        = (int) $r['k'];
			$pageviews = (int) $r['pageviews'];
			$entrances = isset( $entries[ $id ] ) ? (int) $entries[ $id ]['entrances'] : 0;
			$bounces   = isset( $entries[ $id ] ) ? (int) $entries[ $id ]['bounces'] : 0;
			$exit      = isset( $exits[ $id ] ) ? $exits[ $id ] : 0;
			$rows[]    = array_merge(
				$this->page_row( $id, $pages ),
				array(
					'visitors'    => (int) $r['visitors'],
					'pageviews'   => $pageviews,
					'avg_time'    => (int) round( (float) $r['avg_time'] ),
					'avg_scroll'  => (int) round( (float) $r['avg_scroll'] ),
					'entrances'   => $entrances,
					'bounce_rate' => $entrances ? round( $bounces / $entrances * 100, 1 ) : null,
					'exits'       => $exit,
					'exit_rate'   => $pageviews ? round( min( $exit, $pageviews ) / $pageviews * 100, 1 ) : null,
				)
			);
		}

		$result = array(
			'metrics' => array( 'visitors', 'pageviews', 'avg_time', 'avg_scroll', 'entrances', 'bounce_rate', 'exits', 'exit_rate' ),
			'rows'    => $rows,
			'totals'  => $this->summary_for( $q ),
		);
		if ( $args['with_total'] ) {
			$result['total_rows'] = (int) $this->db->get_var( 'SELECT COUNT(DISTINCT p.page_id)' . $from ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $result;
	}

	/**
	 * Landing pages.
	 *
	 * @param Query $q    Query.
	 * @param array $args Arguments.
	 * @return array
	 */
	private function breakdown_entry_pages( Query $q, array $args ) {
		$from  = $this->session_from( $q, array( $this->page_search( 's.entry_page_id', $args['search'] ) ) );
		$sorts = array(
			'visits'       => 'visits',
			'visitors'     => 'visitors',
			'bounce_rate'  => '(bounces / visits)',
			'avg_duration' => 'avg_duration',
		);
		$sql   = 'SELECT s.entry_page_id AS k, ' . self::SESSION_METRICS . $from . ' GROUP BY s.entry_page_id' . $this->order_by( $args['sort'], $sorts, 'visits', $args['order'] ) . $this->db->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		$raw   = (array) $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$pages = $this->pages_by_id( wp_list_pluck( $raw, 'k' ) );
		$rows  = array();
		foreach ( $raw as $r ) {
			$rows[] = array_merge( $this->page_row( (int) $r['k'], $pages ), $this->finalize( $r ) );
		}
		$result = array(
			'metrics' => array( 'visits', 'visitors', 'bounce_rate', 'avg_duration', 'views_per_visit' ),
			'rows'    => $rows,
			'totals'  => $this->summary_for( $q->without( array( 'page', 'post_type' ) ) ),
		);
		if ( $args['with_total'] ) {
			$result['total_rows'] = (int) $this->db->get_var( 'SELECT COUNT(DISTINCT s.entry_page_id)' . $from ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $result;
	}

	/**
	 * Exit pages.
	 *
	 * @param Query $q    Query.
	 * @param array $args Arguments.
	 * @return array
	 */
	private function breakdown_exit_pages( Query $q, array $args ) {
		$from  = $this->session_from( $q, array( $this->page_search( 's.exit_page_id', $args['search'] ) ) );
		$sorts = array(
			'exits'    => 'exits',
			'visitors' => 'visitors',
		);
		$sql   = 'SELECT s.exit_page_id AS k, COUNT(*) AS exits, COUNT(DISTINCT s.visitor_hash) AS visitors' . $from . ' GROUP BY s.exit_page_id' . $this->order_by( $args['sort'], $sorts, 'exits', $args['order'] ) . $this->db->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		$raw   = (array) $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids   = array_map( 'intval', wp_list_pluck( $raw, 'k' ) );
		$pages = $this->pages_by_id( $ids );
		$views = array();
		if ( $ids ) {
			$sql = 'SELECT p.page_id AS k, COUNT(*) AS pageviews' . $this->pageview_from( $q->without( array( 'page', 'post_type' ) ), array( 'p.page_id IN (' . implode( ',', $ids ) . ')' ) ) . ' GROUP BY p.page_id';
			foreach ( (array) $this->db->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$views[ (int) $r['k'] ] = (int) $r['pageviews'];
			}
		}
		$rows = array();
		foreach ( $raw as $r ) {
			$id     = (int) $r['k'];
			$exits  = (int) $r['exits'];
			$pv     = isset( $views[ $id ] ) ? $views[ $id ] : 0;
			$rows[] = array_merge(
				$this->page_row( $id, $pages ),
				array(
					'exits'     => $exits,
					'visitors'  => (int) $r['visitors'],
					'pageviews' => $pv,
					'exit_rate' => $pv ? round( min( $exits, $pv ) / $pv * 100, 1 ) : null,
				)
			);
		}
		$result = array(
			'metrics' => array( 'exits', 'visitors', 'pageviews', 'exit_rate' ),
			'rows'    => $rows,
			'totals'  => $this->summary_for( $q->without( array( 'page', 'post_type' ) ) ),
		);
		if ( $args['with_total'] ) {
			$result['total_rows'] = (int) $this->db->get_var( 'SELECT COUNT(DISTINCT s.exit_page_id)' . $from ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $result;
	}

	/**
	 * Content groupings: post type, author, category.
	 *
	 * @param Query  $q         Query.
	 * @param string $dimension Dimension.
	 * @param array  $args      Arguments.
	 * @return array
	 */
	private function breakdown_content( Query $q, $dimension, array $args ) {
		global $wpdb;
		$joins = '';
		if ( 'post_type' === $dimension ) {
			$key = 'pg.post_type';
		} elseif ( 'author' === $dimension ) {
			$key   = 'wp.post_author';
			$joins = " INNER JOIN {$wpdb->posts} wp ON wp.ID = pg.post_id";
		} else {
			$key   = 'tt.term_id';
			$joins = " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = pg.post_id INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'category'";
		}

		$base = $this->pageview_from( $q, array(), true );
		$from = preg_replace( '/ WHERE /', $joins . ' WHERE ', $base, 1 );
		if ( 'post_type' !== $dimension ) {
			$from .= ' AND pg.post_id > 0';
		}
		$sorts = array(
			'pageviews' => 'pageviews',
			'visitors'  => 'visitors',
			'avg_time'  => 'avg_time',
		);
		$sql   = "SELECT {$key} AS k, COUNT(*) AS pageviews, COUNT(DISTINCT s.visitor_hash) AS visitors, COALESCE(AVG(NULLIF(p.duration,0)),0) AS avg_time" . $from . " GROUP BY {$key}" . $this->order_by( $args['sort'], $sorts, 'pageviews', $args['order'] ) . $this->db->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		$raw   = (array) $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows = array();
		foreach ( $raw as $r ) {
			$rows[] = array(
				'key'       => (string) $r['k'],
				'label'     => $this->content_label( $dimension, (string) $r['k'] ),
				'pageviews' => (int) $r['pageviews'],
				'visitors'  => (int) $r['visitors'],
				'avg_time'  => (int) round( (float) $r['avg_time'] ),
			);
		}
		$result = array(
			'metrics' => array( 'pageviews', 'visitors', 'avg_time' ),
			'rows'    => $rows,
			'totals'  => $this->summary_for( $q ),
		);
		if ( $args['with_total'] ) {
			$result['total_rows'] = (int) $this->db->get_var( "SELECT COUNT(DISTINCT {$key})" . $from ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $result;
	}

	/**
	 * Previous / next page transitions for the filtered page(s).
	 *
	 * @param Query  $q         Query.
	 * @param string $dimension prev_page or next_page.
	 * @param array  $args      Arguments.
	 * @return array
	 */
	private function breakdown_transitions( Query $q, $dimension, array $args ) {
		$result = array(
			'metrics' => array( 'pageviews', 'visitors' ),
			'rows'    => array(),
		);
		if ( ! $q->page_mode() ) {
			return $result;
		}
		if ( 'prev_page' === $dimension ) {
			$sql = 'SELECT p.prev_page_id AS k, COUNT(*) AS pageviews, COUNT(DISTINCT s.visitor_hash) AS visitors' . $this->pageview_from( $q ) . ' GROUP BY p.prev_page_id ORDER BY pageviews DESC, k ASC' . $this->db->prepare( ' LIMIT %d', $args['limit'] );
		} else {
			$conditions = array_merge( array( $this->range( 'p.created_at', $q ) ), $this->page_conditions( $q, 'p.prev_page_id' ), $this->session_conditions( $q, 'pageview' ) );
			$sql        = "SELECT p.page_id AS k, COUNT(*) AS pageviews, COUNT(DISTINCT s.visitor_hash) AS visitors FROM {$this->t['pageviews']} p INNER JOIN {$this->t['sessions']} s ON s.id = p.session_id" . $this->where( $conditions ) . ' GROUP BY p.page_id ORDER BY pageviews DESC, k ASC' . $this->db->prepare( ' LIMIT %d', $args['limit'] );
		}
		$raw   = (array) $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$pages = $this->pages_by_id( wp_list_pluck( $raw, 'k' ) );
		foreach ( $raw as $r ) {
			$id = (int) $r['k'];
			if ( 0 === $id ) {
				$row = array(
					'key'     => '',
					'label'   => __( '(entrance, no previous page)', 'pulse-analytics' ),
					'special' => 'entrance',
				);
			} else {
				$row = $this->page_row( $id, $pages );
			}
			$result['rows'][] = array_merge(
				$row,
				array(
					'pageviews' => (int) $r['pageviews'],
					'visitors'  => (int) $r['visitors'],
				)
			);
		}
		if ( 'next_page' === $dimension ) {
			$exits = (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->t['sessions']} s" . $this->where( array_merge( array( $this->range( 's.created_at', $q ) ), $this->page_conditions( $q, 's.exit_page_id' ), $this->session_conditions( $q, 'pageview' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $exits ) {
				$result['rows'][] = array(
					'key'       => '',
					'label'     => __( '(exit, left the website)', 'pulse-analytics' ),
					'special'   => 'exit',
					'pageviews' => $exits,
					'visitors'  => 0,
				);
				usort(
					$result['rows'],
					static function ( $a, $b ) {
						return $b['pageviews'] - $a['pageviews'];
					}
				);
			}
		}
		$total            = array_sum( wp_list_pluck( $result['rows'], 'pageviews' ) );
		$result['totals'] = array( 'pageviews' => $total );
		return $result;
	}

	/**
	 * Event breakdowns.
	 *
	 * @param Query  $q         Query.
	 * @param string $dimension event_category, event_name or event_label.
	 * @param array  $args      Arguments.
	 * @return array
	 */
	private function breakdown_events( Query $q, $dimension, array $args ) {
		$conditions = array_merge( array( $this->range( 'e.created_at', $q ) ), $this->event_conditions( $q, 'e' ), $this->session_conditions( $q, 'event' ) );
		if ( 'event_category' === $dimension ) {
			$key   = 'e.category';
			$group = 'e.category';
			$extra = '';
		} elseif ( 'event_name' === $dimension ) {
			$key   = 'e.name';
			$group = 'e.category, e.name';
			$extra = ', e.category AS category';
		} else {
			$key          = 'e.label';
			$group        = 'e.label';
			$extra        = '';
			$conditions[] = "e.label <> ''";
		}
		if ( '' !== $args['search'] ) {
			$conditions[] = $this->match( $key, 'contains', $args['search'] );
		}
		$from  = " FROM {$this->t['events']} e INNER JOIN {$this->t['sessions']} s ON s.id = e.session_id" . $this->where( $conditions );
		$sorts = array(
			'events'   => 'events',
			'visitors' => 'visitors',
			'visits'   => 'visits',
		);
		$sql   = "SELECT {$key} AS k{$extra}, COUNT(*) AS events, COUNT(DISTINCT s.visitor_hash) AS visitors, COUNT(DISTINCT e.session_id) AS visits" . $from . " GROUP BY {$group}" . $this->order_by( $args['sort'], $sorts, 'events', $args['order'] ) . $this->db->prepare( ' LIMIT %d OFFSET %d', $args['limit'], $args['offset'] );
		$rows  = array();
		$cats  = self::event_categories();
		foreach ( (array) $this->db->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$k   = (string) $r['k'];
			$row = array(
				'key'      => $k,
				'label'    => 'event_category' === $dimension && isset( $cats[ $k ] ) ? $cats[ $k ] : $k,
				'events'   => (int) $r['events'],
				'visitors' => (int) $r['visitors'],
				'visits'   => (int) $r['visits'],
			);
			if ( isset( $r['category'] ) ) {
				$row['category'] = (string) $r['category'];
			}
			$rows[] = $row;
		}
		$totals = $this->db->get_row( 'SELECT COUNT(*) AS events, COUNT(DISTINCT s.visitor_hash) AS visitors' . $from, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = array(
			'metrics' => array( 'events', 'visitors', 'visits' ),
			'rows'    => $rows,
			'totals'  => array(
				'events'   => isset( $totals['events'] ) ? (int) $totals['events'] : 0,
				'visitors' => isset( $totals['visitors'] ) ? (int) $totals['visitors'] : 0,
			),
		);
		if ( $args['with_total'] ) {
			$result['total_rows'] = (int) $this->db->get_var( "SELECT COUNT(*) FROM (SELECT 1{$from} GROUP BY {$group}) x" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		return $result;
	}

	/**
	 * Event category labels.
	 *
	 * @return array<string,string>
	 */
	public static function event_categories() {
		return array(
			'outbound' => __( 'Outbound links', 'pulse-analytics' ),
			'download' => __( 'File downloads', 'pulse-analytics' ),
			'form'     => __( 'Form submissions', 'pulse-analytics' ),
			'email'    => __( 'Email link clicks', 'pulse-analytics' ),
			'phone'    => __( 'Phone link clicks', 'pulse-analytics' ),
			'search'   => __( 'Site searches', 'pulse-analytics' ),
			'404'      => __( '404 errors', 'pulse-analytics' ),
			'custom'   => __( 'Custom events', 'pulse-analytics' ),
		);
	}

	/**
	 * Human label for a breakdown value.
	 *
	 * @param string $dimension Dimension.
	 * @param array  $row       Raw row (k plus extras).
	 * @return string
	 */
	private function label( $dimension, array $row ) {
		$k = (string) $row['k'];
		switch ( $dimension ) {
			case 'country':
				return Countries::name( $k );
			case 'channel':
				$channels = Referrer::channels();
				return isset( $channels[ $k ] ) ? $channels[ $k ] : ( '' === $k ? __( 'Unknown', 'pulse-analytics' ) : $k );
			case 'device':
				$devices = array(
					'desktop' => __( 'Desktop', 'pulse-analytics' ),
					'mobile'  => __( 'Mobile', 'pulse-analytics' ),
					'tablet'  => __( 'Tablet', 'pulse-analytics' ),
					'tv'      => __( 'TV', 'pulse-analytics' ),
				);
				return isset( $devices[ $k ] ) ? $devices[ $k ] : __( 'Unknown', 'pulse-analytics' );
			case 'visitor_type':
				return 'new' === $k ? __( 'New visitors', 'pulse-analytics' ) : __( 'Returning visitors', 'pulse-analytics' );
			case 'user_type':
				return 'logged_in' === $k ? __( 'Logged-in users', 'pulse-analytics' ) : __( 'Guests', 'pulse-analytics' );
			case 'source':
				return '' === $k ? __( 'Direct / None', 'pulse-analytics' ) : $k;
			case 'browser_version':
				return trim( ( isset( $row['browser'] ) ? $row['browser'] : '' ) . ' ' . ( '' === $k ? __( '(unknown version)', 'pulse-analytics' ) : $k ) );
			case 'os_version':
				return trim( ( isset( $row['os'] ) ? $row['os'] : '' ) . ' ' . $k );
			default:
				return '' === $k ? __( '(not set)', 'pulse-analytics' ) : $k;
		}
	}

	/**
	 * Label for content groupings.
	 *
	 * @param string $dimension Dimension.
	 * @param string $key       Key.
	 * @return string
	 */
	private function content_label( $dimension, $key ) {
		if ( 'author' === $dimension ) {
			$user = get_userdata( (int) $key );
			return $user ? $user->display_name : __( '(unknown author)', 'pulse-analytics' );
		}
		if ( 'category' === $dimension ) {
			$term = get_term( (int) $key, 'category' );
			return $term && ! is_wp_error( $term ) ? $term->name : __( '(unknown category)', 'pulse-analytics' );
		}
		$contexts = array(
			'front'    => __( 'Front page', 'pulse-analytics' ),
			'blog'     => __( 'Blog index', 'pulse-analytics' ),
			'category' => __( 'Category archives', 'pulse-analytics' ),
			'tag'      => __( 'Tag archives', 'pulse-analytics' ),
			'taxonomy' => __( 'Taxonomy archives', 'pulse-analytics' ),
			'author'   => __( 'Author archives', 'pulse-analytics' ),
			'archive'  => __( 'Archives', 'pulse-analytics' ),
			'date'     => __( 'Date archives', 'pulse-analytics' ),
			'search'   => __( 'Search results', 'pulse-analytics' ),
			'404'      => __( '404 pages', 'pulse-analytics' ),
			'other'    => __( 'Other', 'pulse-analytics' ),
			''         => __( '(not set)', 'pulse-analytics' ),
		);
		if ( isset( $contexts[ $key ] ) ) {
			return $contexts[ $key ];
		}
		$object = get_post_type_object( $key );
		return $object ? $object->labels->singular_name : $key;
	}

	/* =================================================================== *
	 * Other reports
	 * =================================================================== */

	/**
	 * Real-time activity (last 30 minutes).
	 *
	 * @param Query $q Query (filters only; the range is replaced).
	 * @return array
	 */
	public function realtime( Query $q ) {
		$q         = $q->with_range( Helpers::today( -1 ), Helpers::today() );
		$since5    = Helpers::now( -5 * MINUTE_IN_SECONDS );
		$since30   = Helpers::now( -30 * MINUTE_IN_SECONDS );
		$s_conds   = $this->session_conditions( $q, 'session' );
		$s_where5  = $this->where( array_merge( array( $this->db->prepare( 's.updated_at >= %s', $since5 ) ), $s_conds ) );
		$s_where30 = $this->where( array_merge( array( $this->db->prepare( 's.updated_at >= %s', $since30 ) ), $s_conds ) );
		$p_conds   = array_merge( array( $this->db->prepare( 'p.created_at >= %s', $since30 ) ), $this->page_conditions( $q, 'p.page_id' ), $this->session_conditions( $q, 'pageview' ) );
		$p_from    = " FROM {$this->t['pageviews']} p INNER JOIN {$this->t['sessions']} s ON s.id = p.session_id" . $this->where( $p_conds );

		$active   = (int) $this->db->get_var( "SELECT COUNT(DISTINCT s.visitor_hash) FROM {$this->t['sessions']} s" . $s_where5 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$active30 = (int) $this->db->get_var( "SELECT COUNT(DISTINCT s.visitor_hash) FROM {$this->t['sessions']} s" . $s_where30 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$per_minute = array();
		foreach ( (array) $this->db->get_results( "SELECT DATE_FORMAT(p.created_at, '%Y-%m-%d %H:%i') AS m, COUNT(*) AS pageviews, COUNT(DISTINCT s.visitor_hash) AS visitors" . $p_from . ' GROUP BY m', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$per_minute[ $r['m'] ] = $r;
		}
		$minutes = array();
		$total   = 0;
		for ( $i = 29; $i >= 0; $i-- ) {
			$label     = substr( Helpers::now( -$i * MINUTE_IN_SECONDS ), 0, 16 );
			$pageviews = isset( $per_minute[ $label ] ) ? (int) $per_minute[ $label ]['pageviews'] : 0;
			$minutes[] = array(
				'time'      => substr( $label, 11 ),
				'ago'       => $i,
				'pageviews' => $pageviews,
				'visitors'  => isset( $per_minute[ $label ] ) ? (int) $per_minute[ $label ]['visitors'] : 0,
			);
			$total    += $pageviews;
		}

		$pages_raw = (array) $this->db->get_results( 'SELECT p.page_id AS k, COUNT(DISTINCT s.visitor_hash) AS visitors, COUNT(*) AS pageviews' . $p_from . ' GROUP BY p.page_id ORDER BY visitors DESC, pageviews DESC LIMIT 10', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$page_info = $this->pages_by_id( wp_list_pluck( $pages_raw, 'k' ) );
		$pages     = array();
		foreach ( $pages_raw as $r ) {
			$pages[] = array_merge(
				$this->page_row( (int) $r['k'], $page_info ),
				array(
					'visitors'  => (int) $r['visitors'],
					'pageviews' => (int) $r['pageviews'],
				)
			);
		}

		$group = function ( $column, $dimension ) use ( $s_where30 ) {
			$rows = array();
			foreach ( (array) $this->db->get_results( "SELECT {$column} AS k, COUNT(DISTINCT s.visitor_hash) AS visitors FROM {$this->t['sessions']} s" . $s_where30 . ' GROUP BY k ORDER BY visitors DESC LIMIT 8', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$rows[] = array(
					'key'      => (string) $r['k'],
					'label'    => $this->label( $dimension, $r ),
					'visitors' => (int) $r['visitors'],
				);
			}
			return $rows;
		};

		$recent = array();
		$sql    = 'SELECT p.id, p.created_at, pg.path, pg.title, s.country, s.city, s.device, s.browser, s.os, s.source, s.channel, s.is_new'
			. " FROM {$this->t['pageviews']} p INNER JOIN {$this->t['sessions']} s ON s.id = p.session_id LEFT JOIN {$this->t['pages']} pg ON pg.id = p.page_id"
			. $this->where( $p_conds ) . ' ORDER BY p.id DESC LIMIT 20';
		$now_ts = strtotime( Helpers::now() );
		foreach ( (array) $this->db->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$recent[] = array(
				'time'    => $r['created_at'],
				'ago'     => max( 0, $now_ts - strtotime( $r['created_at'] ) ),
				'path'    => (string) $r['path'],
				'title'   => (string) $r['title'],
				'country' => (string) $r['country'],
				'city'    => (string) $r['city'],
				'device'  => (string) $r['device'],
				'browser' => (string) $r['browser'],
				'os'      => (string) $r['os'],
				'source'  => (string) $r['source'],
				'channel' => (string) $r['channel'],
				'is_new'  => (int) $r['is_new'],
			);
		}

		return array(
			'now'         => Helpers::now(),
			'active'      => $active,
			'active30'    => $active30,
			'pageviews30' => $total,
			'minutes'     => $minutes,
			'pages'       => $pages,
			'sources'     => $group( 's.source', 'source' ),
			'countries'   => $group( 's.country', 'country' ),
			'devices'     => $group( 's.device', 'device' ),
			'recent'      => $recent,
		);
	}

	/**
	 * Visitors active in the last five minutes (header badge).
	 *
	 * @return int
	 */
	public function active_visitors() {
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(DISTINCT visitor_hash) FROM {$this->t['sessions']} WHERE updated_at >= %s", Helpers::now( -5 * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Visitor log: recent sessions.
	 *
	 * @param Query $q      Query.
	 * @param int   $limit  Page size.
	 * @param int   $offset Offset.
	 * @return array
	 */
	public function sessions( Query $q, $limit = 25, $offset = 0 ) {
		$limit  = max( 1, min( 200, (int) $limit ) );
		$offset = max( 0, (int) $offset );
		$sql    = 'SELECT s.id, s.created_at, s.updated_at, s.duration, s.pageviews, s.events, s.is_new, s.country, s.region, s.city, s.device, s.browser, s.browser_version, s.os, s.os_version, s.screen, s.language, s.channel, s.source, s.referrer_url, s.utm_source, s.utm_medium, s.utm_campaign, s.utm_term, s.utm_content, s.user_id, s.ip,'
			. ' ep.path AS entry_path, ep.title AS entry_title, xp.path AS exit_path, xp.title AS exit_title'
			. " FROM {$this->t['sessions']} s LEFT JOIN {$this->t['pages']} ep ON ep.id = s.entry_page_id LEFT JOIN {$this->t['pages']} xp ON xp.id = s.exit_page_id"
			. $this->where( array_merge( array( $this->range( 's.created_at', $q ) ), $this->session_conditions( $q, 'session' ) ) )
			. ' ORDER BY s.created_at DESC, s.id DESC' . $this->db->prepare( ' LIMIT %d OFFSET %d', $limit + 1, $offset );
		$raw    = (array) $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$more   = count( $raw ) > $limit;
		$raw    = array_slice( $raw, 0, $limit );
		return array(
			'rows'     => array_map( array( $this, 'format_session' ), $raw ),
			'has_more' => $more,
		);
	}

	/**
	 * Normalizes a session row for output.
	 *
	 * @param array $r Row.
	 * @return array
	 */
	public function format_session( array $r ) {
		$user = null;
		if ( ! empty( $r['user_id'] ) ) {
			$data = get_userdata( (int) $r['user_id'] );
			$user = $data ? $data->display_name : '#' . (int) $r['user_id'];
		}
		$channels = Referrer::channels();
		return array(
			'id'              => (int) $r['id'],
			'created_at'      => $r['created_at'],
			'updated_at'      => $r['updated_at'],
			'duration'        => (int) $r['duration'],
			'pageviews'       => (int) $r['pageviews'],
			'events'          => (int) $r['events'],
			'is_new'          => (int) $r['is_new'],
			'country'         => (string) $r['country'],
			'country_name'    => '' === (string) $r['country'] ? '' : Countries::name( $r['country'] ),
			'region'          => (string) $r['region'],
			'city'            => (string) $r['city'],
			'device'          => (string) $r['device'],
			'browser'         => (string) $r['browser'],
			'browser_version' => (string) $r['browser_version'],
			'os'              => (string) $r['os'],
			'os_version'      => (string) $r['os_version'],
			'screen'          => (string) $r['screen'],
			'language'        => (string) $r['language'],
			'channel'         => (string) $r['channel'],
			'channel_label'   => isset( $channels[ $r['channel'] ] ) ? $channels[ $r['channel'] ] : (string) $r['channel'],
			'source'          => (string) $r['source'],
			'referrer_url'    => (string) $r['referrer_url'],
			'utm_source'      => (string) $r['utm_source'],
			'utm_medium'      => (string) $r['utm_medium'],
			'utm_campaign'    => (string) $r['utm_campaign'],
			'utm_term'        => isset( $r['utm_term'] ) ? (string) $r['utm_term'] : '',
			'utm_content'     => isset( $r['utm_content'] ) ? (string) $r['utm_content'] : '',
			'entry_path'      => isset( $r['entry_path'] ) ? (string) $r['entry_path'] : '',
			'entry_title'     => isset( $r['entry_title'] ) ? (string) $r['entry_title'] : '',
			'exit_path'       => isset( $r['exit_path'] ) ? (string) $r['exit_path'] : '',
			'exit_title'      => isset( $r['exit_title'] ) ? (string) $r['exit_title'] : '',
			'user'            => $user,
			'ip'              => '' !== (string) $r['ip'] ? (string) $r['ip'] : null,
		);
	}

	/**
	 * One session with its timeline of pageviews and events.
	 *
	 * @param int $id Session id.
	 * @return array|null
	 */
	public function session_detail( $id ) {
		$id  = (int) $id;
		$row = $this->db->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->db->prepare(
				"SELECT s.*, ep.path AS entry_path, ep.title AS entry_title, xp.path AS exit_path, xp.title AS exit_title FROM {$this->t['sessions']} s LEFT JOIN {$this->t['pages']} ep ON ep.id = s.entry_page_id LEFT JOIN {$this->t['pages']} xp ON xp.id = s.exit_page_id WHERE s.id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$timeline = array();
		$views    = (array) $this->db->get_results( $this->db->prepare( "SELECT p.id, p.created_at, p.duration, p.scroll_depth, pg.path, pg.title FROM {$this->t['pageviews']} p LEFT JOIN {$this->t['pages']} pg ON pg.id = p.page_id WHERE p.session_id = %d ORDER BY p.id ASC LIMIT 500", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $views as $v ) {
			$timeline[] = array(
				'type'     => 'pageview',
				'time'     => $v['created_at'],
				'path'     => (string) $v['path'],
				'title'    => (string) $v['title'],
				'duration' => (int) $v['duration'],
				'scroll'   => (int) $v['scroll_depth'],
				'order'    => 0,
			);
		}
		$events = (array) $this->db->get_results( $this->db->prepare( "SELECT e.created_at, e.category, e.name, e.label, pg.path FROM {$this->t['events']} e LEFT JOIN {$this->t['pages']} pg ON pg.id = e.page_id WHERE e.session_id = %d ORDER BY e.id ASC LIMIT 500", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$cats   = self::event_categories();
		foreach ( $events as $e ) {
			$timeline[] = array(
				'type'           => 'event',
				'time'           => $e['created_at'],
				'category'       => (string) $e['category'],
				'category_label' => isset( $cats[ $e['category'] ] ) ? $cats[ $e['category'] ] : (string) $e['category'],
				'name'           => (string) $e['name'],
				'label'          => (string) $e['label'],
				'path'           => (string) $e['path'],
				'order'          => 1,
			);
		}
		usort(
			$timeline,
			static function ( $a, $b ) {
				$cmp = strcmp( $a['time'], $b['time'] );
				return 0 !== $cmp ? $cmp : $a['order'] - $b['order'];
			}
		);
		return array(
			'session'  => $this->format_session( $row ),
			'timeline' => $timeline,
		);
	}

	/**
	 * Pageviews by weekday and hour.
	 *
	 * @param Query $q Query.
	 * @return array
	 */
	public function heatmap( Query $q ) {
		$cells = array_fill( 0, 7, array_fill( 0, 24, 0 ) );
		$max   = 0;
		$sql   = 'SELECT DAYOFWEEK(p.created_at) - 1 AS d, HOUR(p.created_at) AS h, COUNT(*) AS c' . $this->pageview_from( $q ) . ' GROUP BY d, h';
		foreach ( (array) $this->db->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$d = (int) $r['d'];
			$h = (int) $r['h'];
			if ( $d >= 0 && $d < 7 && $h >= 0 && $h < 24 ) {
				$cells[ $d ][ $h ] = (int) $r['c'];
				$max               = max( $max, (int) $r['c'] );
			}
		}
		return array(
			'metric' => 'pageviews',
			'cells'  => $cells,
			'max'    => $max,
		);
	}

	/**
	 * Scroll depth distribution.
	 *
	 * @param Query $q Query.
	 * @return array
	 */
	public function scroll_depth( Query $q ) {
		$sql     = 'SELECT CASE WHEN p.scroll_depth >= 100 THEN 4 WHEN p.scroll_depth >= 75 THEN 3 WHEN p.scroll_depth >= 50 THEN 2 WHEN p.scroll_depth >= 25 THEN 1 ELSE 0 END AS b, COUNT(*) AS c'
			. $this->pageview_from( $q, array( 'p.scroll_depth > 0' ) ) . ' GROUP BY b';
		$buckets = array_fill( 0, 5, 0 );
		foreach ( (array) $this->db->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$buckets[ (int) $r['b'] ] = (int) $r['c'];
		}
		$total   = array_sum( $buckets );
		$reached = array();
		foreach ( array( 25, 50, 75, 100 ) as $i => $threshold ) {
			$count     = array_sum( array_slice( $buckets, $i + 1 ) );
			$reached[] = array(
				'threshold' => $threshold,
				'pageviews' => $count,
				'percent'   => $total ? round( $count / $total * 100, 1 ) : 0,
			);
		}
		$avg = (float) $this->db->get_var( 'SELECT COALESCE(AVG(p.scroll_depth),0)' . $this->pageview_from( $q, array( 'p.scroll_depth > 0' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'measured' => $total,
			'average'  => (int) round( $avg ),
			'reached'  => $reached,
		);
	}

	/**
	 * Goal conversions.
	 *
	 * @param Query $q Query.
	 * @return array
	 */
	public function goals( Query $q ) {
		$q        = $q->without( array( 'goal' ) );
		$previous = $q->previous();
		$out      = array(
			'total_visitors' => $this->summary_for( $q )['visitors'],
			'goals'          => array(),
		);
		$prev_total = $previous ? $this->summary_for( $previous )['visitors'] : 0;
		foreach ( Goals::all() as $goal ) {
			$current = $this->goal_counts( $goal, $q );
			$row     = array_merge(
				$goal,
				$current,
				array( 'cr' => $out['total_visitors'] ? round( $current['conversions'] / $out['total_visitors'] * 100, 2 ) : 0 )
			);
			if ( $previous ) {
				$prev                    = $this->goal_counts( $goal, $previous );
				$row['prev_conversions'] = $prev['conversions'];
				$row['prev_completions'] = $prev['completions'];
				$row['prev_cr']          = $prev_total ? round( $prev['conversions'] / $prev_total * 100, 2 ) : 0;
			}
			$out['goals'][] = $row;
		}
		return $out;
	}

	/**
	 * Conversions and completions of one goal.
	 *
	 * @param array $goal Goal.
	 * @param Query $q    Query.
	 * @return array{conversions:int,completions:int}
	 */
	private function goal_counts( array $goal, Query $q ) {
		$s_conds = $this->session_conditions( $q, 'session' );
		if ( 'event' === $goal['type'] ) {
			$sql = "SELECT COUNT(*) AS completions, COUNT(DISTINCT s.visitor_hash) AS conversions FROM {$this->t['events']} e INNER JOIN {$this->t['sessions']} s ON s.id = e.session_id"
				. $this->where( array_merge( array( $this->range( 'e.created_at', $q ), $this->goal_event_condition( $goal, 'e' ) ), $s_conds ) );
		} else {
			$sql = "SELECT COUNT(*) AS completions, COUNT(DISTINCT s.visitor_hash) AS conversions FROM {$this->t['pageviews']} p INNER JOIN {$this->t['sessions']} s ON s.id = p.session_id"
				. $this->where( array_merge( array( $this->range( 'p.created_at', $q ), "p.page_id IN (SELECT id FROM {$this->t['pages']} WHERE " . $this->goal_value_condition( 'path', $goal ) . ')' ), $s_conds ) );
		}
		$row = $this->db->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'conversions' => isset( $row['conversions'] ) ? (int) $row['conversions'] : 0,
			'completions' => isset( $row['completions'] ) ? (int) $row['completions'] : 0,
		);
	}

	/**
	 * Details about one page (header of the page report).
	 *
	 * @param string $path Page path.
	 * @return array
	 */
	public function page_info( $path ) {
		$row  = $this->db->get_row( $this->db->prepare( "SELECT id, path, title, post_id, post_type, created_at FROM {$this->t['pages']} WHERE hash = %s", md5( (string) $path ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$info = array(
			'path'       => (string) $path,
			'title'      => $row ? (string) $row['title'] : '',
			'url'        => Helpers::site_origin() . (string) $path,
			'post_id'    => $row ? (int) $row['post_id'] : 0,
			'post_type'  => $row ? (string) $row['post_type'] : '',
			'first_seen' => $row ? (string) $row['created_at'] : '',
			'edit_url'   => '',
			'published'  => '',
			'author'     => '',
		);
		if ( $info['post_id'] ) {
			$post = get_post( $info['post_id'] );
			if ( $post ) {
				$info['title']     = '' !== $info['title'] ? $info['title'] : get_the_title( $post );
				$info['published'] = get_post_time( 'Y-m-d', false, $post );
				$author            = get_userdata( (int) $post->post_author );
				$info['author']    = $author ? $author->display_name : '';
				if ( current_user_can( 'edit_post', $post->ID ) ) {
					$info['edit_url'] = (string) get_edit_post_link( $post->ID, 'raw' );
				}
			}
		}
		return $info;
	}

	/* =================================================================== *
	 * Raw data iterators (CSV export)
	 * =================================================================== */

	/**
	 * Calls $callback for every session matching the query (keyset pagination).
	 *
	 * @param Query    $q        Query.
	 * @param callable $callback Receives a formatted session row.
	 * @param int      $max      Safety cap.
	 */
	public function each_session( Query $q, $callback, $max = 500000 ) {
		$conditions = array_merge( array( $this->range( 's.created_at', $q ) ), $this->session_conditions( $q, 'session' ) );
		$this->paginate(
			'SELECT s.*, ep.path AS entry_path, ep.title AS entry_title, xp.path AS exit_path, xp.title AS exit_title'
			. " FROM {$this->t['sessions']} s LEFT JOIN {$this->t['pages']} ep ON ep.id = s.entry_page_id LEFT JOIN {$this->t['pages']} xp ON xp.id = s.exit_page_id",
			$conditions,
			's.id',
			function ( $row ) use ( $callback ) {
				$formatted                 = $this->format_session( $row );
				$formatted['visitor_hash'] = $row['visitor_hash'];
				call_user_func( $callback, $formatted );
			},
			$max
		);
	}

	/**
	 * Calls $callback for every pageview matching the query.
	 *
	 * @param Query    $q        Query.
	 * @param callable $callback Receives a row.
	 * @param int      $max      Safety cap.
	 */
	public function each_pageview( Query $q, $callback, $max = 1000000 ) {
		$conditions = array_merge( array( $this->range( 'p.created_at', $q ) ), $this->page_conditions( $q, 'p.page_id' ), $this->session_conditions( $q, 'pageview' ) );
		$this->paginate(
			'SELECT p.id, p.session_id, p.created_at, p.duration, p.scroll_depth, pg.path, pg.title, pg.post_type, prev.path AS prev_path, s.visitor_hash, s.is_new, s.country, s.region, s.city, s.device, s.browser, s.os, s.channel, s.source, s.referrer_url, s.utm_source, s.utm_medium, s.utm_campaign'
			. " FROM {$this->t['pageviews']} p INNER JOIN {$this->t['sessions']} s ON s.id = p.session_id LEFT JOIN {$this->t['pages']} pg ON pg.id = p.page_id LEFT JOIN {$this->t['pages']} prev ON prev.id = p.prev_page_id",
			$conditions,
			'p.id',
			$callback,
			$max
		);
	}

	/**
	 * Calls $callback for every event matching the query.
	 *
	 * @param Query    $q        Query.
	 * @param callable $callback Receives a row.
	 * @param int      $max      Safety cap.
	 */
	public function each_event( Query $q, $callback, $max = 1000000 ) {
		$conditions = array_merge( array( $this->range( 'e.created_at', $q ) ), $this->event_conditions( $q, 'e' ), $this->session_conditions( $q, 'event' ) );
		$this->paginate(
			'SELECT e.id, e.session_id, e.created_at, e.category, e.name, e.label, pg.path, s.visitor_hash, s.country, s.city, s.device, s.channel, s.source, s.utm_campaign'
			. " FROM {$this->t['events']} e INNER JOIN {$this->t['sessions']} s ON s.id = e.session_id LEFT JOIN {$this->t['pages']} pg ON pg.id = e.page_id",
			$conditions,
			'e.id',
			$callback,
			$max
		);
	}

	/**
	 * Keyset pagination helper.
	 *
	 * @param string   $select     SELECT ... FROM ... (without WHERE).
	 * @param string[] $conditions Conditions.
	 * @param string   $id_column  Ascending id column.
	 * @param callable $callback   Row callback.
	 * @param int      $max        Max rows.
	 */
	private function paginate( $select, array $conditions, $id_column, $callback, $max ) {
		$last  = 0;
		$count = 0;
		do {
			$sql  = $select . $this->where( array_merge( $conditions, array( $this->db->prepare( "{$id_column} > %d", $last ) ) ) ) . " ORDER BY {$id_column} ASC LIMIT 2000"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $this->db->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			foreach ( $rows as $row ) {
				$last = (int) $row['id'];
				call_user_func( $callback, $row );
				if ( ++$count >= $max ) {
					return;
				}
			}
		} while ( count( $rows ) === 2000 );
	}

	/**
	 * Date of the first recorded session.
	 *
	 * @return string|null
	 */
	public function first_date() {
		$value = $this->db->get_var( "SELECT MIN(created_at) FROM {$this->t['sessions']}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $value ? substr( $value, 0, 10 ) : null;
	}

	/**
	 * Values for the filter autocomplete.
	 *
	 * @param Query  $q         Query.
	 * @param string $dimension Filter dimension.
	 * @param string $search    Search term.
	 * @return array
	 */
	public function suggest( Query $q, $dimension, $search = '' ) {
		$q = $q->without( array( $dimension ) );
		switch ( $dimension ) {
			case 'goal':
				$values = array();
				foreach ( Goals::all() as $goal ) {
					$values[] = array(
						'key'   => $goal['id'],
						'label' => $goal['name'],
					);
				}
				return $values;
			case 'visitor_type':
				return array(
					array(
						'key'   => 'new',
						'label' => __( 'New visitors', 'pulse-analytics' ),
					),
					array(
						'key'   => 'returning',
						'label' => __( 'Returning visitors', 'pulse-analytics' ),
					),
				);
			case 'user_type':
				return array(
					array(
						'key'   => 'logged_in',
						'label' => __( 'Logged-in users', 'pulse-analytics' ),
					),
					array(
						'key'   => 'guest',
						'label' => __( 'Guests', 'pulse-analytics' ),
					),
				);
			case 'entry_page':
			case 'exit_page':
				$dimension = 'page';
				break;
		}
		if ( ! $this->is_dimension( $dimension ) ) {
			return array();
		}
		$result = $this->breakdown(
			$q,
			$dimension,
			array(
				'limit'  => 25,
				'search' => $search,
			)
		);
		$values = array();
		foreach ( $result['rows'] as $row ) {
			if ( isset( $row['special'] ) ) {
				continue;
			}
			$values[] = array(
				'key'   => $row['key'],
				'label' => $row['label'],
			);
		}
		return $values;
	}
}
