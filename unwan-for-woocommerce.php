<?php
/**
 * Plugin Name:       Unwan – Multiple Address Book for WooCommerce
 * Description:       Give WooCommerce customers a reusable address book for My Account, classic checkout, and Checkout Blocks.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.2
 * WC tested up to:   10.9
 * Author:            SmartWP Plugins
 * Author URI:        https://smartwpplugins.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       unwan-for-woocommerce
 *
 * @package Unwan
 */

defined( 'ABSPATH' ) || exit;

define( 'UNWAN_VERSION', '1.0.1' );
define( 'UNWAN_FILE', __FILE__ );
define( 'UNWAN_PATH', plugin_dir_path( __FILE__ ) );
define( 'UNWAN_URL', plugin_dir_url( __FILE__ ) );
define( 'UNWAN_MINIMUM_WC_VERSION', '8.2' );

/**
 * Load the plugin's small PSR-4 namespace without a production dependency.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Unwan\\AddressLibrary\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = UNWAN_PATH . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Declare compatibility before WooCommerce initializes its feature system.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			UNWAN_FILE,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			UNWAN_FILE,
			true
		);
	}
);

/**
 * Boot only after WooCommerce is available.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if (
			! class_exists( 'WooCommerce' )
			|| ! defined( 'WC_VERSION' )
			|| version_compare( WC_VERSION, UNWAN_MINIMUM_WC_VERSION, '<' )
		) {
			add_action(
				'admin_notices',
				static function (): void {
					$message = sprintf(
						/* translators: %s: minimum WooCommerce version. */
						__( 'Unwan requires WooCommerce %s or newer.', 'unwan-for-woocommerce' ),
						UNWAN_MINIMUM_WC_VERSION
					);
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html( $message )
					);
				}
			);
			return;
		}

		\Unwan\AddressLibrary\Plugin::instance()->boot();
	},
	20
);

register_activation_hook( UNWAN_FILE, array( \Unwan\AddressLibrary\Plugin::class, 'activate' ) );
register_deactivation_hook( UNWAN_FILE, array( \Unwan\AddressLibrary\Plugin::class, 'deactivate' ) );
