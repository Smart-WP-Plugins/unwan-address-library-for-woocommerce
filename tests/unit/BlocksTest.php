<?php
/**
 * Checkout Blocks registration, Store API extension, and picker data.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\Admin\Settings;
use Unwan\AddressLibrary\Checkout\BlocksController;
use Unwan\AddressLibrary\Checkout\BlocksIntegration;

/**
 * Exercises the Blocks surface far enough to catch a registration regression
 * or a leak of customer data to guests.
 */
class BlocksTest extends UnwanTestCase {

	/**
	 * Store API controller.
	 *
	 * @var BlocksController
	 */
	private $controller;

	/**
	 * Checkout integration.
	 *
	 * @var BlocksIntegration
	 */
	private $integration;

	/**
	 * Build both Blocks services.
	 */
	public function set_up(): void {
		parent::set_up();

		$settings          = new Settings();
		$this->controller  = new BlocksController( $this->repository, $settings );
		$this->integration = new BlocksIntegration( $this->repository, $settings );
	}

	/**
	 * Sign out between tests.
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Both selector blocks register from their built metadata.
	 */
	public function test_both_selector_blocks_register(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( 'unwan/billing-selector', 'unwan/shipping-selector' ) as $name ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
		}

		$this->controller->register_block_types();

		$this->assertTrue( $registry->is_registered( 'unwan/billing-selector' ) );
		$this->assertTrue( $registry->is_registered( 'unwan/shipping-selector' ) );
	}

	/**
	 * Built block metadata declares the documented names and text domain.
	 */
	public function test_built_block_metadata_matches_the_public_identifiers(): void {
		$expected = array(
			'billing'  => 'unwan/billing-selector',
			'shipping' => 'unwan/shipping-selector',
		);

		foreach ( $expected as $dir => $name ) {
			$path = UNWAN_PATH . "build/blocks/{$dir}/block.json";

			$this->assertFileExists( $path );

			$block = json_decode( (string) file_get_contents( $path ), true );

			$this->assertSame( $name, $block['name'] );
			$this->assertSame( 'unwan-for-woocommerce', $block['textdomain'] );
		}
	}

	/**
	 * The integration identifies itself with the documented namespace.
	 */
	public function test_the_integration_uses_the_documented_namespace(): void {
		$this->assertSame( 'unwan', $this->integration->get_name() );
	}

	/**
	 * Registering the Store API extension is safe to call and idempotent.
	 */
	public function test_registering_the_store_api_extension_is_idempotent(): void {
		$this->controller->register_store_api_extension();
		$this->controller->register_store_api_extension();

		$this->assertTrue( function_exists( 'woocommerce_store_api_register_endpoint_data' ) );
	}

	/**
	 * The writable extension schema exposes both selections as nullable
	 * strings.
	 */
	public function test_the_extension_schema_is_writable_for_both_selections(): void {
		$schema = $this->controller->extension_schema();

		$this->assertSame( array( 'billing_selection', 'shipping_selection' ), array_keys( $schema ) );

		foreach ( $schema as $key => $field ) {
			$this->assertSame( array( 'string', 'null' ), $field['type'], "{$key} accepts a string or null" );
			$this->assertFalse( $field['readonly'], "{$key} is writable" );
			$this->assertNotSame( '', $field['description'] );
		}

		$this->assertSame(
			array(
				'billing_selection'  => '',
				'shipping_selection' => '',
			),
			$this->controller->extension_data(),
			'Nothing is preselected by default'
		);
	}

	/**
	 * A guest receives no addresses at all.
	 */
	public function test_a_guest_receives_no_addresses(): void {
		$this->repository->create( $this->user_id, $this->address() );

		$data = $this->integration->get_script_data();

		$this->assertFalse( $data['isLoggedIn'] );
		$this->assertSame( array(), $data['types']['billing']['addresses'] );
		$this->assertSame( array(), $data['types']['shipping']['addresses'] );
	}

	/**
	 * A signed-in customer receives their own address book.
	 */
	public function test_a_signed_in_customer_receives_their_address_book(): void {
		wp_set_current_user( $this->user_id );

		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->create( $this->user_id, $this->other_address() );

		$data = $this->integration->get_script_data();

		$this->assertTrue( $data['isLoggedIn'] );
		$this->assertCount( 2, $data['types']['billing']['addresses'] );
		$this->assertCount( 2, $data['types']['shipping']['addresses'] );
		$this->assertTrue( $data['types']['billing']['enabled'] );
		$this->assertSame( $this->repository->get_field_keys(), $data['fieldKeys'] );
		$this->assertNotSame( '', $data['baseCountry'] );
		$this->assertArrayHasKey( 'change', $data['labels'] );
	}

	/**
	 * A disabled selector ships no addresses for that type.
	 */
	public function test_a_disabled_selector_ships_no_addresses(): void {
		wp_set_current_user( $this->user_id );
		update_option( 'unwan_shipping_enable', 'no' );

		$this->repository->create( $this->user_id, $this->address() );

		$data = $this->integration->get_script_data();

		$this->assertTrue( $data['types']['billing']['enabled'] );
		$this->assertCount( 1, $data['types']['billing']['addresses'] );
		$this->assertFalse( $data['types']['shipping']['enabled'] );
		$this->assertSame( array(), $data['types']['shipping']['addresses'] );
	}

	/**
	 * The picker-data filter can redact the payload entirely.
	 */
	public function test_the_picker_data_filter_can_redact_the_payload(): void {
		wp_set_current_user( $this->user_id );
		$this->repository->create( $this->user_id, $this->address() );

		$seen = array();

		add_filter(
			'unwan_checkout_block_picker_data',
			static function ( array $data, int $user_id ) use ( &$seen ): array {
				$seen[]                                 = $user_id;
				$data['types']['billing']['addresses'] = array();

				return $data;
			},
			10,
			2
		);

		$data = $this->integration->get_script_data();

		$this->assertSame( array( $this->user_id ), $seen );
		$this->assertSame( array(), $data['types']['billing']['addresses'] );
	}

	/**
	 * Every built script has its wp-scripts asset metadata alongside it.
	 */
	public function test_built_scripts_ship_with_their_asset_metadata(): void {
		$scripts = array(
			'build/unwan-address-picker.js' => 'build/unwan-address-picker.asset.php',
			'build/blocks/editor.js'        => 'build/blocks/editor.asset.php',
			'build/blocks/frontend.js'      => 'build/blocks/frontend.asset.php',
		);

		foreach ( $scripts as $script => $asset ) {
			$this->assertFileExists( UNWAN_PATH . $script );
			$this->assertFileExists( UNWAN_PATH . $asset );

			$meta = require UNWAN_PATH . $asset;

			$this->assertIsArray( $meta['dependencies'] );
			$this->assertNotSame( '', $meta['version'] );
		}
	}

	/**
	 * Every minified bundle ships with readable source, as the Plugin
	 * Directory requires.
	 */
	public function test_every_bundle_has_accessible_source(): void {
		$sources = array(
			'build/unwan-address-picker.js' => 'src/unwan-address-picker.js',
			'build/blocks/editor.js'        => 'src/blocks/editor.js',
			'build/blocks/frontend.js'      => 'src/blocks/frontend.js',
		);

		foreach ( $sources as $bundle => $source ) {
			$this->assertFileExists( UNWAN_PATH . $bundle );
			$this->assertFileExists(
				UNWAN_PATH . $source,
				"{$bundle} must ship alongside its unminified source"
			);
		}

		$dist = json_decode( (string) file_get_contents( UNWAN_PATH . 'package.json' ), true );

		$this->assertContains(
			'src/**/*',
			$dist['distFiles'],
			'src/** is the accessible-source mechanism and must stay in distFiles'
		);
	}
}
