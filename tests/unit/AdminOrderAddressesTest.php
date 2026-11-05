<?php
/**
 * Saved-address dropdown on the admin order screen.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\Admin\OrderAddresses;
use Unwan\AddressLibrary\Admin\Settings;

/**
 * Staff can fill an order from the customer's address book; saving the order
 * never writes to the address book or the customer's defaults.
 */
class AdminOrderAddressesTest extends UnwanTestCase {

	/**
	 * System under test.
	 *
	 * @var OrderAddresses
	 */
	private $order_addresses;

	/**
	 * Build the service.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->order_addresses = new OrderAddresses( $this->repository, new Settings() );
	}

	/**
	 * Clean up the current user and the script registry.
	 */
	public function tear_down(): void {
		wp_dequeue_script( 'unwan-admin-order' );
		wp_deregister_script( 'unwan-admin-order' );
		wp_set_current_user( 0 );
		$_POST = array();
		remove_all_filters( 'unwan_admin_order_addresses_enabled' );
		delete_option( 'woocommerce_allowed_countries' );
		delete_option( 'woocommerce_specific_allowed_countries' );
		delete_option( 'woocommerce_ship_to_countries' );
		delete_option( 'woocommerce_specific_ship_to_countries' );

		parent::tear_down();
	}

	/**
	 * A shop manager.
	 *
	 * @return int
	 */
	private function staff(): int {
		return self::factory()->user->create( array( 'role' => 'shop_manager' ) );
	}

	/**
	 * The plugin hooks into WooCommerce's customer lookup and order meta box.
	 */
	public function test_the_hooks_are_registered(): void {
		$this->assertNotFalse( has_filter( 'woocommerce_ajax_get_customer_details' ) );
		$this->assertNotFalse( has_action( 'woocommerce_admin_order_data_after_billing_address' ) );
	}

	/**
	 * The customer lookup carries the address book, each type with its own
	 * default first, and only the address fields.
	 */
	public function test_the_customer_lookup_carries_the_address_book(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );
		$extra = $this->repository->create( $this->user_id, $this->other_address( array( 'first_name' => 'Katherine' ) ) );

		$data = apply_filters( 'woocommerce_ajax_get_customer_details', array( 'billing' => array() ), new WC_Customer( $this->user_id ), $this->user_id );

		$this->assertArrayHasKey( 'billing', $data, 'WooCommerce data is kept' );
		$this->assertCount( 3, $data['unwan_addresses']['billing'] );
		$this->assertSame( 'default_billing', $data['unwan_addresses']['billing'][0]['id'] );
		$this->assertSame( 'default_shipping', $data['unwan_addresses']['shipping'][0]['id'] );
		$this->assertContains( $extra, wp_list_pluck( $data['unwan_addresses']['shipping'], 'id' ) );

