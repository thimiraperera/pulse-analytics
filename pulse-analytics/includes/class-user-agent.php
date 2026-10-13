<?php
/**
 * Lightweight user-agent parser and bot detector.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects browser, operating system and device type.
 */
class User_Agent {

	/**
	 * Crawlers, monitors, link previewers, HTTP libraries and headless browsers.
	 */
	const BOT_REGEX = '/(?<!cu)bot\b|bot[\/\-_;]|crawl|spider|slurp|archiver|facebookexternalhit|facebookcatalog|meta-externalagent|embedly|quora link preview|outbrain|pinterest\/0\.|vkshare|w3c_validator|^whatsapp|telegrambot|discordbot|slackbot|slack-imgproxy|skypeuripreview|headless|phantomjs|selenium|webdriver|puppeteer|playwright|lighthouse|pagespeed|gtmetrix|pingdom|uptime|statuscake|site24x7|monitor|preview|python|curl\/|wget|java\/|go-http|okhttp|httpclient|axios|node-fetch|undici|libwww|scrapy|guzzle|postman|insomnia|ahrefs|semrush|mj12|dotbot|petalbot|bytespider|gptbot|chatgpt-user|oai-searchbot|claudebot|claude-web|claude-user|claude-searchbot|anthropic-ai|perplexitybot|perplexity-user|ccbot|amazonbot|applebot|yandex(bot|images|metrika)|baiduspider|bingpreview|duckduckbot|sogou|exabot|ia_archiver|wordpress\/|wp-rocket|datadog|newrelic|zgrab|masscan|nmap|nikto|sqlmap|feedfetcher|feedly|feedbin|newsblur|validator|checker|scanner|iframely|nuzzel|google-inspectiontool|googleother|storebot|adsbot|mediapartners/i';

	/**
	 * Whether the user agent belongs to a bot.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		$ua = (string) $ua;
		if ( '' === trim( $ua ) || strlen( $ua ) < 20 ) {
			return true;
		}
		return (bool) preg_match( self::BOT_REGEX, $ua );
	}

	/**
	 * Parses a user agent.
	 *
	 * @param string $ua    User agent.
	 * @param array  $hints Client hints from the tracker: mobile (bool), platform, platform_version, brands, touch.
	 * @return array{browser:string,browser_version:string,os:string,os_version:string,device:string}
	 */
	public static function parse( $ua, array $hints = array() ) {
		$ua     = (string) $ua;
		$result = array(
			'browser'         => '',
			'browser_version' => '',
			'os'              => '',
			'os_version'      => '',
			'device'          => 'desktop',
		);

		list( $result['browser'], $result['browser_version'] ) = self::browser( $ua, $hints );
		list( $result['os'], $result['os_version'] )           = self::os( $ua, $hints );
		$result['device']                                      = self::device( $ua, $hints );

		// iPadOS reports a desktop Safari user agent; touch support gives it away.
		if ( 'macOS' === $result['os'] && ! empty( $hints['touch'] ) && (int) $hints['touch'] > 1 ) {
			$result['os']         = 'iPadOS';
			$result['os_version'] = '';
			$result['device']     = 'tablet';
		}
		if ( 'iOS' === $result['os'] && 'tablet' === $result['device'] ) {
			$result['os'] = 'iPadOS';
		}

		return $result;
	}

