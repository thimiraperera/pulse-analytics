<?php
/**
 * Front-end tracking script.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues the tracker and provides the opt-out shortcode.
 */
class Tracker {

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_shortcode( 'pulse_optout', array( $this, 'optout_shortcode' ) );
	}

	/**
	 * Whether the current request should be tracked.
	 *
	 * @return bool
	 */
	public function should_track() {
		if ( ! Settings::get( 'tracking_enabled' ) ) {
			return false;
		}
		if ( is_admin() || is_feed() || is_robots() || is_trackback() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( is_user_logged_in() && Helpers::user_is_excluded( wp_get_current_user() ) ) {
			return false;
		}
		/**
		 * Filters whether the tracking script is printed on the current page.
		 *
		 * @param bool $track Whether to track.
		 */
		return (bool) apply_filters( 'pulse_analytics_should_track', true );
	}

	/**
	 * Enqueues the tracking script with its configuration.
	 */
	public function enqueue() {
		if ( ! $this->should_track() ) {
			return;
		}
		wp_register_script(
			'pulse-analytics',
			PULSE_ANALYTICS_URL . 'assets/js/tracker.js',
			array(),
			PULSE_ANALYTICS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( 'pulse-analytics', 'window.pulseAnalyticsConfig=' . wp_json_encode( $this->config() ) . ';', 'before' );
		wp_enqueue_script( 'pulse-analytics' );
	}

	/**
	 * Configuration passed to the tracker.
	 *
	 * @return array
	 */
	private function config() {
		$config = array(
			'api'       => esc_url_raw( rest_url( Plugin::REST_NS . '/collect' ) ),
			'ajax'      => esc_url_raw( admin_url( 'admin-ajax.php?action=pulse_collect' ) ),
			'mode'      => Settings::get( 'tracking_mode' ),
			'dnt'       => (bool) Settings::get( 'respect_dnt' ),
			'consent'   => (bool) Settings::get( 'require_consent' ),
			'outbound'  => (bool) Settings::get( 'track_outbound' ),
			'downloads' => (bool) Settings::get( 'track_downloads' ),
			'ext'       => (string) Settings::get( 'download_extensions' ),
			'forms'     => (bool) Settings::get( 'track_forms' ),
			'links'     => (bool) Settings::get( 'track_links' ),
			'scroll'    => (bool) Settings::get( 'track_scroll' ),
			'spa'       => (bool) Settings::get( 'track_spa' ),
			'hosts'     => Helpers::site_hosts(),
			'pid'       => 0,
			'pt'        => $this->page_context(),
		);

		if ( is_singular() || ( is_front_page() && is_page() ) ) {
			$config['pid'] = (int) get_queried_object_id();
		}
		if ( is_search() ) {
			global $wp_query;
			$config['q']  = get_search_query( false );
			$config['qn'] = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
		}
		if ( is_user_logged_in() ) {
			$config['nonce'] = wp_create_nonce( 'wp_rest' );
		}

		/**
		 * Filters the tracker configuration.
		 *
		 * @param array $config Configuration.
		 */
		return (array) apply_filters( 'pulse_analytics_tracker_config', $config );
	}

	/**
	 * Describes the current page: a post type for singular content, otherwise the archive type.
	 *
	 * @return string
	 */
	private function page_context() {
		if ( is_404() ) {
			return '404';
		}
		if ( is_search() ) {
			return 'search';
		}
		if ( is_front_page() ) {
			return is_page() ? 'page' : 'front';
		}
		if ( is_home() ) {
			return 'blog';
		}
		if ( is_singular() ) {
			return (string) get_post_type();
		}
		if ( is_category() ) {
			return 'category';
		}
		if ( is_tag() ) {
			return 'tag';
		}
		if ( is_tax() ) {
			return 'taxonomy';
		}
		if ( is_author() ) {
			return 'author';
		}
		if ( is_post_type_archive() ) {
			return 'archive';
		}
		if ( is_date() ) {
			return 'date';
		}
		if ( is_archive() ) {
			return 'archive';
		}
		return 'other';
	}

	/**
	 * [pulse_optout] shortcode that lets visitors exclude themselves from analytics.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function optout_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'label' => __( 'Exclude me from anonymous website statistics', 'pulse-analytics' ),
			),
			$atts,
			'pulse_optout'
		);
		$id   = 'pulse-optout-' . wp_rand( 1000, 9999 );
		ob_start();
		?>
		<label class="pulse-optout" for="<?php echo esc_attr( $id ); ?>">
			<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" />
			<?php echo esc_html( $atts['label'] ); ?>
		</label>
		<script>
		(function(){var c=document.getElementById(<?php echo wp_json_encode( $id ); ?>),k='pulse_analytics_ignore';if(!c)return;try{c.checked=localStorage.getItem(k)==='1';}catch(e){}c.addEventListener('change',function(){try{c.checked?localStorage.setItem(k,'1'):localStorage.removeItem(k);}catch(e){}});})();
		</script>
		<?php
		return (string) ob_get_clean();
	}
}
