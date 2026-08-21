<?php
/**
 * Default-role assignment, lossless swaps, deletion guards, and limits.
 *
 * @package Unwan
 */

/**
 * Reassigning a default must never destroy an address that was already in the
 * book, and must never leave the same address in the book twice.
 */
class AddressRepositoryRoleTest extends UnwanTestCase {

	/**
	 * Promoting an extra moves it into the profile and drops the extra record.
	 */
	public function test_promoting_an_extra_moves_it_into_the_profile(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$this->assertTrue( $this->repository->make_primary( $this->user_id, 'billing', $id ) );

		$this->assertSameAddress(
			$this->address(),
			$this->repository->get_primary( $this->user_id, 'billing' )
		);
		$this->assertArrayNotHasKey(
			$id,
			$this->repository->get_saved( $this->user_id ),
			'The redundant extra record is removed'
		);
		$this->assertSame(
			array( 'default_billing' => array( 'billing' ) ),
			$this->roles_by_entry()
		);
	}

	/**
	 * The displaced default returns to the shared collection instead of being
	 * discarded.
	 */
	public function test_reassigning_a_default_preserves_the_displaced_address(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$replacement = $this->repository->create( $this->user_id, $this->other_address() );

		$this->repository->make_primary( $this->user_id, 'billing', $replacement );

		$this->assertSameAddress(
			$this->other_address(),
			$this->repository->get_primary( $this->user_id, 'billing' )
		);

		$saved = $this->repository->get_saved( $this->user_id );

		$this->assertCount( 1, $saved, 'Exactly one extra remains: the displaced default' );
		$this->assertSameAddress( $this->address(), reset( $saved )['fields'] );
		$this->assertCount( 2, $this->repository->get_address_book( $this->user_id ) );
	}

	/**
	 * A displaced default still holding the other role is not duplicated into
	 * the extras.
	 */
	public function test_a_displaced_default_keeping_the_other_role_is_not_duplicated(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->address() );
		$replacement = $this->repository->create( $this->user_id, $this->other_address() );

		$this->repository->make_primary( $this->user_id, 'billing', $replacement );

		$this->assertSame(
			array(),
			$this->repository->get_saved( $this->user_id ),
			'The address still owns the shipping role, so it needs no extra record'
		);

