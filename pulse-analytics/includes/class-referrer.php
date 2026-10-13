<?php
/**
 * Traffic source and channel classification.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a referrer + campaign parameters into a channel, a friendly source name and referrer details.
 */
class Referrer {

	/**
	 * Channel keys and labels.
	 *
	 * @return array<string,string>
	 */
	public static function channels() {
		return array(
			'direct'         => __( 'Direct', 'pulse-analytics' ),
			'organic_search' => __( 'Organic Search', 'pulse-analytics' ),
			'paid_search'    => __( 'Paid Search', 'pulse-analytics' ),
			'social'         => __( 'Organic Social', 'pulse-analytics' ),
			'paid_social'    => __( 'Paid Social', 'pulse-analytics' ),
			'ai'             => __( 'AI Assistants', 'pulse-analytics' ),
			'email'          => __( 'Email', 'pulse-analytics' ),
			'referral'       => __( 'Referral', 'pulse-analytics' ),
			'display'        => __( 'Display', 'pulse-analytics' ),
			'affiliate'      => __( 'Affiliates', 'pulse-analytics' ),
			'campaign'       => __( 'Other Campaigns', 'pulse-analytics' ),
		);
	}

	/**
	 * Known referrer hosts: host (or parent domain) => array( name, type ).
	 * Types: search, social, ai, email, video.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function known_hosts() {
		return array(
			// AI assistants (checked before generic search rules).
			'chatgpt.com'                              => array( 'ChatGPT', 'ai' ),
			'chat.openai.com'                          => array( 'ChatGPT', 'ai' ),
			'openai.com'                               => array( 'ChatGPT', 'ai' ),
			'perplexity.ai'                            => array( 'Perplexity', 'ai' ),
			'claude.ai'                                => array( 'Claude', 'ai' ),
			'gemini.google.com'                        => array( 'Gemini', 'ai' ),
			'bard.google.com'                          => array( 'Gemini', 'ai' ),
			'copilot.microsoft.com'                    => array( 'Microsoft Copilot', 'ai' ),
			'copilot.cloud.microsoft'                  => array( 'Microsoft Copilot', 'ai' ),
			'm365.cloud.microsoft'                     => array( 'Microsoft Copilot', 'ai' ),
			'you.com'                                  => array( 'You.com', 'ai' ),
			'phind.com'                                => array( 'Phind', 'ai' ),
			'poe.com'                                  => array( 'Poe', 'ai' ),
			'deepseek.com'                             => array( 'DeepSeek', 'ai' ),
			'meta.ai'                                  => array( 'Meta AI', 'ai' ),
			'grok.com'                                 => array( 'Grok', 'ai' ),
			'chat.mistral.ai'                          => array( 'Mistral Le Chat', 'ai' ),
			'kimi.com'                                 => array( 'Kimi', 'ai' ),
			'kimi.moonshot.cn'                         => array( 'Kimi', 'ai' ),
			'doubao.com'                               => array( 'Doubao', 'ai' ),
			'chat.qwen.ai'                             => array( 'Qwen', 'ai' ),
			'felo.ai'                                  => array( 'Felo', 'ai' ),
			'andisearch.com'                           => array( 'Andi', 'ai' ),
			// Email clients.
			'mail.google.com'                          => array( 'Gmail', 'email' ),
			'com.google.android.gm'                    => array( 'Gmail', 'email' ),
			'outlook.live.com'                         => array( 'Outlook', 'email' ),
			'outlook.office.com'                       => array( 'Outlook', 'email' ),
			'outlook.office365.com'                    => array( 'Outlook', 'email' ),
			'mail.yahoo.com'                           => array( 'Yahoo Mail', 'email' ),
			'mail.aol.com'                             => array( 'AOL Mail', 'email' ),
			'mail.proton.me'                           => array( 'Proton Mail', 'email' ),
			'mail.zoho.com'                            => array( 'Zoho Mail', 'email' ),
			'mail.yandex.ru'                           => array( 'Yandex Mail', 'email' ),
			// Search engines.
			'bing.com'                                 => array( 'Bing', 'search' ),
			'duckduckgo.com'                           => array( 'DuckDuckGo', 'search' ),
			'baidu.com'                                => array( 'Baidu', 'search' ),
			'ya.ru'                                    => array( 'Yandex', 'search' ),
			'ecosia.org'                               => array( 'Ecosia', 'search' ),
			'search.brave.com'                         => array( 'Brave Search', 'search' ),
			'qwant.com'                                => array( 'Qwant', 'search' ),
			'startpage.com'                            => array( 'Startpage', 'search' ),
			'ask.com'                                  => array( 'Ask', 'search' ),
			'search.aol.com'                           => array( 'AOL', 'search' ),
			'naver.com'                                => array( 'Naver', 'search' ),
			'daum.net'                                 => array( 'Daum', 'search' ),
			'seznam.cz'                                => array( 'Seznam', 'search' ),
			'sogou.com'                                => array( 'Sogou', 'search' ),
			'so.com'                                   => array( '360 Search', 'search' ),
			'mojeek.com'                               => array( 'Mojeek', 'search' ),
			'kagi.com'                                 => array( 'Kagi', 'search' ),
			'presearch.com'                            => array( 'Presearch', 'search' ),
			'search.yahoo.com'                         => array( 'Yahoo', 'search' ),
			'yahoo.com'                                => array( 'Yahoo', 'search' ),
			'yahoo.co.jp'                              => array( 'Yahoo Japan', 'search' ),
			'com.google.android.googlequicksearchbox' => array( 'Google', 'search' ),
			// Social networks.
			'facebook.com'                             => array( 'Facebook', 'social' ),
			'fb.com'                                   => array( 'Facebook', 'social' ),
			'fb.me'                                    => array( 'Facebook', 'social' ),
			'messenger.com'                            => array( 'Messenger', 'social' ),
			'instagram.com'                            => array( 'Instagram', 'social' ),
			't.co'                                     => array( 'X (Twitter)', 'social' ),
			'twitter.com'                              => array( 'X (Twitter)', 'social' ),
			'x.com'                                    => array( 'X (Twitter)', 'social' ),
			'linkedin.com'                             => array( 'LinkedIn', 'social' ),
			'lnkd.in'                                  => array( 'LinkedIn', 'social' ),
			'com.linkedin.android'                     => array( 'LinkedIn', 'social' ),
			'pinterest.com'                            => array( 'Pinterest', 'social' ),
			'pin.it'                                   => array( 'Pinterest', 'social' ),
			'reddit.com'                               => array( 'Reddit', 'social' ),
			'youtube.com'                              => array( 'YouTube', 'social' ),
			'youtu.be'                                 => array( 'YouTube', 'social' ),
			'tiktok.com'                               => array( 'TikTok', 'social' ),
			'whatsapp.com'                             => array( 'WhatsApp', 'social' ),
			'wa.me'                                    => array( 'WhatsApp', 'social' ),
			't.me'                                     => array( 'Telegram', 'social' ),
			'telegram.org'                             => array( 'Telegram', 'social' ),
			'snapchat.com'                             => array( 'Snapchat', 'social' ),
			'quora.com'                                => array( 'Quora', 'social' ),
			'tumblr.com'                               => array( 'Tumblr', 'social' ),
			'vk.com'                                   => array( 'VK', 'social' ),
			'ok.ru'                                    => array( 'OK', 'social' ),
			'threads.net'                              => array( 'Threads', 'social' ),
			'threads.com'                              => array( 'Threads', 'social' ),
			'bsky.app'                                 => array( 'Bluesky', 'social' ),
			'mastodon.social'                          => array( 'Mastodon', 'social' ),
			'discord.com'                              => array( 'Discord', 'social' ),
			'discordapp.com'                           => array( 'Discord', 'social' ),
			'medium.com'                               => array( 'Medium', 'social' ),
			'news.ycombinator.com'                     => array( 'Hacker News', 'social' ),
			'line.me'                                  => array( 'LINE', 'social' ),
			'weibo.com'                                => array( 'Weibo', 'social' ),
			'viber.com'                                => array( 'Viber', 'social' ),
			'slack.com'                                => array( 'Slack', 'social' ),
			'com.slack'                                => array( 'Slack', 'social' ),
			'tripadvisor.com'                          => array( 'Tripadvisor', 'social' ),
			'yelp.com'                                 => array( 'Yelp', 'social' ),
			'producthunt.com'                          => array( 'Product Hunt', 'social' ),
			'github.com'                               => array( 'GitHub', 'referral' ),
			'stackoverflow.com'                        => array( 'Stack Overflow', 'referral' ),
		);
	}

	/**
	 * Common utm_source spellings => array( name, type ).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	private static function known_sources() {
		return array(
			'google'     => array( 'Google', 'search' ),
			'bing'       => array( 'Bing', 'search' ),
			'yahoo'      => array( 'Yahoo', 'search' ),
			'duckduckgo' => array( 'DuckDuckGo', 'search' ),
			'facebook'   => array( 'Facebook', 'social' ),
			'fb'         => array( 'Facebook', 'social' ),
			'meta'       => array( 'Facebook', 'social' ),
			'instagram'  => array( 'Instagram', 'social' ),
			'ig'         => array( 'Instagram', 'social' ),
			'twitter'    => array( 'X (Twitter)', 'social' ),
			'x'          => array( 'X (Twitter)', 'social' ),
			'linkedin'   => array( 'LinkedIn', 'social' ),
			'li'         => array( 'LinkedIn', 'social' ),
			'youtube'    => array( 'YouTube', 'social' ),
			'yt'         => array( 'YouTube', 'social' ),
			'tiktok'     => array( 'TikTok', 'social' ),
			'pinterest'  => array( 'Pinterest', 'social' ),
			'reddit'     => array( 'Reddit', 'social' ),
			'whatsapp'   => array( 'WhatsApp', 'social' ),
			'telegram'   => array( 'Telegram', 'social' ),
			'snapchat'   => array( 'Snapchat', 'social' ),
			'threads'    => array( 'Threads', 'social' ),
			'chatgpt'    => array( 'ChatGPT', 'ai' ),
			'chatgpt.com' => array( 'ChatGPT', 'ai' ),
			'openai'     => array( 'ChatGPT', 'ai' ),
			'perplexity' => array( 'Perplexity', 'ai' ),
			'claude'     => array( 'Claude', 'ai' ),
			'gemini'     => array( 'Gemini', 'ai' ),
			'copilot'    => array( 'Microsoft Copilot', 'ai' ),
			'newsletter' => array( 'Newsletter', 'email' ),
			'mailchimp'  => array( 'Mailchimp', 'email' ),
			'klaviyo'    => array( 'Klaviyo', 'email' ),
			'brevo'      => array( 'Brevo', 'email' ),
			'sendinblue' => array( 'Brevo', 'email' ),
			'hubspot'    => array( 'HubSpot', 'email' ),
			'email'      => array( 'Email', 'email' ),
		);
	}

	/**
	 * Looks a host up in the known list (exact host, then parent domains), plus generic rules.
	 *
	 * @param string $host Normalized host (no www.).
	 * @return array{0:string,1:string}|null
	 */
	public static function lookup_host( $host ) {
		$host  = strtolower( (string) $host );
		$known = self::known_hosts();
		$parts = explode( '.', $host );
		$count = count( $parts );
		for ( $i = 0; $i < $count; $i++ ) {
			$candidate = implode( '.', array_slice( $parts, $i ) );
			if ( isset( $known[ $candidate ] ) ) {
				return $known[ $candidate ];
			}
		}
		if ( preg_match( '/(^|\.)google\.(com?\.)?[a-z]{2,3}$/', $host ) ) {
			return array( 'Google', 'search' );
		}
		if ( preg_match( '/(^|\.)yandex\.[a-z.]+$/', $host ) ) {
			return array( 'Yandex', 'search' );
		}
		if ( preg_match( '/(^|\.)yahoo\.[a-z.]+$/', $host ) ) {
			return array( 'Yahoo', 'search' );
		}
		if ( preg_match( '/(^|\.)bing\.[a-z.]+$/', $host ) ) {
			return array( 'Bing', 'search' );
		}
		if ( preg_match( '/(^|\.)pinterest\.[a-z.]+$/', $host ) ) {
			return array( 'Pinterest', 'social' );
		}
		if ( preg_match( '/(^|\.)amazon\.[a-z.]+$/', $host ) ) {
			return array( 'Amazon', 'referral' );
		}
		if ( preg_match( '/^(webmail|mail|email)\./', $host ) ) {
			return array( $host, 'email' );
		}
		return null;
	}