		$first = $data['unwan_addresses']['billing'][0];
		$this->assertSame( array( 'id', 'label', 'fields' ), array_keys( $first ) );
		$this->assertSame( '12 Maple Street', $first['fields']['address_1'] );
		$this->assertArrayNotHasKey( 'email', $first['fields'] );
		$this->assertStringContainsString( 'Ada Lovelace', $first['label'] );
	}

	/**
	 * Guests and customers without addresses get empty lists.
	 */
	public function test_no_addresses_for_guests_or_empty_books(): void {
		$empty = array(
			'billing'  => array(),
			'shipping' => array(),
		);

		$this->assertSame( $empty, $this->order_addresses->get_addresses( 0 ) );
		$this->assertSame( $empty, $this->order_addresses->get_addresses( $this->user_id ) );
	}

	/**
	 * A deleted or unknown customer gets nothing.
	 */
	public function test_no_addresses_for_an_unknown_customer(): void {
		$this->assertSame( array(), $this->order_addresses->get_addresses( 999999 )['billing'] );
	}

	/**
	 * Labels are plain text: WooCommerce's formatter escapes HTML, and the
	 * script writes labels with .text(), so entities would show literally.
	 */
	public function test_labels_are_plain_text(): void {
		$this->repository->create(
			$this->user_id,
			$this->other_address(
				array(
					'last_name' => "O'Connell",
					'company'   => 'Smith & Sons',
				)
			)
		);

		$label = $this->order_addresses->get_addresses( $this->user_id )['billing'][0]['label'];

		$this->assertStringContainsString( "O'Connell", $label );
		$this->assertStringContainsString( 'Smith & Sons', $label );
		$this->assertStringNotContainsString( '&#039;', $label );
		$this->assertStringNotContainsString( '&amp;', $label );
	}

	/**
	 * When both defaults share a name, street and postcode they are one entry,
	 * but each type's dropdown fills that type's own stored default.
	 */
	public function test_a_shared_default_fills_each_types_own_fields(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address( array( 'address_2' => '' ) ) );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->address( array( 'address_2' => 'Apt 5' ) ) );

		$addresses = $this->order_addresses->get_addresses( $this->user_id );

		$this->assertCount( 1, $addresses['shipping'], 'One shared entry' );
		$this->assertSame( 'Apt 5', $addresses['shipping'][0]['fields']['address_2'] );
		$this->assertSame( '', $addresses['billing'][0]['fields']['address_2'] );
		$this->assertStringContainsString( 'Apt 5', $addresses['shipping'][0]['label'] );
	}

	/**
	 * Staff get the script with the order customer's addresses; anyone who
	 * cannot edit orders gets nothing.
	 */
	public function test_the_script_is_loaded_for_staff_only(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$order = wc_create_order( array( 'customer_id' => $this->user_id ) );

		wp_set_current_user( $this->user_id );
		$this->order_addresses->enqueue_assets( $order );
		$this->assertFalse( wp_script_is( 'unwan-admin-order', 'enqueued' ), 'Customers get nothing' );

		wp_set_current_user( $this->staff() );
		$this->order_addresses->enqueue_assets( $order );
		$this->assertTrue( wp_script_is( 'unwan-admin-order', 'enqueued' ) );

		$localized = (string) wp_scripts()->get_data( 'unwan-admin-order', 'data' );
		$this->assertStringContainsString( 'unwanAdminOrder', $localized );
		$this->assertStringContainsString( '12 Maple Street', $localized );
	}

	/**
	 * A guest order still loads the script, so choosing a customer later can
	 * show their addresses, but carries no address data.
	 */
	public function test_a_guest_order_loads_no_addresses(): void {
		wp_set_current_user( $this->staff() );
		$this->order_addresses->enqueue_assets( wc_create_order() );

		$localized = (string) wp_scripts()->get_data( 'unwan-admin-order', 'data' );
		$this->assertStringContainsString( '"billing":[]', $localized );
		$this->assertStringContainsString( '"shipping":[]', $localized );
	}

	/**
	 * Saving an order from the admin screen with a new address never adds it
	 * to the address book and never changes the customer's defaults.
	 */
	public function test_saving_an_admin_order_changes_no_customer_address(): void {
		if ( ! class_exists( 'WC_Meta_Box_Order_Data' ) ) {
			require_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-order-data.php';
		}

		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );
		update_user_meta( $this->user_id, 'last_update', '1000' );

		wp_set_current_user( $this->staff() );
		$order = wc_create_order();

		$typed = $this->other_address(
			array(
				'first_name' => 'Walk',
				'last_name'  => 'In',
				'address_1'  => '1 Counter Street',
			)
		);
		$_POST = array(
			'order_status'    => 'wc-processing',
			'_payment_method' => '',
			'customer_user'   => (string) $this->user_id,
		);
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			foreach ( $typed as $key => $value ) {
				$_POST[ "_{$type}_{$key}" ] = $value;
			}
		}

		WC_Meta_Box_Order_Data::save( $order->get_id() );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( '1 Counter Street', $order->get_billing_address_1(), 'The order has the typed address' );
		$this->assertSame( $this->user_id, $order->get_customer_id() );

		$this->repository = new Unwan\AddressLibrary\AddressRepository();
		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ), 'Nothing added to the address book' );
		$this->assertSameAddress( $this->address(), $this->repository->get_primary( $this->user_id, 'billing' ) );
		$this->assertSameAddress( $this->other_address(), $this->repository->get_primary( $this->user_id, 'shipping' ) );
		$this->assertSame( '1000', get_user_meta( $this->user_id, 'last_update', true ), 'Customer not saved' );
	}

	/**
	 * Turning the setting off removes the dropdown: no page data and nothing
	 * added to WooCommerce's customer lookup.
	 */
	public function test_the_setting_turns_the_dropdown_off(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		update_option( 'unwan_admin_order_addresses', 'no' );

		wp_set_current_user( $this->staff() );
		$this->order_addresses->enqueue_assets( wc_create_order( array( 'customer_id' => $this->user_id ) ) );
		$this->assertFalse( wp_script_is( 'unwan-admin-order', 'enqueued' ) );

		$data = $this->order_addresses->add_customer_addresses( array( 'billing' => array() ), null, $this->user_id );
		$this->assertArrayNotHasKey( 'unwan_addresses', $data );
	}

	/**
	 * The unwan_admin_order_addresses_enabled filter overrides the setting.
	 */
	public function test_the_filter_overrides_the_setting(): void {
		update_option( 'unwan_admin_order_addresses', 'yes' );
		add_filter( 'unwan_admin_order_addresses_enabled', '__return_false' );

		$this->assertFalse( ( new Settings() )->is_admin_order_dropdown_enabled() );

		remove_all_filters( 'unwan_admin_order_addresses_enabled' );
		update_option( 'unwan_admin_order_addresses', 'no' );
		add_filter( 'unwan_admin_order_addresses_enabled', '__return_true' );

		$this->assertTrue( ( new Settings() )->is_admin_order_dropdown_enabled() );
	}

	/**
	 * Addresses in countries the store does not sell to are left out of
	 * billing, and those it does not ship to are left out of shipping.
	 */
	public function test_addresses_outside_the_stores_countries_are_hidden(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->create(
			$this->user_id,
			$this->other_address(
				array(
					'country'  => 'GB',
					'state'    => '',
					'city'     => 'London',
					'postcode' => 'SW1A 2AA',
				)
			)
		);
		$this->repository->create(
			$this->user_id,
			$this->other_address(
				array(
					'first_name' => 'Katherine',
					'country'    => 'CA',
					'state'      => 'ON',
					'city'       => 'Toronto',
					'postcode'   => 'M5V 2T6',
				)
			)
		);

		update_option( 'woocommerce_allowed_countries', 'specific' );
		update_option( 'woocommerce_specific_allowed_countries', array( 'US', 'GB' ) );
		update_option( 'woocommerce_ship_to_countries', 'specific' );
		update_option( 'woocommerce_specific_ship_to_countries', array( 'US' ) );
		WC()->countries = new WC_Countries();

		$addresses = $this->order_addresses->get_addresses( $this->user_id );
		$countries = static function ( array $entries ): array {
			return array_values(
				array_map(
					static function ( $entry ) {
						return $entry['fields']['country'];
					},
					$entries
				)
			);
		};

		$this->assertSame( array( 'US', 'GB' ), $countries( $addresses['billing'] ) );
		$this->assertSame( array( 'US' ), $countries( $addresses['shipping'] ) );

		WC()->countries = new WC_Countries();
	}
}
