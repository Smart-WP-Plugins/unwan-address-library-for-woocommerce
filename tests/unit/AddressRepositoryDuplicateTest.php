<?php
/**
 * Duplicate detection across defaults and shared extras.
 *
 * @package Unwan
 */

/**
 * Duplicates are decided by normalized first name, last name, and address line
 * one — nothing else.
 */
class AddressRepositoryDuplicateTest extends UnwanTestCase {

	/**
	 * Saving the same address twice reuses the first record.
	 */
	public function test_creating_the_same_address_twice_reuses_the_record(): void {
		$first  = $this->repository->create( $this->user_id, $this->address() );
		$second = $this->repository->create( $this->user_id, $this->address() );

		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * Case, surrounding whitespace, and accents are all normalized away.
	 *
	 * @dataProvider provide_equivalent_addresses
	 *
	 * @param array<string,string> $variant Field overrides describing the same address.
	 */
	public function test_normalized_variants_are_treated_as_duplicates( array $variant ): void {
		$original = $this->repository->create(
			$this->user_id,
			$this->address(
				array(
					'first_name' => 'Renée',
					'last_name'  => 'Lovelace',
					'address_1'  => '12 Maple Street',
				)
			)
		);

		$this->assertSame(
			$original,
			$this->repository->create( $this->user_id, $this->address( $variant ) )
		);
		$this->assertCount( 1, $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * Variants of one address that must all collapse together.
	 *
	 * @return array<string,array<int,array<string,string>>>
	 */
	public function provide_equivalent_addresses(): array {
		return array(
			'different case'     => array(
				array(
					'first_name' => 'RENÉE',
					'last_name'  => 'lovelace',
					'address_1'  => '12 MAPLE STREET',
				),
			),
			'accents removed'    => array(
				array(
					'first_name' => 'Renee',
					'last_name'  => 'Lovelace',
					'address_1'  => '12 Maple Street',
				),
			),
			'collapsed spacing'  => array(
				array(
					'first_name' => '  Renée ',
					'last_name'  => 'Lovelace',
					'address_1'  => '12   Maple    Street',
				),
			),
			'other fields differ' => array(
				array(
					'first_name' => 'Renée',
					'last_name'  => 'Lovelace',
					'address_1'  => '12 Maple Street',
					'address_2'  => 'Suite 900',
					'city'       => 'Metropolis',
					'postcode'   => '11111',
					'phone'      => '5559999',
					'company'    => 'Somewhere Else',
				),
			),
		);
	}

	/**
	 * A different person at the same street address is a distinct entry.
	 */
	public function test_a_different_name_at_the_same_street_is_not_a_duplicate(): void {
		$first  = $this->repository->create( $this->user_id, $this->address() );
		$second = $this->repository->create(
			$this->user_id,
			$this->address( array( 'first_name' => 'Grace' ) )
		);

		$this->assertNotSame( $first, $second );
		$this->assertCount( 2, $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * The same person at a different street is a distinct entry.
	 */
	public function test_a_different_street_for_the_same_name_is_not_a_duplicate(): void {
		$this->repository->create( $this->user_id, $this->address() );
		$this->repository->create( $this->user_id, $this->address( array( 'address_1' => '13 Maple Street' ) ) );

		$this->assertCount( 2, $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * Without a street line there is no signature, so nothing is deduped.
	 */
	public function test_addresses_without_a_street_line_are_never_duplicates(): void {
		$this->assertSame(
			'',
			$this->repository->find_duplicate(
				$this->user_id,
				$this->address( array( 'address_1' => '' ) )
			)
		);
	}

	/**
	 * Duplicate detection spans the profile defaults, not just the extras.
	 */
	public function test_find_duplicate_matches_a_profile_default(): void {
		$this->repository->save_primary( $this->user_id, 'shipping', $this->address() );

		$this->assertSame(
			'default_shipping',
			$this->repository->find_duplicate( $this->user_id, $this->address() )
		);
	}

	/**
	 * An entry never counts as a duplicate of itself.
	 */
	public function test_find_duplicate_honors_the_exclusion(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$this->assertSame( $id, $this->repository->find_duplicate( $this->user_id, $this->address() ) );
		$this->assertSame( '', $this->repository->find_duplicate( $this->user_id, $this->address(), $id ) );
	}

	/**
	 * One customer's address book never leaks into another's duplicate check.
	 */
	public function test_duplicate_detection_is_scoped_to_one_customer(): void {
		$this->repository->create( $this->user_id, $this->address() );

		$other = self::factory()->user->create( array( 'role' => 'customer' ) );

		$this->assertSame( '', $this->repository->find_duplicate( $other, $this->address() ) );
	}

	/**
	 * Editing an address into an existing one is rejected rather than merged.
	 */
	public function test_updating_an_address_onto_an_existing_one_is_rejected(): void {
		$first  = $this->repository->create( $this->user_id, $this->address() );
		$second = $this->repository->create( $this->user_id, $this->other_address() );

		$result = $this->repository->update( $this->user_id, $second, $this->address() );

		$this->assertWPError( $result );
		$this->assertSame( 'unwan_address_duplicate', $result->get_error_code() );
		$this->assertCount( 2, $this->repository->get_saved( $this->user_id ) );

		$saved = $this->repository->get_saved( $this->user_id );
		$this->assertSame( '990 Oak Avenue', $saved[ $second ]['fields']['address_1'], 'The record is untouched' );
		$this->assertSame( '12 Maple Street', $saved[ $first ]['fields']['address_1'] );
	}

	/**
	 * Editing an address in place is allowed even though it matches itself.
	 */
	public function test_updating_an_address_in_place_is_allowed(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$result = $this->repository->update(
			$this->user_id,
			$id,
			$this->address( array( 'city' => 'Metropolis' ) )
		);

		$this->assertTrue( $result );

		$saved = $this->repository->get_saved( $this->user_id );
		$this->assertSame( 'Metropolis', $saved[ $id ]['fields']['city'] );
	}

	/**
	 * Updating a record that does not exist is an error, not a silent create.
	 */
	public function test_updating_a_missing_address_errors(): void {
		$result = $this->repository->update( $this->user_id, 'unwan_a_missing', $this->address() );

		$this->assertWPError( $result );
		$this->assertSame( 'unwan_address_not_found', $result->get_error_code() );
		$this->assertCount( 0, $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * An update stamps the modification time without moving the creation time.
	 */
	public function test_updating_an_address_refreshes_only_the_updated_timestamp(): void {
		$id      = $this->repository->create( $this->user_id, $this->address() );
		$created = $this->repository->get_saved( $this->user_id )[ $id ]['created_at'];

		$this->assertNotSame( '', $created );

		$this->repository->update( $this->user_id, $id, $this->address( array( 'city' => 'Metropolis' ) ) );

		$record = $this->repository->get_saved( $this->user_id )[ $id ];

		$this->assertSame( $created, $record['created_at'] );
		$this->assertGreaterThanOrEqual( $created, $record['updated_at'] );
	}
}
