<?php
/**
 * Weekly / monthly email reports.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and sends HTML summary emails.
 */
class Email_Reports {

	/**
	 * Sends the report when it is due (called daily by cron).
	 */
	public static function maybe_send() {
		if ( ! Settings::get( 'email_enabled' ) ) {
			return;
		}
		$today = new \DateTime( 'now', wp_timezone() );
		if ( 'monthly' === Settings::get( 'email_frequency' ) ) {
			if ( '01' !== $today->format( 'd' ) ) {
				return;
			}
		} elseif ( (int) $today->format( 'w' ) !== (int) get_option( 'start_of_week', 1 ) ) {
			return;
		}
		if ( get_option( 'pulse_analytics_email_last' ) === $today->format( 'Y-m-d' ) ) {
			return;
		}
		update_option( 'pulse_analytics_email_last', $today->format( 'Y-m-d' ), false );
		self::send();
	}

	/**
	 * Report period: the last 7 days or the previous calendar month.
	 *
	 * @return array{0:string,1:string}
	 */
	public static function period() {
		if ( 'monthly' === Settings::get( 'email_frequency' ) ) {
			$first = new \DateTime( 'first day of last month', wp_timezone() );
			$last  = new \DateTime( 'last day of last month', wp_timezone() );
			return array( $first->format( 'Y-m-d' ), $last->format( 'Y-m-d' ) );
		}
		return array( Helpers::today( -7 ), Helpers::today( -1 ) );
	}

