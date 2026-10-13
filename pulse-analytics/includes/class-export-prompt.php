<?php
/**
 * Prompt that explains an export to an AI tool.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes a CSV export, or the ZIP with all of them, so that an AI tool such as
 * ChatGPT, Claude or Gemini knows what the files and columns mean before it starts
 * on the numbers.
 */
class Export_Prompt {

	/**
	 * Builds the prompt.
	 *
	 * @param string  $type      'all', 'summary', 'sessions', 'pageviews', 'events', 'goals' or 'breakdown'.
	 * @param string  $dimension Dimension of a breakdown.
	 * @param Query   $query     Period, comparison and filters of the export.
	 * @param string  $interval  Interval of the trend in the summary.
	 * @param Reports $reports   Report engine.
	 * @return string
	 */
	public static function build( $type, $dimension, Query $query, $interval, Reports $reports ) {
		if ( 'all' === $type ) {
			$parts = array(
				__( 'I am attaching statistics from my website as a set of CSV files, exported from Pulse Analytics, a WordPress analytics plugin. Please read these notes first, then analyse the files.', 'pulse-analytics' ),
				self::section( __( 'About the data', 'pulse-analytics' ), self::about( $query ) ),
				self::section( __( 'The files', 'pulse-analytics' ), self::zip_files( $query, $interval, $reports ) ),
				self::section( __( 'What the columns mean', 'pulse-analytics' ), self::glossary( $query ) ),
			);
		} else {
			$parts = array(
				__( 'I am attaching a CSV file with statistics from my website, exported from Pulse Analytics, a WordPress analytics plugin. Please read these notes first, then analyse the file.', 'pulse-analytics' ),
				self::section( __( 'About the data', 'pulse-analytics' ), self::about( $query ) ),
				self::section( __( 'The file', 'pulse-analytics' ), self::file( $type, $dimension, $query, $interval, $reports ) ),
				self::section( __( 'What the columns mean', 'pulse-analytics' ), self::columns( $type, $dimension, $query, $reports ) ),
			);
		}
		$parts[] = self::section( __( 'What I would like from you', 'pulse-analytics' ), self::tasks( $type, $dimension, $query ) );
		return implode( "\n\n", $parts ) . "\n";
	}

	/**
	 * A titled block of lines.
	 *
	 * @param string   $title Title.
	 * @param string[] $lines Lines.
	 * @return string
	 */
	private static function section( $title, array $lines ) {
		return $title . "\n" . implode( "\n", $lines );
	}

