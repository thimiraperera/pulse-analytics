<?php
/**
 * Summary report as a PDF file, for download or email.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lays out the summary report for a period and sends it by email.
 */
class Pdf_Report {

	const MARGIN = 40;
	const ACCENT = '#2a78d6';
	const INK    = '#1d2327';
	const MUTED  = '#646970';
	const LINE   = '#dcdcde';
	const SOFT   = '#f3f5f7';
	const GOOD   = '#007a1f';
	const BAD    = '#c62f2f';

	/**
	 * Height of a table row.
	 */
	const ROW = 17;

	/**
	 * How many report emails one user may send per hour.
	 */
	const MAIL_LIMIT = 20;

	/**
	 * Document being written.
	 *
	 * @var Pdf
	 */
	private $pdf;

	/**
	 * Vertical position on the current page.
	 *
	 * @var float
	 */
	private $y = 0;

	/**
	 * Period, comparison and filters.
	 *
	 * @var Query
	 */
	private $query;

	/**
	 * Interval of the visitor chart.
	 *
	 * @var string
	 */
	private $interval;

	/**
	 * Report engine.
	 *
	 * @var Reports
	 */
	private $reports;

	/**
	 * Sets up a report.
	 *
	 * @param Query  $query    Period, comparison and filters.
	 * @param string $interval Interval of the chart ('' picks one from the length of the period).
	 */
	private function __construct( Query $query, $interval ) {
		$this->query    = $query;
		$this->interval = $interval;
		$this->reports  = new Reports();
	}

	/**
	 * The report as PDF bytes.
	 *
	 * @param Query  $query    Period, comparison and filters.
	 * @param string $interval Interval of the chart.
	 * @return string
	 */
	public static function build( Query $query, $interval = '' ) {
		return ( new self( $query, $interval ) )->render();
	}

	/**
	 * File name for a period.
	 *
	 * @param Query $query Query.
	 * @return string
	 */
	public static function filename( Query $query ) {
		return sanitize_file_name( 'pulse-analytics-report-' . $query->start . '-to-' . $query->end . '.pdf' );
	}

	/* ------------------------------------------------------------------ *
	 * Layout.
	 * ------------------------------------------------------------------ */

	/**
	 * Draws every section.
	 *
	 * @return string
	 */
	private function render() {
		$this->pdf = new Pdf();
		$this->pdf->add_page();
		$this->y = self::MARGIN;

		$summary = $this->reports->summary( $this->query );
		$this->header( $summary );
		$this->kpis( $summary );
		$this->trend();
		$this->share_columns(
			array( __( 'Traffic channels', 'pulse-analytics' ), $this->shares( 'channel', 8 ) ),
			array( __( 'Devices', 'pulse-analytics' ), $this->shares( 'device', 4 ) )
		);
		$this->table(
			__( 'Top pages', 'pulse-analytics' ),
			array( __( 'Page', 'pulse-analytics' ), __( 'Visitors', 'pulse-analytics' ), __( 'Pageviews', 'pulse-analytics' ) ),
			$this->rows( 'page', 10, array( 'visitors', 'pageviews' ) ),
			array( 0.64, 0.18, 0.18 )
		);
		$this->table_pair(
			array( __( 'Top sources', 'pulse-analytics' ), array( __( 'Source', 'pulse-analytics' ), __( 'Visitors', 'pulse-analytics' ) ), $this->rows( 'source', 8, array( 'visitors' ) ) ),
			array( __( 'Top countries', 'pulse-analytics' ), array( __( 'Country', 'pulse-analytics' ), __( 'Visitors', 'pulse-analytics' ) ), $this->rows( 'country', 8, array( 'visitors' ) ) )
		);
		$campaigns = $this->rows( 'utm_campaign', 8, array( 'visitors', 'visits' ) );
		if ( $campaigns ) {
			$this->table( __( 'Campaigns', 'pulse-analytics' ), array( __( 'UTM campaign', 'pulse-analytics' ), __( 'Visitors', 'pulse-analytics' ), __( 'Visits', 'pulse-analytics' ) ), $campaigns, array( 0.64, 0.18, 0.18 ) );
		}
		$goals  = array( __( 'Goals', 'pulse-analytics' ), array( __( 'Goal', 'pulse-analytics' ), __( 'Conversions', 'pulse-analytics' ), __( 'Rate', 'pulse-analytics' ) ), $this->goal_rows() );
		$events = array( __( 'Actions on the site', 'pulse-analytics' ), array( __( 'Event type', 'pulse-analytics' ), __( 'Events', 'pulse-analytics' ), __( 'Visitors', 'pulse-analytics' ) ), $this->rows( 'event_category', 8, array( 'events', 'visitors' ) ) );
		if ( $goals[2] && $events[2] ) {
			$this->table_pair( $goals, $events, array( 0.52, 0.24, 0.24 ) );
		} elseif ( $goals[2] || $events[2] ) {
			$only = $goals[2] ? $goals : $events;
			$this->table( $only[0], $only[1], $only[2], array( 0.64, 0.18, 0.18 ) );
		}
		$this->footers();

		/* translators: 1: site name, 2: first day, 3: last day */
		return $this->pdf->output( sprintf( __( 'Website statistics for %1$s, %2$s to %3$s', 'pulse-analytics' ), self::site_name(), $this->query->start, $this->query->end ) );
	}

