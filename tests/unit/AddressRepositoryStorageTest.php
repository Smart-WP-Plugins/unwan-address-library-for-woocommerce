<?php
/**
 * Storage, sanitization, and address-book composition.
 *
 * @package Unwan
 */

/**
 * Covers how the two WooCommerce defaults and the shared extras combine into
 * one address book.
 */
class AddressRepositoryStorageTest extends UnwanTestCase {

	/**
	 * Additional addresses live in the plugin's own metadata key, never in
	 * WooCommerce's profile fields.
	 */
	public function test_extras_are_stored_in_the_plugin_meta_key(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$this->assertIsString( $id );

		$records = get_user_meta( $this->user_id, '_unwan_addresses', true );

		$this->assertIsArray( $records );
		$this->assertArrayHasKey( $id, $records );
		$this->assertSame( '12 Maple Street', $records[ $id ]['fields']['address_1'] );

		// The extra must not have leaked into either profile default.
		$this->assertSame( '', get_user_meta( $this->user_id, 'billing_address_1', true ) );
		$this->assertSame( '', get_user_meta( $this->user_id, 'shipping_address_1', true ) );
	}

	/**
	 * Defaults stay in WooCommerce's own profile fields.
	 */
	public function test_defaults_are_stored_in_woocommerce_profile_fields(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$this->assertSame( '12 Maple Street', get_user_meta( $this->user_id, 'billing_address_1', true ) );
		$this->assertSame( 'Ada', get_user_meta( $this->user_id, 'billing_first_name', true ) );
		$this->assertSameAddress( $this->address(), $this->repository->get_primary( $this->user_id, 'billing' ) );
	}

	/**
	 * Email is deliberately excluded: it belongs to account identity.
	 */
	public function test_email_is_not_a_stored_address_field(): void {
		$this->assertNotContains( 'email', $this->repository->get_field_keys() );
	}

	/**
	 * Unknown keys are dropped and known values are sanitized.
	 */
	public function test_sanitize_fields_drops_unknown_keys_and_cleans_values(): void {
		$clean = $this->repository->sanitize_fields(
			array(
				'first_name' => '  <b>Ada</b>  ',
				'country'    => 'usa',
				'address_1'  => "12 Maple\tStreet",
				'evil'       => 'dropped',
				'phone'      => array( 'not', 'scalar' ),
			)
		);

		$this->assertSame( 'Ada', $clean['first_name'] );
		$this->assertSame( 'US', $clean['country'], 'Country is upper-cased and clipped to two characters' );
		$this->assertSame( '12 Maple Street', $clean['address_1'] );
		$this->assertArrayNotHasKey( 'evil', $clean );
		$this->assertSame( '', $clean['phone'], 'Non-scalar values collapse to an empty string' );
		$this->assertSame( $this->repository->get_field_keys(), array_keys( $clean ) );
	}

	/**
	 * An unrecognized address type falls back to billing.
	 */
	public function test_normalize_type_falls_back_to_billing(): void {
		$this->assertSame( 'billing', $this->repository->normalize_type( 'billing' ) );
		$this->assertSame( 'shipping', $this->repository->normalize_type( 'shipping' ) );
		$this->assertSame( 'billing', $this->repository->normalize_type( 'gift' ) );
		$this->assertSame( 'billing', $this->repository->normalize_type( '' ) );
	}

	/**
	 * Only postal data counts as an address; a lone name does not.
	 */
	public function test_has_address_requires_postal_data(): void {
		$this->assertFalse( $this->repository->has_address( array() ) );
		$this->assertFalse(
			$this->repository->has_address( array( 'first_name' => 'Ada' ) ),
			'A name alone is not an address'
		);
		$this->assertTrue( $this->repository->has_address( array( 'address_1' => '12 Maple Street' ) ) );
		$this->assertTrue( $this->repository->has_address( array( 'city' => 'Springfield' ) ) );
		$this->assertTrue( $this->repository->has_address( array( 'postcode' => '90210' ) ) );
	}

	/**
	 * The recipient name prefers the person, then the company, then a label.
	 */
	public function test_recipient_name_falls_back_from_person_to_company_to_label(): void {
		$this->assertSame(
			'Ada Lovelace',
			$this->repository->get_recipient_name( $this->address() )
		);

		$this->assertSame(
			'Analytical Engines',
			$this->repository->get_recipient_name(
				$this->address(
					array(
						'first_name' => '',
						'last_name'  => '',
					)
				)
			)
		);

		$this->assertSame(
			'Address',
			$this->repository->get_recipient_name(
				$this->address(
					array(
						'first_name' => '',
						'last_name'  => '',
						'company'    => '',
					)
				)
			)
		);
	}

	/**
	 * A first name without a last name still renders cleanly.
	 */
	public function test_recipient_name_does_not_leave_stray_whitespace(): void {
		$this->assertSame(
			'Ada',
			$this->repository->get_recipient_name( $this->address( array( 'last_name' => '' ) ) )
		);
	}

