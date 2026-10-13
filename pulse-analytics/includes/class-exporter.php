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
 * Streams CSV files (or a ZIP with all of them) through admin-post.php.
 */
class Exporter {

	/**
	 * Report types that are not breakdowns.
	 */
	const TYPES = array( 'summary', 'sessions', 'pageviews', 'events', 'goals' );

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
	 * Files in the "all reports" ZIP: file name => array( type, dimension ).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function zip_contents() {
		return array(
			'summary-and-trend.csv' => array( 'summary', '' ),
			'pages.csv'             => array( 'breakdown', 'page' ),
			'entry-pages.csv'       => array( 'breakdown', 'entry_page' ),
			'exit-pages.csv'        => array( 'breakdown', 'exit_page' ),
			'content-types.csv'     => array( 'breakdown', 'post_type' ),
			'authors.csv'           => array( 'breakdown', 'author' ),
			'categories.csv'        => array( 'breakdown', 'category' ),
			'channels.csv'          => array( 'breakdown', 'channel' ),
			'sources.csv'           => array( 'breakdown', 'source' ),
			'referrers.csv'         => array( 'breakdown', 'referrer' ),
			'referrer-urls.csv'     => array( 'breakdown', 'referrer_url' ),
			'utm-campaigns.csv'     => array( 'breakdown', 'utm_campaign' ),
			'utm-sources.csv'       => array( 'breakdown', 'utm_source' ),
			'utm-mediums.csv'       => array( 'breakdown', 'utm_medium' ),
			'utm-terms.csv'         => array( 'breakdown', 'utm_term' ),
			'utm-content.csv'       => array( 'breakdown', 'utm_content' ),
			'countries.csv'         => array( 'breakdown', 'country' ),
			'regions.csv'           => array( 'breakdown', 'region' ),
			'cities.csv'            => array( 'breakdown', 'city' ),
			'continents.csv'        => array( 'breakdown', 'continent' ),
			'languages.csv'         => array( 'breakdown', 'language' ),
			'devices.csv'           => array( 'breakdown', 'device' ),
			'browsers.csv'          => array( 'breakdown', 'browser' ),
			'browser-versions.csv'  => array( 'breakdown', 'browser_version' ),
			'operating-systems.csv' => array( 'breakdown', 'os' ),
			'os-versions.csv'       => array( 'breakdown', 'os_version' ),
			'screen-sizes.csv'      => array( 'breakdown', 'screen' ),
			'new-vs-returning.csv'  => array( 'breakdown', 'visitor_type' ),
			'event-types.csv'       => array( 'breakdown', 'event_category' ),
			'events.csv'            => array( 'breakdown', 'event_name' ),
			'goals.csv'             => array( 'goals', '' ),
			'raw-visits.csv'        => array( 'sessions', '' ),
			'raw-pageviews.csv'     => array( 'pageviews', '' ),
			'raw-events.csv'        => array( 'events', '' ),
		);
	}

	/**
	 * Download name of a single CSV export.
	 *
	 * @param string $type      Report type.
	 * @param string $dimension Dimension (breakdowns only).
	 * @param Query  $query     Query.
	 * @return string
	 */
	public static function filename( $type, $dimension, Query $query ) {
		return sanitize_file_name( 'pulse-analytics-' . ( 'breakdown' === $type ? str_replace( '_', '-', $dimension ) : $type ) . '-' . $query->start . '-to-' . $query->end . '.csv' );
	}

