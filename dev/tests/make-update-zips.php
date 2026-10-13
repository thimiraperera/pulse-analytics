<?php
// Builds fake release zips of the plugin for the updater tests.
// php dev/tests/make-update-zips.php <plugin folder> <output folder> <version>
//
// release.zip is laid out like the zip the release workflow builds. repo.zip looks like
// GitHub's "Source code" download and renamed.zip has another top folder name; the
// updater should install both into the right folder anyway.
list( , $src, $out, $version ) = $argv;
$src = rtrim( str_replace( '\\', '/', $src ), '/' );

function files_in( $dir ) {
	$list = array();
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		$list[] = str_replace( '\\', '/', $f->getPathname() );
	}
	sort( $list );
	return $list;
}

function build( $zip_path, $src, $prefix, $version, $extra = array() ) {
	@unlink( $zip_path );
	$zip = new ZipArchive();
	$zip->open( $zip_path, ZipArchive::CREATE );
	foreach ( files_in( $src ) as $file ) {
		$rel  = substr( $file, strlen( $src ) + 1 );
		$data = file_get_contents( $file );
		if ( 'pulse-analytics.php' === $rel ) {
			$data = preg_replace( '/^( \* Version:\s+)\S+/m', '${1}' . $version, $data );
			$data = preg_replace( "/define\( 'PULSE_ANALYTICS_VERSION', '[^']+' \);/", "define( 'PULSE_ANALYTICS_VERSION', '" . $version . "' );", $data );
		}
		$zip->addFromString( $prefix . $rel, $data );
	}
	foreach ( $extra as $name => $data ) {
		$zip->addFromString( $name, $data );
	}
	$zip->close();
	echo $zip_path, ' ', filesize( $zip_path ), "\n";
}

// What the release workflow builds: pulse-analytics/...
build( "$out/release.zip", $src, 'pulse-analytics/', $version );
// GitHub's "Source code" zip of the whole repository: plugin one folder down, plus dev/.
build( "$out/repo.zip", $src, 'pulse-analytics-' . $version . '/pulse-analytics/', $version, array( 'pulse-analytics-' . $version . '/dev/README.md' => "dev\n", 'pulse-analytics-' . $version . '/README.md' => "readme\n" ) );
// A zip with the right files under another folder name.
build( "$out/renamed.zip", $src, 'pulse-analytics-main/', $version );
