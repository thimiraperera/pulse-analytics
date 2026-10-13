<?php
// Tests the GitHub updater against updater-mock.php (see dev/README.md).
// wp eval-file dev/tests/test-updater.php <path to release.zip>
use PulseAnalytics\Updater;

require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

if ( defined( 'PULSE_ANALYTICS_GITHUB_TOKEN' ) ) {
	echo "PULSE_ANALYTICS_GITHUB_TOKEN is set in wp-config.php. Remove it while this test runs.\n";
	return;
}
$zip         = isset( $args[0] ) ? $args[0] : '';
$saved_token = get_option( Updater::TOKEN_OPTION, null );
if ( ! is_file( $zip ) ) {
	echo "Pass the path of release.zip from make-update-zips.php.\n";
	return;
}
$file = 'pulse-analytics/pulse-analytics.php';
$fail = 0;
$ok   = function ( $cond, $label, $extra = '' ) use ( &$fail ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . ( $cond || '' === $extra ? '' : '  => ' . ( is_string( $extra ) ? $extra : wp_json_encode( $extra ) ) ) . "\n";
	if ( ! $cond ) {
		$fail++;
	}
};
$mode = function ( $mode, $token = '' ) use ( $zip ) {
	update_option( 'pulse_mock_github', array( 'mode' => $mode, 'zip' => $zip, 'token' => $token ), false );
	delete_option( 'pulse_mock_log' );
};
$updater = new Updater();

echo 'Installed version: ' . PULSE_ANALYTICS_VERSION . "\n";
$ok( 'thimiraperera/pulse-analytics' === Updater::repository(), 'repository from Update URI', Updater::repository() );

// Helpers.
$md = Updater::markdown( "# Title\n\nSome **bold** and `code` text\ncontinues here.\n\n* one\n* [link](https://example.com/?a=1&b=2)\n- <b>three</b>\n\nEnd" );
$ok( '<h4>Title</h4><p>Some <strong>bold</strong> and <code>code</code> text continues here.</p><ul><li>one</li><li><a href="https://example.com/?a=1&#038;b=2">link</a></li><li>&lt;b&gt;three&lt;/b&gt;</li></ul><p>End</p>' === $md, 'markdown conversion', $md );
$ok( '' === Updater::markdown( '' ), 'markdown of empty notes' );
$ok( array( 'requires' => '6.1', 'tested' => '7.1', 'requires_php' => '8.0' ) === Updater::requirements( "Requires at least: 6.1\r\nTested up to: 7.1\nRequires PHP: 8.0\n" ), 'readme requirements' );

// Public repository.
$mode( 'public' );
delete_site_transient( Updater::CACHE_KEY );
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$t = get_site_transient( 'update_plugins' );
$r = isset( $t->response[ $file ] ) ? $t->response[ $file ] : null;
$ok( $r && '90.3.0' === $r->new_version, 'update offered: 90.3.0', $r );
if ( $r ) {
	$ok( 'https://github.com/thimiraperera/pulse-analytics/releases/download/v90.3.0/pulse-analytics.zip' === $r->package, 'public package is the release asset', $r->package );
	$ok( 'pulse-analytics' === $r->slug && 'https://github.com/thimiraperera/pulse-analytics' === $r->id, 'slug and id', array( $r->slug, $r->id ) );
	$ok( '6.1' === $r->requires && '7.1' === $r->tested && '7.4' === $r->requires_php, 'requirements read from the new readme', array( $r->requires, $r->tested, $r->requires_php ) );
	$ok( ! empty( $r->icons['svg'] ) && false !== strpos( $r->icons['svg'], 'assets/img/icon.svg' ), 'icon url' );
}
$cache = get_site_transient( Updater::CACHE_KEY );
$ok( isset( $cache['skipped'] ) && '90.4.0' === $cache['skipped'], 'release without zip reported as skipped', isset( $cache['skipped'] ) ? $cache['skipped'] : null );
$cl = isset( $cache['changelog'] ) ? $cache['changelog'] : '';
$ok( false === strpos( $cl, '<h4>90.4.0' ) && false !== strpos( $cl, '<h4>90.3.0' ) && false !== strpos( $cl, '<h4>90.2.0' ), 'changelog lists the installable releases only' );
$ok( false === strpos( $cl, '90.5.0' ) && false === strpos( $cl, '90.6.0' ) && false === strpos( $cl, 'nightly' ), 'prerelease, draft and odd tags left out' );
$ok( false === strpos( $cl, '<script' ) && false !== strpos( $cl, '&lt;script&gt;' ), 'release notes escaped' );
$ok( false !== strpos( $cl, '<strong>reports</strong>' ) && false !== strpos( $cl, '<code>object cache</code>' ) && false !== strpos( $cl, 'href="https://example.com/a?b=1&#038;c=2"' ), 'release notes formatted' );
$ok( false !== strpos( $cl, '<p>Plain paragraph with two lines.</p>' ), 'windows line endings in notes' );
$log  = get_option( 'pulse_mock_log', array() );
$urls = wp_list_pluck( $log, 'url' );
$ok( 2 === count( $log ), 'two requests to GitHub (releases + readme)', $urls );
$ok( ! in_array( true, wp_list_pluck( $log, 'auth' ), true ), 'no token sent when none is set' );