	/**
	 * Prefixes lines with a dash.
	 *
	 * @param string[] $lines Lines.
	 * @return string[]
	 */
	private static function bullets( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			$out[] = '- ' . $line;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * About the data.
	 * ------------------------------------------------------------------ */

	/**
	 * Period, filters and how the numbers were collected.
	 *
	 * @param Query $query Query.
	 * @return string[]
	 */
	private static function about( Query $query ) {
		$days     = $query->days();
		$previous = $query->previous();
		$filters  = Exporter::filter_text( $query );
		$timeout  = max( 5, (int) Settings::get( 'session_timeout' ) );

		/* translators: 1: site name, 2: site address */
		$lines = array( sprintf( __( 'Website: %1$s (%2$s)', 'pulse-analytics' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), home_url( '/' ) ) );
		/* translators: 1: first day, 2: last day, 3: number of days */
		$lines[] = sprintf( _n( 'Period: %1$s to %2$s (%3$s day)', 'Period: %1$s to %2$s (%3$s days)', $days, 'pulse-analytics' ), $query->start, $query->end, number_format_i18n( $days ) );
		if ( $previous && 'year' === $query->compare ) {
			/* translators: 1: first day, 2: last day */
			$lines[] = sprintf( __( 'Compared with: %1$s to %2$s, the same dates a year earlier', 'pulse-analytics' ), $previous->start, $previous->end );
		} elseif ( $previous ) {
			/* translators: 1: first day, 2: last day */
			$lines[] = sprintf( __( 'Compared with: %1$s to %2$s, the period just before', 'pulse-analytics' ), $previous->start, $previous->end );
		} else {
			$lines[] = __( 'Compared with: nothing, this export has no comparison period', 'pulse-analytics' );
		}
		/* translators: %s: list of filters */
		$lines[] = $filters ? sprintf( __( 'Filters: %s. Everything in the export is limited to these visits.', 'pulse-analytics' ), implode( '; ', $filters ) ) : __( 'Filters: none, all tracked visits are included', 'pulse-analytics' );
		/* translators: %s: time zone, such as Asia/Colombo */
		$lines[] = sprintf( __( 'Dates and times are in the website\'s time zone (%s).', 'pulse-analytics' ), wp_timezone_string() );
		$lines[] = __( 'The files are UTF-8 CSV with a header row. Durations are in seconds, and percentages are numbers from 0 to 100.', 'pulse-analytics' );
		if ( $previous ) {
			$lines[] = __( 'A column whose name ends in "(previous period)" holds the same figure for the comparison period. An empty cell there means the row had no data in that period.', 'pulse-analytics' );
		}
		$lines[] = __( 'A text cell that starts with an apostrophe, such as \'+1 202 555 0143, got the apostrophe on export so that spreadsheet programs do not read it as a formula. Ignore the apostrophe.', 'pulse-analytics' );
		$lines[] = 'cookie' === Settings::get( 'tracking_mode' )
			? __( 'Visitors are recognised by an anonymous ID stored in their browser, so a returning visitor counts once for the whole period unless they clear their browser data or switch devices.', 'pulse-analytics' )
			: __( 'Visitors are counted without cookies, using an anonymous ID that changes every day. The same person on two different days counts as two visitors, so visitor totals for several days are closer to the sum of the daily figures, and "returning" only covers repeat visits on the same day.', 'pulse-analytics' );
		/* translators: %s: number of minutes */
		$lines[] = sprintf( _n( 'A visit ends after %s minute without activity.', 'A visit ends after %s minutes without activity.', $timeout, 'pulse-analytics' ), number_format_i18n( $timeout ) );
		$roles = self::excluded_roles();
		/* translators: %s: list of user roles */
		$lines[] = $roles ? sprintf( __( 'Bots and crawlers are not counted, and neither are logged-in users with these roles: %s.', 'pulse-analytics' ), implode( ', ', $roles ) ) : __( 'Bots and crawlers are not counted.', 'pulse-analytics' );
		if ( Settings::get( 'respect_dnt' ) ) {
			$lines[] = __( 'Visitors whose browser sends a Do Not Track signal are not counted.', 'pulse-analytics' );
		}
		if ( Settings::get( 'require_consent' ) ) {
			$lines[] = __( 'Visits are only counted after the visitor accepts the cookie banner, so the real traffic is higher than these numbers.', 'pulse-analytics' );
		}
		if ( Demo_Data::exists() ) {
			$lines[] = __( 'Warning: the site still contains sample visits that the plugin generated for testing. These numbers probably do not describe real visitors.', 'pulse-analytics' );
		}
		return self::bullets( $lines );
	}

	/**
	 * Names of the user roles that are not counted.
	 *
	 * @return string[]
	 */
	private static function excluded_roles() {
		$names = wp_roles()->get_names();
		$out   = array();
		foreach ( (array) Settings::get( 'exclude_roles' ) as $role ) {
			$out[] = isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : (string) $role;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Files.
	 * ------------------------------------------------------------------ */

	/**
	 * The single file of the export.
	 *
	 * @param string  $type      Report type.
	 * @param string  $dimension Dimension.
	 * @param Query   $query     Query.
	 * @param string  $interval  Interval.
	 * @param Reports $reports   Report engine.
	 * @return string[]
	 */
	private static function file( $type, $dimension, Query $query, $interval, Reports $reports ) {
		/* translators: %s: file name */
		$lines = array( sprintf( __( 'File: %s', 'pulse-analytics' ), Exporter::filename( $type, $dimension, $query ) ) );
		/* translators: %s: what the rows of the file are */
		$lines[] = sprintf( __( 'Contents: %s', 'pulse-analytics' ), self::describe( $type, $dimension, $query, $interval, $reports ) );
		if ( in_array( $type, array( 'sessions', 'pageviews', 'events' ), true ) ) {
			$lines[] = __( 'The file can be large. Use code, for example Python with pandas, to analyse it rather than reading it line by line.', 'pulse-analytics' );
		}
		return self::bullets( $lines );
	}

	/**
	 * The files in the ZIP.
	 *
	 * @param Query   $query    Query.
	 * @param string  $interval Interval.
	 * @param Reports $reports  Report engine.
	 * @return string[]
	 */
	private static function zip_files( Query $query, $interval, Reports $reports ) {
		$files    = array();
		$utm      = array();
		$utm_line = 0;
		foreach ( Exporter::zip_contents() as $name => $report ) {
			// The five UTM files work the same way, so they share one line.
			if ( 0 === strpos( $report[1], 'utm_' ) ) {
				if ( ! $utm ) {
					$utm_line = count( $files );
					$files[]  = '';
				}
				$utm[ $name ] = $report[1];
				continue;
			}
			$files[] = $name . ': ' . self::describe( $report[0], $report[1], $query, $interval, $reports );
		}
		if ( $utm ) {
			/* translators: %s: list of URL parameters, such as utm_campaign */
			$files[ $utm_line ] = implode( ', ', array_keys( $utm ) ) . ': ' . sprintf( __( 'visits grouped by the campaign tags of the link the visitor arrived on (%s), one file per tag. Visits without the tag are left out.', 'pulse-analytics' ), implode( ', ', $utm ) );
		}
		$files[] = 'about-this-export.txt: ' . __( 'a short summary of the export.', 'pulse-analytics' );
		$files[] = 'prompt-for-ai.txt: ' . __( 'these notes.', 'pulse-analytics' );

		/* translators: %s: folder name */
		$lines = array( sprintf( __( 'The ZIP file has a folder named %s with these files:', 'pulse-analytics' ), 'pulse-analytics-' . $query->start . '-to-' . $query->end ) );
		$lines = array_merge( $lines, self::bullets( $files ) );
		$lines[] = __( 'The files whose names start with "raw-" have one row per visit, pageview or event and can be large. Use code, for example Python with pandas, to analyse them rather than reading them line by line.', 'pulse-analytics' );
		return $lines;
	}

	/**
	 * What one row of a report stands for.
	 *
	 * @param string  $type      Report type.
	 * @param string  $dimension Dimension.
	 * @param Query   $query     Query.
	 * @param string  $interval  Interval.
	 * @param Reports $reports   Report engine.
	 * @return string
	 */
	private static function describe( $type, $dimension, Query $query, $interval, Reports $reports ) {
		switch ( $type ) {
			case 'summary':
				$rows = array(
					'hour'  => __( 'one row per hour', 'pulse-analytics' ),
					'day'   => __( 'one row per day', 'pulse-analytics' ),
					'week'  => __( 'one row per week', 'pulse-analytics' ),
					'month' => __( 'one row per month', 'pulse-analytics' ),
				);
				$every = $rows[ $reports->resolve_interval( $query, $interval ) ];
				/* translators: %s: "one row per day" or similar */
				return sprintf( __( 'two tables. The first has the main figures (visitors, visits, pageviews, views per visit, bounce rate and average visit length) for the period and the comparison period, with the change in percent. After an empty row, the second table shows the same figures over time, %s.', 'pulse-analytics' ), $every );
			case 'goals':
				return __( 'one row per goal set up in the plugin, with the number of visitors who reached it, how often it was reached and the conversion rate.', 'pulse-analytics' );
			case 'sessions':
				return __( 'one row per visit (session) with its time, length, number of pages, entry and exit page, traffic source, campaign tags, location, device and browser.', 'pulse-analytics' );
			case 'pageviews':
				return __( 'one row per pageview with its time, page, previous page, time on page, scroll depth, location, device and traffic source.', 'pulse-analytics' );
			case 'events':
				return __( 'one row per tracked event, such as a link click, download, form submission, site search or 404 error, with its page, location, device and traffic source.', 'pulse-analytics' );
		}

		$utm = array(
			'utm_source'   => 'utm_source',
			'utm_medium'   => 'utm_medium',
			'utm_campaign' => 'utm_campaign',
			'utm_term'     => 'utm_term',
			'utm_content'  => 'utm_content',
		);
		if ( isset( $utm[ $dimension ] ) ) {
			/* translators: %s: name of a URL parameter, such as utm_campaign */
			return sprintf( __( 'visits grouped by the %s tag of the campaign link the visitor arrived on. Visits without the tag are left out.', 'pulse-analytics' ), $utm[ $dimension ] );
		}
		$rows = array(
			'page'           => __( 'one row per page, identified by its path, for pages viewed in the period.', 'pulse-analytics' ),
			'entry_page'     => __( 'one row per page where visits started (landing pages).', 'pulse-analytics' ),
			'exit_page'      => __( 'one row per page where visits ended.', 'pulse-analytics' ),
			'prev_page'      => __( 'the pages viewed just before the page in the filter.', 'pulse-analytics' ),
			'next_page'      => __( 'the pages viewed just after the page in the filter, including leaving the site.', 'pulse-analytics' ),
			'post_type'      => __( 'pageviews grouped by content type, such as posts, pages or products.', 'pulse-analytics' ),
			'author'         => __( 'pageviews grouped by the author of the post.', 'pulse-analytics' ),
			'category'       => __( 'pageviews grouped by post category.', 'pulse-analytics' ),
			/* translators: %s: list of channel names */
			'channel'        => sprintf( __( 'visits grouped by channel. The channels are: %s.', 'pulse-analytics' ), implode( ', ', Referrer::channels() ) ),
			'source'         => __( 'visits grouped by traffic source: a search engine, social network, website, or the utm_source of a campaign link. Direct visits have no source.', 'pulse-analytics' ),
			'referrer'       => __( 'visits grouped by the website that linked to this site.', 'pulse-analytics' ),
			'referrer_url'   => __( 'visits grouped by the exact page on another website that linked to this site.', 'pulse-analytics' ),
			'country'        => __( 'visits grouped by country.', 'pulse-analytics' ),
			'region'         => __( 'visits grouped by region or state.', 'pulse-analytics' ),
			'city'           => __( 'visits grouped by city.', 'pulse-analytics' ),
			'continent'      => __( 'visits grouped by continent.', 'pulse-analytics' ),
			'language'       => __( 'visits grouped by the language set in the visitor\'s browser.', 'pulse-analytics' ),
			'device'         => __( 'visits grouped by device type: desktop, mobile or tablet.', 'pulse-analytics' ),
			'browser'        => __( 'visits grouped by browser.', 'pulse-analytics' ),
			'browser_version' => __( 'visits grouped by browser version.', 'pulse-analytics' ),
			'os'             => __( 'visits grouped by operating system.', 'pulse-analytics' ),
			'os_version'     => __( 'visits grouped by operating system version.', 'pulse-analytics' ),
			'screen'         => __( 'visits grouped by screen size.', 'pulse-analytics' ),
			'visitor_type'   => __( 'visits from new visitors and from returning visitors.', 'pulse-analytics' ),
			'user_type'      => __( 'visits from logged-in users and from guests.', 'pulse-analytics' ),
			/* translators: %s: list of event types */
			'event_category' => sprintf( __( 'events grouped by type. The types are: %s.', 'pulse-analytics' ), self::event_types() ),
			'event_name'     => __( 'one row per event, such as a link, file, form or search term, with the number of times it happened.', 'pulse-analytics' ),
			'event_label'    => __( 'events grouped by label, the extra detail recorded with an event.', 'pulse-analytics' ),
		);
		return isset( $rows[ $dimension ] ) ? $rows[ $dimension ] : str_replace( '_', ' ', $dimension );
	}

	/**
	 * The event types as a list.
	 *
	 * @return string
	 */
	private static function event_types() {
		return implode( ', ', Reports::event_categories() );
	}

	/* ------------------------------------------------------------------ *
	 * Columns.
	 * ------------------------------------------------------------------ */

	/**
	 * What the metric columns mean.
	 *
	 * @param string $dimension Dimension, for metrics that depend on it.
	 * @return array<string,string>
	 */
	private static function metric_meanings( $dimension = '' ) {
		$per_page = in_array( $dimension, array( 'page', 'entry_page' ), true );
		return array(
			'visitors'        => __( 'number of unique visitors (see the note on how visitors are counted)', 'pulse-analytics' ),
			'visits'          => 'entry_page' === $dimension ? __( 'number of visits that started on this page', 'pulse-analytics' ) : __( 'number of visits (sessions)', 'pulse-analytics' ),
			'pageviews'       => __( 'number of pages viewed', 'pulse-analytics' ),
			'views_per_visit' => __( 'average number of pages viewed per visit', 'pulse-analytics' ),
			'bounce_rate'     => $per_page ? __( 'share of the visits that started on this page and did not go on to another page', 'pulse-analytics' ) : __( 'share of visits that viewed only one page', 'pulse-analytics' ),
			'avg_duration'    => __( 'average length of a visit', 'pulse-analytics' ),
			'avg_time'        => __( 'average time spent on the page, leaving out pageviews where it could not be measured', 'pulse-analytics' ),
			'avg_scroll'      => __( 'how far down the page visitors scrolled on average', 'pulse-analytics' ),
			'entrances'       => __( 'number of visits that started on this page', 'pulse-analytics' ),
			'exits'           => __( 'number of visits that ended on this page', 'pulse-analytics' ),
			'exit_rate'       => __( 'share of this page\'s pageviews that were the last page of a visit', 'pulse-analytics' ),
			'events'          => __( 'number of times the event happened', 'pulse-analytics' ),
			'conversions'     => __( 'number of visitors who reached the goal in the filter', 'pulse-analytics' ),
			'cr'              => __( 'conversions as a share of visitors', 'pulse-analytics' ),
		);
	}

	/**
	 * "Column: meaning." lines for metric keys.
	 *
	 * @param string[] $keys      Metric keys.
	 * @param string   $dimension Dimension.
	 * @return string[]
	 */
	private static function metric_lines( array $keys, $dimension = '' ) {
		$labels   = Exporter::metric_labels();
		$meanings = self::metric_meanings( $dimension );
		$lines    = array();
		foreach ( $keys as $key ) {
			if ( isset( $labels[ $key ], $meanings[ $key ] ) ) {
				$lines[] = $labels[ $key ] . ': ' . $meanings[ $key ] . '.';
			}
		}
		return $lines;
	}

	/**
	 * Columns of a single file.
	 *
	 * @param string  $type      Report type.
	 * @param string  $dimension Dimension.
	 * @param Query   $query     Query.
	 * @param Reports $reports   Report engine.
	 * @return string[]
	 */
	private static function columns( $type, $dimension, Query $query, Reports $reports ) {
		$compare = (bool) $query->previous();
		$lines   = array();
		switch ( $type ) {
			case 'summary':
				$lines[] = $compare
					? __( 'In the first table, Metric names the figure. The next two columns hold its value for the period and for the comparison period, and Change (%) is the difference in percent.', 'pulse-analytics' )
					: __( 'In the first table, Metric names the figure and the next column holds its value for the period. The last two columns, including Change (%), are empty because there is no comparison period.', 'pulse-analytics' );
				$lines[] = $compare
					? __( 'In the second table, Period is the date or time of the row. Previous period is the matching date in the comparison period, and the columns ending in "(previous period)" hold its figures.', 'pulse-analytics' )
					: __( 'In the second table, Period is the date or time of the row.', 'pulse-analytics' );
				$lines   = array_merge( $lines, self::metric_lines( array( 'visitors', 'visits', 'pageviews', 'views_per_visit', 'bounce_rate', 'avg_duration' ) ) );
				break;

			case 'goals':
				$lines[] = __( 'Goal is the name of the goal. Type is page (reached by viewing a page) or event (reached by an event such as a form submission).', 'pulse-analytics' );
				$lines[] = __( 'Match and Value are the rule of the goal: Match is equals, contains, starts, ends or regex, and Value is the page path or event name it is compared with.', 'pulse-analytics' );
				$lines[] = __( 'Conversions (visitors) is the number of visitors who reached the goal at least once. Completions counts every time it was reached.', 'pulse-analytics' );
				$lines[] = __( 'Conversion rate (%) is conversions as a share of all visitors in the period.', 'pulse-analytics' );
				if ( $compare ) {
					$lines[] = __( 'Conversions (previous), Completions (previous) and Conversion rate (previous, %) hold the same figures for the comparison period.', 'pulse-analytics' );
				}
				break;

			case 'sessions':
				$lines[] = __( 'Session ID is the number of the visit. The raw pageview and event exports use the same number, so they can be joined with this file.', 'pulse-analytics' );
				$lines[] = __( 'Visitor ID (anonymous) is the same for visits from the same visitor (see the note on how visitors are counted).', 'pulse-analytics' );
				$lines[] = __( 'Started and Last activity are the times of the first and the last hit of the visit. Duration (seconds) is the time between them.', 'pulse-analytics' );
				$lines[] = __( 'Pageviews and Events count what happened during the visit. New visitor is yes for a first visit and no for a returning one.', 'pulse-analytics' );
				$lines[] = __( 'Entry page and Exit page are the paths of the first and the last page viewed. Entry page title is the title of the first page.', 'pulse-analytics' );
				$lines[] = __( 'Channel, Source and Referrer show where the visit came from. Referrer is the full address of the page that linked to the site. UTM source, UTM medium, UTM campaign, UTM term and UTM content hold the campaign tags of the link the visitor arrived on.', 'pulse-analytics' );
				$lines[] = __( 'Country, Region, City, Device, Browser, Browser version, OS, OS version, Screen (screen size) and Language (browser language) describe the visitor.', 'pulse-analytics' );
				$lines[] = Settings::get( 'store_user_id' )
					? __( 'User is the name of the WordPress user for visitors who were logged in.', 'pulse-analytics' )
					: __( 'User is empty because the site does not record which user was logged in.', 'pulse-analytics' );
				$ip      = array(
					'none'       => __( 'IP address is empty because the site does not store IP addresses.', 'pulse-analytics' ),
					'anonymized' => __( 'IP address has its last part removed for privacy.', 'pulse-analytics' ),
					'full'       => __( 'IP address is the visitor\'s full IP address.', 'pulse-analytics' ),
				);
				$store   = (string) Settings::get( 'store_ip' );
				$lines[] = isset( $ip[ $store ] ) ? $ip[ $store ] : $ip['none'];
				break;

			case 'pageviews':
				$lines[] = __( 'Pageview ID is the number of the pageview. Session ID links it to its visit in the raw visits export.', 'pulse-analytics' );
				$lines[] = __( 'Visitor ID (anonymous) is the same for pageviews from the same visitor.', 'pulse-analytics' );
				$lines[] = __( 'Time is when the page was opened. Page path and Page title identify the page, and Content type is its WordPress post type.', 'pulse-analytics' );
				$lines[] = __( 'Previous page is the path of the page viewed just before it in the same visit. It is empty for the first page of a visit.', 'pulse-analytics' );
				$lines[] = __( 'Time on page (seconds) is 0 when the browser did not report it. Scroll depth (%) is how far down the page the visitor scrolled.', 'pulse-analytics' );
				$lines[] = __( 'New visitor, Country, Region, City, Device, Browser, OS, Channel, Source, Referrer, UTM source, UTM medium and UTM campaign repeat the values of the visit.', 'pulse-analytics' );
				break;

			case 'events':
				$lines[] = __( 'Event ID is the number of the event. Session ID links it to its visit in the raw visits export, and Visitor ID (anonymous) is the same for events from the same visitor.', 'pulse-analytics' );
				/* translators: %s: list of event types */
				$lines[] = sprintf( __( 'Category is the event type: %s.', 'pulse-analytics' ), self::event_types() );
				$lines[] = __( 'Name says what the event was about: the website for outbound links, the file for downloads, the address or number for email and phone links, the form for form submissions, the search term for site searches and the missing address for 404 errors. Custom events use the name the site owner chose.', 'pulse-analytics' );
				$lines[] = __( 'Label adds detail: the full link for outbound links and downloads, the page for email, phone and form events, the number of results for searches and the referring page for 404 errors.', 'pulse-analytics' );
				$lines[] = __( 'Page is the path of the page where the event happened. Country, City, Device, Channel, Source and UTM campaign repeat the values of the visit.', 'pulse-analytics' );
				break;

			default:
				$lines = self::breakdown_columns( $dimension, $query, $reports );
		}
		return self::bullets( $lines );
	}

	/**
	 * Columns of a breakdown, taken from the report itself so they match the file.
	 *
	 * @param string  $dimension Dimension.
	 * @param Query   $query     Query.
	 * @param Reports $reports   Report engine.
	 * @return string[]
	 */
	private static function breakdown_columns( $dimension, Query $query, Reports $reports ) {
		$result  = $reports->breakdown( $query, $dimension, array( 'limit' => 1 ) );
		$first   = $result['rows'] ? $result['rows'][0] : array();
		$columns = Exporter::dimension_columns( $dimension );

		if ( '_page' === substr( '_' . $dimension, -5 ) ) {
			/* translators: 1: title column, 2: path column */
			$lines = array( sprintf( __( '%1$s is the title of the page and %2$s its path, the address without the domain.', 'pulse-analytics' ), $columns[0], $columns[1] ) );
		} elseif ( 'country' === $dimension || 'continent' === $dimension ) {
			/* translators: 1: name column, 2: code column */
			$lines = array( sprintf( __( '%1$s is the name and %2$s the two-letter code.', 'pulse-analytics' ), $columns[0], $columns[1] ) );
		} elseif ( 'channel' === $dimension || 'event_category' === $dimension ) {
			/* translators: 1: name column, 2: key column */
			$lines = array( sprintf( __( '%1$s is the readable name and %2$s the internal one.', 'pulse-analytics' ), $columns[0], $columns[1] ) );
		} else {
			/* translators: 1: label column, 2: raw value column */
			$lines = array( sprintf( __( '%1$s is the readable value and %2$s the value as it was recorded. They are often the same.', 'pulse-analytics' ), $columns[0], $columns[1] ) );
		}

		$extras = Exporter::extra_columns();
		$notes  = array(
			/* translators: %s: column title */
			'country'  => __( '%s is the two-letter code of the country.', 'pulse-analytics' ),
			/* translators: %s: column title */
			'region'   => __( '%s is the region or state.', 'pulse-analytics' ),
			/* translators: %s: column title */
			'browser'  => __( '%s is the browser the version belongs to.', 'pulse-analytics' ),
			/* translators: %s: column title */
			'os'       => __( '%s is the operating system the version belongs to.', 'pulse-analytics' ),
			/* translators: %s: column title */
			'category' => __( '%s is the internal name of the event type.', 'pulse-analytics' ),
		);
		foreach ( $notes as $key => $note ) {
			if ( array_key_exists( $key, $first ) ) {
				$lines[] = sprintf( $note, $extras[ $key ] );
			}
		}
		if ( array_key_exists( 'lat', $first ) ) {
			/* translators: 1: latitude column, 2: longitude column */
			$lines[] = sprintf( __( '%1$s and %2$s are the approximate coordinates of the place.', 'pulse-analytics' ), $extras['lat'], $extras['lon'] );
		}
		$lines = array_merge( $lines, self::metric_lines( $result['metrics'], $dimension ) );
		if ( in_array( 'conversions', $result['metrics'], true ) ) {
			$lines[] = __( 'Because of the goal filter, Visitors counts all visitors in the row, with or without the goal, while the other columns after Conversion rate (%) only cover visits in which the goal was reached.', 'pulse-analytics' );
		}
		return $lines;
	}

	/**
	 * Columns across all files of the ZIP.
	 *
	 * @param Query $query Query.
	 * @return string[]
	 */
	private static function glossary( Query $query ) {
		$lines = array(
			__( 'Most files start with two columns for the item: a readable name and the value as it was recorded (a column named "... (raw value)", "... code", "... key" or "... path"). Pages are identified by their path, the address without the domain.', 'pulse-analytics' ),
			__( 'Some files add columns such as Country code, Region, Browser, Operating system, Event type, Latitude and Longitude to tell rows apart.', 'pulse-analytics' ),
		);
		$metrics = array_keys( Exporter::metric_labels() );
		if ( ! $query->has( 'goal' ) ) {
			// Conversion columns only appear in breakdowns filtered by a goal.
			$metrics = array_diff( $metrics, array( 'conversions', 'cr' ) );
		}
		$lines   = array_merge( $lines, self::metric_lines( $metrics ) );
		$lines[] = __( 'In pages.csv and entry-pages.csv, Bounce rate (%) only counts visits that started on that page.', 'pulse-analytics' );
		if ( $query->previous() ) {
			$lines[] = __( 'Change (%) in summary-and-trend.csv is the difference from the comparison period in percent.', 'pulse-analytics' );
		}
		$lines[] = __( 'In goals.csv, Conversions (visitors) counts visitors who reached the goal, Completions counts every time it was reached, and Conversion rate (%) is conversions as a share of all visitors in the period.', 'pulse-analytics' );
		$lines[] = __( 'In the raw files, Session ID links rows in raw-visits.csv, raw-pageviews.csv and raw-events.csv, and Visitor ID (anonymous) is the same for rows from the same visitor.', 'pulse-analytics' );
		return self::bullets( $lines );
	}

	/* ------------------------------------------------------------------ *
	 * Tasks.
	 * ------------------------------------------------------------------ */

	/**
	 * What to ask the AI tool for, depending on the report.
	 *
	 * @param string $type      Report type.
	 * @param string $dimension Dimension.
	 * @param Query  $query     Query.
	 * @return string[]
	 */
	private static function tasks( $type, $dimension, Query $query ) {
		$changes = $query->previous() ? __( 'Say what changed compared with the comparison period, including rows that are new or have disappeared.', 'pulse-analytics' ) : '';

		switch ( self::group( $type, $dimension ) ) {
			case 'all':
				$tasks = array(
					$query->previous() ? __( 'Summarise how the website did in this period and how it compares with the comparison period.', 'pulse-analytics' ) : __( 'Summarise how the website did in this period.', 'pulse-analytics' ),
					__( 'Show which channels, sources and campaigns bring the most visitors, and which of them bring visitors who stay longer, view more pages or reach a goal.', 'pulse-analytics' ),
					__( 'List the pages that attract the most visitors and the pages where most visitors leave.', 'pulse-analytics' ),
					__( 'Point out anything unusual, such as sudden rises or drops, sources that are new or gone, or countries and devices that behave very differently from the rest.', 'pulse-analytics' ),
					__( 'Finish with five concrete actions that could bring more visitors or more conversions, each backed by numbers from the files.', 'pulse-analytics' ),
				);
				break;
			case 'summary':
				$tasks = array(
					$query->previous() ? __( 'Summarise the main figures and how they changed compared with the comparison period.', 'pulse-analytics' ) : __( 'Summarise the main figures.', 'pulse-analytics' ),
					__( 'Describe how the figures moved over time: the strongest and weakest points, any steady rise or fall, and sudden spikes or drops.', 'pulse-analytics' ),
					__( 'Look for repeating patterns, such as differences between weekdays and weekends.', 'pulse-analytics' ),
					__( 'Suggest what might explain the changes and what I should check next.', 'pulse-analytics' ),
				);
				break;
			case 'acquisition':
				$tasks = array(
					__( 'Rank the rows by visitors and say which bring the most traffic.', 'pulse-analytics' ),
					__( 'Compare the quality of the traffic using bounce rate, visit duration and views per visit.', 'pulse-analytics' ),
					$changes,
					__( 'Suggest where to put more marketing effort and what to improve or stop.', 'pulse-analytics' ),
				);
				break;
			case 'content':
				$tasks = array(
					__( 'Show which rows get the most visitors and pageviews.', 'pulse-analytics' ),
					__( 'Point out what holds attention well and what loses visitors, based on the engagement columns in the file.', 'pulse-analytics' ),
					$changes,
					__( 'Suggest which pages to improve, promote or link to from other pages.', 'pulse-analytics' ),
				);
				break;
			case 'audience':
				$tasks = array(
					__( 'Show which groups bring the most visitors.', 'pulse-analytics' ),
					__( 'Compare how the groups behave, using bounce rate, visit duration and views per visit.', 'pulse-analytics' ),
					$changes,
					__( 'Suggest which audiences deserve more attention, for example with translated content or local campaigns.', 'pulse-analytics' ),
				);
				break;
			case 'technology':
				$tasks = array(
					__( 'Show the share of visitors in each group.', 'pulse-analytics' ),
					__( 'Point out groups with clearly worse engagement than the rest. That can mean the site does not work well on those devices or browsers.', 'pulse-analytics' ),
					$changes,
					__( 'Suggest what to test or fix first.', 'pulse-analytics' ),
				);
				break;
			case 'events':
				$tasks = array(
					__( 'Show which actions visitors take most often and how many visitors take them.', 'pulse-analytics' ),
					__( 'Point out the actions that matter most for the business, such as form submissions, phone and email clicks and downloads, and any 404 errors that should be fixed.', 'pulse-analytics' ),
					$changes,
					__( 'Suggest how to get more of the valuable actions.', 'pulse-analytics' ),
				);
				break;
			case 'goals':
				$tasks = array(
					__( 'Show which goals convert best and how many visitors reach them.', 'pulse-analytics' ),
					$query->previous() ? __( 'Say how the goals changed compared with the comparison period.', 'pulse-analytics' ) : '',
					__( 'Suggest ways to raise the conversion rates, starting with the goals that matter most.', 'pulse-analytics' ),
				);
				break;
			case 'sessions':
				$tasks = array(
					__( 'Find patterns in the visits: the busiest days and hours, common entry and exit pages, and which sources lead to longer visits.', 'pulse-analytics' ),
					__( 'Use the visitor ID to see how often people come back, keeping the note on visitor counting in mind.', 'pulse-analytics' ),
					__( 'Point out anything unusual, such as many very short visits from one source.', 'pulse-analytics' ),
					__( 'Suggest concrete improvements based on what you find.', 'pulse-analytics' ),
				);
				break;
			case 'pageviews':
				$tasks = array(
					__( 'Find the most common paths through the site, using Session ID and Previous page.', 'pulse-analytics' ),
					__( 'Show which pages lead to long visits and which pages visits usually end on.', 'pulse-analytics' ),
					__( 'Show the busiest hours and days.', 'pulse-analytics' ),
					__( 'Suggest changes to navigation or content based on what you find.', 'pulse-analytics' ),
				);
				break;
			default:
				$tasks = array(
					__( 'Show which events happen most, on which pages and at what times.', 'pulse-analytics' ),
					__( 'Connect the events to traffic sources and campaigns to see which visitors take action.', 'pulse-analytics' ),
					__( 'Suggest how to get more of the valuable actions.', 'pulse-analytics' ),
				);
		}

		$lines = array();
		foreach ( array_values( array_filter( $tasks ) ) as $i => $task ) {
			$lines[] = ( $i + 1 ) . '. ' . $task;
		}
		$lines[] = __( 'Quote the exact numbers each point is based on. If something cannot be answered from the data, or the numbers are too small to mean much, say so instead of guessing.', 'pulse-analytics' );
		return $lines;
	}

	/**
	 * Which set of tasks fits a report.
	 *
	 * @param string $type      Report type.
	 * @param string $dimension Dimension.
	 * @return string
	 */
	private static function group( $type, $dimension ) {
		if ( 'breakdown' !== $type ) {
			return 'events' === $type ? 'raw_events' : $type;
		}
		$groups = array(
			'acquisition' => array( 'channel', 'source', 'referrer', 'referrer_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ),
			'content'     => array( 'page', 'entry_page', 'exit_page', 'prev_page', 'next_page', 'post_type', 'author', 'category' ),
			'audience'    => array( 'country', 'region', 'city', 'continent', 'language', 'visitor_type', 'user_type' ),
			'technology'  => array( 'device', 'browser', 'browser_version', 'os', 'os_version', 'screen' ),
			'events'      => array( 'event_category', 'event_name', 'event_label' ),
		);
		foreach ( $groups as $group => $dimensions ) {
			if ( in_array( $dimension, $dimensions, true ) ) {
				return $group;
			}
		}
		return 'audience';
	}
}