	/**
	 * Starts a new page when the next block does not fit.
	 *
	 * @param float $height Height of the next block.
	 */
	private function ensure( $height ) {
		if ( $this->y + $height > Pdf::HEIGHT - 56 ) {
			$this->pdf->add_page();
			$this->y = self::MARGIN;
		}
	}

	/**
	 * Width between the margins.
	 *
	 * @return float
	 */
	private static function content_width() {
		return Pdf::WIDTH - 2 * self::MARGIN;
	}

	/**
	 * Logo or site name, title, period and filters.
	 *
	 * @param array $summary Summary report.
	 */
	private function header( array $summary ) {
		$pdf   = $this->pdf;
		$left  = self::MARGIN;
		$right = Pdf::WIDTH - self::MARGIN;
		$top   = $this->y;
		$jpeg  = self::logo_jpeg();
		$image = '' !== $jpeg ? $pdf->add_jpeg( $jpeg ) : '';

		if ( '' !== $image ) {
			list( $w, $h ) = $pdf->image_size( $image );
			$scale         = min( 170 / $w, 48 / $h );
			$pdf->image( $image, $left, $top, $w * $scale, $h * $scale );
			$pdf->text( $right, $top + 31, $pdf->fit( self::site_name(), 260, 10 ), array( 'size' => 10, 'align' => 'right' ) );
		} else {
			$pdf->text( $left, $top + 20, $pdf->fit( self::site_name(), 250, 17, true ), array( 'size' => 17, 'bold' => true ) );
			$pdf->text( $left, $top + 36, self::domain(), array( 'size' => 9, 'color' => self::MUTED ) );
		}
		$pdf->text( $right, $top + 15, __( 'Website statistics report', 'pulse-analytics' ), array( 'size' => 15, 'bold' => true, 'align' => 'right' ) );
		$pdf->text( $right, $top + 46, self::range_text( $this->query->start, $this->query->end ), array( 'size' => 10, 'color' => self::MUTED, 'align' => 'right' ) );

		$this->y = $top + 60;
		$pdf->rect( $left, $this->y, $right - $left, 2, self::ACCENT );
		$this->y += 17;

		$filters = Exporter::filter_text( $this->query );
		$parts   = array(
			/* translators: %s: date range */
			$summary['prev_range'] ? sprintf( __( 'Compared with %s', 'pulse-analytics' ), self::range_text( $summary['prev_range'][0], $summary['prev_range'][1] ) ) : __( 'No comparison period', 'pulse-analytics' ),
			/* translators: %s: list of filters */
			$filters ? sprintf( __( 'Filters: %s', 'pulse-analytics' ), implode( '; ', $filters ) ) : __( 'All visits, no filters', 'pulse-analytics' ),
		);
		$pdf->text( $left, $this->y, $pdf->fit( implode( '   |   ', $parts ), $right - $left, 9 ), array( 'size' => 9, 'color' => self::MUTED ) );
		$this->y += 16;
	}