// The cache is used on the next check.
delete_option( 'pulse_mock_log' );
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$ok( array() === get_option( 'pulse_mock_log', array() ), 'second check uses the cache' );

// WordPress.org must not offer its own "pulse-analytics" plugin.
$t = get_site_transient( 'update_plugins' );
$ok( isset( $t->response[ $file ] ) && 'https://github.com/thimiraperera/pulse-analytics' === $t->response[ $file ]->id, 'update entry comes from GitHub, not WordPress.org' );

// Details window.
$api = plugins_api( 'plugin_information', array( 'slug' => 'pulse-analytics' ) );
$ok( is_object( $api ) && '90.3.0' === $api->version && '6.1' === $api->requires && ! empty( $api->sections['changelog'] ) && false !== strpos( $api->sections['description'], 'github.com/thimiraperera/pulse-analytics' ), 'plugins_api details', is_wp_error( $api ) ? $api->get_error_message() : $api );
$ok( false !== strpos( $api->author, 'Thimira Perera' ), 'author in details' );
$other = plugins_api( 'plugin_information', array( 'slug' => 'hello-dolly', 'fields' => array( 'sections' => false ) ) );
$ok( is_object( $other ) && 'hello-dolly' === $other->slug, 'other plugins still go to WordPress.org', is_wp_error( $other ) ? $other->get_error_message() : '' );

// Errors.
$mode( 'ratelimit' );
$res = $updater->release( true );
$ok( isset( $res['error'] ) && false !== strpos( $res['error'], 'limit' ) && '90.3.0' === $res['version'], 'rate limit: error shown, last release kept', $res['error'] );
delete_site_transient( Updater::CACHE_KEY );
$res = $updater->release( true );
$ok( isset( $res['error'] ) && ! isset( $res['version'] ), 'rate limit with nothing cached' );
$mode( 'private' );
delete_site_transient( Updater::CACHE_KEY );
$res = $updater->release( true );
$ok( isset( $res['error'] ) && false !== strpos( $res['error'], 'private' ), 'private repo without token', $res );
$mode( 'noasset' );
$res = $updater->release( true );
$ok( isset( $res['error'] ) && false !== strpos( $res['error'], '90.4.0' ) && false !== strpos( $res['error'], 'pulse-analytics.zip' ), 'no zip attached', $res );
$mode( 'empty' );
$res = $updater->release( true );
$ok( isset( $res['error'] ) && false !== strpos( $res['error'], 'no releases' ), 'no releases yet', $res );
delete_site_transient( Updater::CACHE_KEY );
delete_site_transient( 'update_plugins' );
wp_update_plugins();
$t = get_site_transient( 'update_plugins' );
$ok( ! isset( $t->response[ $file ] ), 'no update offered when GitHub fails' );

// Private repository with a token.
$token = 'test_token_for_mock_only_123456';
$mode( 'private', $token );
update_option( Updater::TOKEN_OPTION, 'wrong_token_for_mock_only_0000', false );
$res = $updater->release( true );
$ok( isset( $res['error'] ) && false !== strpos( $res['error'], 'token' ), 'wrong token', $res );
update_option( Updater::TOKEN_OPTION, $token, false );
$res = $updater->release( true );
$ok( isset( $res['package'] ) && 'https://api.github.com/repos/thimiraperera/pulse-analytics/releases/assets/101' === $res['package'], 'private package goes through the API', $res );
$path = $updater->download( false, $res['package'] );
$ok( is_string( $path ) && is_file( $path ) && filesize( $path ) === filesize( $zip ), 'private download', is_wp_error( $path ) ? $path->get_error_message() : $path );
if ( is_string( $path ) && is_file( $path ) ) {
	unlink( $path );
}
$log = get_option( 'pulse_mock_log', array() );
$asset_calls = array_values( array_filter( $log, function ( $l ) { return false !== strpos( $l['url'], 'releases/assets/101' ); } ) );
$file_calls  = array_values( array_filter( $log, function ( $l ) { return false !== strpos( $l['url'], 'release-assets.githubusercontent.com' ); } ) );
$ok( 1 === count( $asset_calls ) && $asset_calls[0]['auth'] && 0 === (int) $asset_calls[0]['redirection'] && 'application/octet-stream' === $asset_calls[0]['accept'], 'asset request with token, no redirects', $asset_calls );
$ok( 1 === count( $file_calls ) && ! $file_calls[0]['auth'], 'file host gets no token', $file_calls );
$ok( false === $updater->download( false, 'https://downloads.wordpress.org/plugin/hello-dolly.zip' ), 'other downloads untouched' );
$ok( 'x' === $updater->download( 'x', $res['package'] ), 'earlier filter result kept' );

update_option( 'pulse_mock_github', array( 'mode' => 'public', 'zip' => $zip ), false );
delete_option( 'pulse_mock_log' );
if ( null === $saved_token ) {
	delete_option( Updater::TOKEN_OPTION );
} else {
	update_option( Updater::TOKEN_OPTION, $saved_token, false );
}
delete_site_transient( Updater::CACHE_KEY );
delete_site_transient( 'update_plugins' );

echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
echo "The fake GitHub is still on, so 'wp plugin update pulse-analytics' would now install 90.3.0.\n";
echo "Switch it off with: wp option delete pulse_mock_github\n";
