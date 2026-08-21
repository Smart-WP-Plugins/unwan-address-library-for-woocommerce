<?php
/**
 * Checkout option shaping, formatting, and request-level caching.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\AddressRepository;

/**
 * Both selectors receive the same address set; only ordering and the default
 * badge depend on the requested type.
 */
class AddressRepositoryCheckoutTest extends UnwanTestCase {

	/**
	 * Seed a book with a billing default, a shipping default, and one extra.
	 *
	 * @return string The extra's ID.
	 */
	private function seed_mixed_book(): string {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );

		return $this->repository->create(
			$this->user_id,
			$this->address(
				array(
					'first_name' => 'Alan',
					'last_name'  => 'Turing',
					'address_1'  => '7 Elm Road',
				)
			)
		);
	}

	/**
	 * Billing and shipping selectors offer the identical set of addresses.
	 */
	public function test_both_selectors_receive_the_same_address_set(): void {
		$this->seed_mixed_book();

		$billing  = $this->repository->get_checkout_options( $this->user_id, 'billing' );
		$shipping = $this->repository->get_checkout_options( $this->user_id, 'shipping' );

		$this->assertCount( 3, $billing );
		$this->assertCount( 3, $shipping );
		$billing_ids  = wp_list_pluck( $billing, 'id' );
		$shipping_ids = wp_list_pluck( $shipping, 'id' );
		sort( $billing_ids );
		sort( $shipping_ids );

		$this->assertSame(
			$billing_ids,
			$shipping_ids,
			'The same IDs are offered to both selectors; only the order differs'
		);
		$this->assertSame(
			$billing_ids,
			array_unique( $billing_ids ),
			'No address is offered twice'
		);
	}

	/**
	 * The requested type's default sorts first and is the only one badged.
	 */
	public function test_the_requested_types_default_sorts_first_and_is_badged(): void {
		$this->seed_mixed_book();

		$billing = $this->repository->get_checkout_options( $this->user_id, 'billing' );
		$this->assertSame( 'default_billing', $billing[0]['id'] );
		$this->assertTrue( $billing[0]['isDefault'] );
		$this->assertSame( array( false, false ), array( $billing[1]['isDefault'], $billing[2]['isDefault'] ) );

		$shipping = $this->repository->get_checkout_options( $this->user_id, 'shipping' );
		$this->assertSame( 'default_shipping', $shipping[0]['id'] );
		$this->assertTrue( $shipping[0]['isDefault'] );
		$this->assertSame( array( false, false ), array( $shipping[1]['isDefault'], $shipping[2]['isDefault'] ) );
	}

	/**
	 * A collapsed both-roles entry is badged in both selectors.
	 */
	public function test_a_shared_default_is_badged_in_both_selectors(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->address() );

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$options = $this->repository->get_checkout_options( $this->user_id, $type );

			$this->assertCount( 1, $options );
			$this->assertTrue( $options[0]['isDefault'], "Badged for {$type}" );
			$this->assertSame( array( 'billing', 'shipping' ), $options[0]['defaultRoles'] );
		}
	}

	/**
	 * Every option exposes the keys the pickers and the public filter rely on.
	 */
	public function test_each_option_exposes_the_documented_keys(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$option = $this->repository->get_checkout_options( $this->user_id, 'billing' )[0];

		$this->assertSame(
			array( 'id', 'name', 'description', 'street', 'details', 'isDefault', 'defaultRoles', 'selectLabel', 'fields' ),
			array_keys( $option )
		);
		$this->assertSame( 'Ada Lovelace', $option['name'] );
		$this->assertSame( '12 Maple Street, Apt 4', $option['street'] );
		$this->assertStringContainsString( 'Springfield', $option['details'] );
		$this->assertStringContainsString( '90210', $option['details'] );
		$this->assertStringContainsString( 'United States', $option['details'] );
	}

	/**
	 * The select label marks the default and folds in the formatted address.
	 */
	public function test_the_select_label_marks_the_default(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$extra = $this->repository->create( $this->user_id, $this->other_address() );

		$options = $this->repository->get_checkout_options( $this->user_id, 'billing' );
		$labels  = wp_list_pluck( $options, 'selectLabel', 'id' );

		$this->assertStringContainsString( '(default)', $labels['default_billing'] );
		$this->assertStringContainsString( 'Ada Lovelace', $labels['default_billing'] );
		$this->assertStringNotContainsString( '(default)', $labels[ $extra ] );
		$this->assertStringContainsString( 'Grace Hopper', $labels[ $extra ] );
	}

	/**
	 * Only IDs actually in the book validate.
	 */
	public function test_checkout_option_existence_is_verified_against_the_book(): void {
		$extra = $this->seed_mixed_book();

		$this->assertTrue( $this->repository->checkout_option_exists( $this->user_id, 'billing', 'default_billing' ) );
		$this->assertTrue( $this->repository->checkout_option_exists( $this->user_id, 'billing', 'default_shipping' ) );
		$this->assertTrue( $this->repository->checkout_option_exists( $this->user_id, 'shipping', $extra ) );
		$this->assertFalse( $this->repository->checkout_option_exists( $this->user_id, 'billing', 'unwan_a_missing' ) );
		$this->assertFalse( $this->repository->checkout_option_exists( $this->user_id, 'billing', 'new' ) );
	}

	/**
	 * Another customer's address ID must never validate.
	 */
	public function test_another_customers_address_does_not_validate(): void {
		$extra = $this->repository->create( $this->user_id, $this->address() );
		$other = self::factory()->user->create( array( 'role' => 'customer' ) );

		$this->assertFalse( $this->repository->checkout_option_exists( $other, 'billing', $extra ) );
	}

	/**
	 * The street line joins both address lines and skips an empty second line.
	 */
	public function test_street_text_joins_present_address_lines_only(): void {
		$this->assertSame(
			'12 Maple Street, Apt 4',
			$this->repository->format_street_text( $this->address() )
		);
		$this->assertSame(
			'12 Maple Street',
			$this->repository->format_street_text( $this->address( array( 'address_2' => '' ) ) )
		);
		$this->assertSame( '', $this->repository->format_street_text( array() ) );
	}

	/**
	 * Country and state codes are expanded to their display names.
	 */
	public function test_location_text_expands_country_and_state_codes(): void {
		$details = $this->repository->format_location_text( $this->address() );

		$this->assertSame( 'Springfield, California 90210, United States (US)', $details );
	}

	/**
	 * An unknown state code falls back to the raw value rather than vanishing.
	 */
	public function test_location_text_falls_back_to_raw_codes(): void {
		$details = $this->repository->format_location_text(
			$this->address(
				array(
					'country' => 'ZZ',
					'state'   => 'QQ',
				)
			)
		);

		$this->assertStringContainsString( 'QQ', $details );
		$this->assertStringContainsString( 'ZZ', $details );
	}

	/**
	 * The formatted address is single-line plain text with no markup.
	 */
	public function test_formatted_address_is_plain_single_line_text(): void {
		$formatted = $this->repository->format_address_text( $this->address() );

		$this->assertStringNotContainsString( '<', $formatted );
		$this->assertStringNotContainsString( "\n", $formatted );
		$this->assertStringContainsString( '12 Maple Street', $formatted );
		$this->assertStringContainsString( 'Springfield', $formatted );
	}

	/**
	 * The recipient's name and phone are not part of the formatted address.
	 */
	public function test_formatted_address_omits_name_and_phone(): void {
		$formatted = $this->repository->format_address_text( $this->address() );

		$this->assertStringNotContainsString( 'Lovelace', $formatted );
		$this->assertStringNotContainsString( '5550100', $formatted );
	}

	/**
	 * A mutation must invalidate every derived cache immediately.
	 */
	public function test_creating_an_address_invalidates_the_derived_caches(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		// Warm every cache layer.
		$this->repository->get_saved( $this->user_id );
		$this->repository->get_address_book( $this->user_id );
		$this->repository->get_checkout_options( $this->user_id, 'billing' );
		$this->repository->get_checkout_options( $this->user_id, 'shipping' );

		$this->repository->create( $this->user_id, $this->other_address() );

		$this->assertCount( 1, $this->repository->get_saved( $this->user_id ) );
		$this->assertCount( 2, $this->repository->get_address_book( $this->user_id ) );
		$this->assertCount( 2, $this->repository->get_checkout_options( $this->user_id, 'billing' ) );
		$this->assertCount( 2, $this->repository->get_checkout_options( $this->user_id, 'shipping' ) );
	}

	/**
	 * Changing a default invalidates the caches too.
	 */
	public function test_changing_a_default_invalidates_the_derived_caches(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$this->repository->get_checkout_options( $this->user_id, 'billing' );

		$this->repository->make_primary( $this->user_id, 'billing', $id );

		$options = $this->repository->get_checkout_options( $this->user_id, 'billing' );

		$this->assertCount( 1, $options );
		$this->assertSame( 'default_billing', $options[0]['id'] );
		$this->assertTrue( $options[0]['isDefault'] );
	}

	/**
	 * Deleting an address invalidates the caches too.
	 */
	public function test_deleting_an_address_invalidates_the_derived_caches(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$this->assertCount( 1, $this->repository->get_checkout_options( $this->user_id, 'shipping' ) );

		$this->repository->delete( $this->user_id, $id );

		$this->assertCount( 0, $this->repository->get_checkout_options( $this->user_id, 'shipping' ) );
	}

	/**
	 * Caches are keyed per customer, so one book never serves another.
	 */
	public function test_caches_are_scoped_per_customer(): void {
		$this->repository->create( $this->user_id, $this->address() );
		$this->repository->get_address_book( $this->user_id );

		$other = self::factory()->user->create( array( 'role' => 'customer' ) );

		$this->assertSame( array(), $this->repository->get_address_book( $other ) );
		$this->assertCount( 1, $this->repository->get_address_book( $this->user_id ) );
	}
}