	/**
	 * The six main numbers with the change from the comparison period.
	 *
	 * @param array $summary Summary report.
	 */
	private function kpis( array $summary ) {
		$pdf     = $this->pdf;
		$items   = array(
			'visitors'        => __( 'Visitors', 'pulse-analytics' ),
			'visits'          => __( 'Visits', 'pulse-analytics' ),
			'pageviews'       => __( 'Pageviews', 'pulse-analytics' ),
			'views_per_visit' => __( 'Views per visit', 'pulse-analytics' ),
			'bounce_rate'     => __( 'Bounce rate', 'pulse-analytics' ),
			'avg_duration'    => __( 'Avg. visit duration', 'pulse-analytics' ),
		);
		$gap     = 10;
		$width   = ( self::content_width() - 2 * $gap ) / 3;
		$height  = 62;
		$current = $summary['current'];
		$before  = $summary['previous'];
		$i       = 0;
		foreach ( $items as $key => $label ) {
			$x = self::MARGIN + ( $i % 3 ) * ( $width + $gap );
			$y = $this->y + intdiv( $i, 3 ) * ( $height + $gap );
			$pdf->rect( $x, $y, $width, $height, self::SOFT );
			$pdf->text( $x + 12, $y + 18, $label, array( 'size' => 9, 'color' => self::MUTED ) );
			$value = isset( $current[ $key ] ) ? $current[ $key ] : null;
			$pdf->text( $x + 12, $y + 41, self::metric( $key, $value ), array( 'size' => 19, 'bold' => true ) );
			if ( $before && isset( $before[ $key ] ) && null !== $value && (float) $before[ $key ] > 0 ) {
				$change = ( $value - $before[ $key ] ) / $before[ $key ] * 100;
				$good   = 'bounce_rate' === $key ? $change <= 0 : $change >= 0;
				/* translators: 1: change in percent, such as +12.5%, 2: value in the comparison period */
				$text = sprintf( __( '%1$s from %2$s', 'pulse-analytics' ), ( $change >= 0 ? '+' : '-' ) . number_format_i18n( abs( $change ), 1 ) . '%', self::metric( $key, $before[ $key ] ) );
				$pdf->text( $x + 12, $y + 54, $text, array( 'size' => 8.5, 'color' => $good ? self::GOOD : self::BAD ) );
			}
			++$i;
		}
		$this->y += 2 * $height + $gap + 20;
	}

	/**
	 * Line chart of visitors over the period, with the comparison period in grey.
	 */
	private function trend() {
		$series = $this->reports->timeseries( $this->query, $this->interval );
		$labels = $series['labels'];
		if ( ! $labels ) {
			return;
		}
		$this->ensure( 215 );
		$pdf      = $this->pdf;
		$left     = self::MARGIN;
		$right    = Pdf::WIDTH - self::MARGIN;
		$current  = array_map( 'floatval', $series['current']['visitors'] );
		$previous = ! empty( $series['previous']['visitors'] ) ? array_map( 'floatval', $series['previous']['visitors'] ) : array();
		$titles   = array(
			'hour'  => __( 'Visitors per hour', 'pulse-analytics' ),
			'day'   => __( 'Visitors per day', 'pulse-analytics' ),
			'week'  => __( 'Visitors per week', 'pulse-analytics' ),
			'month' => __( 'Visitors per month', 'pulse-analytics' ),
		);
		$pdf->text( $left, $this->y + 12, $titles[ $series['interval'] ], array( 'size' => 11, 'bold' => true ) );

		// Legend.
		$legend_x = $right;
		if ( $previous ) {
			$legend_x -= $pdf->width( __( 'Previous period', 'pulse-analytics' ), 8.5 );
			$pdf->text( $legend_x, $this->y + 11, __( 'Previous period', 'pulse-analytics' ), array( 'size' => 8.5, 'color' => self::MUTED ) );
			$pdf->line( $legend_x - 20, $this->y + 8, $legend_x - 6, $this->y + 8, '#a7aaad', 1.5, true );
			$legend_x -= 34;
		}
		$legend_x -= $pdf->width( __( 'This period', 'pulse-analytics' ), 8.5 );
		$pdf->text( $legend_x, $this->y + 11, __( 'This period', 'pulse-analytics' ), array( 'size' => 8.5, 'color' => self::MUTED ) );
		$pdf->line( $legend_x - 20, $this->y + 8, $legend_x - 6, $this->y + 8, self::ACCENT, 2 );

		$top    = $this->y + 28;
		$height = 150;
		$bottom = $top + $height;
		$plot_l = $left + 34;
		$plot_r = $right - 4;
		$max    = self::nice_max( max( max( $current ), $previous ? max( $previous ) : 0 ) );
		for ( $g = 0; $g <= 4; $g++ ) {
			$gy = $bottom - $height * $g / 4;
			$pdf->line( $plot_l, $gy, $plot_r, $gy, 0 === $g ? '#c3c4c7' : '#ececec', 0.6 );
			$pdf->text( $plot_l - 6, $gy + 3, number_format_i18n( $max * $g / 4 ), array( 'size' => 7.5, 'color' => self::MUTED, 'align' => 'right' ) );
		}

		$count = count( $labels );
		$step  = $count > 1 ? ( $plot_r - $plot_l ) / ( $count - 1 ) : 0;
		$point = static function ( $i, $value ) use ( $plot_l, $plot_r, $step, $count, $bottom, $height, $max ) {
			$x = $count > 1 ? $plot_l + $i * $step : ( $plot_l + $plot_r ) / 2;
			return array( $x, $bottom - $height * min( $value, $max ) / $max );
		};
		if ( $previous ) {
			$points = array();
			foreach ( array_slice( $previous, 0, $count ) as $i => $value ) {
				$points[] = $point( $i, $value );
			}
			$pdf->polyline( 1 === count( $points ) ? array( array( $plot_l, $points[0][1] ), array( $plot_r, $points[0][1] ) ) : $points, '#a7aaad', 1.2, true );
		}
		$points = array();
		foreach ( $current as $i => $value ) {
			$points[] = $point( $i, $value );
		}
		if ( 1 === count( $points ) ) {
			$points = array( array( $plot_l, $points[0][1] ), array( $plot_r, $points[0][1] ) );
		}
		$pdf->polyline( $points, self::ACCENT, 1.8, false, '#dbe8f8', $bottom );

		// Up to seven labels under the chart.
		$shown = array_unique( array_map( 'intval', array_map( 'round', range( 0, $count - 1, max( 1, ( $count - 1 ) / 6 ) ) ) ) );
		foreach ( $shown as $i ) {
			if ( isset( $labels[ $i ] ) ) {
				$pdf->text( $count > 1 ? $plot_l + $i * $step : ( $plot_l + $plot_r ) / 2, $bottom + 13, self::bucket_label( $labels[ $i ], $series['interval'] ), array( 'size' => 7.5, 'color' => self::MUTED, 'align' => 'center' ) );
			}
		}
		$this->y = $bottom + 34;
	}

