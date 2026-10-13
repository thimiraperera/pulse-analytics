<?php
/**
 * Admin menu and the statistics dashboard page.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Statistics" menu and loads the dashboard app.
 */
class Admin {

	const PAGE_SLUG = 'pulse-analytics';

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'network_admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PULSE_ANALYTICS_FILE ), array( $this, 'action_links' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Adds the top-level "Statistics" menu.
	 */
	public function menu() {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M2 14.5h2.6l2.3-5.2 3.2 8.1 3.1-11.4 2.2 6.5H18v1.7h-3.8l-1.1-3.1-3.1 11.2-3.3-8.5-1.2 2.4H2z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		add_menu_page(
			__( 'Statistics', 'pulse-analytics' ),
			__( 'Statistics', 'pulse-analytics' ),
			Plugin::CAP_VIEW,
			self::PAGE_SLUG,
			array( $this, 'render' ),
			$icon,
			3
		);
		add_submenu_page( self::PAGE_SLUG, __( 'Statistics', 'pulse-analytics' ), __( 'Dashboard', 'pulse-analytics' ), Plugin::CAP_VIEW, self::PAGE_SLUG, array( $this, 'render' ) );
	}

	/**
	 * Whether the current screen is one of ours.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	private function is_dashboard( $hook ) {
		return 'toplevel_page_' . self::PAGE_SLUG === $hook;
	}

	/**
	 * Adds a body class on the dashboard (used to tidy the admin chrome).
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && $this->is_dashboard( $screen->id ) ) {
			$classes .= ' pulse-analytics-screen';
		}
		return $classes;
	}

	/**
	 * Loads the dashboard assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( ! $this->is_dashboard( $hook ) ) {
			return;
		}
		$url = PULSE_ANALYTICS_URL . 'assets/';
		$ver = PULSE_ANALYTICS_VERSION;
		wp_enqueue_style( 'pulse-analytics-admin', $url . 'css/admin.css', array(), $ver );
		wp_enqueue_script( 'pulse-analytics-charts', $url . 'js/charts.js', array(), $ver, true );
		wp_enqueue_script( 'pulse-analytics-map', $url . 'js/map.js', array(), $ver, true );
		wp_enqueue_script( 'pulse-analytics-core', $url . 'js/admin-core.js', array( 'wp-i18n', 'pulse-analytics-charts', 'pulse-analytics-map' ), $ver, true );
		wp_enqueue_script( 'pulse-analytics-admin', $url . 'js/admin.js', array( 'pulse-analytics-core' ), $ver, true );
		wp_set_script_translations( 'pulse-analytics-core', 'pulse-analytics', PULSE_ANALYTICS_PATH . 'languages' );
		wp_set_script_translations( 'pulse-analytics-admin', 'pulse-analytics', PULSE_ANALYTICS_PATH . 'languages' );
		wp_add_inline_script( 'pulse-analytics-core', 'window.PulseAnalytics=' . wp_json_encode( $this->app_data() ) . ';', 'before' );
	}

	/**
	 * Data passed to the dashboard app.
	 *
	 * @return array
	 */
	private function app_data() {
		$reports   = new Reports();
		$countries = array();
		foreach ( Countries::all() as $code => $data ) {
			$countries[ $code ] = array( $data[0], $data[1] );
		}
		$first_date = Cache::remember(
			'first_date',
			HOUR_IN_SECONDS,
			static function () use ( $reports ) {
				$date = $reports->first_date();
				return $date ? $date : '';
			}
		);
		$geo = GeoIP::status();

		return array(
			'version'        => PULSE_ANALYTICS_VERSION,
			'restUrl'        => esc_url_raw( rest_url( Plugin::REST_NS . '/' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'exportUrl'      => esc_url_raw( admin_url( 'admin-post.php' ) ),
			'exportNonce'    => wp_create_nonce( 'pulse_analytics_export' ),
			'settingsUrl'    => esc_url_raw( admin_url( 'admin.php?page=' . Settings_Page::PAGE_SLUG ) ),
			'adminUrl'       => esc_url_raw( admin_url() ),
			'homeUrl'        => esc_url_raw( home_url( '/' ) ),
			'origin'         => esc_url_raw( Helpers::site_origin() ),
			'siteName'       => get_bloginfo( 'name' ),
			'today'          => Helpers::today(),
			'now'            => Helpers::now(),
			'firstDate'      => $first_date,
			'startOfWeek'    => (int) get_option( 'start_of_week', 1 ),
			'locale'         => str_replace( '_', '-', get_user_locale() ),
			'timezone'       => wp_timezone_string(),
			'trackingMode'   => Settings::get( 'tracking_mode' ),
			'trackingOn'     => (bool) Settings::get( 'tracking_enabled' ),
			'excludedRoles'  => array_values( (array) Settings::get( 'exclude_roles', array() ) ),
			'geo'            => array(
				'source'   => $geo['source'],
				'exists'   => $geo['exists'],
				'city'     => GeoIP::has_city_data(),
				'error'    => $geo['error'],
				'cfHeader' => isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ),
			),
			'canManage'      => current_user_can( 'manage_options' ),
			'externalAssets' => (bool) Settings::get( 'external_assets' ),
			'mapCdn'         => REST_Controller::MAP_URL,
			'flagFont'       => 'https://cdn.jsdelivr.net/npm/country-flag-emoji-polyfill@0.1/dist/TwemojiCountryFlags.woff2',
			'countries'      => $countries,
			'continents'     => Countries::continents(),
			'channels'       => Referrer::channels(),
			'eventCats'      => Reports::event_categories(),
			'goals'          => Goals::all(),
			// wp_nonce_url() returns '&amp;' instead of '&', which breaks the link when it is used in JavaScript.
			'demoUrl'        => esc_url_raw(
				add_query_arg(
					array(
						'action'   => 'pulse_analytics_demo',
						'_wpnonce' => wp_create_nonce( 'pulse_analytics_demo' ),
					),
					admin_url( 'admin-post.php' )
				)
			),
			'hasDemo'        => Demo_Data::exists(),
		);
	}

	/**
	 * Renders the dashboard container; the app is drawn by admin.js.
	 */
	public function render() {
		if ( ! Helpers::can_view() ) {
			wp_die( esc_html__( 'You are not allowed to view statistics.', 'pulse-analytics' ) );
		}
		?>
		<div class="wrap pa-wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'Statistics', 'pulse-analytics' ); ?></h1>
			<div id="pulse-analytics-app" class="pa-app" aria-live="polite">
				<div class="pa-boot">
					<span class="spinner is-active"></span>
					<?php esc_html_e( 'Loading statistics...', 'pulse-analytics' ); ?>
				</div>
			</div>
			<noscript><div class="notice notice-error"><p><?php esc_html_e( 'The statistics dashboard needs JavaScript.', 'pulse-analytics' ); ?></p></div></noscript>
		</div>
		<?php
	}

	/**
	 * Sends the user to the dashboard right after activation.
	 */
	public function activation_redirect() {
		if ( ! get_transient( 'pulse_analytics_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'pulse_analytics_activation_redirect' );
		if ( wp_doing_ajax() || is_network_admin() || ! Helpers::can_view() ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&welcome=1' ) );
		exit;
	}

	/**
	 * Admin notices (results of tool actions).
	 */
	public function notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( empty( $_GET['pulse_notice'] ) ) {
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET['pulse_notice'] ) );
		// phpcs:enable
		$extra = (string) get_transient( 'pulse_analytics_notice_' . get_current_user_id() );
		delete_transient( 'pulse_analytics_notice_' . get_current_user_id() );
		$messages = array(
			'settings_saved' => array( 'success', __( 'Settings saved.', 'pulse-analytics' ) ),
			'geoip_ok'       => array( 'success', __( 'The geolocation database was downloaded successfully.', 'pulse-analytics' ) ),
			'geoip_error'    => array( 'error', __( 'The geolocation database could not be downloaded.', 'pulse-analytics' ) ),
			'demo_created'   => array( 'success', __( 'Sample data was generated. Explore the dashboard!', 'pulse-analytics' ) ),
			'demo_deleted'   => array( 'success', __( 'Sample data was removed.', 'pulse-analytics' ) ),
			'data_deleted'   => array( 'success', __( 'All statistics were deleted.', 'pulse-analytics' ) ),
			'cache_cleared'  => array( 'success', __( 'Report cache cleared.', 'pulse-analytics' ) ),
			'purged'         => array( 'success', __( 'Old data was purged according to your retention setting.', 'pulse-analytics' ) ),
			'email_sent'     => array( 'success', __( 'A test report was sent.', 'pulse-analytics' ) ),
			'email_failed'   => array( 'error', __( 'The test email could not be sent. Check that your site is able to send email.', 'pulse-analytics' ) ),
			'update_checked' => array( 'success', __( 'Checked GitHub for updates.', 'pulse-analytics' ) ),
			'update_error'   => array( 'error', __( 'Could not check GitHub for updates.', 'pulse-analytics' ) ),
			'token_invalid'  => array( 'error', __( 'That does not look like a GitHub token. Nothing was saved.', 'pulse-analytics' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		list( $type, $text ) = $messages[ $notice ];
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s%3$s</p></div>',
			esc_attr( $type ),
			esc_html( $text ),
			'' !== $extra ? ' ' . esc_html( $extra ) : ''
		);
	}

	/**
	 * Links on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) . '">' . esc_html__( 'Statistics', 'pulse-analytics' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . Settings_Page::PAGE_SLUG ) ) . '">' . esc_html__( 'Settings', 'pulse-analytics' ) . '</a>'
		);
		return $links;
	}
}