	/**
	 * Normalizes a utm_source value.
	 *
	 * @param string $source Raw source.
	 * @return array{0:string,1:string} Name and type ('' when unknown).
	 */
	public static function lookup_source( $source ) {
		$key   = strtolower( trim( (string) $source ) );
		$known = self::known_sources();
		if ( isset( $known[ $key ] ) ) {
			return $known[ $key ];
		}
		$host = self::lookup_host( Helpers::normalize_host( $key ) );
		if ( $host ) {
			return $host;
		}
		return array( Helpers::truncate( trim( (string) $source ), 100 ), '' );
	}

	/**
	 * Classifies the traffic source of a new session.
	 *
	 * @param string $referrer Raw referrer URL.
	 * @param array  $params   Landing page query parameters.
	 * @return array{channel:string,source:string,referrer_host:string,referrer_url:string,blocked:bool}
	 */
	public static function classify( $referrer, array $params ) {
		$get = static function ( $key ) use ( $params ) {
			return isset( $params[ $key ] ) && is_scalar( $params[ $key ] ) ? Helpers::clean_text( $params[ $key ], 150 ) : '';
		};

		$utm_source = $get( 'utm_source' );
		if ( '' === $utm_source ) {
			$utm_source = '' !== $get( 'ref' ) ? $get( 'ref' ) : $get( 'source' );
		}
		$medium = strtolower( $get( 'utm_medium' ) );

		// Referrer.
		$ref_host = '';
		$ref_url  = '';
		if ( '' !== (string) $referrer ) {
			$parts = wp_parse_url( (string) $referrer );
			$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
			if ( '' !== $host && ! in_array( Helpers::normalize_host( $host ), Helpers::site_hosts(), true ) ) {
				// Collapse redirect / mobile sub-domains such as l.facebook.com or m.youtube.com.
				$ref_host = preg_replace( '/^(?:m|l|lm|mobile|amp|out)\.(?=[^.]+\.[^.]+)/', '', Helpers::normalize_host( $host ) );
				$path     = isset( $parts['path'] ) ? $parts['path'] : '';
				$ref_url  = Helpers::truncate( $ref_host . ( '/' === $path ? '' : $path ), 255 );
			}
		}

		$blocked = false;
		if ( '' !== $ref_host ) {
			foreach ( array_merge( self::spam_hosts(), Helpers::lines( (string) Settings::get( 'blocked_referrers', '' ) ) ) as $spam ) {
				$spam = Helpers::normalize_host( $spam );
				if ( '' !== $spam && ( $ref_host === $spam || substr( $ref_host, -strlen( '.' . $spam ) ) === '.' . $spam ) ) {
					$blocked = true;
					break;
				}
			}
		}

		$known      = '' !== $ref_host ? self::lookup_host( $ref_host ) : null;
		$src_known  = '' !== $utm_source ? self::lookup_source( $utm_source ) : null;
		$click_type = '';
		$click_name = '';
		$click_map  = array(
			'gclid'     => array( 'Google', 'paid_search' ),
			'gbraid'    => array( 'Google', 'paid_search' ),
			'wbraid'    => array( 'Google', 'paid_search' ),
			'msclkid'   => array( 'Bing', 'paid_search' ),
			'yclid'     => array( 'Yandex', 'paid_search' ),
			'dclid'     => array( 'Google', 'display' ),
			'ttclid'    => array( 'TikTok', 'paid_social' ),
			'li_fat_id' => array( 'LinkedIn', 'paid_social' ),
			'twclid'    => array( 'X (Twitter)', 'paid_social' ),
			'epik'      => array( 'Pinterest', 'paid_social' ),
		);
		foreach ( $click_map as $param => $info ) {
			if ( '' !== $get( $param ) ) {
				list( $click_name, $click_type ) = $info;
				break;
			}
		}

		// Friendly source name.
		if ( $src_known ) {
			$source = $src_known[0];
		} elseif ( $known ) {
			$source = $known[0];
		} elseif ( '' !== $ref_host ) {
			$source = $ref_host;
		} elseif ( '' !== $click_name ) {
			$source = $click_name;
		} elseif ( '' !== $get( 'fbclid' ) ) {
			$source = 'Facebook';
		} else {
			$source = '';
		}

		$type = $src_known && '' !== $src_known[1] ? $src_known[1] : ( $known ? $known[1] : '' );
		if ( '' === $type && '' !== $get( 'fbclid' ) ) {
			$type = 'social';
		}

		// Channel.
		if ( '' !== $click_type ) {
			$channel = $click_type;
			if ( 'paid_search' === $channel && 'social' === $type ) {
				$channel = 'paid_social';
			}
		} elseif ( '' !== $medium ) {
			if ( preg_match( '/^(display|banner|cpm|expandable|interstitial|programmatic)$/', $medium ) ) {
				$channel = 'display';
			} elseif ( preg_match( '/^(.*cp.*|ppc|retargeting|paid.*)$/', $medium ) ) {
				$channel = ( 'social' === $type || false !== strpos( $medium, 'social' ) ) ? 'paid_social' : 'paid_search';
			} elseif ( preg_match( '/^(e-?mail|e_mail|newsletter|mail)$/', $medium ) ) {
				$channel = 'email';
			} elseif ( preg_match( '/^(social|social[-_ ]?(network|media)|sm|organic[-_]social)$/', $medium ) ) {
				$channel = 'social';
			} elseif ( preg_match( '/^(affiliates?|partners?)$/', $medium ) ) {
				$channel = 'affiliate';
			} elseif ( 'organic' === $medium ) {
				$channel = 'organic_search';
			} elseif ( preg_match( '/^(referral|link)$/', $medium ) ) {
				$channel = 'referral';
			} elseif ( '' !== $type ) {
				$channel = self::type_channel( $type );
			} else {
				$channel = 'campaign';
			}
		} elseif ( '' !== $utm_source ) {
			$channel = '' !== $type ? self::type_channel( $type ) : 'campaign';
		} elseif ( $known ) {
			$channel = self::type_channel( $known[1] );
		} elseif ( '' !== $ref_host ) {
			$channel = 'referral';
		} elseif ( 'social' === $type ) {
			$channel = 'social';
		} else {
			$channel = 'direct';
		}

		return array(
			'channel'       => $channel,
			'source'        => Helpers::truncate( $source, 100 ),
			'referrer_host' => Helpers::truncate( $ref_host, 191 ),
			'referrer_url'  => $ref_url,
			'blocked'       => $blocked,
		);
	}

	/**
	 * Maps a source type to a channel.
	 *
	 * @param string $type Source type.
	 * @return string
	 */
	private static function type_channel( $type ) {
		switch ( $type ) {
			case 'search':
				return 'organic_search';
			case 'social':
				return 'social';
			case 'ai':
				return 'ai';
			case 'email':
				return 'email';
			default:
				return 'referral';
		}
	}

	/**
	 * Well-known referrer spam domains.
	 *
	 * @return string[]
	 */
	private static function spam_hosts() {
		return array( 'semalt.com', 'buttons-for-website.com', 'buttons-for-your-website.com', 'darodar.com', 'econom.co', 'ilovevitaly.com', 'priceg.com', 'hulfingtonpost.com', 'best-seo-offer.com', 'best-seo-solution.com', 'free-share-buttons.com', 'get-free-traffic-now.com', 'social-buttons.com', 'trafficmonetize.org', 'webmonetizer.net', '4webmasters.org', 'floating-share-buttons.com', 'success-seo.com', 'videos-for-your-business.com', 'rank-checker.online', 'seo-platform.com' );
	}
}