	/**
	 * Browser name and major version.
	 *
	 * @param string $ua    User agent.
	 * @param array  $hints Client hints.
	 * @return array{0:string,1:string}
	 */
	private static function browser( $ua, array $hints ) {
		$brands = isset( $hints['brands'] ) ? strtolower( (string) $hints['brands'] ) : '';
		if ( '' !== $brands ) {
			if ( false !== strpos( $brands, 'brave' ) ) {
				return array( 'Brave', self::version( $ua, '/Chrome\/(\d+)/' ) );
			}
		}

		$rules = array(
			array( '/FBAN|FBAV|FB_IAB|FBIOS/', 'Facebook App', '/FBAV\/(\d+)/' ),
			array( '/Instagram/', 'Instagram App', '/Instagram (\d+)/' ),
			array( '/musical_ly|BytedanceWebview|TikTok|trill_/i', 'TikTok App', '' ),
			array( '/\bLine\/\d/', 'LINE', '/Line\/(\d+)/' ),
			array( '/Snapchat/i', 'Snapchat', '/Snapchat\/(\d+)/' ),
			array( '/Pinterest/i', 'Pinterest App', '' ),
			array( '/LinkedInApp/i', 'LinkedIn App', '' ),
			array( '/\bEdg(?:e|A|iOS)?\//', 'Edge', '/Edg(?:e|A|iOS)?\/(\d+)/' ),
			array( '/OPR\/|Opera|OPiOS\/|OPT\//', 'Opera', '/(?:OPR|OPiOS|OPT|Opera|Version)[\/ ](\d+)/' ),
			array( '/SamsungBrowser\//', 'Samsung Internet', '/SamsungBrowser\/(\d+)/' ),
			array( '/UCBrowser\/|UCWEB/', 'UC Browser', '/UCBrowser\/(\d+)/' ),
			array( '/YaBrowser\//', 'Yandex Browser', '/YaBrowser\/(\d+)/' ),
			array( '/Vivaldi\//', 'Vivaldi', '/Vivaldi\/(\d+)/' ),
			array( '/DuckDuckGo\/|\bDdg\//', 'DuckDuckGo', '/(?:DuckDuckGo|Ddg)\/(\d+)/' ),
			array( '/MiuiBrowser\//', 'MIUI Browser', '/MiuiBrowser\/(\d+)/' ),
			array( '/HuaweiBrowser\//', 'Huawei Browser', '/HuaweiBrowser\/(\d+)/' ),
			array( '/Silk\//', 'Amazon Silk', '/Silk\/(\d+)/' ),
			array( '/Firefox\/|FxiOS\//', 'Firefox', '/(?:Firefox|FxiOS)\/(\d+)/' ),
			array( '/CriOS\//', 'Chrome', '/CriOS\/(\d+)/' ),
			array( '/; wv\)/', 'Android WebView', '/Chrome\/(\d+)/' ),
			array( '/Chrome\/|Chromium\//', 'Chrome', '/(?:Chrome|Chromium)\/(\d+)/' ),
			array( '/Version\/[\d.]+.*Safari\//', 'Safari', '/Version\/(\d+)/' ),
			array( '/(?:iPhone|iPad|iPod).*AppleWebKit/', 'Safari (in-app)', '/OS (\d+)_/' ),
			array( '/MSIE |Trident\//', 'Internet Explorer', '/(?:MSIE |rv:)(\d+)/' ),
		);
		foreach ( $rules as $rule ) {
			if ( preg_match( $rule[0], $ua ) ) {
				$name = $rule[1];
				if ( 'Chrome' === $name && false !== strpos( $brands, 'opera' ) ) {
					$name = 'Opera';
				}
				return array( $name, $rule[2] ? self::version( $ua, $rule[2] ) : '' );
			}
		}
		return array( __( 'Other', 'pulse-analytics' ), '' );
	}

