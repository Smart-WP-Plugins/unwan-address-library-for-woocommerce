<?php
/**
 * Main plugin coordinator.
 *
 * @package Unwan
 */

namespace Unwan\AddressLibrary;

use Unwan\AddressLibrary\Admin\Settings;
use Unwan\AddressLibrary\Checkout\BlocksController;
use Unwan\AddressLibrary\Checkout\ClassicCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates the plugin's independent account and checkout adapters.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Prevent repeated booting.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Runtime settings service.
	 *
	 * @var Settings|null
	 */
	private $settings;

	/**
	 * Get the plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register plugin services.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$repository     = new AddressRepository();
		$settings       = new Settings();
		$this->settings = $settings;

		$settings->register();
		( new AccountController( $repository, $settings ) )->register();
		( new ClassicCheckout( $repository, $settings ) )->register();
		( new BlocksController( $repository, $settings ) )->register();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ), 99 );
		add_filter( 'body_class', array( $this, 'add_color_scheme_body_class' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( UNWAN_FILE ),
			array( $this, 'add_plugin_action_links' )
		);
	}

	/**
	 * Register the bundled translations directory.
	 *
	 * WordPress.org-hosted plugins usually need no loader, because core finds
	 * translations in WP_LANG_DIR on its own. Unwan also ships its own
	 * catalogues under languages/, and WP_Textdomain_Registry only searches a
	 * plugin-local directory once it has been registered through this call —
	 * without it the bundled .mo files would never load. Community translations
	 * still take precedence: WP_LANG_DIR is checked ahead of the custom path,
	 * so a locale published on translate.wordpress.org overrides the copy
	 * shipped here.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'unwan-for-woocommerce',
			false,
			dirname( plugin_basename( UNWAN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Add a direct settings shortcut on the Plugins screen.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public function add_plugin_action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=wc-settings&tab=account&section=unwan' );

		array_unshift(
			$links,
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html__( 'Settings', 'unwan-for-woocommerce' )
			)
		);

		return $links;
	}

	/**
	 * Load the shared interface styles only where the address book can render.
	 *
	 * Registering this on WordPress' normal enqueue lifecycle also avoids the
	 * Checkout Blocks integration registry initializing after wp_head().
	 *
	 * @return void
	 */
	public function enqueue_frontend_styles(): void {
		if ( ! is_account_page() && ! is_checkout() ) {
			return;
		}

		$stylesheet = UNWAN_PATH . 'assets/css/unwan.css';
		$version    = UNWAN_VERSION;
		if ( file_exists( $stylesheet ) ) {
			$version .= '-' . (string) filemtime( $stylesheet );
		}

		wp_enqueue_style(
			'unwan',
			UNWAN_URL . 'assets/css/unwan.css',
			array(),
			$version
		);

		if ( $this->settings instanceof Settings ) {
			wp_add_inline_style(
				'unwan',
				sprintf(
					':root{--unwan-color-accent:%1$s;--unwan-color-accent-rgb:%2$s;}',
					$this->settings->get_accent_color(),
					$this->settings->get_accent_rgb()
				)
			);
		}
	}

	/**
	 * Add the selected color mode without changing any theme-owned classes.
	 *
	 * @param string[] $classes Existing body classes.
	 * @return string[]
	 */
	public function add_color_scheme_body_class( array $classes ): array {
		if (
			( is_account_page() || is_checkout() )
			&& $this->settings instanceof Settings
		) {
			$classes[] = 'unwan-color-scheme-' . $this->settings->get_color_scheme();
		}

		return $classes;
	}

	/**
	 * Add the account endpoint and refresh rewrite rules on activation.
	 *
	 * @return void
	 */
	public static function activate(): void {
		add_rewrite_endpoint( AccountController::ENDPOINT, EP_ROOT | EP_PAGES );
		update_option( 'unwan_plugin_version', UNWAN_VERSION, false );
		flush_rewrite_rules();
	}

	/**
	 * Refresh rewrite rules when the endpoint is removed.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
	}
}
