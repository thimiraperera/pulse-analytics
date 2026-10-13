<?php
/**
 * Standalone test of PulseAnalytics\MMDB_Reader against the databases made by make_mmdb.py.
 *
 * Usage: php test-mmdb.php <folder with the generated .mmdb files>
 */
define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__, 2 ) . '/pulse-analytics/includes/class-mmdb-reader.php';

$dir      = $argv[1];
$failures = 0;
$expect   = array(
	'1.2.3.4'          => array( 'AU', 'Brisbane', 'Queensland', -27.4679 ),
	'1.2.3.255'        => array( 'AU', 'Brisbane', 'Queensland', -27.4679 ),
	'8.8.8.8'          => array( 'US', 'Mountain View', 'California', 37.386 ),
	'112.134.200.1'    => array( 'LK', 'Colombo', 'Western Province', 6.9319 ),
	'203.0.113.200'    => array( 'reg:DE', null, null, null ),
	'2001:4860:4860::8888' => array( 'US', 'Mountain View', null, null ),
	'1.2.4.1'          => null,
	'9.9.9.9'          => null,
	'203.0.113.5'      => null,
	'::1'              => null,
);
foreach ( array( 'test-24.mmdb', 'test-28.mmdb', 'test-32.mmdb', 'test-28-big.mmdb', 'test-32-big.mmdb' ) as $file ) {
	$reader = new PulseAnalytics\MMDB_Reader( $dir . '/' . $file );
	$meta   = $reader->metadata();
	printf( "%-18s type=%s record_size=%d nodes=%d epoch=%s\n", $file, $meta['database_type'], $meta['record_size'], $meta['node_count'], $meta['build_epoch'] );
	foreach ( $expect as $ip => $want ) {
		$rec = $reader->get( $ip );
		if ( null === $want ) {
			$ok = null === $rec;
			$got = $rec ? json_encode( $rec ) : 'null';
		} elseif ( 0 === strpos( $want[0], 'reg:' ) ) {
			$ok  = isset( $rec['registered_country']['iso_code'] ) && 'DE' === $rec['registered_country']['iso_code'];
			$got = $rec ? json_encode( $rec ) : 'null';
		} else {
			$got = array(
				isset( $rec['country']['iso_code'] ) ? $rec['country']['iso_code'] : null,
				isset( $rec['city']['names']['en'] ) ? $rec['city']['names']['en'] : null,
				isset( $rec['subdivisions'][0]['names']['en'] ) ? $rec['subdivisions'][0]['names']['en'] : null,
				isset( $rec['location']['latitude'] ) ? round( $rec['location']['latitude'], 4 ) : null,
			);
			$ok  = $got === $want;
			$got = json_encode( $got, JSON_UNESCAPED_UNICODE );
		}
		if ( ! $ok ) {
			$failures++;
			echo "   FAIL $ip => $got\n";
		}
	}
	$lk = $reader->get( '112.134.1.1' );
	if ( 'කොළඹ' !== $lk['city']['names']['si'] ) {
		$failures++;
		echo "   FAIL unicode city name\n";
	}
	$us = $reader->get( '8.8.8.8' );
	if ( -28800 !== $us['offset'] ) {
		$failures++;
		echo "   FAIL int32 => " . var_export( $us['offset'], true ) . "\n";
	}
	$au = $reader->get( '1.2.3.4' );
	if ( false !== $au['is_anycast'] || 500 !== $au['location']['accuracy_radius'] || 2174003 !== $au['city']['geoname_id'] ) {
		$failures++;
		echo "   FAIL bool/uint16/uint32 => " . json_encode( $au ) . "\n";
	}
	$reader->close();
}
echo $failures ? "\n$failures FAILURES\n" : "\nALL MMDB TESTS PASSED\n";