		$this->assertSame(
			array(
				'default_billing'  => array( 'billing' ),
				'default_shipping' => array( 'shipping' ),
			),
			$this->roles_by_entry()
		);
	}

	/**
	 * Preserving a displaced default is allowed past the extras limit, because
	 * a swap must never delete an address already in the book.
	 */
	public function test_a_displaced_default_bypasses_the_extras_limit(): void {
		update_option( 'unwan_address_save_limit', 1 );

		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$replacement = $this->repository->create( $this->user_id, $this->other_address() );

		$this->assertFalse( $this->repository->can_add( $this->user_id ), 'The extras slot is full' );

		$this->repository->make_primary( $this->user_id, 'billing', $replacement );

		$saved = $this->repository->get_saved( $this->user_id );

		$this->assertCount( 1, $saved );
		$this->assertSameAddress(
			$this->address(),
			reset( $saved )['fields'],
			'The displaced default was preserved despite the full limit'
		);
		$this->assertCount( 2, $this->repository->get_address_book( $this->user_id ) );
	}

	/**
	 * Promoting an address that already holds the role is a no-op.
	 */
	public function test_promoting_the_current_default_changes_nothing(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$extra = $this->repository->create( $this->user_id, $this->other_address() );

		$this->assertTrue( $this->repository->make_primary( $this->user_id, 'billing', 'default_billing' ) );

		$this->assertSameAddress(
			$this->address(),
			$this->repository->get_primary( $this->user_id, 'billing' )
		);
		$this->assertSame( array( $extra ), array_keys( $this->repository->get_saved( $this->user_id ) ) );
	}

	/**
	 * Giving an address the second role collapses the book to one entry.
	 */
	public function test_taking_the_second_role_collapses_the_entry(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );

		$this->repository->make_primary( $this->user_id, 'shipping', 'default_billing' );

		$book = $this->repository->get_address_book( $this->user_id );

		$this->assertCount( 2, $book, 'The displaced shipping default is kept as an extra' );
		$this->assertSame( array( 'billing', 'shipping' ), $book[0]['roles'] );
		$this->assertSameAddress( $this->address(), $book[0]['fields'] );
		$this->assertSame( array(), $book[1]['roles'] );
		$this->assertSameAddress( $this->other_address(), $book[1]['fields'] );
	}

	/**
	 * Promoting an unknown entry is an error.
	 */
	public function test_promoting_an_unknown_entry_errors(): void {
		$result = $this->repository->make_primary( $this->user_id, 'billing', 'unwan_a_missing' );

		$this->assertWPError( $result );
		$this->assertSame( 'unwan_address_not_found', $result->get_error_code() );
	}

	/**
	 * Role-free extras can be deleted.
	 */
	public function test_role_free_extras_can_be_deleted(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$this->assertTrue( $this->repository->delete( $this->user_id, $id ) );
		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ) );
		$this->assertSame( array(), $this->repository->get_address_book( $this->user_id ) );
	}

	/**
	 * An address holding a default role is protected from deletion.
	 */
	public function test_an_address_holding_a_default_role_cannot_be_deleted(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$result = $this->repository->delete( $this->user_id, 'default_billing' );

		$this->assertWPError( $result );
		$this->assertSame( 'unwan_default_address', $result->get_error_code() );
		$this->assertSameAddress(
			$this->address(),
			$this->repository->get_primary( $this->user_id, 'billing' ),
			'The profile default survives the rejected delete'
		);
	}

	/**
	 * Deleting an unknown entry is an error.
	 */
	public function test_deleting_an_unknown_entry_errors(): void {
		$result = $this->repository->delete( $this->user_id, 'unwan_a_missing' );

		$this->assertWPError( $result );
		$this->assertSame( 'unwan_address_not_found', $result->get_error_code() );
	}

	/**
	 * The account editor can assign both roles to a new address at once.
	 */
	public function test_save_entry_can_assign_both_roles_to_a_new_address(): void {
		$result = $this->repository->save_entry(
			$this->user_id,
			'new',
			$this->address(),
			array( 'billing', 'shipping' )
		);

		$this->assertSame( 'default_billing', $result );
		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ), 'No redundant extra is created' );
		$this->assertSame(
			array( 'default_billing' => array( 'billing', 'shipping' ) ),
			$this->roles_by_entry()
		);
	}

	/**
	 * Saving a new address without roles files it as a shared extra.
	 */
	public function test_save_entry_without_roles_creates_an_extra(): void {
		$result = $this->repository->save_entry( $this->user_id, 'new', $this->address(), array() );

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'unwan_a_', $result );
		$this->assertCount( 1, $this->repository->get_saved( $this->user_id ) );
		$this->assertSame( array( $result => array() ), $this->roles_by_entry() );
	}

	/**
	 * Editing an address it already owns updates in place rather than swapping.
	 */
	public function test_save_entry_edits_a_default_in_place(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$result = $this->repository->save_entry(
			$this->user_id,
			'default_billing',
			$this->address( array( 'city' => 'Metropolis' ) ),
			array( 'billing' )
		);

		$this->assertSame( 'default_billing', $result );
		$this->assertSame(
			'Metropolis',
			$this->repository->get_primary( $this->user_id, 'billing' )['city']
		);
		$this->assertSame(
			array(),
			$this->repository->get_saved( $this->user_id ),
			'Editing in place must not spawn a copy of the previous version'
		);
	}

	/**
	 * Clearing an entry's roles releases the profile slot and files the address
	 * back into the shared collection.
	 */
	public function test_save_entry_dropping_every_role_returns_the_address_to_the_extras(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$result = $this->repository->save_entry(
			$this->user_id,
			'default_billing',
			$this->address(),
			array()
		);

		$this->assertIsString( $result );
		$this->assertFalse(
			$this->repository->has_address( $this->repository->get_primary( $this->user_id, 'billing' ) ),
			'The billing slot is released'
		);
		$this->assertCount( 1, $this->repository->get_saved( $this->user_id ) );
		$this->assertSame( array( $result => array() ), $this->roles_by_entry() );
	}

	/**
	 * Editing a role-free extra leaves it role-free.
	 */
	public function test_save_entry_updates_a_role_free_extra_in_place(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$result = $this->repository->save_entry(
			$this->user_id,
			$id,
			$this->address( array( 'city' => 'Metropolis' ) ),
			array()
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->repository->get_saved( $this->user_id ) );
		$this->assertSame(
			'Metropolis',
			$this->repository->get_saved( $this->user_id )[ $id ]['fields']['city']
		);
	}

	/**
	 * Unknown roles are discarded, while a recognizable role survives
	 * sanitization.
	 */
	public function test_save_entry_ignores_unsupported_roles(): void {
		$result = $this->repository->save_entry(
			$this->user_id,
			'new',
			$this->address(),
			array( 'billing', 'gift', 'SHIPPING ' )
		);

		$this->assertSame( 'default_billing', $result );
		$this->assertSame(
			array( 'default_billing' => array( 'billing', 'shipping' ) ),
			$this->roles_by_entry(),
			'"gift" is dropped; "SHIPPING " normalizes to the shipping role'
		);
	}

	/**
	 * A role list containing nothing usable is treated as no roles at all.
	 */
	public function test_save_entry_with_only_unsupported_roles_creates_an_extra(): void {
		$result = $this->repository->save_entry(
			$this->user_id,
			'new',
			$this->address(),
			array( 'gift', 'pickup' )
		);

		$this->assertIsString( $result );
		$this->assertStringStartsWith( 'unwan_a_', $result );
		$this->assertSame( array( $result => array() ), $this->roles_by_entry() );
	}

	/**
	 * Saving an entry that does not exist is an error.
	 */
	public function test_save_entry_rejects_an_unknown_id(): void {
		$result = $this->repository->save_entry(
			$this->user_id,
			'unwan_a_missing',
			$this->address(),
			array()
		);

		$this->assertWPError( $result );
		$this->assertSame( 'unwan_address_not_found', $result->get_error_code() );
	}

	/**
	 * Zero means unlimited.
	 */
	public function test_a_zero_limit_is_unlimited(): void {
		update_option( 'unwan_address_save_limit', 0 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->repository->create(
				$this->user_id,
				$this->address( array( 'address_1' => sprintf( '%d Maple Street', $i ) ) )
			);
		}

		$this->assertTrue( $this->repository->can_add( $this->user_id ) );
		$this->assertCount( 5, $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * A new address past the limit is refused with an error.
	 */
	public function test_creating_past_the_limit_errors(): void {
		update_option( 'unwan_address_save_limit', 2 );

		$this->repository->create( $this->user_id, $this->address( array( 'address_1' => '1 Maple Street' ) ) );
		$this->repository->create( $this->user_id, $this->address( array( 'address_1' => '2 Maple Street' ) ) );

		$this->assertFalse( $this->repository->can_add( $this->user_id ) );

		$result = $this->repository->create( $this->user_id, $this->address( array( 'address_1' => '3 Maple Street' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'unwan_address_limit', $result->get_error_code() );
		$this->assertCount( 2, $this->repository->get_saved( $this->user_id ) );
	}

	/**
	 * Defaults do not consume the extras limit.
	 */
	public function test_profile_defaults_do_not_count_against_the_limit(): void {
		update_option( 'unwan_address_save_limit', 1 );

		$this->repository->save_primary( $this->user_id, 'billing', $this->address( array( 'address_1' => '1 Maple Street' ) ) );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->address( array( 'address_1' => '2 Maple Street' ) ) );

		$this->assertTrue( $this->repository->can_add( $this->user_id ) );
		$this->assertIsString(
			$this->repository->create( $this->user_id, $this->address( array( 'address_1' => '3 Maple Street' ) ) )
		);
	}

	/**
	 * Re-saving an address that already exists is not blocked by the limit.
	 */
	public function test_a_duplicate_is_reused_even_when_the_limit_is_reached(): void {
		update_option( 'unwan_address_save_limit', 1 );

		$id = $this->repository->create( $this->user_id, $this->address() );

		$this->assertFalse( $this->repository->can_add( $this->user_id ) );
		$this->assertSame( $id, $this->repository->create( $this->user_id, $this->address() ) );
	}
}
