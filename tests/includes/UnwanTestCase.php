<?php
/**
 * Shared base class for Unwan integration tests.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\AddressRepository;

/**
 * Provides a fresh repository, a customer, and address fixtures.
 */
abstract class UnwanTestCase extends WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var AddressRepository
	 */
	protected $repository;

	/**
	 * Customer whose address book each test operates on.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Build a fresh repository so request-level caches never leak between tests.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->repository = new AddressRepository();
		$this->user_id    = self::factory()->user->create( array( 'role' => 'customer' ) );
	}

	/**
	 * Discard every Unwan option so option-driven behavior starts from
	 * defaults. Matching on the prefix rather than a hard-coded list keeps this
	 * correct when a new setting is added.
	 */
	public function tear_down(): void {
		global $wpdb;

		$options = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'unwan\\_%'"
		);

		foreach ( (array) $options as $option ) {
			delete_option( $option );
		}

		parent::tear_down();
	}

	/**
	 * Build a complete address, overriding any field.
	 *
	 * @param array<string,string> $overrides Field overrides.
	 * @return array<string,string>
	 */
	protected function address( array $overrides = array() ): array {
		return array_merge(
			array(
				'first_name' => 'Ada',
				'last_name'  => 'Lovelace',
				'company'    => 'Analytical Engines',
				'country'    => 'US',
				'address_1'  => '12 Maple Street',
				'address_2'  => 'Apt 4',
				'city'       => 'Springfield',
				'state'      => 'CA',
				'postcode'   => '90210',
				'phone'      => '5550100',
			),
			$overrides
		);
	}

	/**
	 * A clearly distinct second address.
	 *
	 * @param array<string,string> $overrides Field overrides.
	 * @return array<string,string>
	 */
	protected function other_address( array $overrides = array() ): array {
		return $this->address(
			array_merge(
				array(
					'first_name' => 'Grace',
					'last_name'  => 'Hopper',
					'company'    => '',
					'address_1'  => '990 Oak Avenue',
					'address_2'  => '',
					'city'       => 'Riverton',
					'state'      => 'NY',
					'postcode'   => '10001',
				),
				$overrides
			)
		);
	}

	/**
	 * Assert that two address arrays describe the same postal address.
	 *
	 * @param array<string,string> $expected Expected fields.
	 * @param array<string,string> $actual   Actual fields.
	 * @param string               $message  Failure message.
	 */
	protected function assertSameAddress( array $expected, array $actual, string $message = '' ): void {
		$keys = $this->repository->get_field_keys();

		$this->assertSame(
			array_intersect_key( $this->repository->sanitize_fields( $expected ), array_flip( $keys ) ),
			array_intersect_key( $this->repository->sanitize_fields( $actual ), array_flip( $keys ) ),
			$message
		);
	}

	/**
	 * Collect the roles held by each entry, keyed by entry ID.
	 *
	 * @return array<string,array<int,string>>
	 */
	protected function roles_by_entry(): array {
		$roles = array();

		foreach ( $this->repository->get_address_book( $this->user_id ) as $entry ) {
			$roles[ $entry['id'] ] = (array) $entry['roles'];
		}

		return $roles;
	}
}
