<?php
/**
 * Plugin Name:       Cart Renewal Price for Sublium
 * Plugin URI:        https://betatech.co/
 * Description:       Makes Sublium Subscribe & Save renewal / subscription totals use the cart line price instead of the raw variation price. Does not alter plan assignment. Not affiliated with Sublium.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Betatech
 * Author URI:        https://betatech.co/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       cart-renewal-price-for-sublium
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.6
 *
 * @package Cart_Renewal_Price_For_Sublium
 */

defined( 'ABSPATH' ) || exit;

define( 'SCRP_VERSION', '1.0.1' );
define( 'SCRP_FILE', __FILE__ );
define( 'SCRP_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Bootstrap after plugins load.
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Sublium must be present.
		if (
			! function_exists( 'sublium_get_subscription' )
			&& ! function_exists( 'sublium_init' )
			&& ! class_exists( '\Sublium_WCS\Plugin' )
			&& ! class_exists( '\Sublium\Plugin' )
		) {
			return;
		}

		require_once SCRP_PATH . 'includes/class-scrp-plugin.php';
		SCRP_Plugin::instance();
	},
	30
);