	/**
	 * Two lists of shares (label, percentage and bar) next to each other.
	 *
	 * @param array $left_block  Title and rows.
	 * @param array $right_block Title and rows.
	 */
	private function share_columns( array $left_block, array $right_block ) {
		$gap    = 24;
		$width  = ( self::content_width() - $gap ) / 2;
		$rows   = max( 1, count( $left_block[1] ), count( $right_block[1] ) );
		$height = 30 + $rows * 22;
		$this->ensure( $height );
		$this->share_block( self::MARGIN, $width, $left_block[0], $left_block[1] );
		$this->share_block( self::MARGIN + $width + $gap, $width, $right_block[0], $right_block[1] );
		$this->y += $height + 14;
	}

	/**
	 * One list of shares.
	 *
	 * @param float  $x     Left edge.
	 * @param float  $width Width.
	 * @param string $title Title.
	 * @param array  $rows  Rows of label and share (0 to 1).
	 */
	private function share_block( $x, $width, $title, array $rows ) {
		$pdf = $this->pdf;
		$pdf->text( $x, $this->y + 12, $title, array( 'size' => 11, 'bold' => true ) );
		$y = $this->y + 34;
		if ( ! $rows ) {
			$pdf->text( $x, $y, __( 'No data for this period.', 'pulse-analytics' ), array( 'size' => 9, 'color' => self::MUTED ) );
			return;
		}
		foreach ( $rows as $row ) {
			$share = number_format_i18n( $row[1] * 100, 1 ) . '%';
			$pdf->text( $x, $y, $pdf->fit( $row[0], $width - 50, 9 ), array( 'size' => 9 ) );
			$pdf->text( $x + $width, $y, $share, array( 'size' => 9, 'bold' => true, 'align' => 'right' ) );
			$pdf->rect( $x, $y + 4, $width, 4, '#e8eaed' );
			$pdf->rect( $x, $y + 4, max( 0.5, $width * $row[1] ), 4, self::ACCENT );
			$y += 22;
		}
	}

	/**
	 * A table across the full width.
	 *
	 * @param string $title   Title.
	 * @param array  $headers Column titles.
	 * @param array  $rows    Rows of cells.
	 * @param array  $widths  Column widths as fractions.
	 */
	private function table( $title, array $headers, array $rows, array $widths ) {
		$height = 36 + max( 1, count( $rows ) ) * self::ROW;
		$this->ensure( $height );
		$this->draw_table( self::MARGIN, self::content_width(), $title, $headers, $rows, $widths );
		$this->y += $height + 12;
	}

	/**
	 * Two tables next to each other.
	 *
	 * @param array $left_table  Title, headers and rows.
	 * @param array $right_table Title, headers and rows.
	 * @param array $widths      Column widths as fractions.
	 */
	private function table_pair( array $left_table, array $right_table, array $widths = array( 0.7, 0.3 ) ) {
		$gap    = 24;
		$width  = ( self::content_width() - $gap ) / 2;
		$height = 36 + max( 1, count( $left_table[2] ), count( $right_table[2] ) ) * self::ROW;
		$this->ensure( $height );
		$this->draw_table( self::MARGIN, $width, $left_table[0], $left_table[1], $left_table[2], $widths );
		$this->draw_table( self::MARGIN + $width + $gap, $width, $right_table[0], $right_table[1], $right_table[2], $widths );
		$this->y += $height + 12;
	}

