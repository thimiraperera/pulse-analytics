<?php
/**
 * Privacy policy helper text.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggests privacy policy text based on the current settings.
 */
class Privacy {

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'admin_init', array( $this, 'policy_content' ) );
	}

	/**
	 * Adds suggested text to the Privacy Policy Guide (Settings > Privacy).
	 */
	public function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$cookie = 'cookie' === Settings::get( 'tracking_mode' );
		$ip     = Settings::get( 'store_ip' );

		$text  = '<p class="privacy-policy-tutorial">' . esc_html__( 'Pulse Analytics is self-hosted. Statistics are stored in the database of this website and are not shared with anyone else.', 'pulse-analytics' ) . '</p>';
		$text .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'pulse-analytics' ) . ' </strong>';
		$text .= esc_html__( 'We collect anonymous usage statistics to understand how our website is used and to improve it. This includes the pages you visit, how long you stay, the website or campaign that referred you, your approximate location (country, and possibly region and city) derived from your IP address, your browser, operating system, device type, screen size and language.', 'pulse-analytics' ) . ' ';
		if ( $cookie ) {
			$text .= esc_html__( 'A random identifier is saved in the local storage of your browser so we can recognise returning visits. It does not contain any personal information.', 'pulse-analytics' ) . ' ';
		} else {
			$text .= esc_html__( 'No cookies or similar identifiers are stored on your device; visits are counted with a daily-changing anonymous hash.', 'pulse-analytics' ) . ' ';
		}
		if ( 'full' === $ip ) {
			$text .= esc_html__( 'Your IP address is stored together with the statistics.', 'pulse-analytics' ) . ' ';
		} elseif ( 'anonymized' === $ip ) {
			$text .= esc_html__( 'An anonymised (shortened) version of your IP address is stored.', 'pulse-analytics' ) . ' ';
		} else {
			$text .= esc_html__( 'Your IP address is not stored.', 'pulse-analytics' ) . ' ';
		}
		$months = (int) Settings::get( 'retention_months', 24 );
		if ( $months > 0 ) {
			/* translators: %d: number of months */
			$text .= esc_html( sprintf( __( 'Statistics are deleted automatically after %d months.', 'pulse-analytics' ), $months ) ) . ' ';
		}
		if ( Settings::get( 'respect_dnt' ) ) {
			$text .= esc_html__( 'We respect the "Do Not Track" and "Global Privacy Control" signals sent by browsers.', 'pulse-analytics' );
		}
		$text .= '</p>';

		wp_add_privacy_policy_content( 'Pulse Analytics', wp_kses_post( $text ) );
	}
}
