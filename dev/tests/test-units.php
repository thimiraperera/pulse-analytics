<?php
/**
 * Unit checks for user agent parsing, traffic sources, IP helpers, URL handling and geolocation.
 *
 * Usage (inside a WordPress install with the plugin active):
 *   wp eval-file test-units.php [path/to/test-24.mmdb]
 * The optional .mmdb file comes from make_mmdb.py and enables the geolocation checks.
 */
use PulseAnalytics\User_Agent;
use PulseAnalytics\Referrer;
use PulseAnalytics\Helpers;
use PulseAnalytics\GeoIP;
use PulseAnalytics\Settings;

$origin = untrailingslashit( Helpers::site_origin() );
$www    = preg_replace( '#^(https?://)(www\.)?#', '$1www.', $origin );

$fail = 0;
$eq   = function ( $label, $got, $want ) use ( &$fail ) {
	if ( $got !== $want ) {
		$fail++;
		echo "FAIL $label\n   got:  " . json_encode( $got ) . "\n   want: " . json_encode( $want ) . "\n";
	}
};

// ---- User agents.
$uas = array(
	array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', array( 'platform_version' => '15.0.0' ), array( 'Chrome', '153', 'Windows', '11', 'desktop' ) ),
	array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 Edg/153.0.0.0', array(), array( 'Edge', '153', 'Windows', '10', 'desktop' ) ),
	array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15', array(), array( 'Safari', '26', 'macOS', '', 'desktop' ) ),
	array( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Safari/605.1.15', array( 'touch' => 5 ), array( 'Safari', '26', 'iPadOS', '', 'tablet' ) ),
	array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.0 Mobile/15E148 Safari/604.1', array(), array( 'Safari', '26', 'iOS', '18.6', 'mobile' ) ),
	array( 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/153.0.0.0 Mobile/15E148 Safari/604.1', array(), array( 'Chrome', '153', 'iOS', '18.6', 'mobile' ) ),
	array( 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', array(), array( 'Chrome', '153', 'Android', '10', 'mobile' ) ),
	array( 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', array(), array( 'Chrome', '153', 'Android', '14', 'tablet' ) ),
	array( 'Mozilla/5.0 (Linux; Android 16; SAMSUNG SM-S938B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/29.0 Chrome/136.0.0.0 Mobile Safari/537.36', array(), array( 'Samsung Internet', '29', 'Android', '16', 'mobile' ) ),
	array( 'Mozilla/5.0 (X11; Linux x86_64; rv:155.0) Gecko/20100101 Firefox/155.0', array(), array( 'Firefox', '155', 'Linux', '', 'desktop' ) ),
	array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36 OPR/120.0.0.0', array(), array( 'Opera', '120', 'Windows', '10', 'desktop' ) ),
	array( 'Mozilla/5.0 (Linux; Android 15; Pixel 8 Build/AP3A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/153.0.0.0 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/500.0.0.0;]', array(), array( 'Facebook App', '500', 'Android', '15', 'mobile' ) ),
	array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', array( 'brands' => 'Brave|Chromium|Not.A/Brand' ), array( 'Brave', '153', 'Windows', '10', 'desktop' ) ),
	array( 'Mozilla/5.0 (X11; CrOS x86_64 16000.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36', array(), array( 'Chrome', '153', 'Chrome OS', '', 'desktop' ) ),
	array( 'Mozilla/5.0 (Linux; Android 10; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Mobile Safari/537.36', array(), array( 'Chrome', '153', 'Android', '10', 'mobile' ) ),
);
foreach ( $uas as $case ) {
	$r = User_Agent::parse( $case[0], $case[1] );
	$eq( 'UA ' . substr( $case[0], 0, 70 ), array( $r['browser'], $r['browser_version'], $r['os'], $r['os_version'], $r['device'] ), $case[2] );
}
$bots = array( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36', 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot', 'curl/8.4.0', 'python-requests/2.31.0', 'WhatsApp/2.23.20.0 A', 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse' );
foreach ( $bots as $ua ) {
	$eq( 'bot ' . substr( $ua, 0, 50 ), User_Agent::is_bot( $ua ), true );
}
foreach ( $uas as $case ) {
	$eq( 'not bot ' . substr( $case[0], 0, 50 ), User_Agent::is_bot( $case[0] ), false );
}
$eq( 'not bot Claude desktop', User_Agent::is_bot( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Claude/1.2.3 Chrome/138.0.7204.243 Electron/37.3.1 Safari/537.36' ), false );

// ---- Referrers / channels.
$refs = array(
	array( 'https://www.google.com/', array(), array( 'organic_search', 'Google', 'google.com' ) ),
	array( 'https://www.google.lk/', array(), array( 'organic_search', 'Google', 'google.lk' ) ),
	array( 'https://www.google.co.uk/search?q=x', array(), array( 'organic_search', 'Google', 'google.co.uk' ) ),
	array( 'https://gemini.google.com/app', array(), array( 'ai', 'Gemini', 'gemini.google.com' ) ),
	array( 'https://chatgpt.com/', array(), array( 'ai', 'ChatGPT', 'chatgpt.com' ) ),
	array( 'https://www.perplexity.ai/search/abc', array(), array( 'ai', 'Perplexity', 'perplexity.ai' ) ),
	array( 'https://l.facebook.com/l.php?u=x', array(), array( 'social', 'Facebook', 'facebook.com' ) ),
	array( 'https://m.facebook.com/', array(), array( 'social', 'Facebook', 'facebook.com' ) ),
	array( 'https://t.co/abc', array(), array( 'social', 'X (Twitter)', 't.co' ) ),
	array( 'https://lnkd.in/xyz', array(), array( 'social', 'LinkedIn', 'lnkd.in' ) ),
	array( 'https://mail.google.com/', array(), array( 'email', 'Gmail', 'mail.google.com' ) ),
	array( 'android-app://com.google.android.googlequicksearchbox/', array(), array( 'organic_search', 'Google', 'com.google.android.googlequicksearchbox' ) ),
	array( 'https://someblog.example.org/post', array(), array( 'referral', 'someblog.example.org', 'someblog.example.org' ) ),
	array( '', array(), array( 'direct', '', '' ) ),
	array( $origin . '/other/', array(), array( 'direct', '', '' ) ),
	array( '', array( 'utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'oct' ), array( 'email', 'Newsletter', '' ) ),
	array( '', array( 'utm_source' => 'facebook', 'utm_medium' => 'cpc' ), array( 'paid_social', 'Facebook', '' ) ),
	array( 'https://www.google.com/', array( 'gclid' => 'abc' ), array( 'paid_search', 'Google', 'google.com' ) ),
	array( '', array( 'gclid' => 'abc' ), array( 'paid_search', 'Google', '' ) ),
	array( '', array( 'msclkid' => 'abc' ), array( 'paid_search', 'Bing', '' ) ),
	array( '', array( 'fbclid' => 'abc' ), array( 'social', 'Facebook', '' ) ),
	array( '', array( 'utm_source' => 'partner', 'utm_medium' => 'affiliate' ), array( 'affiliate', 'partner', '' ) ),
	array( '', array( 'utm_source' => 'flyer' ), array( 'campaign', 'flyer', '' ) ),
	array( '', array( 'ref' => 'producthunt' ), array( 'campaign', 'producthunt', '' ) ),
	array( '', array( 'utm_source' => 'google', 'utm_medium' => 'display' ), array( 'display', 'Google', '' ) ),
	array( 'https://semalt.com/', array(), 'blocked' ),
);
foreach ( $refs as $case ) {
	$r = Referrer::classify( $case[0], $case[1] );
	if ( 'blocked' === $case[2] ) {
		$eq( 'ref blocked ' . $case[0], $r['blocked'], true );
		continue;
	}
	$eq( 'ref ' . $case[0] . ' ' . json_encode( $case[1] ), array( $r['channel'], $r['source'], $r['referrer_host'] ), $case[2] );
}

// ---- Helpers.
$eq( 'anon v4', Helpers::anonymize_ip( '203.0.113.77' ), '203.0.113.0' );
$eq( 'anon v6', Helpers::anonymize_ip( '2001:db8:abcd:12:34:56:78:9a' ), '2001:db8:abcd::' );
$eq( 'cidr v4 in', Helpers::ip_in_list( '10.1.2.3', "192.168.1.1\n10.0.0.0/8" ), true );
$eq( 'cidr v4 out', Helpers::ip_in_list( '11.1.2.3', '10.0.0.0/8' ), false );
$eq( 'cidr /20', Helpers::ip_in_list( '172.16.15.255', '172.16.0.0/20' ), true );
$eq( 'cidr /20 out', Helpers::ip_in_list( '172.16.16.0', '172.16.0.0/20' ), false );
$eq( 'wildcard', Helpers::ip_in_list( '192.168.5.9', '192.168.*' ), true );
$eq( 'cidr v6', Helpers::ip_in_list( '2001:db8::1', '2001:db8::/32' ), true );
$eq( 'public', Helpers::is_public_ip( '8.8.8.8' ), true );
$eq( 'private', Helpers::is_public_ip( '192.168.1.1' ), false );
$p = Helpers::parse_page_url( $origin . '/shop/?utm_source=x&p=12&color=red#top' );
$eq( 'page path keeps p, drops utm + other', $p['path'], '/shop/?p=12' );
$eq( 'page params', $p['params']['utm_source'], 'x' );
$eq( 'foreign host', Helpers::parse_page_url( 'https://evil.example/' ), null );
$eq( 'www host allowed', Helpers::parse_page_url( $www . '/x' ) !== null, true );
$eq( 'sinhala path', Helpers::parse_page_url( $origin . '/%E0%B6%B8%E0%B7%94%E0%B6%BD%E0%B7%8A/' )['path'], '/%E0%B6%B8%E0%B7%94%E0%B6%BD%E0%B7%8A/' );

// ---- GeoIP with a custom database (settings are restored afterwards).
if ( ! empty( $args[0] ) ) {
	$saved = Settings::all();
	Settings::update( array( 'geoip_source' => 'custom', 'geoip_custom_path' => $args[0], 'geoip_headers' => 1 ) );
	$eq( 'geo db au', GeoIP::lookup( '1.2.3.4' ), array( 'country' => 'AU', 'region' => 'Queensland', 'city' => 'Brisbane', 'latitude' => -27.4679, 'longitude' => 153.0281 ) );
	$eq( 'geo registered country', GeoIP::lookup( '203.0.113.200' )['country'], 'DE' );
	$eq( 'geo private ip', GeoIP::lookup( '192.168.1.5' )['country'], '' );
	$_SERVER['HTTP_CF_IPCOUNTRY'] = 'LK';
	$g = GeoIP::lookup( '1.2.3.4' );
	$eq( 'geo header wins, db city ignored when country differs', array( $g['country'], $g['city'] ), array( 'LK', '' ) );
	$_SERVER['HTTP_CF_IPCOUNTRY'] = 'AU';
	$g = GeoIP::lookup( '1.2.3.4' );
	$eq( 'geo header + db city when consistent', array( $g['country'], $g['city'] ), array( 'AU', 'Brisbane' ) );
	$_SERVER['HTTP_CF_IPCOUNTRY'] = 'XX';
	$eq( 'geo XX ignored', GeoIP::lookup( '1.2.3.4' )['country'], 'AU' );
	unset( $_SERVER['HTTP_CF_IPCOUNTRY'] );
	$eq( 'has city data', GeoIP::has_city_data(), true );
	Settings::update(
		array(
			'geoip_source'      => $saved['geoip_source'],
			'geoip_custom_path' => $saved['geoip_custom_path'],
			'geoip_headers'     => $saved['geoip_headers'],
		)
	);
}

echo $fail ? "\n$fail FAILURES\n" : "\nALL UNIT TESTS PASSED\n";
