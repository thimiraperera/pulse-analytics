<?php
/**
 * Plugin Name:       Pulse Analytics
 * Plugin URI:        https://github.com/thimiraperera/pulse-analytics
 * Description:       Self-hosted website statistics. See visitors, pages, traffic sources, campaigns, countries on a world map and goals, and export any report to CSV.
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Thimira Perera
 * Author URI:        https://github.com/thimiraperera
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pulse-analytics
 * Domain Path:       /languages
 * Update URI:        https://github.com/thimiraperera/pulse-analytics
 *
 * @package PulseAnalytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PULSE_ANALYTICS_VERSION', '0.2.1' );
define( 'PULSE_ANALYTICS_FILE', __FILE__ );
define( 'PULSE_ANALYTICS_PATH', plugin_dir_path( __FILE__ ) );
define( 'PULSE_ANALYTICS_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloader: PulseAnalytics\Some_Class => includes/class-some-class.php
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'PulseAnalytics\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = PULSE_ANALYTICS_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'PulseAnalytics\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'PulseAnalytics\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'PulseAnalytics\\Plugin', 'instance' ) );