	/**
	 * An empty address book has no entries at all.
	 */
	public function test_address_book_is_empty_for_a_new_customer(): void {
		$this->assertSame( array(), $this->repository->get_address_book( $this->user_id ) );
		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * A profile default holding only a name is not surfaced as an address.
	 */
	public function test_address_book_ignores_a_default_without_postal_data(): void {
		$this->repository->save_primary(
			$this->user_id,
			'billing',
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
			)
		);

		$this->assertSame( array(), $this->repository->get_address_book( $this->user_id ) );
	}

	/**
	 * Identical billing and shipping defaults collapse into one entry that
	 * carries both roles.
	 */
	public function test_matching_defaults_collapse_into_one_entry_with_both_roles(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->address() );

		$book = $this->repository->get_address_book( $this->user_id );

		$this->assertCount( 1, $book );
		$this->assertSame( 'default_billing', $book[0]['id'] );
		$this->assertSame( array( 'billing', 'shipping' ), $book[0]['roles'] );
		$this->assertTrue( $book[0]['is_default'] );
	}

	/**
	 * Different defaults stay as two separate single-role entries.
	 */
	public function test_differing_defaults_remain_two_entries(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );

		$this->assertSame(
			array(
				'default_billing'  => array( 'billing' ),
				'default_shipping' => array( 'shipping' ),
			),
			$this->roles_by_entry()
		);
	}

	/**
	 * Extras carry no default role and sort after the defaults.
	 */
	public function test_extras_are_role_free_and_sort_after_defaults(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$extra_id = $this->repository->create( $this->user_id, $this->other_address() );

		$book = $this->repository->get_address_book( $this->user_id );

		$this->assertCount( 2, $book );
		$this->assertSame( 'default_billing', $book[0]['id'] );
		$this->assertSame( $extra_id, $book[1]['id'] );
		$this->assertSame( array(), $book[1]['roles'] );
		$this->assertFalse( $book[1]['is_default'] );
	}

	/**
	 * An extra duplicating a default is not shown twice.
	 */
	public function test_an_extra_matching_a_default_is_hidden_from_the_book(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		// Write the duplicate straight to metadata: create() would dedupe it.
		update_user_meta(
			$this->user_id,
			'_unwan_addresses',
			array(
				'unwan_a_manual' => array(
					'id'         => 'unwan_a_manual',
					'fields'     => $this->address(),
					'created_at' => '2026-01-01T00:00:00+00:00',
					'updated_at' => '2026-01-01T00:00:00+00:00',
				),
			)
		);

		$fresh = new \Unwan\AddressLibrary\AddressRepository();

		$this->assertCount( 1, $fresh->get_address_book( $this->user_id ) );
	}

	/**
	 * Malformed stored records are skipped rather than surfaced.
	 */
	public function test_malformed_stored_records_are_discarded(): void {
		update_user_meta(
			$this->user_id,
			'_unwan_addresses',
			array(
				'unwan_a_good' => array(
					'id'     => 'unwan_a_good',
					'fields' => $this->address(),
				),
				'unwan_a_bad'  => 'not-an-array',
				''             => array( 'fields' => $this->address() ),
			)
		);

		$saved = ( new \Unwan\AddressLibrary\AddressRepository() )->get_saved( $this->user_id );

		$this->assertSame( array( 'unwan_a_good' ), array_keys( $saved ) );
	}

	/**
	 * Metadata that is not an array at all degrades to an empty book.
	 */
	public function test_non_array_metadata_degrades_to_an_empty_book(): void {
		update_user_meta( $this->user_id, '_unwan_addresses', 'corrupt' );

		$this->assertSame(
			array(),
			( new \Unwan\AddressLibrary\AddressRepository() )->get_saved( $this->user_id )
		);
	}

	/**
	 * Entries are addressable by ID; unknown IDs return null.
	 */
	public function test_get_entry_resolves_defaults_and_extras(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$extra_id = $this->repository->create( $this->user_id, $this->other_address() );

		$this->assertNotNull( $this->repository->get_entry( $this->user_id, 'default_billing' ) );
		$this->assertNotNull( $this->repository->get_entry( $this->user_id, $extra_id ) );
		$this->assertNull( $this->repository->get_entry( $this->user_id, 'unwan_a_missing' ) );
		$this->assertNull( $this->repository->get_entry( $this->user_id, 'default_shipping' ) );
	}

	/**
	 * The entry occupying a role is found through either of its IDs.
	 */
	public function test_get_default_entry_follows_the_role_not_the_id(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->address() );

		$shipping = $this->repository->get_default_entry( $this->user_id, 'shipping' );

		$this->assertNotNull( $shipping );
		$this->assertSame(
			'default_billing',
			$shipping['id'],
			'The collapsed entry keeps the billing ID while holding both roles'
		);

		$this->assertNull(
			( new \Unwan\AddressLibrary\AddressRepository() )->get_default_entry(
				self::factory()->user->create(),
				'billing'
			)
		);
	}

	/**
	 * Clearing a default empties the WooCommerce profile fields.
	 */
	public function test_clear_primary_empties_the_profile_default(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->clear_primary( $this->user_id, 'billing' );

		$this->assertFalse(
			$this->repository->has_address( $this->repository->get_primary( $this->user_id, 'billing' ) )
		);
		$this->assertSame( array(), $this->repository->get_address_book( $this->user_id ) );
	}
}
