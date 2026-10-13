<?php
/**
 * Runs every report, breakdown and filter type against the database and lists SQL errors.
 *
 * Usage (on a test site, not on a live site):
 *   wp eval-file test-reports.php        uses the data that is already there
 *   wp eval-file test-reports.php demo   replaces the sample data with fresh sample data first
 */
use PulseAnalytics\Demo_Data;
use PulseAnalytics\Reports;
use PulseAnalytics\Query;
use PulseAnalytics\Helpers;
use PulseAnalytics\Goals;

global $wpdb;
$wpdb->suppress_errors( true );
$errors = array();
$check  = function ( $label, $fn ) use ( &$errors, $wpdb ) {
	$wpdb->last_error = '';
	$t0               = microtime( true );
	try {
		$result = $fn();
	} catch ( \Throwable $e ) {
		$errors[] = "$label: EXCEPTION " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine();
		echo "FAIL $label\n";
		return null;
	}
	$ms = round( ( microtime( true ) - $t0 ) * 1000 );
	if ( $wpdb->last_error ) {
		$errors[] = "$label: SQL " . $wpdb->last_error . "\n   QUERY: " . substr( $wpdb->last_query, 0, 1500 );
		echo "FAIL $label ({$ms}ms)\n";
	} else {
		echo "ok   $label ({$ms}ms)\n";
	}
	return $result;
};

if ( ! empty( $args[0] ) && 'demo' === $args[0] ) {
	$t0 = microtime( true );
	if ( Demo_Data::exists() ) {
		Demo_Data::delete();
	}
	$n = Demo_Data::generate( 60 );
	echo "Generated $n sessions in " . round( microtime( true ) - $t0, 2 ) . "s\n";
	if ( $wpdb->last_error ) {
		echo 'Last error: ' . $wpdb->last_error . "\n";
	}
}

$r     = new Reports();
$today = Helpers::today();
$q     = new Query( Helpers::today( -29 ), $today, array(), 'previous' );

$summary = $check( 'summary', function () use ( $r, $q ) { return $r->summary( $q ); } );
echo '     ' . wp_json_encode( $summary['current'] ) . "\n     prev " . wp_json_encode( $summary['previous'] ) . "\n";
foreach ( array( '', 'hour', 'day', 'week', 'month' ) as $iv ) {
	$check( "timeseries[$iv]", function () use ( $r, $q, $iv ) { return $r->timeseries( $q, $iv ); } );
}
$check( 'timeseries today hourly', function () use ( $r, $today ) { $x = $r->timeseries( new Query( $today, $today, array(), 'previous' ), '' ); echo '     buckets ' . count( $x['labels'] ) . ' interval ' . $x['interval'] . "\n"; return $x; } );
$check( 'timeseries compare=year', function () use ( $r, $today ) { return $r->timeseries( new Query( Helpers::today( -6 ), $today, array(), 'year' ), 'day' ); } );