	/**
	 * Header rows of the raw exports.
	 *
	 * @param string $type sessions, pageviews or events.
	 * @return string[]
	 */
	public static function raw_columns( $type ) {
		$columns = array(
			'sessions'  => array( 'Session ID', 'Visitor ID (anonymous)', 'Started', 'Last activity', 'Duration (seconds)', 'Pageviews', 'Events', 'New visitor', 'Entry page', 'Entry page title', 'Exit page', 'Channel', 'Source', 'Referrer', 'UTM source', 'UTM medium', 'UTM campaign', 'UTM term', 'UTM content', 'Country', 'Region', 'City', 'Device', 'Browser', 'Browser version', 'OS', 'OS version', 'Screen', 'Language', 'User', 'IP address' ),
			'pageviews' => array( 'Pageview ID', 'Session ID', 'Visitor ID (anonymous)', 'Time', 'Page path', 'Page title', 'Content type', 'Previous page', 'Time on page (seconds)', 'Scroll depth (%)', 'New visitor', 'Country', 'Region', 'City', 'Device', 'Browser', 'OS', 'Channel', 'Source', 'Referrer', 'UTM source', 'UTM medium', 'UTM campaign' ),
			'events'    => array( 'Event ID', 'Session ID', 'Visitor ID (anonymous)', 'Time', 'Category', 'Name', 'Label', 'Page', 'Country', 'City', 'Device', 'Channel', 'Source', 'UTM campaign' ),
		);
		return isset( $columns[ $type ] ) ? $columns[ $type ] : array();
	}

	/**
	 * Header row of the goals export.
	 *
	 * @param bool $with_previous Whether the comparison columns are included.
	 * @return string[]
	 */
	public static function goal_columns( $with_previous ) {
		$columns = array( 'Goal', 'Type', 'Match', 'Value', 'Conversions (visitors)', 'Completions', 'Conversion rate (%)' );
		return $with_previous ? array_merge( $columns, array( 'Conversions (previous)', 'Completions (previous)', 'Conversion rate (previous, %)' ) ) : $columns;
	}

	/**
	 * Titles of the extra columns some breakdowns have (key in the row => title).
	 *
	 * @return array<string,string>
	 */
	public static function extra_columns() {
		return array(
			'country'  => __( 'Country code', 'pulse-analytics' ),
			'region'   => __( 'Region', 'pulse-analytics' ),
			'browser'  => __( 'Browser', 'pulse-analytics' ),
			'os'       => __( 'Operating system', 'pulse-analytics' ),
			'category' => __( 'Event type', 'pulse-analytics' ),
			'lat'      => __( 'Latitude', 'pulse-analytics' ),
			'lon'      => __( 'Longitude', 'pulse-analytics' ),
		);
	}

	/**
	 * The filters of a query in words, such as "Country is Sri Lanka (LK)".
	 *
	 * @param Query $query Query.
	 * @return string[]
	 */
	public static function filter_text( Query $query ) {
		$ops = array(
			'is'           => __( 'is', 'pulse-analytics' ),
			'is_not'       => __( 'is not', 'pulse-analytics' ),
			'contains'     => __( 'contains', 'pulse-analytics' ),
			'not_contains' => __( 'does not contain', 'pulse-analytics' ),
		);
		$out = array();
		foreach ( $query->filters as $filter ) {
			// Page filters hold a path, so name the path column rather than the title.
			$columns = self::dimension_columns( $filter['dim'] );
			$label   = '_page' === substr( '_' . $filter['dim'], -5 ) ? $columns[1] : $columns[0];
			$values  = array();
			foreach ( (array) $filter['value'] as $value ) {
				$values[] = self::filter_value( $filter['dim'], (string) $value );
			}
			$out[] = $label . ' ' . ( isset( $ops[ $filter['op'] ] ) ? $ops[ $filter['op'] ] : $filter['op'] ) . ' ' . implode( ', ', $values );
		}
		return $out;
	}