	/**
	 * Operating system and version.
	 *
	 * @param string $ua    User agent.
	 * @param array  $hints Client hints.
	 * @return array{0:string,1:string}
	 */
	private static function os( $ua, array $hints ) {
		$platform_version = isset( $hints['platform_version'] ) ? (string) $hints['platform_version'] : '';

		if ( preg_match( '/Windows Phone(?: OS)? ([\d.]+)/', $ua, $m ) ) {
			return array( 'Windows Phone', $m[1] );
		}
		if ( preg_match( '/Windows NT ([\d.]+)/', $ua, $m ) ) {
			$map     = array(
				'10.0' => '10',
				'6.3'  => '8.1',
				'6.2'  => '8',
				'6.1'  => '7',
				'6.0'  => 'Vista',
				'5.2'  => 'XP',
				'5.1'  => 'XP',
			);
			$version = isset( $map[ $m[1] ] ) ? $map[ $m[1] ] : $m[1];
			// Chromium reports platformVersion >= 13 on Windows 11.
			if ( '10' === $version && '' !== $platform_version && (int) $platform_version >= 13 ) {
				$version = '11';
			}
			return array( 'Windows', $version );
		}
		if ( preg_match( '/(?:iPhone|iPad|iPod)(?:.*?) OS (\d+)(?:_(\d+))?/', $ua, $m ) ) {
			return array( 'iOS', $m[1] . ( isset( $m[2] ) && '' !== $m[2] ? '.' . $m[2] : '' ) );
		}
		if ( preg_match( '/Android[ \/]?([\d]+(?:\.\d+)?)?/', $ua, $m ) ) {
			if ( false !== stripos( $ua, 'HarmonyOS' ) ) {
				return array( 'HarmonyOS', '' );
			}
			return array( 'Android', isset( $m[1] ) ? (string) (int) $m[1] : '' );
		}
		if ( false !== strpos( $ua, 'CrOS' ) ) {
			return array( 'Chrome OS', '' );
		}
		if ( false !== strpos( $ua, 'Macintosh' ) || false !== strpos( $ua, 'Mac OS X' ) ) {
			$version = '';
			if ( '' !== $platform_version ) {
				$version = (string) (int) $platform_version;
			}
			return array( 'macOS', $version );
		}
		if ( false !== stripos( $ua, 'KaiOS' ) ) {
			return array( 'KaiOS', '' );
		}
		if ( preg_match( '/Tizen/i', $ua ) ) {
			return array( 'Tizen', '' );
		}
		if ( preg_match( '/Web0S|webOS/i', $ua ) ) {
			return array( 'webOS', '' );
		}
		if ( false !== stripos( $ua, 'Ubuntu' ) ) {
			return array( 'Ubuntu', '' );
		}
		if ( false !== stripos( $ua, 'Linux' ) || false !== stripos( $ua, 'X11' ) ) {
			return array( 'Linux', '' );
		}
		if ( preg_match( '/FreeBSD|OpenBSD|NetBSD/i', $ua ) ) {
			return array( 'BSD', '' );
		}
		return array( __( 'Other', 'pulse-analytics' ), '' );
	}

	/**
	 * Device type: desktop, mobile, tablet or tv.
	 *
	 * @param string $ua    User agent.
	 * @param array  $hints Client hints.
	 * @return string
	 */
	private static function device( $ua, array $hints ) {
		if ( preg_match( '/SmartTV|SMART-TV|Smart TV|Tizen.*TV|Web0S|webOS.*TV|HbbTV|AppleTV|CrKey|\bAFT[A-Z]|BRAVIA|Roku|GoogleTV|Android TV/i', $ua ) ) {
			return 'tv';
		}
		if ( preg_match( '/iPad|Tablet|PlayBook|Kindle|Silk|Nexus (7|9|10)|SM-T\d|Tab\b/i', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/Mobi|iPhone|iPod|Windows Phone|BlackBerry|BB10|Opera Mini|IEMobile|KaiOS/i', $ua ) ) {
			return 'mobile';
		}
		if ( false !== stripos( $ua, 'Android' ) ) {
			// Android without the "Mobile" token is a tablet.
			return ! empty( $hints['mobile'] ) ? 'mobile' : 'tablet';
		}
		if ( ! empty( $hints['mobile'] ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Extracts the first capture group.
	 *
	 * @param string $ua    User agent.
	 * @param string $regex Pattern.
	 * @return string
	 */
	private static function version( $ua, $regex ) {
		return preg_match( $regex, $ua, $m ) ? substr( $m[1], 0, 20 ) : '';
	}
}