$dims = array( 'country', 'continent', 'region', 'city', 'device', 'browser', 'browser_version', 'os', 'os_version', 'screen', 'language', 'channel', 'source', 'referrer', 'referrer_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'visitor_type', 'user_type', 'page', 'entry_page', 'exit_page', 'post_type', 'author', 'category', 'event_category', 'event_name', 'event_label' );
foreach ( $dims as $dim ) {
	$res = $check( "breakdown $dim", function () use ( $r, $q, $dim ) { return $r->breakdown( $q, $dim, array( 'limit' => 5, 'with_previous' => true, 'with_total' => true ) ); } );
	if ( $res && $res['rows'] ) {
		$first = $res['rows'][0];
		echo '     rows=' . count( $res['rows'] ) . ' total=' . ( isset( $res['total_rows'] ) ? $res['total_rows'] : '-' ) . ' first=' . wp_json_encode( array_intersect_key( $first, array_flip( array( 'key', 'label', 'visitors', 'pageviews', 'events', 'visits' ) ) ) ) . "\n";
	} elseif ( $res ) {
		echo "     (no rows)\n";
	}
}
// Sorting and search.
foreach ( array( 'bounce_rate', 'views_per_visit', 'avg_duration', 'pageviews' ) as $sort ) {
	$check( "breakdown country sort=$sort asc", function () use ( $r, $q, $sort ) { return $r->breakdown( $q, 'country', array( 'sort' => $sort, 'order' => 'asc' ) ); } );
}
$check( 'breakdown country search=sri', function () use ( $r, $q ) { $x = $r->breakdown( $q, 'country', array( 'search' => 'sri' ) ); echo '     ' . wp_json_encode( wp_list_pluck( $x['rows'], 'label' ) ) . "\n"; return $x; } );
$check( 'breakdown page search', function () use ( $r, $q ) { return $r->breakdown( $q, 'page', array( 'search' => 'market', 'sort' => 'avg_scroll' ) ); } );
$check( 'breakdown channel search', function () use ( $r, $q ) { return $r->breakdown( $q, 'channel', array( 'search' => 'search' ) ); } );

// Filters of every type.
$top_page = $r->breakdown( $q, 'page', array( 'limit' => 2 ) );
$path     = $top_page['rows'][0]['key'];
$filters  = array(
	'country is LK'             => array( array( 'dim' => 'country', 'op' => 'is', 'value' => 'LK' ) ),
	'country is_not US'         => array( array( 'dim' => 'country', 'op' => 'is_not', 'value' => 'US' ) ),
	'country multi'             => array( array( 'dim' => 'country', 'op' => 'is', 'value' => array( 'LK', 'IN' ) ) ),
	'continent AS'              => array( array( 'dim' => 'continent', 'op' => 'is', 'value' => 'AS' ) ),
	'source contains goo'       => array( array( 'dim' => 'source', 'op' => 'contains', 'value' => 'goo' ) ),
	'source not_contains goo'   => array( array( 'dim' => 'source', 'op' => 'not_contains', 'value' => 'goo' ) ),
	'visitor_type returning'    => array( array( 'dim' => 'visitor_type', 'op' => 'is', 'value' => 'returning' ) ),
	'user_type guest'           => array( array( 'dim' => 'user_type', 'op' => 'is', 'value' => 'guest' ) ),
	'entry_page'                => array( array( 'dim' => 'entry_page', 'op' => 'is', 'value' => $path ) ),
	'exit_page contains'        => array( array( 'dim' => 'exit_page', 'op' => 'contains', 'value' => '/' ) ),
	'event_category form'       => array( array( 'dim' => 'event_category', 'op' => 'is', 'value' => 'form' ) ),
	'event_name not'            => array( array( 'dim' => 'event_name', 'op' => 'is_not', 'value' => 'CTA Click' ) ),
	'device mobile + channel'   => array( array( 'dim' => 'device', 'op' => 'is', 'value' => 'mobile' ), array( 'dim' => 'channel', 'op' => 'is', 'value' => 'organic_search' ) ),
	'utm_campaign'              => array( array( 'dim' => 'utm_campaign', 'op' => 'is', 'value' => 'spring-promo' ) ),
);
$goals = Goals::all();
if ( $goals ) {
	$filters['goal'] = array( array( 'dim' => 'goal', 'op' => 'is', 'value' => $goals[0]['id'] ) );
}
foreach ( $filters as $label => $f ) {
	$fq = new Query( Helpers::today( -29 ), $today, $f, 'previous' );
	$s  = $check( "filter [$label] summary", function () use ( $r, $fq ) { return $r->summary( $fq ); } );
	echo '     visitors=' . $s['current']['visitors'] . ' visits=' . $s['current']['visits'] . ' pv=' . $s['current']['pageviews'] . ' bounce=' . $s['current']['bounce_rate'] . "\n";
	$check( "filter [$label] timeseries", function () use ( $r, $fq ) { return $r->timeseries( $fq, 'day' ); } );
	$check( "filter [$label] breakdown source", function () use ( $r, $fq ) { return $r->breakdown( $fq, 'source', array( 'limit' => 5 ) ); } );
	$check( "filter [$label] breakdown page", function () use ( $r, $fq ) { return $r->breakdown( $fq, 'page', array( 'limit' => 5 ) ); } );
	$check( "filter [$label] events", function () use ( $r, $fq ) { return $r->breakdown( $fq, 'event_name', array( 'limit' => 5 ) ); } );
}

// Page mode.
$pq = new Query( Helpers::today( -29 ), $today, array( array( 'dim' => 'page', 'op' => 'is', 'value' => $path ) ), 'previous' );
$s  = $check( 'page mode summary', function () use ( $r, $pq ) { return $r->summary( $pq ); } );
echo '     ' . wp_json_encode( $s['current'] ) . "\n";
$check( 'page mode timeseries', function () use ( $r, $pq ) { return $r->timeseries( $pq, '' ); } );
foreach ( array( 'country', 'city', 'source', 'device', 'prev_page', 'next_page', 'entry_page', 'exit_page', 'page', 'event_name', 'post_type' ) as $dim ) {
	$res = $check( "page mode breakdown $dim", function () use ( $r, $pq, $dim ) { return $r->breakdown( $pq, $dim, array( 'limit' => 5, 'with_total' => true ) ); } );
	if ( $res && in_array( $dim, array( 'prev_page', 'next_page' ), true ) ) {
		echo '     ' . wp_json_encode( array_map( function ( $row ) { return $row['label'] . '=' . $row['pageviews']; }, $res['rows'] ) ) . "\n";
	}
}
$check( 'page mode heatmap', function () use ( $r, $pq ) { return $r->heatmap( $pq ); } );
$check( 'page mode scroll', function () use ( $r, $pq ) { return $r->scroll_depth( $pq ); } );
$check( 'post_type filter summary', function () use ( $r, $today ) { return $r->summary( new Query( Helpers::today( -29 ), $today, array( array( 'dim' => 'post_type', 'op' => 'is', 'value' => 'post' ) ) ) ); } );

// Goal mode breakdown.
if ( $goals ) {
	$gq  = new Query( Helpers::today( -29 ), $today, array( array( 'dim' => 'goal', 'op' => 'is', 'value' => $goals[0]['id'] ) ), 'previous' );
	$res = $check( 'goal mode breakdown source', function () use ( $r, $gq ) { return $r->breakdown( $gq, 'source', array( 'limit' => 5 ) ); } );
	echo '     ' . wp_json_encode( array_map( function ( $row ) { return array( $row['label'], $row['visitors'], $row['conversions'], $row['cr'] ); }, $res['rows'] ) ) . "\n";
	$check( 'goal mode breakdown city', function () use ( $r, $gq ) { return $r->breakdown( $gq, 'city', array( 'limit' => 5 ) ); } );
}

// Other reports.
$check( 'stacked channel', function () use ( $r, $q ) { $x = $r->stacked( $q, 'channel', '', 5 ); echo '     series=' . count( $x['series'] ) . "\n"; return $x; } );
$rt = $check( 'realtime', function () use ( $r, $q ) { return $r->realtime( $q ); } );
echo '     active=' . $rt['active'] . ' active30=' . $rt['active30'] . ' pv30=' . $rt['pageviews30'] . ' recent=' . count( $rt['recent'] ) . "\n";
$check( 'realtime filtered', function () use ( $r ) { return $r->realtime( new Query( '', '', array( array( 'dim' => 'page', 'op' => 'contains', 'value' => '/' ) ) ) ); } );
$sess = $check( 'sessions', function () use ( $r, $q ) { return $r->sessions( $q, 10, 0 ); } );
echo '     rows=' . count( $sess['rows'] ) . ' more=' . (int) $sess['has_more'] . "\n";
$check( 'session detail', function () use ( $r, $sess ) { $x = $r->session_detail( $sess['rows'][0]['id'] ); echo '     timeline=' . count( $x['timeline'] ) . "\n"; return $x; } );
$check( 'heatmap', function () use ( $r, $q ) { $x = $r->heatmap( $q ); echo '     max=' . $x['max'] . "\n"; return $x; } );
$check( 'scroll', function () use ( $r, $q ) { $x = $r->scroll_depth( $q ); echo '     ' . wp_json_encode( $x ) . "\n"; return $x; } );
$g = $check( 'goals', function () use ( $r, $q ) { return $r->goals( $q ); } );
echo '     ' . wp_json_encode( array_map( function ( $x ) { return array( $x['name'], $x['conversions'], $x['completions'], $x['cr'] ); }, $g['goals'] ) ) . "\n";
$check( 'page_info', function () use ( $r, $path ) { $x = $r->page_info( $path ); echo '     ' . wp_json_encode( $x ) . "\n"; return $x; } );
foreach ( array( 'country', 'page', 'entry_page', 'goal', 'visitor_type', 'event_name', 'utm_campaign', 'continent', 'post_type' ) as $dim ) {
	$check( "suggest $dim", function () use ( $r, $q, $dim ) { return $r->suggest( $q, $dim, '' ); } );
}
$check( 'first_date', function () use ( $r ) { echo '     ' . $r->first_date() . "\n"; return 1; } );
$check( 'each_session', function () use ( $r, $q ) { $n = 0; $r->each_session( $q, function () use ( &$n ) { $n++; } ); echo "     n=$n\n"; return $n; } );
$check( 'each_pageview', function () use ( $r, $q ) { $n = 0; $r->each_pageview( $q, function () use ( &$n ) { $n++; } ); echo "     n=$n\n"; return $n; } );
$check( 'each_event', function () use ( $r, $q ) { $n = 0; $r->each_event( $q, function () use ( &$n ) { $n++; } ); echo "     n=$n\n"; return $n; } );

echo "\n==== " . count( $errors ) . " errors ====\n" . implode( "\n", $errors ) . "\n";
