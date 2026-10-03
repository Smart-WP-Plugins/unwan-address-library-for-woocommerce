<?php
/**
 * Classic checkout: validation, default preservation, and address capture.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\Admin\Settings;
use Unwan\AddressLibrary\Checkout\ClassicCheckout;

/**
 * An order-specific choice must not silently rewrite the customer's profile
 * defaults unless the merchant asked for that.
 */
class ClassicCheckoutTest extends UnwanTestCase {

	/**
	 * System under test.
	 *
	 * @var ClassicCheckout
	 */
	private $checkout;

	/**
	 * Sign the customer in and build the adapter.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( $this->user_id );

		$this->checkout = new ClassicCheckout( $this->repository, new Settings() );
	}

	/**
	 * Reset request state.
	 */
	public function tear_down(): void {
		unset( $_POST['unwan_billing_address_id'], $_POST['unwan_shipping_address_id'] );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Build posted checkout data for one address.
	 *
	 * @param string               $type      Billing or shipping.
	 * @param string               $selection Selected option ID or "new".
	 * @param array<string,string> $fields    Address fields.
	 * @return array<string,mixed>
	 */
	private function posted( string $type, string $selection, array $fields ): array {
		$data = array( "unwan_{$type}_address_id" => $selection );

		foreach ( $fields as $key => $value ) {
			$data[ "{$type}_{$key}" ] = $value;
		}

		return $data;
	}

	/**
	 * A new address entered at checkout becomes a role-free shared extra.
	 */
	public function test_a_new_checkout_address_becomes_a_role_free_extra(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$this->checkout->save_checkout_choices(
			$this->user_id,
			$this->posted( 'billing', 'new', $this->other_address() )
		);

		$saved = $this->repository->get_saved( $this->user_id );

		$this->assertCount( 1, $saved );
		$this->assertSameAddress( $this->other_address(), reset( $saved )['fields'] );
		$this->assertSameAddress(
			$this->address(),
			$this->repository->get_primary( $this->user_id, 'billing' ),
			'The profile default is untouched'
		);
	}

	/**
	 * Choosing a saved address does not create another copy of it.
	 */
	public function test_choosing_a_saved_address_creates_nothing(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$this->checkout->save_checkout_choices(
			$this->user_id,
			$this->posted( 'billing', $id, $this->address() )
		);

		$this->assertSame( array( $id ), array_keys( $this->repository->get_saved( $this->user_id ) ) );
	}

	/**
	 * With saving disabled, nothing enters the address book.
	 */
	public function test_nothing_is_saved_when_the_setting_is_off(): void {
		update_option( 'unwan_save_checkout_addresses', 'no' );

		$this->checkout->save_checkout_choices(
			$this->user_id,
			$this->posted( 'billing', 'new', $this->address() )
		);

		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * Opting into promotion makes the new address the matching default and
	 * preserves the one it displaced.
	 */
	public function test_promotion_updates_the_default_without_losing_the_old_one(): void {
		update_option( 'unwan_checkout_default_behavior', 'update' );

		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$this->checkout->save_checkout_choices(
			$this->user_id,
			$this->posted( 'billing', 'new', $this->other_address() )
		);

		$this->assertSameAddress(
			$this->other_address(),
			$this->repository->get_primary( $this->user_id, 'billing' )
		);

		$saved = $this->repository->get_saved( $this->user_id );

		$this->assertCount( 1, $saved );
		$this->assertSameAddress(
			$this->address(),
			reset( $saved )['fields'],
			'The displaced default is preserved'
		);
	}

	/**
	 * A disabled selector is skipped entirely.
	 */
	public function test_a_disabled_selector_is_skipped(): void {
		update_option( 'unwan_billing_enable', 'no' );

		$this->checkout->save_checkout_choices(
			$this->user_id,
			$this->posted( 'billing', 'new', $this->address() )
		);

		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * When billing is reused for shipping, no second copy is created.
	 */
	public function test_billing_reused_for_shipping_does_not_duplicate(): void {
		$data = array_merge(
			$this->posted( 'billing', 'new', $this->address() ),
			$this->posted( 'shipping', 'new', $this->address() ),
			array( 'ship_to_different_address' => true )
		);

		$this->checkout->save_checkout_choices( $this->user_id, $data );

		$this->assertCount(
			1,
			$this->repository->get_saved( $this->user_id ),
			'The duplicate signature reuses the same record'
		);
	}

	/**
	 * A shipping address is ignored unless the shopper shipped elsewhere.
	 */
	public function test_shipping_is_ignored_without_ship_to_different_address(): void {
		$this->checkout->save_checkout_choices(
			$this->user_id,
			$this->posted( 'shipping', 'new', $this->other_address() )
		);

		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * A guest checkout writes nothing.
	 */
	public function test_a_guest_checkout_saves_nothing(): void {
		$this->checkout->save_checkout_choices(
			0,
			$this->posted( 'billing', 'new', $this->address() )
		);

		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * The customer object WooCommerce is about to save, holding the order's
	 * addresses, a new billing email and a field editor's custom field.
	 *
	 * @param array<string,mixed> $data Parsed checkout data.
	 * @return WC_Customer
	 */
	private function customer_after_woocommerce( array $data ): WC_Customer {
		$customer = new WC_Customer( $this->user_id );
		foreach ( $this->other_address() as $key => $value ) {
			$customer->{"set_billing_{$key}"}( $value );
			$customer->{"set_shipping_{$key}"}( $value );
		}
		$customer->set_billing_email( 'new-address@example.com' );
		$customer->update_meta_data( 'billing_vat', 'GB123' );

		$this->checkout->keep_customer_defaults( $customer, $data );

		return $customer;
	}

	/**
	 * Choosing a saved address keeps both address defaults, while WooCommerce
	 * still saves the email and custom billing_ fields from field editors.
	 */
	public function test_picking_an_address_keeps_the_defaults_but_not_other_customer_data(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->address( array( 'address_2' => 'Suite 9' ) ) );

		$customer = $this->customer_after_woocommerce( array( 'unwan_billing_address_id' => 'default_billing' ) );
		$customer->save();

		$this->repository = new Unwan\AddressLibrary\AddressRepository();
		$this->assertSameAddress( $this->address(), $this->repository->get_primary( $this->user_id, 'billing' ) );
		$this->assertSame( 'Suite 9', $this->repository->get_primary( $this->user_id, 'shipping' )['address_2'] );

		$saved = new WC_Customer( $this->user_id );
		$this->assertSame( 'new-address@example.com', $saved->get_billing_email(), 'Email still saved' );
		$this->assertSame( 'GB123', $saved->get_meta( 'billing_vat' ), 'Field editor meta still saved' );
	}

	/**
	 * Without a posted selection WooCommerce keeps its normal behaviour.
	 */
	public function test_an_unused_picker_leaves_woocommerce_in_charge(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		foreach ( array( array(), array( 'unwan_billing_address_id' => '' ) ) as $data ) {
			$customer = $this->customer_after_woocommerce( $data );
			$this->assertSame( 'Grace', $customer->get_billing_first_name() );
		}
	}

	/**
	 * A type whose selector is off is left to WooCommerce, and an empty
	 * default is filled by WooCommerce rather than kept empty.
	 */
	public function test_disabled_or_empty_defaults_are_left_to_woocommerce(): void {
		update_option( 'unwan_shipping_enable', 'no' );
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->address() );

		$customer = $this->customer_after_woocommerce( array( 'unwan_billing_address_id' => 'default_billing' ) );
		$this->assertSame( 'Ada', $customer->get_billing_first_name(), 'Managed billing kept' );
		$this->assertSame( 'Grace', $customer->get_shipping_first_name(), 'Disabled shipping left to WooCommerce' );

		update_option( 'unwan_shipping_enable', 'yes' );
		$this->repository->clear_primary( $this->user_id, 'shipping' );

		$customer = $this->customer_after_woocommerce( array( 'unwan_billing_address_id' => 'default_billing' ) );
		$this->assertSame( 'Grace', $customer->get_shipping_first_name(), 'Empty default filled by WooCommerce' );
	}

	/**
	 * A signed-out shopper never changes WooCommerce's customer update.
	 */
	public function test_guest_checkout_leaves_woocommerce_in_charge(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		wp_set_current_user( 0 );

		$customer = $this->customer_after_woocommerce( array( 'unwan_billing_address_id' => 'default_billing' ) );
		$this->assertSame( 'Grace', $customer->get_billing_first_name() );
	}

	/**
	 * An address ID the customer does not own is rejected.
	 */
	public function test_an_unowned_address_id_fails_validation(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$other    = self::factory()->user->create( array( 'role' => 'customer' ) );
		$other_id = $this->repository->create( $other, $this->other_address() );

		$errors = new WP_Error();
		$this->checkout->validate_selection(
			array( 'unwan_billing_address_id' => $other_id ),
			$errors
		);

		$this->assertSame( array( 'unwan_invalid_address' ), $errors->get_error_codes() );
	}

	/**
	 * A missing or blanked selection means the picker took no part (a checkout
	 * builder that prints only its own fields, or another script clearing the
	 * input). It must not block the order.
	 */
	public function test_a_missing_or_empty_selection_does_not_block_checkout(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$errors = new WP_Error();
		$this->checkout->validate_selection( array(), $errors );
		$this->assertSame( array(), $errors->get_error_codes(), 'Field not posted' );

		$errors = new WP_Error();
		$this->checkout->validate_selection( array( 'unwan_billing_address_id' => '' ), $errors );
		$this->assertSame( array(), $errors->get_error_codes(), 'Field posted empty' );
	}

	/**
	 * With the additional-address limit full, promoting a new checkout address
	 * still makes it the default and keeps the displaced one.
	 */
	public function test_promotion_works_when_the_address_limit_is_full(): void {
		update_option( 'unwan_checkout_default_behavior', 'update' );
		update_option( 'unwan_address_save_limit', 1 );

		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$third = $this->other_address(
			array(
				'first_name' => 'Katherine',
				'last_name'  => 'Johnson',
				'address_1'  => '7 Langley Road',
			)
		);
		$this->repository->create( $this->user_id, $third );

		$this->checkout->save_checkout_choices(
			$this->user_id,
			$this->posted( 'billing', 'new', $this->other_address() )
		);

		$this->assertSameAddress(
			$this->other_address(),
			$this->repository->get_primary( $this->user_id, 'billing' ),
			'The new address became the default'
		);

		$names = array_map(
			static function ( $record ) {
				return $record['fields']['first_name'];
			},
			array_values( $this->repository->get_saved( $this->user_id ) )
		);
		sort( $names );

		$this->assertSame( array( 'Ada', 'Katherine' ), $names, 'The displaced default is kept' );
	}

	/**
	 * An empty address book renders no selector, so nothing is validated and
	 * the customer can enter an address and place the order.
	 */
	public function test_an_empty_address_book_does_not_block_checkout(): void {
		$errors = new WP_Error();
		$this->checkout->validate_selection( array(), $errors );

		$this->assertSame( array(), $errors->get_error_codes() );
	}

	/**
	 * Deleting every saved address returns the customer to an empty book,
	 * which must not leave checkout demanding a selection that cannot exist.
	 */
	public function test_deleting_every_address_does_not_block_checkout(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );
		$this->assertIsString( $id );

		$this->repository->delete( $this->user_id, $id );
		$this->assertSame( array(), $this->repository->get_checkout_options( $this->user_id, 'billing' ) );

		$errors = new WP_Error();
		$this->checkout->validate_selection( array(), $errors );

		$this->assertSame( array(), $errors->get_error_codes() );
	}

	/**
	 * A valid saved selection and the "new" sentinel both pass.
	 */
	public function test_valid_selections_pass_validation(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$errors = new WP_Error();
		$this->checkout->validate_selection(
			array( 'unwan_billing_address_id' => 'default_billing' ),
			$errors
		);
		$this->assertSame( array(), $errors->get_error_codes() );

		$errors = new WP_Error();
		$this->checkout->validate_selection(
			array( 'unwan_billing_address_id' => 'new' ),
			$errors
		);
		$this->assertSame( array(), $errors->get_error_codes() );
	}

	/**
	 * The hidden selection field is added only when the customer has addresses.
	 */
	public function test_the_hidden_selection_field_is_added_when_addresses_exist(): void {
		$fields = array(
			'billing'  => array(),
			'shipping' => array(),
		);

		$this->assertSame(
			$fields,
			$this->checkout->add_fields( $fields ),
			'An empty address book adds no hidden field'
		);

		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$updated = $this->checkout->add_fields( $fields );

		$this->assertArrayHasKey( 'unwan_billing_address_id', $updated['billing'] );
		$this->assertSame( 'hidden', $updated['billing']['unwan_billing_address_id']['type'] );
		$this->assertSame( 'default_billing', $updated['billing']['unwan_billing_address_id']['default'] );
		$this->assertFalse( $updated['billing']['unwan_billing_address_id']['required'] );
	}

	/**
	 * A disabled selector adds no hidden field.
	 */
	public function test_a_disabled_selector_adds_no_hidden_field(): void {
		update_option( 'unwan_shipping_enable', 'no' );

		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$updated = $this->checkout->add_fields(
			array(
				'billing'  => array(),
				'shipping' => array(),
			)
		);

		$this->assertArrayHasKey( 'unwan_billing_address_id', $updated['billing'] );
		$this->assertArrayNotHasKey( 'unwan_shipping_address_id', $updated['shipping'] );
	}
}