	/**
	 * Draws a table at the current height.
	 *
	 * @param float  $x       Left edge.
	 * @param float  $width   Width.
	 * @param string $title   Title.
	 * @param array  $headers Column titles.
	 * @param array  $rows    Rows of cells; the first cell is text, the others numbers.
	 * @param array  $widths  Column widths as fractions.
	 */
	private function draw_table( $x, $width, $title, array $headers, array $rows, array $widths ) {
		$pdf = $this->pdf;
		$pdf->text( $x, $this->y + 12, $title, array( 'size' => 11, 'bold' => true ) );
		$y     = $this->y + 30;
		$edges = array();
		$cx    = $x;
		foreach ( $widths as $fraction ) {
			$edges[] = array( $cx, $cx + $width * $fraction );
			$cx     += $width * $fraction;
		}
		foreach ( $headers as $i => $header ) {
			$pdf->text( 0 === $i ? $edges[ $i ][0] : $edges[ $i ][1], $y, $header, array( 'size' => 8, 'bold' => true, 'color' => self::MUTED, 'align' => 0 === $i ? 'left' : 'right' ) );
		}
		$pdf->line( $x, $y + 5, $x + $width, $y + 5, '#c3c4c7', 0.8 );
		if ( ! $rows ) {
			$pdf->text( $x, $y + 20, __( 'No data for this period.', 'pulse-analytics' ), array( 'size' => 9, 'color' => self::MUTED ) );
			return;
		}
		foreach ( $rows as $row ) {
			$y += self::ROW;
			foreach ( $row as $i => $cell ) {
				if ( 0 === $i ) {
					$pdf->text( $edges[0][0], $y, $pdf->fit( $cell, $edges[0][1] - $edges[0][0] - 10, 9 ), array( 'size' => 9 ) );
				} else {
					$pdf->text( $edges[ $i ][1], $y, $cell, array( 'size' => 9, 'align' => 'right' ) );
				}
			}
			$pdf->line( $x, $y + 6, $x + $width, $y + 6, '#ececec', 0.5 );
		}
	}

