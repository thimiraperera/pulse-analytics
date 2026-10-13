<?php
/**
 * Checks the per-visit limits of the collector (30 pageviews and 60 events per minute).
 * The test visit is deleted again at the end.
 *
 * Usage: wp eval-file test-flood.php
 */
global $wpdb;
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 FloodTest2';
$_SERVER['REMOTE_ADDR']     = '198.51.100.23';
$c = new PulseAnalytics\Collector();
$ok = 0; $rejected = 0; $last = null;
for ( $i = 1; $i <= 34; $i++ ) {
	$r = $c->handle( array( 'a' => 'pv', 'u' => home_url( '/flood-test-' . $i . '/' ), 't' => 'Flood' ) );
	if ( ! empty( $r['ok'] ) ) { $ok++; $last = $r; } else { $rejected++; }
}
echo "pageviews accepted=$ok rejected=$rejected (expect 30/4)\n";
$e_ok = 0; $e_rej = 0;
for ( $i = 1; $i <= 64; $i++ ) {
	$r = $c->handle( array( 'a' => 'ev', 'c' => 'custom', 'n' => 'Spam', 'pv' => $last['pv'], 'sig' => $last['sig'] ) );
	if ( ! empty( $r['ok'] ) ) { $e_ok++; } else { $e_rej++; }
}
echo "events accepted=$e_ok rejected=$e_rej (expect 60/4)\n";
$t = PulseAnalytics\Plugin::tables();
$sid = $wpdb->get_var( $wpdb->prepare( "SELECT session_id FROM {$t['pageviews']} WHERE id = %d", $last['pv'] ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['pageviews']} WHERE session_id = %d", $sid ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['events']} WHERE session_id = %d", $sid ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$t['sessions']} WHERE id = %d", $sid ) );
PulseAnalytics\Cron::delete_orphan_pages();
echo "cleaned up\n";
