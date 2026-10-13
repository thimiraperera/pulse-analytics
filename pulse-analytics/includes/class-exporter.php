<?php
/**
 * CSV export of reports and raw data.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams CSV files through admin-post.php.
 */
class Exporter {

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'admin_post_pulse_analytics_export', array( $this, 'handle' ) );
	}

	/**
	 * Column labels for metrics.
	 *
	 * @return array<string,string>
	 */
	public static function metric_labels() {
		return array(
			'visitors'        => __( 'Visitors', 'pulse-analytics' ),
			'visits'          => __( 'Visits', 'pulse-analytics' ),
			'pageviews'       => __( 'Pageviews', 'pulse-analytics' ),
			'views_per_visit' => __( 'Views per visit', 'pulse-analytics' ),
			'bounce_rate'     => __( 'Bounce rate (%)', 'pulse-analytics' ),
			'avg_duration'    => __( 'Avg. duration (seconds)', 'pulse-analytics' ),
			'avg_time'        => __( 'Avg. time on page (seconds)', 'pulse-analytics' ),
			'avg_scroll'      => __( 'Avg. scroll depth (%)', 'pulse-analytics' ),
			'entrances'       => __( 'Entrances', 'pulse-analytics' ),
			'exits'           => __( 'Exits', 'pulse-analytics' ),
			'exit_rate'       => __( 'Exit rate (%)', 'pulse-analytics' ),
			'events'          => __( 'Events', 'pulse-analytics' ),
			'conversions'     => __( 'Conversions', 'pulse-analytics' ),
			'cr'              => __( 'Conversion rate (%)', 'pulse-analytics' ),
		);
	}

	/**
	 * Handles an export request.
	 */
	public function handle() {
		if ( ! Helpers::can_view() ) {
			wp_die( esc_html__( 'You are not allowed to export statistics.', 'pulse-analytics' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'pulse_analytics_export' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Verified above.
		$type    = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'breakdown';
		$filters = isset( $_GET['filters'] ) ? json_decode( wp_unslash( $_GET['filters'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Query::sanitize_filters().
		$query   = Query::from_array(
			array(
				'start'   => isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : '',
				'end'     => isset( $_GET['end'] ) ? sanitize_text_field( wp_unslash( $_GET['end'] ) ) : '',
				'compare' => isset( $_GET['compare'] ) ? sanitize_key( wp_unslash( $_GET['compare'] ) ) : '',
				'filters' => is_array( $filters ) ? $filters : array(),
			)
		);
		$dimension = isset( $_GET['dimension'] ) ? sanitize_key( wp_unslash( $_GET['dimension'] ) ) : '';
		$interval  = isset( $_GET['interval'] ) ? sanitize_key( wp_unslash( $_GET['interval'] ) ) : '';
		// phpcs:enable

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$reports = new Reports();

		switch ( $type ) {
			case 'summary':
				$this->export_summary( $reports, $query, $interval );
				break;
			case 'sessions':
				$this->export_sessions( $reports, $query );
				break;
			case 'pageviews':
				$this->export_pageviews( $reports, $query );
				break;
			case 'events':
				$this->export_events( $reports, $query );
				break;
			case 'goals':
				$this->export_goals( $reports, $query );
				break;
			default:
				if ( ! $reports->is_dimension( $dimension ) ) {
					wp_die( esc_html__( 'Unknown report.', 'pulse-analytics' ) );
				}
				$this->export_breakdown( $reports, $query, $dimension );
		}
		exit;
	}

	/**
	 * Opens the output stream with download headers.
	 *
	 * @param string $name  File name part.
	 * @param Query  $query Query (for the date range in the name).
	 * @return resource
	 */
	private function open( $name, Query $query ) {
		$filename = sanitize_file_name( 'pulse-analytics-' . $name . '-' . $query->start . '-to-' . $query->end . '.csv' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM so Excel shows non-Latin text correctly.
		return $out;
	}

	/**
	 * Writes one CSV row, neutralising spreadsheet formulas in text cells.
	 *
	 * @param resource $out  Stream.
	 * @param array    $cells Cells.
	 */
	private function row( $out, array $cells ) {
		foreach ( $cells as &$cell ) {
			if ( null === $cell ) {
				$cell = '';
			} elseif ( is_bool( $cell ) ) {
				$cell = $cell ? '1' : '0';
			} elseif ( is_string( $cell ) && '' !== $cell && ! is_numeric( $cell ) && in_array( $cell[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
				$cell = "'" . $cell;
			}
		}
		unset( $cell );
		fputcsv( $out, $cells, ',', '"', '\\' );
	}

	/**
	 * Dimension column titles.
	 *
	 * @param string $dimension Dimension.
	 * @return array{0:string,1:string} Label and key column titles.
	 */
	private function dimension_columns( $dimension ) {
		$map = array(
			'page'           => array( __( 'Page title', 'pulse-analytics' ), __( 'Page path', 'pulse-analytics' ) ),
			'entry_page'     => array( __( 'Entry page title', 'pulse-analytics' ), __( 'Entry page path', 'pulse-analytics' ) ),
			'exit_page'      => array( __( 'Exit page title', 'pulse-analytics' ), __( 'Exit page path', 'pulse-analytics' ) ),
			'prev_page'      => array( __( 'Previous page title', 'pulse-analytics' ), __( 'Previous page path', 'pulse-analytics' ) ),
			'next_page'      => array( __( 'Next page title', 'pulse-analytics' ), __( 'Next page path', 'pulse-analytics' ) ),
			'country'        => array( __( 'Country', 'pulse-analytics' ), __( 'Country code', 'pulse-analytics' ) ),
			'continent'      => array( __( 'Continent', 'pulse-analytics' ), __( 'Continent code', 'pulse-analytics' ) ),
			'channel'        => array( __( 'Channel', 'pulse-analytics' ), __( 'Channel key', 'pulse-analytics' ) ),
			'event_category' => array( __( 'Event category', 'pulse-analytics' ), __( 'Category key', 'pulse-analytics' ) ),
		);
		if ( isset( $map[ $dimension ] ) ) {
			return $map[ $dimension ];
		}
		$title = ucwords( str_replace( '_', ' ', $dimension ) );
		return array( $title, $title . ' ' . __( '(raw value)', 'pulse-analytics' ) );
	}

	/**
	 * Full breakdown export (all rows).
	 *
	 * @param Reports $reports   Engine.
	 * @param Query   $query     Query.
	 * @param string  $dimension Dimension.
	 */
	private function export_breakdown( Reports $reports, Query $query, $dimension ) {
		$out    = $this->open( str_replace( '_', '-', $dimension ), $query );
		$labels = self::metric_labels();
		$header = null;
		$offset = 0;
		$chunk  = 1000;
		$total  = 0;
		do {
			$result = $reports->breakdown(
				$query,
				$dimension,
				array(
					'limit'         => $chunk,
					'offset'        => $offset,
					'with_previous' => '' !== $query->compare,
				)
			);
			$rows   = $result['rows'];
			if ( null === $header ) {
				list( $label_title, $key_title ) = $this->dimension_columns( $dimension );
				$header                          = array( $label_title, $key_title );
				$extras                          = array();
				foreach ( array( 'country', 'region', 'browser', 'os', 'category', 'lat', 'lon' ) as $extra ) {
					if ( $rows && array_key_exists( $extra, $rows[0] ) ) {
						$extras[] = $extra;
						$header[] = ucfirst( $extra );
					}
				}
				foreach ( $result['metrics'] as $metric ) {
					$header[] = isset( $labels[ $metric ] ) ? $labels[ $metric ] : $metric;
				}
				if ( '' !== $query->compare ) {
					foreach ( $result['metrics'] as $metric ) {
						/* translators: %s: metric name */
						$header[] = sprintf( __( '%s (previous period)', 'pulse-analytics' ), isset( $labels[ $metric ] ) ? $labels[ $metric ] : $metric );
					}
				}
				$this->row( $out, $header );
			}
			foreach ( $rows as $row ) {
				$cells = array( $row['label'], $row['key'] );
				foreach ( $extras as $extra ) {
					$cells[] = isset( $row[ $extra ] ) ? $row[ $extra ] : '';
				}
				foreach ( $result['metrics'] as $metric ) {
					$cells[] = isset( $row[ $metric ] ) ? $row[ $metric ] : '';
				}
				if ( '' !== $query->compare ) {
					foreach ( $result['metrics'] as $metric ) {
						$cells[] = isset( $row['prev'][ $metric ] ) ? $row['prev'][ $metric ] : '';
					}
				}
				$this->row( $out, $cells );
			}
			$offset += $chunk;
			$total  += count( $rows );
			$more    = count( $rows ) === $chunk && in_array( $dimension, array( 'page', 'entry_page', 'exit_page', 'referrer', 'referrer_url', 'city', 'region', 'event_name', 'event_label', 'utm_campaign', 'utm_term', 'utm_content', 'source', 'screen', 'language', 'browser_version', 'os_version' ), true );
		} while ( $more && $total < 100000 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Summary metrics plus the time series.
	 *
	 * @param Reports $reports  Engine.
	 * @param Query   $query    Query.
	 * @param string  $interval Interval.
	 */
	private function export_summary( Reports $reports, Query $query, $interval ) {
		$out     = $this->open( 'summary', $query );
		$labels  = self::metric_labels();
		$summary = $reports->summary( $query );
		$keys    = array( 'visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'avg_duration' );

		$this->row( $out, array( __( 'Metric', 'pulse-analytics' ), $query->start . ' to ' . $query->end, $summary['prev_range'] ? implode( ' to ', $summary['prev_range'] ) : '', __( 'Change (%)', 'pulse-analytics' ) ) );
		foreach ( $keys as $key ) {
			$current  = $summary['current'][ $key ];
			$previous = $summary['previous'] ? $summary['previous'][ $key ] : null;
			$change   = ( null !== $previous && $previous ) ? round( ( $current - $previous ) / $previous * 100, 1 ) : '';
			$this->row( $out, array( $labels[ $key ], $current, $previous, $change ) );
		}

		$series = $reports->timeseries( $query, $interval );
		$this->row( $out, array() );
		$header = array( __( 'Period', 'pulse-analytics' ) );
		foreach ( $keys as $key ) {
			$header[] = $labels[ $key ];
		}
		if ( $series['previous'] ) {
			$header[] = __( 'Previous period', 'pulse-analytics' );
			foreach ( $keys as $key ) {
				/* translators: %s: metric name */
				$header[] = sprintf( __( '%s (previous period)', 'pulse-analytics' ), $labels[ $key ] );
			}
		}
		$this->row( $out, $header );
		foreach ( $series['labels'] as $i => $label ) {
			$cells = array( $label );
			foreach ( $keys as $key ) {
				$cells[] = $series['current'][ $key ][ $i ];
			}
			if ( $series['previous'] ) {
				$cells[] = isset( $series['prev_labels'][ $i ] ) ? $series['prev_labels'][ $i ] : '';
				foreach ( $keys as $key ) {
					$cells[] = isset( $series['previous'][ $key ][ $i ] ) ? $series['previous'][ $key ][ $i ] : '';
				}
			}
			$this->row( $out, $cells );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Raw sessions.
	 *
	 * @param Reports $reports Engine.
	 * @param Query   $query   Query.
	 */
	private function export_sessions( Reports $reports, Query $query ) {
		$out = $this->open( 'sessions', $query );
		$this->row( $out, array( 'Session ID', 'Visitor ID (anonymous)', 'Started', 'Last activity', 'Duration (seconds)', 'Pageviews', 'Events', 'New visitor', 'Entry page', 'Entry page title', 'Exit page', 'Channel', 'Source', 'Referrer', 'UTM source', 'UTM medium', 'UTM campaign', 'UTM term', 'UTM content', 'Country', 'Region', 'City', 'Device', 'Browser', 'Browser version', 'OS', 'OS version', 'Screen', 'Language', 'User', 'IP address' ) );
		$reports->each_session(
			$query,
			function ( $s ) use ( $out ) {
				$this->row( $out, array( $s['id'], $s['visitor_hash'], $s['created_at'], $s['updated_at'], $s['duration'], $s['pageviews'], $s['events'], $s['is_new'] ? 'yes' : 'no', $s['entry_path'], $s['entry_title'], $s['exit_path'], $s['channel_label'], $s['source'], $s['referrer_url'], $s['utm_source'], $s['utm_medium'], $s['utm_campaign'], $s['utm_term'], $s['utm_content'], $s['country_name'], $s['region'], $s['city'], $s['device'], $s['browser'], $s['browser_version'], $s['os'], $s['os_version'], $s['screen'], $s['language'], $s['user'], $s['ip'] ) );
			}
		);
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Raw pageviews.
	 *
	 * @param Reports $reports Engine.
	 * @param Query   $query   Query.
	 */
	private function export_pageviews( Reports $reports, Query $query ) {
		$out      = $this->open( 'pageviews', $query );
		$channels = Referrer::channels();
		$this->row( $out, array( 'Pageview ID', 'Session ID', 'Visitor ID (anonymous)', 'Time', 'Page path', 'Page title', 'Content type', 'Previous page', 'Time on page (seconds)', 'Scroll depth (%)', 'New visitor', 'Country', 'Region', 'City', 'Device', 'Browser', 'OS', 'Channel', 'Source', 'Referrer', 'UTM source', 'UTM medium', 'UTM campaign' ) );
		$reports->each_pageview(
			$query,
			function ( $p ) use ( $out, $channels ) {
				$this->row( $out, array( $p['id'], $p['session_id'], $p['visitor_hash'], $p['created_at'], $p['path'], $p['title'], $p['post_type'], $p['prev_path'], $p['duration'], $p['scroll_depth'], $p['is_new'] ? 'yes' : 'no', Countries::name( $p['country'] ), $p['region'], $p['city'], $p['device'], $p['browser'], $p['os'], isset( $channels[ $p['channel'] ] ) ? $channels[ $p['channel'] ] : $p['channel'], $p['source'], $p['referrer_url'], $p['utm_source'], $p['utm_medium'], $p['utm_campaign'] ) );
			}
		);
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Raw events.
	 *
	 * @param Reports $reports Engine.
	 * @param Query   $query   Query.
	 */
	private function export_events( Reports $reports, Query $query ) {
		$out  = $this->open( 'events', $query );
		$cats = Reports::event_categories();
		$this->row( $out, array( 'Event ID', 'Session ID', 'Visitor ID (anonymous)', 'Time', 'Category', 'Name', 'Label', 'Page', 'Country', 'City', 'Device', 'Channel', 'Source', 'UTM campaign' ) );
		$reports->each_event(
			$query,
			function ( $e ) use ( $out, $cats ) {
				$this->row( $out, array( $e['id'], $e['session_id'], $e['visitor_hash'], $e['created_at'], isset( $cats[ $e['category'] ] ) ? $cats[ $e['category'] ] : $e['category'], $e['name'], $e['label'], $e['path'], Countries::name( $e['country'] ), $e['city'], $e['device'], $e['channel'], $e['source'], $e['utm_campaign'] ) );
			}
		);
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Goal conversions.
	 *
	 * @param Reports $reports Engine.
	 * @param Query   $query   Query.
	 */
	private function export_goals( Reports $reports, Query $query ) {
		$out    = $this->open( 'goals', $query );
		$result = $reports->goals( $query );
		$header = array( 'Goal', 'Type', 'Match', 'Value', 'Conversions (visitors)', 'Completions', 'Conversion rate (%)' );
		if ( '' !== $query->compare ) {
			$header = array_merge( $header, array( 'Conversions (previous)', 'Completions (previous)', 'Conversion rate (previous, %)' ) );
		}
		$this->row( $out, $header );
		foreach ( $result['goals'] as $goal ) {
			$cells = array( $goal['name'], $goal['type'], $goal['match'], $goal['value'], $goal['conversions'], $goal['completions'], $goal['cr'] );
			if ( '' !== $query->compare ) {
				$cells = array_merge( $cells, array( isset( $goal['prev_conversions'] ) ? $goal['prev_conversions'] : '', isset( $goal['prev_completions'] ) ? $goal['prev_completions'] : '', isset( $goal['prev_cr'] ) ? $goal['prev_cr'] : '' ) );
			}
			$this->row( $out, $cells );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
