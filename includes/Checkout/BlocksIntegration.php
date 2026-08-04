<?php
/**
 * WooCommerce Checkout Blocks asset integration.
 *
 * @package Unwan
 */

namespace Unwan\AddressLibrary\Checkout;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use Unwan\AddressLibrary\AddressRepository;
use Unwan\AddressLibrary\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the extension through WooCommerce's integration registry so forced
 * inner blocks are registered even when their markup is not stored in the
 * Checkout page content.
 */
final class BlocksIntegration implements IntegrationInterface {

	/**
	 * Address repository.
	 *
	 * @var AddressRepository
	 */
	private $repository;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param AddressRepository $repository Address repository.
	 * @param Settings          $settings   Plugin settings.
	 */
	public function __construct( AddressRepository $repository, Settings $settings ) {
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	/**
	 * Integration namespace.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'unwan';
	}

	/**
	 * Register checkout scripts.
	 *
	 * @return void
	 */
	public function initialize() {
		$this->register_script(
			'unwan-address-picker',
			'build/unwan-address-picker.js',
			'build/unwan-address-picker.asset.php'
		);
		$this->register_script(
			'unwan-checkout-blocks-frontend',
			'build/blocks/frontend.js',
			'build/blocks/frontend.asset.php',
			array( 'unwan-address-picker' )
		);
		$this->register_script(
			'unwan-checkout-blocks-editor',
			'build/blocks/editor.js',
			'build/blocks/editor.asset.php'
		);
	}

	/**
	 * Frontend script handles.
	 *
	 * @return string[]
	 */
	public function get_script_handles() {
		return array( 'unwan-checkout-blocks-frontend' );
	}

	/**
	 * Editor script handles.
	 *
	 * @return string[]
	 */
	public function get_editor_script_handles() {
		return array(
			'unwan-checkout-blocks-editor',
			'unwan-checkout-blocks-frontend',
		);
	}

	/**
	 * Private customer data exposed only in the checkout request.
	 *
	 * @return array<string,mixed>
	 */
	public function get_script_data() {
		$user_id = get_current_user_id();
		$data    = array(
			'isLoggedIn'      => $user_id > 0,
			'fieldKeys'       => $this->repository->get_field_keys(),
			'baseCountry'     => WC()->countries->get_base_country(),
			'searchThreshold' => $this->settings->get_address_search_threshold(),
			'labels'          => $this->settings->get_checkout_picker_labels(),
			'types'           => array(),
		);

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$enabled = $this->settings->is_enabled( $type );

			$data['types'][ $type ] = array(
				'enabled'   => $enabled,
				'addresses' => $enabled && $user_id > 0
					? $this->repository->get_checkout_options( $user_id, $type )
					: array(),
			);
		}

		/**
		 * Filter data exposed to the Checkout Block picker.
		 *
		 * This data is customer-private and is emitted only on checkout.
		 *
		 * @param array<string,mixed> $data    Picker data.
		 * @param int                 $user_id Customer ID, or 0 for guests.
		 */
		return (array) apply_filters(
			'unwan_checkout_block_picker_data',
			$data,
			$user_id
		);
	}

	/**
	 * Register a built script using wp-scripts asset metadata.
	 *
	 * @param string   $handle             Script handle.
	 * @param string   $script             Relative script path.
	 * @param string   $asset_file         Relative asset metadata path.
	 * @param string[] $extra_dependencies Additional script dependencies.
	 * @return void
	 */
	private function register_script(
		string $handle,
		string $script,
		string $asset_file,
		array $extra_dependencies = array()
	): void {
		$asset_path  = UNWAN_PATH . $asset_file;
		$script_path = UNWAN_PATH . $script;
		$asset       = file_exists( $asset_path )
			? require $asset_path
			: array(
				'dependencies' => array(),
				'version'      => UNWAN_VERSION,
			);
		$version     = (string) ( $asset['version'] ?? UNWAN_VERSION );

		// Include the built file timestamp so local/proxied environments cannot
		// reuse an older bundle when PHP opcode caching retains asset metadata.
		if ( file_exists( $script_path ) ) {
			$version .= '-' . (string) filemtime( $script_path );
		}

		wp_register_script(
			$handle,
			UNWAN_URL . $script,
			array_values(
				array_unique(
					array_merge(
						(array) ( $asset['dependencies'] ?? array() ),
						$extra_dependencies
					)
				)
			),
			$version,
			true
		);
		wp_set_script_translations(
			$handle,
			'unwan-for-woocommerce',
			UNWAN_PATH . 'languages'
		);
	}
}