	/**
	 * Readable form of a filter value.
	 *
	 * @param string $dimension Dimension.
	 * @param string $value     Stored value.
	 * @return string
	 */
	private static function filter_value( $dimension, $value ) {
		if ( 'country' === $dimension ) {
			$name = Countries::name( $value );
			return $name !== $value ? $name . ' (' . $value . ')' : $value;
		}
		$labels = array();
		if ( 'channel' === $dimension ) {
			$labels = Referrer::channels();
		} elseif ( 'event_category' === $dimension ) {
			$labels = Reports::event_categories();
		} elseif ( 'goal' === $dimension ) {
			$goal = Goals::get( $value );
			return $goal && ! empty( $goal['name'] ) ? $goal['name'] : $value;
		}
		return isset( $labels[ $value ] ) ? $labels[ $value ] : $value;
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
		$format    = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : 'csv';
		// phpcs:enable

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$reports = new Reports();

		if ( 'all' !== $type && ! in_array( $type, self::TYPES, true ) ) {
			if ( ! $reports->is_dimension( $dimension ) ) {
				wp_die( esc_html__( 'Unknown report.', 'pulse-analytics' ) );
			}
			$type = 'breakdown';
		}

		// The text to paste into an AI tool along with the file.
		if ( 'prompt' === $format ) {
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			echo Export_Prompt::build( $type, $dimension, $query, $interval, $reports ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text response.
			exit;
		}

		if ( 'all' === $type ) {
			$this->send_zip( $reports, $query, $interval );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . self::filename( $type, $dimension, $query ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$this->write( $out, $type, $dimension, $interval, $reports, $query );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Writes one report as CSV. Starts with a UTF-8 byte order mark so Excel reads non-Latin text.
	 *
	 * @param resource $out       Stream.
	 * @param string   $type      Report type.
	 * @param string   $dimension Dimension (breakdowns only).
	 * @param string   $interval  Interval (summary only).
	 * @param Reports  $reports   Engine.
	 * @param Query    $query     Query.
	 */
	private function write( $out, $type, $dimension, $interval, Reports $reports, Query $query ) {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		switch ( $type ) {
			case 'summary':
				$this->summary_rows( $out, $reports, $query, $interval );
				break;
			case 'sessions':
				$this->session_rows( $out, $reports, $query );
				break;
			case 'pageviews':
				$this->pageview_rows( $out, $reports, $query );
				break;
			case 'events':
				$this->event_rows( $out, $reports, $query );
				break;
			case 'goals':
				$this->goal_rows( $out, $reports, $query );
				break;
			default:
				$this->breakdown_rows( $out, $reports, $query, $dimension );
		}
	}

	/**
	 * Builds a ZIP with every report as a separate CSV file and sends it.
	 *
	 * @param Reports $reports  Engine.
	 * @param Query   $query    Query.
	 * @param string  $interval Interval for the summary.
	 */
	private function send_zip( Reports $reports, Query $query, $interval ) {
		$folder = 'pulse-analytics-' . $query->start . '-to-' . $query->end;
		$dir    = trailingslashit( get_temp_dir() ) . $folder . '-' . wp_generate_password( 8, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			wp_die( esc_html__( 'Could not create a temporary folder for the export.', 'pulse-analytics' ) );
		}

		$files = array();
		foreach ( self::zip_contents() as $name => $report ) {
			$path = $dir . '/' . $name;
			$out  = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $out ) {
				continue;
			}
			$this->write( $out, $report[0], $report[1], $interval, $reports, $query );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$files[ $name ] = $path;
		}
		$about = $dir . '/about-this-export.txt';
		file_put_contents( $about, $this->about_text( $query ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$files['about-this-export.txt'] = $about;
		$prompt                         = $dir . '/prompt-for-ai.txt';
		file_put_contents( $prompt, str_replace( "\n", "\r\n", Export_Prompt::build( 'all', '', $query, $interval, $reports ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$files['prompt-for-ai.txt'] = $prompt;

		$zip_path = $dir . '.zip';
		$created  = $this->create_zip( $zip_path, $files, $folder );
		foreach ( $files as $path ) {
			wp_delete_file( $path );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir

		if ( is_wp_error( $created ) ) {
			wp_delete_file( $zip_path );
			wp_die( esc_html( $created->get_error_message() ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $folder . '.zip' ) . '"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $zip_path );
	}

	/**
	 * Packs files into a ZIP (ZipArchive when available, otherwise the PclZip copy bundled with WordPress).
	 *
	 * @param string $zip_path Target file.
	 * @param array  $files    Name inside the archive => path on disk.
	 * @param string $folder   Folder name inside the archive.
	 * @return true|\WP_Error
	 */
	private function create_zip( $zip_path, array $files, $folder ) {
		if ( class_exists( 'ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return new \WP_Error( 'pulse_zip', __( 'Could not create the ZIP file.', 'pulse-analytics' ) );
			}
			foreach ( $files as $name => $path ) {
				$zip->addFile( $path, $folder . '/' . $name );
			}
			return $zip->close() ? true : new \WP_Error( 'pulse_zip', __( 'Could not create the ZIP file.', 'pulse-analytics' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$archive = new \PclZip( $zip_path );
		$result  = $archive->create( array_values( $files ), PCLZIP_OPT_REMOVE_ALL_PATH, PCLZIP_OPT_ADD_PATH, $folder );
		return 0 === $result ? new \WP_Error( 'pulse_zip', $archive->errorInfo( true ) ) : true;
	}

	/**
	 * Short description of the export, saved as a text file in the ZIP.
	 *
	 * @param Query $query Query.
	 * @return string
	 */
	private function about_text( Query $query ) {
		$previous = $query->previous();
		$filters  = self::filter_text( $query );
		$user     = wp_get_current_user();
		$lines = array(
			'Pulse Analytics export',
			'',
			'Website:  ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . ' (' . home_url( '/' ) . ')',
			'Period:   ' . $query->start . ' to ' . $query->end,
			'Compared: ' . ( $previous ? $previous->start . ' to ' . $previous->end : 'no comparison' ),
			'Filters:  ' . ( $filters ? implode( '; ', $filters ) : 'none' ),
			'Exported: ' . Helpers::now() . ( $user->exists() ? ' by ' . $user->display_name : '' ),
			'',
			'Every CSV file opens in Excel, Google Sheets or Numbers.',
			'Durations are in seconds and rates are in percent.',
			'Files starting with "raw-" have one row per visit, pageview or event.',
			'prompt-for-ai.txt explains the files to an AI tool. Upload it with the CSV files or paste its text into the chat.',
		);
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Writes one CSV row, neutralising spreadsheet formulas in text cells.
	 *
	 * @param resource $out   Stream.
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
	public static function dimension_columns( $dimension ) {
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
		$titles = array(
			'utm_source'      => __( 'UTM source', 'pulse-analytics' ),
			'utm_medium'      => __( 'UTM medium', 'pulse-analytics' ),
			'utm_campaign'    => __( 'UTM campaign', 'pulse-analytics' ),
			'utm_term'        => __( 'UTM term', 'pulse-analytics' ),
			'utm_content'     => __( 'UTM content', 'pulse-analytics' ),
			'os'              => __( 'Operating system', 'pulse-analytics' ),
			'os_version'      => __( 'OS version', 'pulse-analytics' ),
			'browser_version' => __( 'Browser version', 'pulse-analytics' ),
			'referrer_url'    => __( 'Referrer URL', 'pulse-analytics' ),
			'post_type'       => __( 'Content type', 'pulse-analytics' ),
			'event_name'      => __( 'Event', 'pulse-analytics' ),
			'event_label'     => __( 'Event label', 'pulse-analytics' ),
			'visitor_type'    => __( 'Visitor type', 'pulse-analytics' ),
			'user_type'       => __( 'User type', 'pulse-analytics' ),
			'screen'          => __( 'Screen size', 'pulse-analytics' ),
		);
		$title  = isset( $titles[ $dimension ] ) ? $titles[ $dimension ] : ucfirst( str_replace( '_', ' ', $dimension ) );
		/* translators: %s: column title */
		return array( $title, sprintf( __( '%s (raw value)', 'pulse-analytics' ), $title ) );
	}

	/**
	 * Full breakdown (all rows).
	 *
	 * @param resource $out       Stream.
	 * @param Reports  $reports   Engine.
	 * @param Query    $query     Query.
	 * @param string   $dimension Dimension.
	 */
	private function breakdown_rows( $out, Reports $reports, Query $query, $dimension ) {
		$labels = self::metric_labels();
		$header = null;
		$extras = array();
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
				$header = self::dimension_columns( $dimension );
				foreach ( self::extra_columns() as $extra => $extra_title ) {
					if ( $rows && array_key_exists( $extra, $rows[0] ) ) {
						$extras[] = $extra;
						$header[] = $extra_title;
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
	}

	/**
	 * Summary metrics plus the time series.
	 *
	 * @param resource $out      Stream.
	 * @param Reports  $reports  Engine.
	 * @param Query    $query    Query.
	 * @param string   $interval Interval.
	 */
	private function summary_rows( $out, Reports $reports, Query $query, $interval ) {
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
	}

	/**
	 * Raw visits.
	 *
	 * @param resource $out     Stream.
	 * @param Reports  $reports Engine.
	 * @param Query    $query   Query.
	 */
	private function session_rows( $out, Reports $reports, Query $query ) {
		$this->row( $out, self::raw_columns( 'sessions' ) );
		$reports->each_session(
			$query,
			function ( $s ) use ( $out ) {
				$this->row( $out, array( $s['id'], $s['visitor_hash'], $s['created_at'], $s['updated_at'], $s['duration'], $s['pageviews'], $s['events'], $s['is_new'] ? 'yes' : 'no', $s['entry_path'], $s['entry_title'], $s['exit_path'], $s['channel_label'], $s['source'], $s['referrer_url'], $s['utm_source'], $s['utm_medium'], $s['utm_campaign'], $s['utm_term'], $s['utm_content'], $s['country_name'], $s['region'], $s['city'], $s['device'], $s['browser'], $s['browser_version'], $s['os'], $s['os_version'], $s['screen'], $s['language'], $s['user'], $s['ip'] ) );
			}
		);
	}

	/**
	 * Raw pageviews.
	 *
	 * @param resource $out     Stream.
	 * @param Reports  $reports Engine.
	 * @param Query    $query   Query.
	 */
	private function pageview_rows( $out, Reports $reports, Query $query ) {
		$channels = Referrer::channels();
		$this->row( $out, self::raw_columns( 'pageviews' ) );
		$reports->each_pageview(
			$query,
			function ( $p ) use ( $out, $channels ) {
				$this->row( $out, array( $p['id'], $p['session_id'], $p['visitor_hash'], $p['created_at'], $p['path'], $p['title'], $p['post_type'], $p['prev_path'], $p['duration'], $p['scroll_depth'], $p['is_new'] ? 'yes' : 'no', Countries::name( $p['country'] ), $p['region'], $p['city'], $p['device'], $p['browser'], $p['os'], isset( $channels[ $p['channel'] ] ) ? $channels[ $p['channel'] ] : $p['channel'], $p['source'], $p['referrer_url'], $p['utm_source'], $p['utm_medium'], $p['utm_campaign'] ) );
			}
		);
	}

	/**
	 * Raw events.
	 *
	 * @param resource $out     Stream.
	 * @param Reports  $reports Engine.
	 * @param Query    $query   Query.
	 */
	private function event_rows( $out, Reports $reports, Query $query ) {
		$cats = Reports::event_categories();
		$this->row( $out, self::raw_columns( 'events' ) );
		$reports->each_event(
			$query,
			function ( $e ) use ( $out, $cats ) {
				$this->row( $out, array( $e['id'], $e['session_id'], $e['visitor_hash'], $e['created_at'], isset( $cats[ $e['category'] ] ) ? $cats[ $e['category'] ] : $e['category'], $e['name'], $e['label'], $e['path'], Countries::name( $e['country'] ), $e['city'], $e['device'], $e['channel'], $e['source'], $e['utm_campaign'] ) );
			}
		);
	}

	/**
	 * Goal conversions.
	 *
	 * @param resource $out     Stream.
	 * @param Reports  $reports Engine.
	 * @param Query    $query   Query.
	 */
	private function goal_rows( $out, Reports $reports, Query $query ) {
		$result = $reports->goals( $query );
		$this->row( $out, self::goal_columns( '' !== $query->compare ) );
		foreach ( $result['goals'] as $goal ) {
			$cells = array( $goal['name'], $goal['type'], $goal['match'], $goal['value'], $goal['conversions'], $goal['completions'], $goal['cr'] );
			if ( '' !== $query->compare ) {
				$cells = array_merge( $cells, array( isset( $goal['prev_conversions'] ) ? $goal['prev_conversions'] : '', isset( $goal['prev_completions'] ) ? $goal['prev_completions'] : '', isset( $goal['prev_cr'] ) ? $goal['prev_cr'] : '' ) );
			}
			$this->row( $out, $cells );
		}
	}
}
