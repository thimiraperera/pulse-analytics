<?php
/**
 * Settings screen and maintenance tools.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Statistics > Settings screen.
 */
class Settings_Page {

	const PAGE_SLUG = 'pulse-analytics-settings';

	/**
	 * Registers hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_pulse_analytics_save', array( $this, 'save' ) );
		add_action( 'admin_post_pulse_analytics_geoip', array( $this, 'download_geoip' ) );
		add_action( 'admin_post_pulse_analytics_demo', array( $this, 'demo' ) );
		add_action( 'admin_post_pulse_analytics_demo_delete', array( $this, 'demo_delete' ) );
		add_action( 'admin_post_pulse_analytics_reset', array( $this, 'reset' ) );
		add_action( 'admin_post_pulse_analytics_purge', array( $this, 'purge' ) );
		add_action( 'admin_post_pulse_analytics_clear_cache', array( $this, 'clear_cache' ) );
		add_action( 'admin_post_pulse_analytics_test_email', array( $this, 'test_email' ) );
		add_action( 'admin_post_pulse_analytics_email_report', array( $this, 'email_report' ) );
	}

	/**
	 * Adds the submenu.
	 */
	public function menu() {
		add_submenu_page( Admin::PAGE_SLUG, __( 'Statistics settings', 'pulse-analytics' ), __( 'Settings', 'pulse-analytics' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render' ) );
	}

	/**
	 * Assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}
		if ( 'email' === $this->current_tab() ) {
			wp_enqueue_media(); // Media library for the logo field.
		}
		wp_enqueue_style( 'pulse-analytics-settings', PULSE_ANALYTICS_URL . 'assets/css/settings.css', array(), PULSE_ANALYTICS_VERSION );
		wp_enqueue_script( 'pulse-analytics-settings', PULSE_ANALYTICS_URL . 'assets/js/settings.js', array( 'wp-i18n' ), PULSE_ANALYTICS_VERSION, true );
		wp_set_script_translations( 'pulse-analytics-settings', 'pulse-analytics', PULSE_ANALYTICS_PATH . 'languages' );
	}

	/**
	 * Tabs.
	 *
	 * @return array<string,string>
	 */
	private function tabs() {
		$tabs = array(
			'tracking' => __( 'Tracking', 'pulse-analytics' ),
			'geo'      => __( 'Geolocation', 'pulse-analytics' ),
			'privacy'  => __( 'Privacy & data', 'pulse-analytics' ),
			'display'  => __( 'Access & display', 'pulse-analytics' ),
			'email'    => __( 'Reports', 'pulse-analytics' ),
			'tools'    => __( 'Tools', 'pulse-analytics' ),
		);
		if ( current_user_can( 'update_plugins' ) ) {
			$tabs['updates'] = __( 'Updates', 'pulse-analytics' );
		}
		return $tabs;
	}

	/**
	 * Current tab.
	 *
	 * @return string
	 */
	private function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'tracking';
		return array_key_exists( $tab, $this->tabs() ) ? $tab : 'tracking';
	}

	/**
	 * Renders the screen.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage these settings.', 'pulse-analytics' ) );
		}
		$tab = $this->current_tab();
		$s   = Settings::all();
		?>
		<div class="wrap pa-settings">
			<h1>
				<?php esc_html_e( 'Statistics settings', 'pulse-analytics' ); ?>
				<a class="page-title-action" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Open dashboard', 'pulse-analytics' ); ?></a>
			</h1>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $this->tabs() as $key => $label ) : ?>
					<a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php
			if ( 'tools' === $tab ) {
				$this->render_tools();
			} elseif ( 'updates' === $tab ) {
				$this->render_updates();
			} else {
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pa-settings-form">
					<input type="hidden" name="action" value="pulse_analytics_save" />
					<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
					<?php wp_nonce_field( 'pulse_analytics_settings' ); ?>
					<?php call_user_func( array( $this, 'render_' . $tab ), $s ); ?>
					<?php submit_button(); ?>
				</form>
				<?php
			}
			?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Field helpers.
	 * ------------------------------------------------------------------ */

	/**
	 * Checkbox row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $help  Help text.
	 * @param array  $s     Settings.
	 */
	private function checkbox( $key, $label, $help, array $s ) {
		?>
		<label class="pa-check">
			<input type="hidden" name="pulse[<?php echo esc_attr( $key ); ?>]" value="0" />
			<input type="checkbox" name="pulse[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?> />
			<span><?php echo esc_html( $label ); ?></span>
		</label>
		<?php if ( $help ) : ?>
			<p class="description pa-check-help"><?php echo esc_html( $help ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Role checkboxes.
	 *
	 * @param string $key Setting key.
	 * @param array  $s   Settings.
	 */
	private function roles( $key, array $s ) {
		$selected = (array) $s[ $key ];
		echo '<input type="hidden" name="pulse[' . esc_attr( $key ) . '][]" value="" />';
		echo '<div class="pa-roles">';
		foreach ( wp_roles()->get_names() as $role => $name ) {
			printf(
				'<label class="pa-check"><input type="checkbox" name="pulse[%1$s][]" value="%2$s" %3$s /> <span>%4$s</span></label>',
				esc_attr( $key ),
				esc_attr( $role ),
				checked( in_array( $role, $selected, true ), true, false ),
				esc_html( translate_user_role( $name ) )
			);
		}
		echo '</div>';
	}

	/* ------------------------------------------------------------------ *
	 * Tabs.
	 * ------------------------------------------------------------------ */

	/**
	 * Tracking tab.
	 *
	 * @param array $s Settings.
	 */
	private function render_tracking( array $s ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Tracking', 'pulse-analytics' ); ?></th>
				<td><?php $this->checkbox( 'tracking_enabled', __( 'Collect statistics', 'pulse-analytics' ), __( 'Untick to pause tracking without deactivating the plugin.', 'pulse-analytics' ), $s ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Visitor counting', 'pulse-analytics' ); ?></th>
				<td>
					<fieldset>
						<label class="pa-radio"><input type="radio" name="pulse[tracking_mode]" value="cookieless" <?php checked( 'cookieless', $s['tracking_mode'] ); ?> /> <strong><?php esc_html_e( 'Cookieless (recommended)', 'pulse-analytics' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Nothing is saved in the browser. Each visitor is counted with an anonymous hash that changes every day, so in most countries you do not need a cookie banner for it. The downside is that a returning visitor is only recognised on the same day.', 'pulse-analytics' ); ?></p>
						<label class="pa-radio"><input type="radio" name="pulse[tracking_mode]" value="cookie" <?php checked( 'cookie', $s['tracking_mode'] ); ?> /> <strong><?php esc_html_e( 'Persistent visitor ID', 'pulse-analytics' ); ?></strong></label>
						<p class="description"><?php esc_html_e( 'Saves a random ID in local storage, so new and returning visitors are counted exactly. In the EU and UK this usually needs consent. If you use a consent banner, turn on "Wait for consent" on the Privacy tab.', 'pulse-analytics' ); ?></p>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Exclude logged-in roles', 'pulse-analytics' ); ?></th>
				<td>
					<?php $this->roles( 'exclude_roles', $s ); ?>
					<p class="description"><?php esc_html_e( 'Visits from users with these roles are not counted. Tip: to test tracking, open your site in a private window.', 'pulse-analytics' ); ?></p>
					<?php $this->checkbox( 'store_user_id', __( 'Link visits of other logged-in users to their account (shown in the visitor log)', 'pulse-analytics' ), '', $s ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic event tracking', 'pulse-analytics' ); ?></th>
				<td class="pa-grid-2">
					<?php
					$this->checkbox( 'track_outbound', __( 'Outbound link clicks', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'track_downloads', __( 'File downloads', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'track_forms', __( 'Form submissions', 'pulse-analytics' ), __( 'Includes Contact Form 7, WPForms, Gravity Forms, Elementor, Fluent Forms and Forminator successes.', 'pulse-analytics' ), $s );
					$this->checkbox( 'track_links', __( 'Email (mailto:) and phone (tel:) clicks', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'track_scroll', __( 'Scroll depth', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'track_search', __( 'Internal site searches', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'track_404', __( '404 (page not found) errors', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'track_spa', __( 'Single-page navigation (history API)', 'pulse-analytics' ), __( 'Only needed for themes that load pages without a full reload.', 'pulse-analytics' ), $s );
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-ext"><?php esc_html_e( 'Download file types', 'pulse-analytics' ); ?></label></th>
				<td><input type="text" id="pa-ext" class="large-text code" name="pulse[download_extensions]" value="<?php echo esc_attr( $s['download_extensions'] ); ?>" /></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Custom events', 'pulse-analytics' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Track any button or link without code by adding attributes:', 'pulse-analytics' ); ?></p>
					<code>&lt;a href="/offer" data-pa-event="Offer click" data-pa-label="Header banner"&gt;</code>
					<p class="description"><?php esc_html_e( 'Or from JavaScript:', 'pulse-analytics' ); ?> <code>pulseAnalytics.track('Signup', 'Pro plan')</code></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-keep"><?php esc_html_e( 'Keep query parameters', 'pulse-analytics' ); ?></label></th>
				<td>
					<input type="text" id="pa-keep" class="regular-text code" name="pulse[keep_query_params]" value="<?php echo esc_attr( $s['keep_query_params'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Other query parameters are removed from page addresses so reports are not split into thousands of URLs. Campaign (utm_*) and click IDs are always captured separately.', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-timeout"><?php esc_html_e( 'Session timeout', 'pulse-analytics' ); ?></label></th>
				<td><input type="number" min="5" max="240" id="pa-timeout" class="small-text" name="pulse[session_timeout]" value="<?php echo esc_attr( $s['session_timeout'] ); ?>" /> <?php esc_html_e( 'minutes of inactivity', 'pulse-analytics' ); ?></td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-exclude-ips"><?php esc_html_e( 'Exclude IP addresses', 'pulse-analytics' ); ?></label></th>
				<td>
					<textarea id="pa-exclude-ips" class="large-text code" rows="3" name="pulse[exclude_ips]" placeholder="203.0.113.7&#10;198.51.100.0/24&#10;192.168.1.*"><?php echo esc_textarea( $s['exclude_ips'] ); ?></textarea>
					<p class="description">
						<?php
						/* translators: %s: current IP address */
						printf( esc_html__( 'One per line; ranges (CIDR) and * wildcards work. Your current IP address: %s', 'pulse-analytics' ), '<code>' . esc_html( Helpers::client_ip() ) . '</code>' );
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-exclude-paths"><?php esc_html_e( 'Exclude pages', 'pulse-analytics' ); ?></label></th>
				<td>
					<textarea id="pa-exclude-paths" class="large-text code" rows="3" name="pulse[exclude_paths]" placeholder="/checkout/*&#10;/my-account/"><?php echo esc_textarea( $s['exclude_paths'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Paths that should never be tracked, one per line (* as wildcard).', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'This browser', 'pulse-analytics' ); ?></th>
				<td>
					<button type="button" class="button" id="pa-ignore-toggle"><?php esc_html_e( 'Exclude this browser from statistics', 'pulse-analytics' ); ?></button>
					<span id="pa-ignore-status" class="pa-pill"></span>
					<p class="description"><?php esc_html_e( 'Useful for team members who are not logged in. Stored in this browser only.', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-ip-header"><?php esc_html_e( 'Visitor IP detection', 'pulse-analytics' ); ?></label></th>
				<td>
					<select id="pa-ip-header" name="pulse[ip_header]">
						<?php
						$options = array(
							'auto'                  => __( 'Automatic (recommended)', 'pulse-analytics' ),
							'REMOTE_ADDR'           => 'REMOTE_ADDR',
							'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP (Cloudflare)',
							'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
							'HTTP_X_REAL_IP'        => 'X-Real-IP',
							'HTTP_TRUE_CLIENT_IP'   => 'True-Client-IP',
						);
						foreach ( $options as $value => $label ) {
							printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $s['ip_header'], $value, false ), esc_html( $label ) );
						}
						?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-hosts"><?php esc_html_e( 'Additional domains', 'pulse-analytics' ); ?></label></th>
				<td>
					<textarea id="pa-hosts" class="large-text code" rows="2" name="pulse[allowed_hosts]" placeholder="shop.example.com"><?php echo esc_textarea( $s['allowed_hosts'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Other domains that show this site (they are also treated as internal, not as referrers).', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Geolocation tab.
	 *
	 * @param array $s Settings.
	 */
	private function render_geo( array $s ) {
		$status = GeoIP::status();
		$ip     = Helpers::client_ip();
		$test   = Helpers::is_public_ip( $ip ) ? GeoIP::lookup( $ip ) : null;
		?>
		<div class="pa-card">
			<h2><?php esc_html_e( 'Database status', 'pulse-analytics' ); ?></h2>
			<?php if ( $status['exists'] ) : ?>
				<p><span class="pa-pill pa-pill-ok"><?php esc_html_e( 'Active', 'pulse-analytics' ); ?></span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: database type, 2: file size, 3: date */
						__( '%1$s, %2$s, updated %3$s', 'pulse-analytics' ),
						$status['type'] ? $status['type'] : basename( $status['path'] ),
						size_format( $status['size'] ),
						wp_date( get_option( 'date_format' ), $status['modified'] )
					)
				);
				?>
				</p>
			<?php elseif ( 'none' === $status['source'] ) : ?>
				<p><span class="pa-pill"><?php esc_html_e( 'No database', 'pulse-analytics' ); ?></span> <?php esc_html_e( 'Only CDN headers, such as CF-IPCountry from Cloudflare, are used.', 'pulse-analytics' ); ?></p>
			<?php else : ?>
				<p><span class="pa-pill pa-pill-warn"><?php esc_html_e( 'Not downloaded yet', 'pulse-analytics' ); ?></span> <?php esc_html_e( 'Countries show as "Unknown" until a database is available, unless your CDN sends location headers.', 'pulse-analytics' ); ?></p>
			<?php endif; ?>
			<?php if ( $status['error'] ) : ?>
				<p class="pa-error"><?php echo esc_html( sprintf( /* translators: %s: error message */ __( 'Last download error: %s', 'pulse-analytics' ), $status['error'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) : ?>
				<p><span class="pa-pill pa-pill-ok">Cloudflare</span> <?php esc_html_e( 'Cloudflare country headers detected. Countries work even without a database.', 'pulse-analytics' ); ?></p>
			<?php endif; ?>
			<p>
				<?php
				if ( $test ) {
					/* translators: 1: IP address, 2: location */
					printf( esc_html__( 'Test lookup for your IP %1$s: %2$s', 'pulse-analytics' ), '<code>' . esc_html( $ip ) . '</code>', '<strong>' . esc_html( trim( implode( ', ', array_filter( array( $test['city'], $test['region'], '' !== $test['country'] ? Countries::name( $test['country'] ) : __( 'not found', 'pulse-analytics' ) ) ) ) ) ) . '</strong>' );
				} else {
					/* translators: %s: IP address */
					printf( esc_html__( 'Your IP %s is a private/local address, so it cannot be geolocated (normal on a local test site).', 'pulse-analytics' ), '<code>' . esc_html( $ip ) . '</code>' );
				}
				?>
			</p>
			<?php if ( GeoIP::is_downloadable_source( $s['geoip_source'] ) ) : ?>
				<p><a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pulse_analytics_geoip' ), 'pulse_analytics_geoip' ) ); ?>"><?php echo $status['exists'] ? esc_html__( 'Update database now', 'pulse-analytics' ) : esc_html__( 'Download database now', 'pulse-analytics' ); ?></a></p>
			<?php endif; ?>
		</div>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'CDN location headers', 'pulse-analytics' ); ?></th>
				<td><?php $this->checkbox( 'geoip_headers', __( 'Use location headers from Cloudflare, CloudFront, Vercel, Google App Engine and server GeoIP modules', 'pulse-analytics' ), __( 'Fast and accurate. On Cloudflare, turn on "Add visitor location headers" under Rules > Transform Rules > Managed Transforms to get cities too.', 'pulse-analytics' ), $s ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Database', 'pulse-analytics' ); ?></th>
				<td>
					<fieldset>
						<?php foreach ( GeoIP::sources() as $value => $label ) : ?>
							<label class="pa-radio"><input type="radio" name="pulse[geoip_source]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['geoip_source'], $value ); ?> data-pa-source /> <?php echo esc_html( $label ); ?></label><br />
						<?php endforeach; ?>
					</fieldset>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to db-ip.com */
							esc_html__( 'DB-IP Lite databases are free and licensed under CC BY 4.0 (%s).', 'pulse-analytics' ),
							'<a href="https://db-ip.com" target="_blank" rel="noopener">IP Geolocation by DB-IP</a>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr data-pa-show="maxmind_country maxmind_city">
				<th scope="row"><label for="pa-mm-key"><?php esc_html_e( 'MaxMind license key', 'pulse-analytics' ); ?></label></th>
				<td>
					<input type="password" id="pa-mm-key" class="regular-text" name="pulse[maxmind_key]" value="<?php echo esc_attr( $s['maxmind_key'] ); ?>" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Create a free account at maxmind.com, then generate a license key under "Manage License Keys".', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
			<tr data-pa-show="custom">
				<th scope="row"><label for="pa-custom-path"><?php esc_html_e( 'Custom database path', 'pulse-analytics' ); ?></label></th>
				<td>
					<input type="text" id="pa-custom-path" class="large-text code" name="pulse[geoip_custom_path]" value="<?php echo esc_attr( $s['geoip_custom_path'] ); ?>" placeholder="/var/lib/GeoIP/GeoLite2-City.mmdb" />
					<p class="description"><?php esc_html_e( 'Absolute path to any MaxMind-format (.mmdb) country or city database on the server.', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Updates', 'pulse-analytics' ); ?></th>
				<td><?php $this->checkbox( 'geoip_auto_update', __( 'Update the database automatically every month', 'pulse-analytics' ), '', $s ); ?></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Privacy tab.
	 *
	 * @param array $s Settings.
	 */
	private function render_privacy( array $s ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Browser privacy signals', 'pulse-analytics' ); ?></th>
				<td><?php $this->checkbox( 'respect_dnt', __( 'Do not track visitors who send a "Do Not Track" or "Global Privacy Control" signal', 'pulse-analytics' ), '', $s ); ?></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Consent', 'pulse-analytics' ); ?></th>
				<td>
					<?php $this->checkbox( 'require_consent', __( 'Wait for consent before tracking', 'pulse-analytics' ), __( 'Works automatically with consent plugins that support the WP Consent API (statistics category). Otherwise call pulseAnalytics.consent() from your banner when the visitor agrees.', 'pulse-analytics' ), $s ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'IP addresses', 'pulse-analytics' ); ?></th>
				<td>
					<fieldset>
						<label class="pa-radio"><input type="radio" name="pulse[store_ip]" value="none" <?php checked( 'none', $s['store_ip'] ); ?> /> <?php esc_html_e( 'Do not store (recommended)', 'pulse-analytics' ); ?></label><br />
						<label class="pa-radio"><input type="radio" name="pulse[store_ip]" value="anonymized" <?php checked( 'anonymized', $s['store_ip'] ); ?> /> <?php esc_html_e( 'Store anonymised (last part removed, e.g. 203.0.113.0)', 'pulse-analytics' ); ?></label><br />
						<label class="pa-radio"><input type="radio" name="pulse[store_ip]" value="full" <?php checked( 'full', $s['store_ip'] ); ?> /> <?php esc_html_e( 'Store full IP address (this is personal data, so check your local privacy law)', 'pulse-analytics' ); ?></label>
					</fieldset>
					<p class="description"><?php esc_html_e( 'IP addresses are always used in memory for geolocation and are never sent anywhere.', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-retention"><?php esc_html_e( 'Data retention', 'pulse-analytics' ); ?></label></th>
				<td>
					<input type="number" min="0" max="240" id="pa-retention" class="small-text" name="pulse[retention_months]" value="<?php echo esc_attr( $s['retention_months'] ); ?>" /> <?php esc_html_e( 'months', 'pulse-analytics' ); ?>
					<p class="description"><?php esc_html_e( 'Older statistics are deleted automatically every night. 0 keeps data forever.', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-blocked"><?php esc_html_e( 'Block referrer spam', 'pulse-analytics' ); ?></label></th>
				<td>
					<textarea id="pa-blocked" class="large-text code" rows="3" name="pulse[blocked_referrers]" placeholder="spam-domain.com"><?php echo esc_textarea( $s['blocked_referrers'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Visits referred by these domains are ignored (a list of well-known spam domains is always blocked).', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Visitor opt-out', 'pulse-analytics' ); ?></th>
				<td><p class="description"><?php esc_html_e( 'Add the shortcode [pulse_optout] to your privacy policy page to let visitors exclude themselves.', 'pulse-analytics' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'pulse-analytics' ); ?></th>
				<td><?php $this->checkbox( 'delete_on_uninstall', __( 'Delete all statistics and settings when the plugin is deleted', 'pulse-analytics' ), '', $s ); ?></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Access & display tab.
	 *
	 * @param array $s Settings.
	 */
	private function render_display( array $s ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Who can view statistics', 'pulse-analytics' ); ?></th>
				<td>
					<?php $this->roles( 'view_roles', $s ); ?>
					<p class="description"><?php esc_html_e( 'Administrators always have access. Only administrators can change settings and goals.', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Show statistics in', 'pulse-analytics' ); ?></th>
				<td>
					<?php
					$this->checkbox( 'dashboard_widget', __( 'WordPress dashboard widget', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'admin_bar', __( 'Admin bar (views of the page you are looking at)', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'post_columns', __( 'Views column in post and page lists', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'post_metabox', __( 'Statistics box in the post editor', 'pulse-analytics' ), '', $s );
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'External assets', 'pulse-analytics' ); ?></th>
				<td><?php $this->checkbox( 'external_assets', __( 'Load map shapes, flag icons and website favicons from public CDNs (admin screens only)', 'pulse-analytics' ), __( 'The world map outline (Natural Earth, via jsDelivr) is downloaded once and cached on your server. Your visitors never load anything from these services.', 'pulse-analytics' ), $s ); ?></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Email tab.
	 *
	 * @param array $s Settings.
	 */
	private function render_email( array $s ) {
		$logo_id  = (int) $s['report_logo'];
		$logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		$theme    = (int) get_theme_mod( 'custom_logo' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Logo', 'pulse-analytics' ); ?></th>
				<td>
					<div class="pa-logo-field" data-pa-logo>
						<div class="pa-logo-preview">
							<?php if ( $logo_url ) : ?>
								<img src="<?php echo esc_url( $logo_url ); ?>" alt="" />
							<?php else : ?>
								<span class="pa-logo-empty"><?php esc_html_e( 'No logo chosen', 'pulse-analytics' ); ?></span>
							<?php endif; ?>
						</div>
						<input type="hidden" name="pulse[report_logo]" value="<?php echo esc_attr( (string) $logo_id ); ?>" data-pa-logo-input />
						<p class="pa-inline">
							<button type="button" class="button" data-pa-logo-choose><?php esc_html_e( 'Choose logo', 'pulse-analytics' ); ?></button>
							<button type="button" class="button-link button-link-delete" data-pa-logo-remove <?php echo $logo_url ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove logo', 'pulse-analytics' ); ?></button>
						</p>
					</div>
					<p class="description">
						<?php
						echo esc_html(
							$theme
								? __( 'Printed at the top of PDF reports and shown in report emails. A wide PNG or JPG works best. Without a logo here, the logo from your theme is used.', 'pulse-analytics' )
								: __( 'Printed at the top of PDF reports and shown in report emails. A wide PNG or JPG works best.', 'pulse-analytics' )
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Email reports', 'pulse-analytics' ); ?></th>
				<td>
					<?php
					$this->checkbox( 'email_enabled', __( 'Send a summary report by email', 'pulse-analytics' ), '', $s );
					$this->checkbox( 'email_attach_pdf', __( 'Attach the PDF report to these emails', 'pulse-analytics' ), '', $s );
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-email-freq"><?php esc_html_e( 'Frequency', 'pulse-analytics' ); ?></label></th>
				<td>
					<select id="pa-email-freq" name="pulse[email_frequency]">
						<option value="weekly" <?php selected( 'weekly', $s['email_frequency'] ); ?>><?php esc_html_e( 'Weekly (last 7 days)', 'pulse-analytics' ); ?></option>
						<option value="monthly" <?php selected( 'monthly', $s['email_frequency'] ); ?>><?php esc_html_e( 'Monthly (previous month)', 'pulse-analytics' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pa-email-to"><?php esc_html_e( 'Recipients', 'pulse-analytics' ); ?></label></th>
				<td>
					<input type="text" id="pa-email-to" class="large-text" name="pulse[email_recipients]" value="<?php echo esc_attr( $s['email_recipients'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'Comma separated. Leave empty to use the site admin email.', 'pulse-analytics' ); ?></p>
				</td>
			</tr>
		</table>
		<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pulse_analytics_test_email' ), 'pulse_analytics_test_email' ) ); ?>"><?php esc_html_e( 'Send a test report now', 'pulse-analytics' ); ?></a></p>
		<?php
	}

	/**
	 * Tools tab.
	 */
	private function render_tools() {
		$export = function ( $type, $label, $class = 'button' ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'pulse_analytics_export',
						'type'   => $type,
						'start'  => Helpers::today( -29 ),
						'end'    => Helpers::today(),
					),
					admin_url( 'admin-post.php' )
				),
				'pulse_analytics_export'
			);
			return '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '" data-pa-export="' . esc_attr( $type ) . '">' . esc_html( $label ) . '</a>';
		};
		$has_demo = Demo_Data::exists();
		?>
		<div class="pa-tools">
			<div class="pa-card">
				<h2><?php esc_html_e( 'Export data (CSV)', 'pulse-analytics' ); ?></h2>
				<p><?php esc_html_e( 'Choose a period, then download one file or all of them at once. The ZIP has every report from the dashboard as its own CSV file, plus the raw visits, pageviews and events. Each report in the dashboard also has its own CSV button that uses your filters.', 'pulse-analytics' ); ?></p>
				<p class="pa-inline">
					<label><?php esc_html_e( 'From', 'pulse-analytics' ); ?> <input type="date" id="pa-export-start" value="<?php echo esc_attr( Helpers::today( -29 ) ); ?>" /></label>
					<label><?php esc_html_e( 'To', 'pulse-analytics' ); ?> <input type="date" id="pa-export-end" value="<?php echo esc_attr( Helpers::today() ); ?>" /></label>
				</p>
				<p class="pa-inline">
					<?php
					echo $export( 'all', __( 'All reports (ZIP)', 'pulse-analytics' ), 'button button-primary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure.
					echo $export( 'sessions', __( 'Visits (sessions)', 'pulse-analytics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in the closure.
					echo $export( 'pageviews', __( 'Pageviews', 'pulse-analytics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $export( 'events', __( 'Events', 'pulse-analytics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $export( 'summary', __( 'Daily summary', 'pulse-analytics' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</p>
				<p class="pa-inline pa-prompt-row">
					<label for="pa-prompt-type"><?php esc_html_e( 'Prompt for AI tools:', 'pulse-analytics' ); ?></label>
					<select id="pa-prompt-type">
						<option value="all"><?php esc_html_e( 'All reports (ZIP)', 'pulse-analytics' ); ?></option>
						<option value="sessions"><?php esc_html_e( 'Visits (sessions)', 'pulse-analytics' ); ?></option>
						<option value="pageviews"><?php esc_html_e( 'Pageviews', 'pulse-analytics' ); ?></option>
						<option value="events"><?php esc_html_e( 'Events', 'pulse-analytics' ); ?></option>
						<option value="summary"><?php esc_html_e( 'Daily summary', 'pulse-analytics' ); ?></option>
					</select>
					<button type="button" class="button" id="pa-prompt-open"><?php esc_html_e( 'AI prompt', 'pulse-analytics' ); ?></button>
				</p>
				<p class="description"><?php esc_html_e( 'Paste the prompt into ChatGPT, Claude, Gemini or another AI chat together with the file, so the AI knows what the columns mean and what to look for. The ZIP also contains it as prompt-for-ai.txt.', 'pulse-analytics' ); ?></p>
				<dialog id="pa-prompt-dialog" class="pa-prompt-dialog" aria-labelledby="pa-prompt-title">
					<div class="pa-prompt-inner">
						<h2 id="pa-prompt-title"><?php esc_html_e( 'Prompt for AI tools', 'pulse-analytics' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Paste this into the AI chat together with the file. You can change the text before you copy it.', 'pulse-analytics' ); ?></p>
						<textarea id="pa-prompt-text" rows="16" spellcheck="false" aria-label="<?php esc_attr_e( 'Prompt', 'pulse-analytics' ); ?>"></textarea>
						<p class="pa-prompt-actions">
							<button type="button" class="button" data-pa-prompt-close><?php esc_html_e( 'Close', 'pulse-analytics' ); ?></button>
							<button type="button" class="button button-primary" id="pa-prompt-copy"><?php esc_html_e( 'Copy prompt', 'pulse-analytics' ); ?></button>
						</p>
					</div>
				</dialog>
			</div>

			<div class="pa-card">
				<h2><?php esc_html_e( 'PDF report', 'pulse-analytics' ); ?></h2>
				<p><?php esc_html_e( 'A printable summary for the dates you choose: the main numbers and how they changed, a chart of visitors, traffic channels, devices, top pages, sources, countries, campaigns, goals and actions on the site. The logo from the Reports tab is printed at the top.', 'pulse-analytics' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pa-report-form">
					<input type="hidden" name="action" value="pulse_analytics_email_report" />
					<?php wp_nonce_field( 'pulse_analytics_email_report' ); ?>
					<p class="pa-inline">
						<label><?php esc_html_e( 'From', 'pulse-analytics' ); ?> <input type="date" name="start" id="pa-report-start" value="<?php echo esc_attr( Helpers::today( -29 ) ); ?>" required /></label>
						<label><?php esc_html_e( 'To', 'pulse-analytics' ); ?> <input type="date" name="end" id="pa-report-end" value="<?php echo esc_attr( Helpers::today() ); ?>" required /></label>
						<label><?php esc_html_e( 'Compare with', 'pulse-analytics' ); ?>
							<select name="compare" id="pa-report-compare">
								<option value="previous"><?php esc_html_e( 'the period before', 'pulse-analytics' ); ?></option>
								<option value="year"><?php esc_html_e( 'the same dates a year earlier', 'pulse-analytics' ); ?></option>
								<option value=""><?php esc_html_e( 'nothing', 'pulse-analytics' ); ?></option>
							</select>
						</label>
						<a class="button button-primary" id="pa-report-download" href="<?php echo esc_url( add_query_arg( array( 'action' => 'pulse_analytics_export', 'type' => 'summary', 'format' => 'pdf', 'filters' => '[]', '_wpnonce' => wp_create_nonce( 'pulse_analytics_export' ) ), admin_url( 'admin-post.php' ) ) ); ?>"><?php esc_html_e( 'Download PDF', 'pulse-analytics' ); ?></a>
					</p>
					<h3><?php esc_html_e( 'Send by email', 'pulse-analytics' ); ?></h3>
					<p>
						<label for="pa-report-to"><?php esc_html_e( 'To', 'pulse-analytics' ); ?></label><br />
						<input type="text" id="pa-report-to" name="to" class="large-text" placeholder="name@example.com, another@example.com" />
					</p>
					<p>
						<label for="pa-report-subject"><?php esc_html_e( 'Subject', 'pulse-analytics' ); ?></label><br />
						<input type="text" id="pa-report-subject" name="subject" class="large-text" maxlength="200" placeholder="<?php echo esc_attr( Pdf_Report::default_subject( new Query( Helpers::today( -29 ), Helpers::today() ) ) ); ?>" />
					</p>
					<p>
						<label for="pa-report-message"><?php esc_html_e( 'Message', 'pulse-analytics' ); ?></label><br />
						<textarea id="pa-report-message" name="message" rows="5" class="large-text" maxlength="5000" placeholder="<?php esc_attr_e( 'A short note for the people who get the report (optional).', 'pulse-analytics' ); ?>"></textarea>
					</p>
					<p><button type="submit" class="button"><?php esc_html_e( 'Send PDF by email', 'pulse-analytics' ); ?></button></p>
				</form>
			</div>

			<div class="pa-card">
				<h2><?php esc_html_e( 'Sample data', 'pulse-analytics' ); ?></h2>
				<p><?php esc_html_e( 'Fill the dashboard with 60 days of realistic sample visits to explore every report. Sample data is clearly separated and can be removed at any time without touching real statistics.', 'pulse-analytics' ); ?></p>
				<p class="pa-inline">
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pulse_analytics_demo' ), 'pulse_analytics_demo' ) ); ?>"><?php esc_html_e( 'Generate sample data', 'pulse-analytics' ); ?></a>
					<?php if ( $has_demo ) : ?>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pulse_analytics_demo_delete' ), 'pulse_analytics_demo_delete' ) ); ?>"><?php esc_html_e( 'Remove sample data', 'pulse-analytics' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

			<div class="pa-card">
				<h2><?php esc_html_e( 'Maintenance', 'pulse-analytics' ); ?></h2>
				<p class="pa-inline">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pulse_analytics_clear_cache' ), 'pulse_analytics_clear_cache' ) ); ?>"><?php esc_html_e( 'Clear report cache', 'pulse-analytics' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=pulse_analytics_purge' ), 'pulse_analytics_purge' ) ); ?>"><?php esc_html_e( 'Apply data retention now', 'pulse-analytics' ); ?></a>
				</p>
				<?php
				global $wpdb;
				$sizes = array();
				foreach ( Plugin::tables() as $label => $table ) {
					$sizes[ $label ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: sessions, 2: pageviews, 3: events, 4: pages */
							__( 'Stored: %1$s visits, %2$s pageviews, %3$s events, %4$s unique pages.', 'pulse-analytics' ),
							number_format_i18n( $sizes['sessions'] ),
							number_format_i18n( $sizes['pageviews'] ),
							number_format_i18n( $sizes['events'] ),
							number_format_i18n( $sizes['pages'] )
						)
					);
					?>
				</p>
			</div>

			<div class="pa-card pa-danger">
				<h2><?php esc_html_e( 'Danger zone', 'pulse-analytics' ); ?></h2>
				<p><?php esc_html_e( 'Permanently delete all collected statistics. Settings and goals are kept. This cannot be undone.', 'pulse-analytics' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-pa-confirm="<?php esc_attr_e( 'Delete ALL statistics permanently? This cannot be undone.', 'pulse-analytics' ); ?>">
					<input type="hidden" name="action" value="pulse_analytics_reset" />
					<?php wp_nonce_field( 'pulse_analytics_reset' ); ?>
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete all statistics', 'pulse-analytics' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Updates tab: where updates come from, the last check and the token for private repositories.
	 */
	private function render_updates() {
		$release = ( new Updater() )->release();
		$latest  = isset( $release['version'] ) ? $release['version'] : '';
		$newer   = '' !== $latest && version_compare( $latest, PULSE_ANALYTICS_VERSION, '>' );
		$install = $newer ? Updater::update_url() : '';
		$token   = Updater::token_source();
		?>
		<div class="pa-tools">
			<div class="pa-card">
				<h2><?php esc_html_e( 'Plugin updates', 'pulse-analytics' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %s: link to the GitHub repository */
						esc_html__( 'New versions come from the releases of %s on GitHub. They show up on the Plugins screen like any other plugin update, and you can turn on automatic updates for this plugin there.', 'pulse-analytics' ),
						'<a href="' . esc_url( Updater::repository_url() ) . '">' . esc_html( Updater::repository() ) . '</a>'
					);
					?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Installed version', 'pulse-analytics' ); ?></th>
						<td><?php echo esc_html( PULSE_ANALYTICS_VERSION ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Latest release', 'pulse-analytics' ); ?></th>
						<td>
							<?php if ( '' !== $latest ) : ?>
								<span class="pa-pill <?php echo $newer ? 'pa-pill-warn' : 'pa-pill-ok'; ?>"><?php echo $newer ? esc_html__( 'Update available', 'pulse-analytics' ) : esc_html__( 'Up to date', 'pulse-analytics' ); ?></span>
								<?php
								echo esc_html( $latest );
								if ( ! empty( $release['date'] ) ) {
									/* translators: %s: time since the release, like "3 days" */
									echo ' &middot; ' . esc_html( sprintf( __( 'published %s ago', 'pulse-analytics' ), human_time_diff( strtotime( $release['date'] ) ) ) );
								}
								?>
								&middot; <a href="<?php echo esc_url( $release['url'] ); ?>"><?php esc_html_e( 'Release notes', 'pulse-analytics' ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $release['error'] ) ) : ?>
								<p class="pa-error"><?php echo esc_html( $release['error'] ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $release['skipped'] ) ) : ?>
								<p class="description">
									<?php
									/* translators: 1: version number, 2: file name */
									echo esc_html( sprintf( __( 'Release %1$s was skipped because it has no %2$s attached.', 'pulse-analytics' ), $release['skipped'], Updater::ASSET ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last checked', 'pulse-analytics' ); ?></th>
						<td>
							<?php
							/* translators: %s: time since the check, like "5 minutes" */
							echo esc_html( sprintf( __( '%s ago', 'pulse-analytics' ), human_time_diff( (int) $release['checked'] ) ) );
							?>
						</td>
					</tr>
				</table>
				<p class="pa-inline">
					<a class="button" href="<?php echo esc_url( Updater::check_url() ); ?>"><?php esc_html_e( 'Check for updates', 'pulse-analytics' ); ?></a>
					<?php if ( '' !== $install ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $install ); ?>">
							<?php
							/* translators: %s: version number */
							echo esc_html( sprintf( __( 'Update to %s now', 'pulse-analytics' ), $latest ) );
							?>
						</a>
					<?php endif; ?>
				</p>
			</div>

			<div class="pa-card">
				<h2><?php esc_html_e( 'Private repository', 'pulse-analytics' ); ?></h2>
				<p><?php esc_html_e( 'WordPress can only see the releases of a private repository if it has a GitHub token. On GitHub, create a fine-grained personal access token that has read-only access to the contents of this one repository, and paste it below. A public repository does not need a token, although one raises the limit on how often this site may check.', 'pulse-analytics' ); ?></p>
				<?php if ( 'constant' === $token ) : ?>
					<p><?php esc_html_e( 'A token is set in wp-config.php (PULSE_ANALYTICS_GITHUB_TOKEN). Change or remove it there.', 'pulse-analytics' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="pulse_analytics_github_token" />
						<?php wp_nonce_field( 'pulse_analytics_github_token' ); ?>
						<p>
							<label for="pa-github-token" class="screen-reader-text"><?php esc_html_e( 'GitHub token', 'pulse-analytics' ); ?></label>
							<input type="password" id="pa-github-token" name="github_token" class="regular-text" autocomplete="off" spellcheck="false" placeholder="<?php echo 'saved' === $token ? esc_attr__( 'A token is saved. Paste a new one to replace it.', 'pulse-analytics' ) : 'github_pat_...'; ?>" />
						</p>
						<?php if ( 'saved' === $token ) : ?>
							<p><label><input type="checkbox" name="remove_token" value="1" /> <?php esc_html_e( 'Remove the saved token', 'pulse-analytics' ); ?></label></p>
						<?php endif; ?>
						<p><button type="submit" class="button"><?php esc_html_e( 'Save token', 'pulse-analytics' ); ?></button></p>
					</form>
					<p class="description">
						<?php
						printf(
							/* translators: %s: line of PHP code */
							esc_html__( 'If you would rather not keep the token in the database, add %s to wp-config.php instead.', 'pulse-analytics' ),
							"<code>define( 'PULSE_ANALYTICS_GITHUB_TOKEN', '...' );</code>"
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ *
	 * Actions.
	 * ------------------------------------------------------------------ */

	/**
	 * Common permission + nonce check.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pulse-analytics' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirects back with a notice.
	 *
	 * @param string $notice  Notice key.
	 * @param string $tab     Tab.
	 * @param string $message Extra message.
	 * @param string $page    Admin page slug.
	 */
	private function back( $notice, $tab = 'tracking', $message = '', $page = self::PAGE_SLUG ) {
		$args = array(
			'page'         => $page,
			'pulse_notice' => $notice,
		);
		if ( self::PAGE_SLUG === $page ) {
			$args['tab'] = $tab;
		}
		if ( '' !== $message ) {
			// Keep the message in a transient instead of the URL so it cannot be faked.
			set_transient( 'pulse_analytics_notice_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Saves a settings tab.
	 */
	public function save() {
		$this->guard( 'pulse_analytics_settings' );
		$tab   = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'tracking';
		$input = isset( $_POST['pulse'] ) && is_array( $_POST['pulse'] ) ? wp_unslash( $_POST['pulse'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by Settings::sanitize().
		$old   = Settings::get( 'geoip_source' );
		Settings::update( Settings::sanitize( $input ) );
		Cache::flush();

		$new = Settings::get( 'geoip_source' );
		if ( $new !== $old && GeoIP::is_downloadable_source( $new ) && ! GeoIP::database_exists() ) {
			wp_schedule_single_event( time(), Cron::GEOIP_HOOK );
			spawn_cron();
		}
		$this->back( 'settings_saved', $tab );
	}

	/**
	 * Downloads the geolocation database now.
	 */
	public function download_geoip() {
		$this->guard( 'pulse_analytics_geoip' );
		$result = GeoIP::download();
		if ( is_wp_error( $result ) ) {
			$this->back( 'geoip_error', 'geo', $result->get_error_message() );
		}
		$this->back( 'geoip_ok', 'geo' );
	}

	/**
	 * Generates sample data.
	 */
	public function demo() {
		$this->guard( 'pulse_analytics_demo' );
		if ( Demo_Data::exists() ) {
			Demo_Data::delete();
		}
		Demo_Data::generate( 60 );
		$this->back( 'demo_created', '', '', Admin::PAGE_SLUG );
	}

	/**
	 * Removes sample data.
	 */
	public function demo_delete() {
		$this->guard( 'pulse_analytics_demo_delete' );
		Demo_Data::delete();
		$this->back( 'demo_deleted', 'tools' );
	}

	/**
	 * Deletes all statistics.
	 */
	public function reset() {
		$this->guard( 'pulse_analytics_reset' );
		Installer::truncate_tables();
		$this->back( 'data_deleted', 'tools' );
	}

	/**
	 * Applies the retention policy now.
	 */
	public function purge() {
		$this->guard( 'pulse_analytics_purge' );
		$deleted = Cron::purge_old_data();
		/* translators: %s: number of visits */
		$this->back( 'purged', 'tools', sprintf( _n( '%s visit removed.', '%s visits removed.', $deleted, 'pulse-analytics' ), number_format_i18n( $deleted ) ) );
	}

	/**
	 * Clears cached reports.
	 */
	public function clear_cache() {
		$this->guard( 'pulse_analytics_clear_cache' );
		Cache::flush();
		$this->back( 'cache_cleared', 'tools' );
	}

	/**
	 * Sends a test email report.
	 */
	public function test_email() {
		$this->guard( 'pulse_analytics_test_email' );
		$sent = Email_Reports::send();
		$this->back( $sent ? 'email_sent' : 'email_failed', 'email' );
	}

	/**
	 * Emails the PDF report from the Tools tab.
	 */
	public function email_report() {
		$this->guard( 'pulse_analytics_email_report' );
		$query = Query::from_array(
			array(
				'start'   => isset( $_POST['start'] ) ? sanitize_text_field( wp_unslash( $_POST['start'] ) ) : '',
				'end'     => isset( $_POST['end'] ) ? sanitize_text_field( wp_unslash( $_POST['end'] ) ) : '',
				'compare' => isset( $_POST['compare'] ) ? sanitize_key( wp_unslash( $_POST['compare'] ) ) : '',
			)
		);
		$to    = Pdf_Report::recipients( isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '' );
		if ( is_wp_error( $to ) ) {
			$this->back( 'report_failed', 'tools', $to->get_error_message() );
		}
		if ( ! Pdf_Report::allow_send() ) {
			$this->back( 'report_failed', 'tools', __( 'You have sent many reports in the last hour. Please try again later.', 'pulse-analytics' ) );
		}
		$subject = isset( $_POST['subject'] ) ? Helpers::truncate( sanitize_text_field( wp_unslash( $_POST['subject'] ) ), 200 ) : '';
		$message = isset( $_POST['message'] ) ? Helpers::truncate( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ), 5000 ) : '';
		$result  = Pdf_Report::send( $query, $to, $subject, $message );
		if ( is_wp_error( $result ) ) {
			$this->back( 'report_failed', 'tools', $result->get_error_message() );
		}
		/* translators: %s: list of email addresses */
		$this->back( 'report_sent', 'tools', sprintf( __( 'Sent to %s.', 'pulse-analytics' ), implode( ', ', $to ) ) );
	}
}
