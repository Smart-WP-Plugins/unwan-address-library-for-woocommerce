<?php
/**
 * PHPUnit bootstrap for the Unwan integration test suite.
 *
 * Requires a WordPress core test library (wordpress-develop's
 * tests/phpunit/includes) and a throwaway MySQL database. Point
 * WP_TESTS_DIR at the directory holding wp-tests-config.php; see
 * tests/README.md for the one-command setup.
 *
 * @package Unwan
 */

$unwan_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $unwan_tests_dir ) {
	echo 'WP_TESTS_DIR is not set. See tests/README.md for setup instructions.' . PHP_EOL;
	exit( 1 );
}

$unwan_tests_dir = rtrim( $unwan_tests_dir, '/\\' );

if ( ! file_exists( $unwan_tests_dir . '/includes/functions.php' ) ) {
	printf(
		'Could not find %s/includes/functions.php. See tests/README.md.' . PHP_EOL,
		$unwan_tests_dir
	);
	exit( 1 );
}

// The polyfills ship with this plugin's dev dependencies rather than with the
// core test library, so point the core bootstrap at our vendor copy.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $unwan_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce and Unwan before WordPress finishes booting.
 *
 * WooCommerce must load first: Unwan's bootstrap gates itself on the
 * WooCommerce class being present.
 *
 * @return void
 */
function unwan_tests_load_plugins(): void {
	$plugins = dirname( __DIR__, 2 );

	require_once $plugins . '/woocommerce/woocommerce.php';
	require_once $plugins . '/unwan-for-woocommerce/unwan-for-woocommerce.php';
}
tests_add_filter( 'muplugins_loaded', 'unwan_tests_load_plugins' );

/**
 * Install WooCommerce's tables and options once WordPress is loaded.
 *
 * @return void
 */
function unwan_tests_install_woocommerce(): void {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}

	// WooCommerce's installer is normally driven by an activation hook that
	// never fires in the test environment.
	remove_action( 'init', array( 'WC_Install', 'check_version' ), 5 );
	WC_Install::install();

	if ( is_callable( array( 'WC_Install', 'create_roles' ) ) ) {
		WC_Install::create_roles();
	}

	// Rebuild the roles WooCommerce just added into the current request.
	$GLOBALS['wp_roles'] = null;
	wp_roles();
}
tests_add_filter( 'setup_theme', 'unwan_tests_install_woocommerce' );

require $unwan_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/UnwanTestCase.php';
