<?php
/**
 * Fake GitHub for testing the Pulse Analytics updater. Copy it into wp-content/mu-plugins
 * on a test site, never a live one. It does nothing until the option pulse_mock_github is
 * set, which test-updater.php does. Remove the file and that option when you are done.
 *
 * The fake releases use version numbers in the 90s, so they are always newer than the
 * installed plugin: 90.3.0 is the one to install, 90.4.0 has no zip, 90.5.0 is a
 * pre-release and 90.6.0 is a draft.
 */

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$cfg = get_option( 'pulse_mock_github' );
		if ( ! is_array( $cfg ) || empty( $cfg['mode'] ) ) {
			return $pre;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, array( 'api.github.com', 'github.com', 'release-assets.githubusercontent.com' ), true ) ) {
			return $pre;
		}
		$auth = isset( $args['headers']['Authorization'] ) ? $args['headers']['Authorization'] : '';

		$log   = get_option( 'pulse_mock_log', array() );
		$log[] = array(
			'url'         => $url,
			'auth'        => '' !== $auth,
			'redirection' => isset( $args['redirection'] ) ? $args['redirection'] : null,
			'accept'      => isset( $args['headers']['Accept'] ) ? $args['headers']['Accept'] : '',
		);
		update_option( 'pulse_mock_log', $log, false );

		$reply = function ( $code, $body = '', $headers = array() ) {
			return array(
				'headers'  => $headers,
				'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
				'response' => array( 'code' => $code, 'message' => 'Mock' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		$file = function () use ( $args, $cfg ) {
			copy( $cfg['zip'], $args['filename'] );
			return array(
				'headers'  => array( 'content-type' => 'application/zip' ),
				'body'     => '',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => $args['filename'],
			);
		};

		$mode  = $cfg['mode'];
		$token = isset( $cfg['token'] ) ? $cfg['token'] : '';
		$api   = 'https://api.github.com/repos/thimiraperera/pulse-analytics';

		if ( 0 === strpos( $url, $api . '/releases?' ) ) {
			if ( 'ratelimit' === $mode ) {
				return $reply( 403, array( 'message' => 'API rate limit exceeded' ), array( 'x-ratelimit-remaining' => '0' ) );
			}
			if ( 'private' === $mode && '' === $auth ) {
				return $reply( 404, array( 'message' => 'Not Found' ) );
			}
			if ( 'private' === $mode && 'Bearer ' . $token !== $auth ) {
				return $reply( 401, array( 'message' => 'Bad credentials' ) );
			}
			if ( 'empty' === $mode ) {
				return $reply( 200, array() );
			}
			$asset = array(
				'id'                   => 101,
				'name'                 => 'pulse-analytics.zip',
				'url'                  => $api . '/releases/assets/101',
				'browser_download_url' => 'https://github.com/thimiraperera/pulse-analytics/releases/download/v90.3.0/pulse-analytics.zip',
			);
			$other = array( 'name' => 'notes.txt', 'id' => 5, 'browser_download_url' => 'https://github.com/x/y' );
			$list  = array(
				array( 'tag_name' => 'v90.5.0', 'draft' => false, 'prerelease' => true, 'body' => 'Beta', 'assets' => array( $asset ), 'html_url' => 'https://github.com/thimiraperera/pulse-analytics/releases/tag/v90.5.0', 'published_at' => '2026-10-13T08:00:00Z' ),
				array( 'tag_name' => 'v90.6.0', 'draft' => true, 'prerelease' => false, 'body' => 'Draft', 'assets' => array( $asset ), 'html_url' => 'x', 'published_at' => null ),
				array( 'tag_name' => 'v90.4.0', 'draft' => false, 'prerelease' => false, 'body' => 'Forgot the zip', 'assets' => array( $other ), 'html_url' => 'https://github.com/thimiraperera/pulse-analytics/releases/tag/v90.4.0', 'published_at' => '2026-10-12T08:00:00Z' ),
				array( 'tag_name' => 'v90.3.0', 'draft' => false, 'prerelease' => false, 'body' => "## What changed\r\n\r\n* Faster **reports** with `object cache`\r\n* See [the docs](https://example.com/a?b=1&c=2)\r\n- <script>alert(1)</script> & more\r\n\r\nPlain paragraph\r\nwith two lines.", 'assets' => array( $other, $asset ), 'html_url' => 'https://github.com/thimiraperera/pulse-analytics/releases/tag/v90.3.0', 'published_at' => '2026-10-10T08:00:00Z' ),
				array( 'tag_name' => 'v90.2.0', 'draft' => false, 'prerelease' => false, 'body' => "* Zip export\n* Updates from GitHub", 'assets' => array( array_merge( $asset, array( 'id' => 100, 'browser_download_url' => 'https://github.com/thimiraperera/pulse-analytics/releases/download/v90.2.0/pulse-analytics.zip' ) ) ), 'html_url' => 'https://github.com/thimiraperera/pulse-analytics/releases/tag/v90.2.0', 'published_at' => '2026-10-01T08:00:00Z' ),
				array( 'tag_name' => 'nightly', 'draft' => false, 'prerelease' => false, 'body' => '', 'assets' => array( $asset ), 'html_url' => 'x', 'published_at' => '2026-10-13T08:00:00Z' ),
			);
			if ( 'noasset' === $mode ) {
				foreach ( $list as &$item ) {
					$item['assets'] = array( $other );
				}
				unset( $item );
			}
			return $reply( 200, $list );
		}
		if ( 0 === strpos( $url, $api . '/contents/pulse-analytics/readme.txt?ref=v90.3.0' ) ) {
			return $reply( 200, "=== Pulse Analytics ===\r\nRequires at least: 6.1\r\nTested up to: 7.1\r\nRequires PHP: 7.4\r\nStable tag: 90.3.0\r\n" );
		}
		if ( $api . '/releases/assets/101' === $url ) {
			if ( 'Bearer ' . $token !== $auth ) {
				return $reply( 404, array( 'message' => 'Not Found' ) );
			}
			if ( ! isset( $args['redirection'] ) || 0 !== (int) $args['redirection'] ) {
				return $reply( 500, 'Expected the updater to follow the redirect itself.' );
			}
			return $reply( 302, '', array( 'location' => 'https://release-assets.githubusercontent.com/fake/101?sig=abc' ) );
		}
		if ( 0 === strpos( $url, 'https://release-assets.githubusercontent.com/fake/101' ) ) {
			if ( '' !== $auth ) {
				return $reply( 400, 'The token was sent to the file host.' );
			}
			return $file();
		}
		if ( 'https://github.com/thimiraperera/pulse-analytics/releases/download/v90.3.0/pulse-analytics.zip' === $url ) {
			return $file();
		}
		return $reply( 404, array( 'message' => 'Not Found (mock)' ) );
	},
	10,
	3
);