	/**
	 * Page numbers and the creation date at the bottom of every page.
	 */
	private function footers() {
		$pdf   = $this->pdf;
		$total = $pdf->page_count();
		/* translators: %s: date */
		$created = sprintf( __( 'Created on %s with Pulse Analytics', 'pulse-analytics' ), self::date_text( Helpers::today() ) );
		for ( $page = 0; $page < $total; $page++ ) {
			$pdf->text( self::MARGIN, Pdf::HEIGHT - 26, $created, array( 'size' => 7.5, 'color' => self::MUTED, 'page' => $page ) );
			/* translators: 1: page number, 2: number of pages */
			$pdf->text( Pdf::WIDTH - self::MARGIN, Pdf::HEIGHT - 26, sprintf( __( 'Page %1$d of %2$d', 'pulse-analytics' ), $page + 1, $total ), array( 'size' => 7.5, 'color' => self::MUTED, 'align' => 'right', 'page' => $page ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Data.
	 * ------------------------------------------------------------------ */

	/**
	 * Breakdown rows as table cells.
	 *
	 * @param string   $dimension Dimension.
	 * @param int      $limit     Rows.
	 * @param string[] $metrics   Number columns.
	 * @return array
	 */
	private function rows( $dimension, $limit, array $metrics ) {
		$result = $this->reports->breakdown( $this->query, $dimension, array( 'limit' => $limit ) );
		$rows   = array();
		foreach ( $result['rows'] as $row ) {
			$label = (string) $row['label'];
			if ( 'page' === $dimension && ! Pdf::representable( $label ) ) {
				// The standard PDF fonts cannot show every script; the path always works.
				$label = (string) $row['key'];
			}
			$cells = array( $label );
			foreach ( $metrics as $metric ) {
				$cells[] = isset( $row[ $metric ] ) ? number_format_i18n( (float) $row[ $metric ] ) : '-';
			}
			$rows[] = $cells;
		}
		return $rows;
	}

	/**
	 * Share of visits per row.
	 *
	 * @param string $dimension Dimension.
	 * @param int    $limit     Rows.
	 * @return array Rows of label and share (0 to 1).
	 */
	private function shares( $dimension, $limit ) {
		$result = $this->reports->breakdown( $this->query, $dimension, array( 'limit' => $limit ) );
		$total  = isset( $result['totals']['visits'] ) ? (float) $result['totals']['visits'] : 0;
		if ( $total <= 0 ) {
			$total = array_sum( wp_list_pluck( $result['rows'], 'visits' ) );
		}
		$rows = array();
		foreach ( $result['rows'] as $row ) {
			if ( $total > 0 && isset( $row['visits'] ) ) {
				$rows[] = array( (string) $row['label'], min( 1, $row['visits'] / $total ) );
			}
		}
		return $rows;
	}

	/**
	 * Goals as table cells.
	 *
	 * @return array
	 */
	private function goal_rows() {
		$rows = array();
		foreach ( $this->reports->goals( $this->query )['goals'] as $goal ) {
			$rows[] = array( (string) $goal['name'], number_format_i18n( (int) $goal['conversions'] ), number_format_i18n( (float) $goal['cr'], 2 ) . '%' );
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ *
	 * Formatting.
	 * ------------------------------------------------------------------ */

	/**
	 * A metric value for display.
	 *
	 * @param string    $key   Metric.
	 * @param float|int $value Value.
	 * @return string
	 */
	private static function metric( $key, $value ) {
		if ( null === $value ) {
			return '-';
		}
		switch ( $key ) {
			case 'views_per_visit':
				return number_format_i18n( (float) $value, 2 );
			case 'bounce_rate':
				return number_format_i18n( (float) $value, 1 ) . '%';
			case 'avg_duration':
				return self::duration( (int) round( $value ) );
		}
		return number_format_i18n( (float) $value );
	}

	/**
	 * Seconds as "3m 05s".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function duration( $seconds ) {
		if ( $seconds >= 3600 ) {
			return sprintf( '%dh %02dm', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
		}
		if ( $seconds >= 60 ) {
			return sprintf( '%dm %02ds', intdiv( $seconds, 60 ), $seconds % 60 );
		}
		return $seconds . 's';
	}

	/**
	 * A date in the site's format, or Y-m-d when the font cannot show it.
	 *
	 * @param string $ymd Date.
	 * @param string $format Date format.
	 * @return string
	 */
	private static function date_text( $ymd, $format = 'j M Y' ) {
		$time = strtotime( $ymd . ' 12:00:00' );
		$text = $time ? wp_date( $format, $time ) : '';
		return $text && Pdf::representable( $text ) ? $text : $ymd;
	}

	/**
	 * "1 Sep 2026 to 30 Sep 2026".
	 *
	 * @param string $start First day.
	 * @param string $end   Last day.
	 * @return string
	 */
	private static function range_text( $start, $end ) {
		if ( $start === $end ) {
			return self::date_text( $start );
		}
		/* translators: 1: first day, 2: last day */
		return sprintf( __( '%1$s to %2$s', 'pulse-analytics' ), self::date_text( $start ), self::date_text( $end ) );
	}

	/**
	 * Label of a chart point.
	 *
	 * @param string $label    Bucket label from the report.
	 * @param string $interval Interval.
	 * @return string
	 */
	private static function bucket_label( $label, $interval ) {
		if ( 'hour' === $interval ) {
			return substr( $label, 11, 5 );
		}
		if ( 'month' === $interval ) {
			return self::date_text( substr( $label, 0, 7 ) . '-01', 'M Y' );
		}
		return self::date_text( substr( $label, 0, 10 ), 'j M' );
	}

	/**
	 * Round number at or above a value, with four even steps.
	 *
	 * @param float $value Highest value in the chart.
	 * @return float
	 */
	private static function nice_max( $value ) {
		if ( $value <= 0 ) {
			return 4;
		}
		$raw  = $value / 4;
		$base = pow( 10, floor( log10( $raw ) ) );
		foreach ( array( 1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10 ) as $step ) {
			if ( $step * $base >= $raw ) {
				return max( 4, ceil( $step * $base ) * 4 );
			}
		}
		return 40 * $base;
	}

	/**
	 * Site name, or the domain when the font cannot show it.
	 *
	 * @return string
	 */
	private static function site_name() {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return '' !== trim( $name ) && Pdf::representable( $name ) ? $name : self::domain();
	}

	/**
	 * Domain of the site.
	 *
	 * @return string
	 */
	private static function domain() {
		return (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	}

	/* ------------------------------------------------------------------ *
	 * Logo.
	 * ------------------------------------------------------------------ */

	/**
	 * Attachment id of the report logo: the one set in the settings, else the theme's logo.
	 *
	 * @return int
	 */
	public static function logo_id() {
		$id = (int) Settings::get( 'report_logo' );
		if ( ! $id ) {
			$id = (int) get_theme_mod( 'custom_logo' );
		}
		return $id && wp_attachment_is_image( $id ) ? $id : 0;
	}

	/**
	 * Address of the logo, for emails.
	 *
	 * @return string
	 */
	public static function logo_url() {
		$id = self::logo_id();
		return $id ? (string) wp_get_attachment_image_url( $id, 'medium' ) : '';
	}

	/**
	 * The logo as JPEG bytes on a white background, scaled down for the PDF.
	 *
	 * @return string
	 */
	private static function logo_jpeg() {
		$id   = self::logo_id();
		$file = $id ? get_attached_file( $id ) : '';
		// A resized copy is plenty for a logo and saves memory with large uploads.
		$size = $id ? image_get_intermediate_size( $id, 'medium_large' ) : false;
		if ( $size && ! empty( $size['path'] ) ) {
			$uploads = wp_get_upload_dir();
			$smaller = trailingslashit( $uploads['basedir'] ) . $size['path'];
			$file    = is_readable( $smaller ) ? $smaller : $file;
		}
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}
		$data = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' ) ) {
			$source = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $source ) {
				$w     = imagesx( $source );
				$h     = imagesy( $source );
				$scale = min( 1, 680 / $w, 192 / $h );
				$nw    = max( 1, (int) round( $w * $scale ) );
				$nh    = max( 1, (int) round( $h * $scale ) );
				$image = imagecreatetruecolor( $nw, $nh );
				imagefill( $image, 0, 0, imagecolorallocate( $image, 255, 255, 255 ) );
				imagecopyresampled( $image, $source, 0, 0, 0, 0, $nw, $nh, $w, $h );
				ob_start();
				imagejpeg( $image, null, 90 );
				$jpeg = (string) ob_get_clean();
				imagedestroy( $source );
				imagedestroy( $image );
				return $jpeg;
			}
		}
		// Without GD only JPEG files can be used as they are.
		$type = wp_check_filetype( $file );
		return 'image/jpeg' === $type['type'] ? $data : '';
	}

	/* ------------------------------------------------------------------ *
	 * Email.
	 * ------------------------------------------------------------------ */

	/**
	 * Valid addresses from a list typed by a person.
	 *
	 * @param string $raw Addresses separated by commas, semicolons or new lines.
	 * @return string[]|\WP_Error
	 */
	public static function recipients( $raw ) {
		$parts = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		$valid = array();
		foreach ( $parts as $part ) {
			$email = sanitize_email( $part );
			if ( ! is_email( $email ) ) {
				/* translators: %s: what was typed */
				return new \WP_Error( 'pulse_report_to', sprintf( __( '"%s" is not a valid email address.', 'pulse-analytics' ), $part ), array( 'status' => 400 ) );
			}
			$valid[] = $email;
		}
		$valid = array_values( array_unique( $valid ) );
		if ( ! $valid ) {
			return new \WP_Error( 'pulse_report_to', __( 'Add at least one email address.', 'pulse-analytics' ), array( 'status' => 400 ) );
		}
		if ( count( $valid ) > 10 ) {
			return new \WP_Error( 'pulse_report_to', __( 'You can send the report to up to 10 addresses at a time.', 'pulse-analytics' ), array( 'status' => 400 ) );
		}
		return $valid;
	}

	/**
	 * Default email subject for a period.
	 *
	 * @param Query $query Query.
	 * @return string
	 */
	public static function default_subject( Query $query ) {
		/* translators: 1: site name, 2: date range */
		return sprintf( __( 'Website statistics for %1$s: %2$s', 'pulse-analytics' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), self::range_text( $query->start, $query->end ) );
	}

	/**
	 * Counts a report email against the hourly limit of the current user.
	 *
	 * @return bool False when the limit is reached.
	 */
	public static function allow_send() {
		$key   = 'pulse_analytics_mail_' . get_current_user_id();
		$count = (int) get_transient( $key );
		if ( $count >= self::MAIL_LIMIT ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Sends the PDF report with a message.
	 *
	 * @param Query    $query    Period, comparison and filters.
	 * @param string[] $to       Recipients.
	 * @param string   $subject  Subject.
	 * @param string   $message  Message from the sender.
	 * @param string   $interval Interval of the chart.
	 * @return true|\WP_Error
	 */
	public static function send( Query $query, array $to, $subject, $message, $interval = '' ) {
		$file = self::temp_file( $query, $interval );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$user    = wp_get_current_user();
		if ( $user->exists() && is_email( $user->user_email ) ) {
			$headers[] = 'Reply-To: ' . $user->user_email;
		}
		$subject = '' !== trim( (string) $subject ) ? $subject : self::default_subject( $query );
		$sent    = wp_mail( $to, $subject, self::email_html( $query, $message ), $headers, array( $file ) );
		self::delete_temp_file( $file );
		return $sent ? true : new \WP_Error( 'pulse_report_mail', __( 'The email could not be sent. Check that your site can send email, for example with an SMTP plugin.', 'pulse-analytics' ), array( 'status' => 500 ) );
	}

	/**
	 * Writes the report to a temporary file named after the period.
	 *
	 * @param Query  $query    Query.
	 * @param string $interval Interval.
	 * @return string|\WP_Error Path.
	 */
	public static function temp_file( Query $query, $interval = '' ) {
		$dir = trailingslashit( get_temp_dir() ) . 'pulse-report-' . wp_generate_password( 12, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'pulse_report_file', __( 'Could not create a temporary file for the report.', 'pulse-analytics' ), array( 'status' => 500 ) );
		}
		$file = $dir . '/' . self::filename( $query );
		file_put_contents( $file, self::build( $query, $interval ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $file;
	}

	/**
	 * Removes a temporary report and its folder.
	 *
	 * @param string $file Path.
	 */
	public static function delete_temp_file( $file ) {
		wp_delete_file( $file );
		@rmdir( dirname( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Email body: logo, the sender's message and the main numbers.
	 *
	 * @param Query  $query   Query.
	 * @param string $message Message from the sender.
	 * @return string
	 */
	private static function email_html( Query $query, $message ) {
		$summary = ( new Reports() )->summary( $query );
		$logo    = self::logo_url();
		$site    = get_bloginfo( 'name' );
		$html    = '<div style="background:#f6f7f7;padding:24px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">';
		$html   .= '<div style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e1e0d9;border-radius:8px;overflow:hidden;">';
		$html   .= '<div style="padding:20px 24px;border-bottom:1px solid #e1e0d9;">';
		$html   .= $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site ) . '" style="display:block;max-height:48px;max-width:240px;border:0;">' : '<div style="font-size:20px;font-weight:600;color:#0b0b0b;">' . esc_html( $site ) . '</div>';
		/* translators: %s: date range */
		$html   .= '<div style="font-size:13px;color:#52514e;margin-top:8px;">' . esc_html( sprintf( __( 'Website statistics, %s', 'pulse-analytics' ), self::range_text( $query->start, $query->end ) ) ) . '</div>';
		$filters = Exporter::filter_text( $query );
		if ( $filters ) {
			/* translators: %s: list of filters */
			$html .= '<div style="font-size:13px;color:#52514e;margin-top:2px;">' . esc_html( sprintf( __( 'Filters: %s', 'pulse-analytics' ), implode( '; ', $filters ) ) ) . '</div>';
		}
		$html .= '</div>';
		if ( '' !== trim( (string) $message ) ) {
			$html .= '<div style="padding:20px 24px 4px;font-size:14px;line-height:1.6;color:#1d2327;">' . wpautop( esc_html( $message ) ) . '</div>';
		}
		$html .= '<table role="presentation" style="width:100%;border-collapse:collapse;margin:8px 0;"><tr>';
		foreach ( array(
			'visitors'  => __( 'Visitors', 'pulse-analytics' ),
			'visits'    => __( 'Visits', 'pulse-analytics' ),
			'pageviews' => __( 'Pageviews', 'pulse-analytics' ),
		) as $key => $label ) {
			$value  = isset( $summary['current'][ $key ] ) ? (float) $summary['current'][ $key ] : 0;
			$change = '';
			if ( $summary['previous'] && ! empty( $summary['previous'][ $key ] ) ) {
				$pct    = ( $value - $summary['previous'][ $key ] ) / $summary['previous'][ $key ] * 100;
				$change = '<div style="font-size:12px;color:' . ( $pct >= 0 ? '#006300' : '#d03b3b' ) . ';">' . ( $pct >= 0 ? '+' : '-' ) . esc_html( number_format_i18n( abs( $pct ), 1 ) ) . '%</div>';
			}
			$html .= '<td style="padding:12px;text-align:center;vertical-align:top;"><div style="font-size:12px;color:#52514e;">' . esc_html( $label ) . '</div><div style="font-size:20px;font-weight:600;color:#0b0b0b;margin:4px 0;">' . esc_html( number_format_i18n( $value ) ) . '</div>' . $change . '</td>';
		}
		$html .= '</tr></table>';
		$html .= '<div style="padding:4px 24px 20px;font-size:13px;color:#52514e;">' . esc_html__( 'The full report is attached as a PDF file.', 'pulse-analytics' ) . '</div>';
		$html .= '</div></div>';
		return $html;
	}
}