	/**
	 * Builds and sends the report.
	 *
	 * @param string|null $recipients Comma separated emails (defaults to settings / admin email).
	 * @return bool
	 */
	public static function send( $recipients = null ) {
		$recipients = null === $recipients ? (string) Settings::get( 'email_recipients', '' ) : $recipients;
		$to         = array_filter( array_map( 'trim', explode( ',', $recipients ) ), 'is_email' );
		if ( ! $to ) {
			$to = array( get_option( 'admin_email' ) );
		}

		list( $start, $end ) = self::period();
		$query               = new Query( $start, $end, array(), 'previous' );
		$reports             = new Reports();
		$data                = array(
			'start'     => $start,
			'end'       => $end,
			'summary'   => $reports->summary( $query ),
			'pages'     => $reports->breakdown( $query, 'page', array( 'limit' => 7 ) )['rows'],
			'sources'   => $reports->breakdown( $query, 'source', array( 'limit' => 7 ) )['rows'],
			'countries' => $reports->breakdown( $query, 'country', array( 'limit' => 7 ) )['rows'],
			'goals'     => $reports->goals( $query )['goals'],
		);

		/* translators: 1: site name, 2: start date, 3: end date */
		$subject = sprintf( __( '[%1$s] Website statistics for %2$s to %3$s', 'pulse-analytics' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $start, $end );
		return wp_mail( $to, $subject, self::render( $data ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Email HTML (inline styles for email clients).
	 *
	 * @param array $data Report data.
	 * @return string
	 */
	private static function render( array $data ) {
		$current  = $data['summary']['current'];
		$previous = $data['summary']['previous'];
		$metrics  = array(
			'visitors'     => __( 'Visitors', 'pulse-analytics' ),
			'visits'       => __( 'Visits', 'pulse-analytics' ),
			'pageviews'    => __( 'Pageviews', 'pulse-analytics' ),
			'bounce_rate'  => __( 'Bounce rate', 'pulse-analytics' ),
			'avg_duration' => __( 'Avg. visit duration', 'pulse-analytics' ),
		);

		$cell  = 'padding:8px 12px;border-bottom:1px solid #e1e0d9;font-size:14px;color:#0b0b0b;';
		$head  = 'padding:8px 12px;border-bottom:2px solid #c3c2b7;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#52514e;text-align:left;';
		$html  = '<div style="background:#f6f7f7;padding:24px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">';
		$html .= '<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e1e0d9;border-radius:8px;overflow:hidden;">';
		$html .= '<div style="padding:20px 24px;border-bottom:1px solid #e1e0d9;"><div style="font-size:12px;color:#52514e;text-transform:uppercase;letter-spacing:.05em;">' . esc_html__( 'Website statistics', 'pulse-analytics' ) . '</div>';
		$html .= '<div style="font-size:20px;font-weight:600;color:#0b0b0b;margin-top:4px;">' . esc_html( get_bloginfo( 'name' ) ) . '</div>';
		$html .= '<div style="font-size:13px;color:#52514e;margin-top:4px;">' . esc_html( $data['start'] . ' to ' . $data['end'] ) . '</div></div>';

		$html .= '<table role="presentation" style="width:100%;border-collapse:collapse;margin:8px 0;"><tr>';
		foreach ( $metrics as $key => $label ) {
			$value = $current[ $key ];
			if ( 'bounce_rate' === $key ) {
				$shown = null === $value ? '-' : number_format_i18n( $value, 1 ) . '%';
			} elseif ( 'avg_duration' === $key ) {
				$shown = self::duration( (int) $value );
			} else {
				$shown = number_format_i18n( (int) $value );
			}
			$change = '';
			if ( $previous && ! empty( $previous[ $key ] ) && null !== $value ) {
				$pct    = ( $value - $previous[ $key ] ) / $previous[ $key ] * 100;
				$good   = 'bounce_rate' === $key ? $pct <= 0 : $pct >= 0;
				$change = '<div style="font-size:12px;color:' . ( $good ? '#006300' : '#d03b3b' ) . ';">' . ( $pct >= 0 ? '+' : '-' ) . esc_html( number_format_i18n( abs( $pct ), 1 ) ) . '%</div>';
			}
			$html .= '<td style="padding:12px;text-align:center;vertical-align:top;"><div style="font-size:12px;color:#52514e;">' . esc_html( $label ) . '</div><div style="font-size:20px;font-weight:600;color:#0b0b0b;margin:4px 0;">' . esc_html( $shown ) . '</div>' . $change . '</td>';
		}
		$html .= '</tr></table>';

		$sections = array(
			array( __( 'Top pages', 'pulse-analytics' ), $data['pages'], 'pageviews', __( 'Pageviews', 'pulse-analytics' ) ),
			array( __( 'Top sources', 'pulse-analytics' ), $data['sources'], 'visitors', __( 'Visitors', 'pulse-analytics' ) ),
			array( __( 'Top countries', 'pulse-analytics' ), $data['countries'], 'visitors', __( 'Visitors', 'pulse-analytics' ) ),
		);
		foreach ( $sections as $section ) {
			list( $title, $rows, $metric, $metric_label ) = $section;
			if ( ! $rows ) {
				continue;
			}
			$html .= '<div style="padding:8px 24px 0;"><table role="presentation" style="width:100%;border-collapse:collapse;"><tr><th style="' . $head . '">' . esc_html( $title ) . '</th><th style="' . $head . 'text-align:right;">' . esc_html( $metric_label ) . '</th></tr>';
			foreach ( $rows as $row ) {
				$html .= '<tr><td style="' . $cell . '">' . esc_html( wp_html_excerpt( $row['label'], 60, '...' ) ) . '</td><td style="' . $cell . 'text-align:right;">' . esc_html( number_format_i18n( (int) $row[ $metric ] ) ) . '</td></tr>';
			}
			$html .= '</table></div>';
		}

		if ( $data['goals'] ) {
			$html .= '<div style="padding:8px 24px 0;"><table role="presentation" style="width:100%;border-collapse:collapse;"><tr><th style="' . $head . '">' . esc_html__( 'Goals', 'pulse-analytics' ) . '</th><th style="' . $head . 'text-align:right;">' . esc_html__( 'Conversions', 'pulse-analytics' ) . '</th><th style="' . $head . 'text-align:right;">' . esc_html__( 'Rate', 'pulse-analytics' ) . '</th></tr>';
			foreach ( $data['goals'] as $goal ) {
				$html .= '<tr><td style="' . $cell . '">' . esc_html( $goal['name'] ) . '</td><td style="' . $cell . 'text-align:right;">' . esc_html( number_format_i18n( (int) $goal['conversions'] ) ) . '</td><td style="' . $cell . 'text-align:right;">' . esc_html( number_format_i18n( (float) $goal['cr'], 2 ) ) . '%</td></tr>';
			}
			$html .= '</table></div>';
		}

		$html .= '<div style="padding:20px 24px;"><a href="' . esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SLUG ) ) . '" style="display:inline-block;background:#2a78d6;color:#ffffff;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px;">' . esc_html__( 'Open full statistics', 'pulse-analytics' ) . '</a></div>';
		$html .= '</div><div style="text-align:center;font-size:11px;color:#898781;margin-top:12px;">' . esc_html__( 'Sent by Pulse Analytics. You can change or turn off these emails under Statistics > Settings.', 'pulse-analytics' ) . '</div></div>';
		return $html;
	}

	/**
	 * Formats seconds as "3m 05s".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function duration( $seconds ) {
		if ( $seconds >= 3600 ) {
			return sprintf( '%dh %02dm', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ) );
		}
		if ( $seconds >= 60 ) {
			return sprintf( '%dm %02ds', intdiv( $seconds, 60 ), $seconds % 60 );
		}
		return $seconds . 's';
	}
}
