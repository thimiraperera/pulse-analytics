<?php
/**
 * Plugin updates from GitHub releases.
 *
 * @package PulseAnalytics
 */

namespace PulseAnalytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets WordPress update the plugin from the releases of its GitHub repository.
 *
 * The "Update URI" line in the plugin header points at the repository. Because of it,
 * WordPress does not look for this plugin on WordPress.org and asks the
 * update_plugins_github.com filter instead. Every release needs pulse-analytics.zip
 * attached to it; the release workflow in the repository builds that file.
 */
class Updater {

	const SLUG         = 'pulse-analytics';
	const ASSET        = 'pulse-analytics.zip';
	const CACHE_KEY    = 'pulse_analytics_release';
	const TOKEN_OPTION = 'pulse_analytics_github_token';
	const API          = 'https://api.github.com';

	/**
	 * Whether this request already skipped the cache once.
	 *
	 * @var bool
	 */
	private $forced = false;

	/**
	 * Registers hooks. Runs outside the admin too, because WordPress also looks for
	 * updates from cron and WP-CLI.
	 */
	public function hooks() {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_folder' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_pulse_analytics_check_updates', array( $this, 'check_now' ) );
		add_action( 'admin_post_pulse_analytics_github_token', array( $this, 'save_token' ) );
	}

	/**
	 * "owner/repo" taken from the Update URI header, or an empty string.
	 *
	 * @return string
	 */
	public static function repository() {
		static $repository = null;
		if ( null === $repository ) {
			$header     = get_file_data( PULSE_ANALYTICS_FILE, array( 'uri' => 'Update URI' ) );
			$parts      = wp_parse_url( (string) $header['uri'] );
			$path       = isset( $parts['path'] ) ? preg_replace( '/\.git$/', '', trim( $parts['path'], '/' ) ) : '';
			$repository = isset( $parts['host'] ) && 'github.com' === strtolower( $parts['host'] ) && preg_match( '#^[\w.-]+/[\w.-]+$#', $path ) ? $path : '';
		}
		return $repository;
	}

	/**
	 * Address of the repository on github.com.
	 *
	 * @return string
	 */
	public static function repository_url() {
		return 'https://github.com/' . self::repository();
	}

	/**
	 * GitHub token for private repositories. The constant in wp-config.php wins over the saved one.
	 *
	 * @return string
	 */
	public static function token() {
		if ( defined( 'PULSE_ANALYTICS_GITHUB_TOKEN' ) && PULSE_ANALYTICS_GITHUB_TOKEN ) {
			return (string) PULSE_ANALYTICS_GITHUB_TOKEN;
		}
		return (string) get_site_option( self::TOKEN_OPTION, '' );
	}

	/**
	 * Where the token comes from: 'constant', 'saved' or ''.
	 *
	 * @return string
	 */
	public static function token_source() {
		if ( defined( 'PULSE_ANALYTICS_GITHUB_TOKEN' ) && PULSE_ANALYTICS_GITHUB_TOKEN ) {
			return 'constant';
		}
		return '' !== (string) get_site_option( self::TOKEN_OPTION, '' ) ? 'saved' : '';
	}

	/* ------------------------------------------------------------------ *
	 * Talking to GitHub.
	 * ------------------------------------------------------------------ */

	/**
	 * The newest usable release, from cache when possible.
	 *
	 * The result has either 'version', 'tag', 'package', 'url', 'date' and 'changelog',
	 * or 'error'. Both kinds have 'checked' (a timestamp).
	 *
	 * @param bool $force Skip the cache.
	 * @return array
	 */
	public function release( $force = false ) {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}

		$release = $this->fetch_release();
		if ( is_wp_error( $release ) ) {
			// Keep the last release we know about, so a short GitHub outage does not hide an update.
			$data            = is_array( $cached ) && ! empty( $cached['version'] ) ? $cached : array();
			$data['error']   = $release->get_error_message();
			$data['checked'] = time();
			set_site_transient( self::CACHE_KEY, $data, HOUR_IN_SECONDS );
			return $data;
		}

		$release['checked'] = time();
		set_site_transient( self::CACHE_KEY, $release, 3 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Reads the release list from GitHub and picks the highest version that has the zip attached.
	 *
	 * @return array|\WP_Error
	 */
	private function fetch_release() {
		$repository = self::repository();
		if ( '' === $repository ) {
			return new \WP_Error( 'pulse_update_repository', __( 'The plugin header has no GitHub address to check for updates.', 'pulse-analytics' ) );
		}

		$body = $this->get( '/repos/' . $repository . '/releases?per_page=20' );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$list = json_decode( $body, true );
		if ( ! is_array( $list ) ) {
			return new \WP_Error( 'pulse_update_json', __( 'GitHub sent a reply that could not be read.', 'pulse-analytics' ) );
		}

		$best     = null;
		$releases = array();
		$no_zip   = '';
		foreach ( $list as $item ) {
			if ( ! is_array( $item ) || ! empty( $item['draft'] ) || ! empty( $item['prerelease'] ) || empty( $item['tag_name'] ) ) {
				continue;
			}
			$version = ltrim( (string) $item['tag_name'], 'vV' );
			if ( ! preg_match( '/^\d+(\.\d+)*$/', $version ) ) {
				continue;
			}
			$releases[ $version ] = $item;

			$package = $this->package_url( $item, $repository );
			if ( '' === $package ) {
				if ( '' === $no_zip || version_compare( $version, $no_zip, '>' ) ) {
					$no_zip = $version;
				}
				continue;
			}
			if ( null === $best || version_compare( $version, $best['version'], '>' ) ) {
				$best = array(
					'version' => $version,
					'tag'     => (string) $item['tag_name'],
					'package' => $package,
					'url'     => isset( $item['html_url'] ) ? (string) $item['html_url'] : self::repository_url() . '/releases',
					'date'    => isset( $item['published_at'] ) ? (string) $item['published_at'] : '',
				);
			}
		}

		if ( null === $best ) {
			if ( '' !== $no_zip ) {
				/* translators: 1: version number, 2: file name */
				return new \WP_Error( 'pulse_update_asset', sprintf( __( 'Release %1$s on GitHub has no %2$s attached, so WordPress cannot install it.', 'pulse-analytics' ), $no_zip, self::ASSET ) );
			}
			return new \WP_Error( 'pulse_update_none', __( 'There are no releases on GitHub yet.', 'pulse-analytics' ) );
		}

		if ( '' !== $no_zip && version_compare( $no_zip, $best['version'], '>' ) ) {
			$best['skipped'] = $no_zip;
		}
		$best['changelog'] = $this->changelog(
			array_filter(
				$releases,
				static function ( $version ) use ( $best ) {
					return version_compare( $version, $best['version'], '<=' );
				},
				ARRAY_FILTER_USE_KEY
			)
		);

		// WordPress uses these to refuse an update the site cannot run, so read them from the new version.
		if ( version_compare( $best['version'], PULSE_ANALYTICS_VERSION, '>' ) ) {
			$readme = $this->get( '/repos/' . $repository . '/contents/' . self::SLUG . '/readme.txt?ref=' . rawurlencode( $best['tag'] ), 'application/vnd.github.raw+json' );
			if ( ! is_wp_error( $readme ) ) {
				$best = array_merge( $best, self::requirements( $readme ) );
			}
		}

		return $best;
	}

	/**
	 * Download address of the release zip. Private repositories go through the API with the token.
	 *
	 * @param array  $item       Release from the GitHub API.
	 * @param string $repository owner/repo.
	 * @return string
	 */
	private function package_url( array $item, $repository ) {
		if ( empty( $item['assets'] ) || ! is_array( $item['assets'] ) ) {
			return '';
		}
		foreach ( $item['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['name'] ) || self::ASSET !== $asset['name'] ) {
				continue;
			}
			if ( '' !== self::token() && ! empty( $asset['id'] ) ) {
				return self::API . '/repos/' . $repository . '/releases/assets/' . absint( $asset['id'] );
			}
			$url = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			return 0 === strpos( $url, 'https://github.com/' . $repository . '/releases/download/' ) ? $url : '';
		}
		return '';
	}

	/**
	 * GET request to the GitHub API.
	 *
	 * @param string $path   Path below https://api.github.com.
	 * @param string $accept Accept header.
	 * @return string|\WP_Error Response body.
	 */
	private function get( $path, $accept = 'application/vnd.github+json' ) {
		$response = wp_safe_remote_get(
			self::API . $path,
			array(
				'timeout' => 15,
				'headers' => $this->headers( $accept ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'pulse_update_http', $this->http_error( $code, $response ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Request headers, with the token when there is one.
	 *
	 * @param string $accept Accept header.
	 * @return array
	 */
	private function headers( $accept ) {
		$headers = array(
			'Accept'               => $accept,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		$token   = self::token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	/**
	 * Explains a failed GitHub request.
	 *
	 * @param int   $code     HTTP status.
	 * @param array $response Response.
	 * @return string
	 */
	private function http_error( $code, $response ) {
		if ( ( 403 === $code || 429 === $code ) && '0' === (string) wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' ) ) {
			return __( 'GitHub limits how often a server can check without a token, and that limit was reached. Try again in an hour, or add a token on the Updates tab.', 'pulse-analytics' );
		}
		if ( 401 === $code ) {
			return __( 'GitHub did not accept the token. It may have expired or been deleted.', 'pulse-analytics' );
		}
		if ( 404 === $code ) {
			return '' === self::token()
				? __( 'GitHub could not find the repository. If it is private, add a token on the Updates tab.', 'pulse-analytics' )
				: __( 'GitHub could not find the repository with this token. Check that the token can read this repository.', 'pulse-analytics' );
		}
		/* translators: %d: HTTP status code */
		return sprintf( __( 'GitHub answered with HTTP status %d.', 'pulse-analytics' ), $code );
	}

	/**
	 * Requirement lines from a readme.txt.
	 *
	 * @param string $text readme.txt contents.
	 * @return array Keys requires, tested and requires_php when found.
	 */
	public static function requirements( $text ) {
		$labels = array(
			'requires'     => 'Requires at least',
			'tested'       => 'Tested up to',
			'requires_php' => 'Requires PHP',
		);
		$found  = array();
		foreach ( $labels as $key => $label ) {
			if ( preg_match( '/^' . preg_quote( $label, '/' ) . ':[ \t]*([0-9.]+)[ \t\r]*$/mi', (string) $text, $match ) ) {
				$found[ $key ] = rtrim( $match[1], '.' );
			}
		}
		return $found;
	}

	/**
	 * HTML changelog from the release notes, newest first.
	 *
	 * @param array $releases Releases keyed by version.
	 * @return string
	 */
	private function changelog( array $releases ) {
		uksort(
			$releases,
			static function ( $a, $b ) {
				return version_compare( $b, $a );
			}
		);
		$html = '';
		foreach ( array_slice( $releases, 0, 10, true ) as $version => $item ) {
			$date  = ! empty( $item['published_at'] ) ? strtotime( $item['published_at'] ) : 0;
			$html .= '<h4>' . esc_html( $version ) . ( $date ? ' <small>(' . esc_html( wp_date( get_option( 'date_format' ), $date ) ) . ')</small>' : '' ) . '</h4>';
			$notes = isset( $item['body'] ) ? trim( (string) $item['body'] ) : '';
			$html .= '' !== $notes ? self::markdown( substr( $notes, 0, 8000 ) ) : '<p>' . esc_html__( 'No notes for this release.', 'pulse-analytics' ) . '</p>';
		}
		return $html;
	}

	/**
	 * Turns the bit of Markdown used in release notes into HTML: headings, lists,
	 * paragraphs, links, bold and code. Everything else stays plain text.
	 *
	 * @param string $text Markdown.
	 * @return string
	 */
	public static function markdown( $text ) {
		$html      = '';
		$paragraph = array();
		$in_list   = false;
		$lines     = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		$lines[]   = '';
		foreach ( $lines as $line ) {
			$line = trim( $line );
			$item = preg_match( '/^[-*+]\s+(.+)$/', $line, $match ) ? $match[1] : null;
			if ( ( null !== $item || '' === $line || '#' === substr( $line, 0, 1 ) ) && $paragraph ) {
				$html     .= '<p>' . implode( ' ', $paragraph ) . '</p>';
				$paragraph = array();
			}
			if ( null === $item && $in_list ) {
				$html   .= '</ul>';
				$in_list = false;
			}
			if ( null !== $item ) {
				$html   .= ( $in_list ? '' : '<ul>' ) . '<li>' . self::inline( $item ) . '</li>';
				$in_list = true;
			} elseif ( preg_match( '/^#{1,6}\s+(.+?)\s*#*$/', $line, $match ) ) {
				$html .= '<h4>' . self::inline( $match[1] ) . '</h4>';
			} elseif ( '' !== $line ) {
				$paragraph[] = self::inline( $line );
			}
		}
		return $html;
	}

	/**
	 * Inline Markdown on one escaped line.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function inline( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
		return preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
			static function ( $match ) {
				return '<a href="' . esc_url( wp_specialchars_decode( $match[2] ) ) . '">' . $match[1] . '</a>';
			},
			$text
		);
	}

	/* ------------------------------------------------------------------ *
	 * WordPress hooks.
	 * ------------------------------------------------------------------ */

	/**
	 * Answers WordPress when it looks for updates (filter update_plugins_github.com).
	 *
	 * @param array|false $update      Update data from an earlier filter, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( PULSE_ANALYTICS_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = $this->release( $this->force_requested() );
		if ( empty( $release['version'] ) ) {
			return $update;
		}
		$data = array(
			'slug'    => self::SLUG,
			'version' => $release['version'],
			'url'     => self::repository_url(),
			'package' => $release['package'],
			'icons'   => array( 'svg' => PULSE_ANALYTICS_URL . 'assets/img/icon.svg' ),
		);
		foreach ( array( 'requires', 'tested', 'requires_php' ) as $key ) {
			if ( ! empty( $release[ $key ] ) ) {
				$data[ $key ] = $release[ $key ];
			}
		}
		return $data;
	}

	/**
	 * True once per request when someone clicked "Check again" on Dashboard > Updates.
	 *
	 * @return bool
	 */
	private function force_requested() {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only decides whether to skip a cache.
		if ( $this->forced || ! is_admin() || 'update-core.php' !== $pagenow || empty( $_GET['force-check'] ) || ! current_user_can( 'update_plugins' ) ) {
			return false;
		}
		$this->forced = true;
		return true;
	}

	/**
	 * Fills the "View details" window on the Plugins screen (filter plugins_api).
	 *
	 * @param false|object|array $result Result from an earlier filter.
	 * @param string             $action API action.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$header  = get_file_data(
			PULSE_ANALYTICS_FILE,
			array(
				'name'         => 'Plugin Name',
				'description'  => 'Description',
				'author'       => 'Author',
				'author_uri'   => 'Author URI',
				'requires'     => 'Requires at least',
				'requires_php' => 'Requires PHP',
			)
		);
		$release = $this->release();
		$newer   = ! empty( $release['version'] ) && version_compare( $release['version'], PULSE_ANALYTICS_VERSION, '>' );
		$readme  = PULSE_ANALYTICS_PATH . 'readme.txt';
		$local   = is_readable( $readme ) ? self::requirements( (string) file_get_contents( $readme ) ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$local   = array_merge( $local, array_filter( array_intersect_key( $header, array_flip( array( 'requires', 'requires_php' ) ) ) ) );
		$info    = array(
			'name'          => $header['name'],
			'slug'          => self::SLUG,
			'version'       => $newer ? $release['version'] : PULSE_ANALYTICS_VERSION,
			'author'        => $header['author_uri'] ? '<a href="' . esc_url( $header['author_uri'] ) . '">' . esc_html( $header['author'] ) . '</a>' : esc_html( $header['author'] ),
			'homepage'      => self::repository_url(),
			'last_updated'  => isset( $release['date'] ) ? $release['date'] : '',
			'download_link' => isset( $release['package'] ) ? $release['package'] : '',
			'sections'      => array(
				'description' => '<p>' . esc_html( $header['description'] ) . '</p><p><a href="' . esc_url( self::repository_url() ) . '">' . esc_html__( 'Source code and releases on GitHub', 'pulse-analytics' ) . '</a></p>',
				'changelog'   => ! empty( $release['changelog'] ) ? $release['changelog'] : '<p>' . esc_html( isset( $release['error'] ) ? $release['error'] : __( 'No release notes yet.', 'pulse-analytics' ) ) . '</p>',
			),
		);
		foreach ( array( 'requires', 'tested', 'requires_php' ) as $key ) {
			$value = $newer ? ( isset( $release[ $key ] ) ? $release[ $key ] : '' ) : ( isset( $local[ $key ] ) ? $local[ $key ] : '' );
			if ( '' !== $value ) {
				$info[ $key ] = $value;
			}
		}
		return (object) $info;
	}

	/**
	 * Downloads the zip of a private repository (filter upgrader_pre_download).
	 *
	 * GitHub answers the API address with a redirect to a short-lived link. The redirect is
	 * followed by hand so the token only ever goes to api.github.com.
	 *
	 * @param bool|string|\WP_Error $reply   Result from an earlier filter.
	 * @param string                $package Package address.
	 * @return bool|string|\WP_Error Path of the downloaded file, an error, or $reply.
	 */
	public function download( $reply, $package ) {
		$repository = self::repository();
		if ( false !== $reply || '' === $repository || 0 !== strpos( (string) $package, self::API . '/repos/' . $repository . '/releases/assets/' ) ) {
			return $reply;
		}
		$response = wp_safe_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => $this->headers( 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( $code < 300 || $code > 399 || 0 !== strpos( $location, 'https://' ) ) {
			return new \WP_Error( 'pulse_update_download', $this->http_error( $code, $response ) );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return download_url( $location, 300 );
	}

	/**
	 * Makes sure the new files end up in wp-content/plugins/pulse-analytics (filter upgrader_source_selection).
	 *
	 * The zip built by the release workflow is already right. This catches a zip of the
	 * whole repository, where the plugin sits one folder down, and zips with another folder name.
	 *
	 * @param string|\WP_Error $source        Unpacked folder.
	 * @param string           $remote_source Folder the zip was unpacked into.
	 * @param object           $upgrader      Upgrader.
	 * @param array            $hook_extra    Details about the upgrade.
	 * @return string|\WP_Error
	 */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( is_wp_error( $source ) || ! $wp_filesystem || empty( $hook_extra['plugin'] ) || plugin_basename( PULSE_ANALYTICS_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$source = trailingslashit( $source );
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( $source === $wanted ) {
			return $source;
		}
		if ( $wp_filesystem->exists( $source . self::SLUG . '/pulse-analytics.php' ) ) {
			return $source . self::SLUG . '/';
		}
		if ( untrailingslashit( $source ) !== untrailingslashit( $remote_source ) && $wp_filesystem->exists( $source . 'pulse-analytics.php' ) && $wp_filesystem->move( $source, $wanted ) ) {
			return $wanted;
		}
		return $source;
	}

	/**
	 * "Check for updates" link under the plugin on the Plugins screen.
	 *
	 * @param array  $links Links.
	 * @param string $file  Plugin basename.
	 * @return array
	 */
	public function row_meta( $links, $file ) {
		if ( plugin_basename( PULSE_ANALYTICS_FILE ) === $file && current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( self::check_url( 'plugins' ) ) . '">' . esc_html__( 'Check for updates', 'pulse-analytics' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Address of the "check now" action.
	 *
	 * @param string $back Where to go afterwards: 'plugins' or 'settings'.
	 * @return string
	 */
	public static function check_url( $back = 'settings' ) {
		return add_query_arg(
			array(
				'action'   => 'pulse_analytics_check_updates',
				'back'     => $back,
				'_wpnonce' => wp_create_nonce( 'pulse_analytics_check_updates' ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Link that installs the update right away, or '' when there is none waiting.
	 *
	 * @return string
	 */
	public static function update_url() {
		$file    = plugin_basename( PULSE_ANALYTICS_FILE );
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) || empty( $updates->response[ $file ] ) ) {
			return '';
		}
		$base = is_multisite() ? network_admin_url( 'update.php' ) : admin_url( 'update.php' );
		return wp_nonce_url( add_query_arg( array( 'action' => 'upgrade-plugin', 'plugin' => rawurlencode( $file ) ), $base ), 'upgrade-plugin_' . $file );
	}

	/**
	 * Checks GitHub now and refreshes the list of plugin updates.
	 */
	public function check_now() {
		$this->guard( 'pulse_analytics_check_updates' );
		$release = $this->release( true );
		$this->refresh_update_list();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checked in guard().
		$back = isset( $_GET['back'] ) && 'plugins' === sanitize_key( wp_unslash( $_GET['back'] ) ) ? 'plugins' : 'settings';
		$this->back( $release, $back );
	}

	/**
	 * Saves or removes the GitHub token, then checks again with it.
	 */
	public function save_token() {
		$this->guard( 'pulse_analytics_github_token' );
		$token = isset( $_POST['github_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['github_token'] ) ) ) : '';

		if ( ! empty( $_POST['remove_token'] ) ) {
			delete_site_option( self::TOKEN_OPTION );
		} elseif ( '' !== $token ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]{20,255}$/', $token ) ) {
				$this->redirect( 'settings', 'token_invalid' );
			}
			if ( is_multisite() ) {
				update_site_option( self::TOKEN_OPTION, $token );
			} else {
				update_option( self::TOKEN_OPTION, $token, false );
			}
		}

		$release = $this->release( true );
		$this->refresh_update_list();
		$this->back( $release, 'settings' );
	}

	/**
	 * Permission and nonce check for the actions above.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( $action ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to update plugins on this site.', 'pulse-analytics' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Asks WordPress to look for plugin updates again, so the Plugins screen is current.
	 */
	private function refresh_update_list() {
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
	}

	/**
	 * Redirects with a notice describing the check.
	 *
	 * @param array  $release Result of release().
	 * @param string $back    'plugins' or 'settings'.
	 */
	private function back( array $release, $back ) {
		if ( isset( $release['error'] ) ) {
			$this->redirect( $back, 'update_error', $release['error'] );
		}
		$message = version_compare( $release['version'], PULSE_ANALYTICS_VERSION, '>' )
			/* translators: %s: version number */
			? sprintf( __( 'Version %s is ready to install.', 'pulse-analytics' ), $release['version'] )
			/* translators: %s: version number */
			: sprintf( __( 'You have the latest version (%s).', 'pulse-analytics' ), PULSE_ANALYTICS_VERSION );
		$this->redirect( $back, 'update_checked', $message );
	}

	/**
	 * Redirects to the Plugins screen or the Updates tab.
	 *
	 * @param string $back    'plugins' or 'settings'.
	 * @param string $notice  Notice key, see Admin::notices().
	 * @param string $message Extra text for the notice.
	 */
	private function redirect( $back, $notice, $message = '' ) {
		if ( '' !== $message ) {
			set_transient( 'pulse_analytics_notice_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS );
		}
		$url = 'plugins' === $back
			? ( is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) )
			: admin_url( 'admin.php?page=' . Settings_Page::PAGE_SLUG . '&tab=updates' );
		wp_safe_redirect( add_query_arg( 'pulse_notice', $notice, $url ) );
		exit;
	}
}
