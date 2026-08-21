<?php
/**
 * The documented public filter and action contract.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\AddressRepository;

/**
 * Each hook in CLAUDE.md's developer API is exercised with its documented
 * signature so a rename or an argument-count change fails loudly.
 */
class HooksTest extends UnwanTestCase {

	/**
	 * Extensions can add a field to the stored set.
	 */
	public function test_address_field_keys_filter_extends_the_stored_fields(): void {
		add_filter(
			'unwan_address_field_keys',
			static function ( array $keys ): array {
				$keys[] = 'delivery_note';

				return $keys;
			}
		);

		$repository = new AddressRepository();

		$this->assertContains( 'delivery_note', $repository->get_field_keys() );

		$clean = $repository->sanitize_fields( $this->address( array( 'delivery_note' => 'Leave at door' ) ) );

		$this->assertSame( 'Leave at door', $clean['delivery_note'] );
	}

	/**
	 * Duplicate keys and unsanitary keys are normalized away.
	 */
	public function test_address_field_keys_filter_output_is_normalized(): void {
		add_filter(
			'unwan_address_field_keys',
			static fn( array $keys ): array => array_merge( $keys, array( 'city', 'Delivery Note' ) )
		);

		$keys = ( new AddressRepository() )->get_field_keys();

		$this->assertSame( array_values( array_unique( $keys ) ), $keys, 'No duplicate keys' );
		$this->assertSame( 1, count( array_keys( $keys, 'city', true ) ), 'A re-added key is not duplicated' );
		$this->assertContains(
			'deliverynote',
			$keys,
			'Keys pass through sanitize_key, which strips the space rather than replacing it'
		);
		$this->assertNotContains( 'Delivery Note', $keys );
	}

	/**
	 * The saved-addresses filter receives the normalized set and the user ID.
	 */
	public function test_saved_addresses_filter_receives_the_user_id(): void {
		$this->repository->create( $this->user_id, $this->address() );

		$seen = array();

		add_filter(
			'unwan_saved_addresses',
			static function ( array $addresses, int $user_id ) use ( &$seen ): array {
				$seen[] = array( count( $addresses ), $user_id );

				return $addresses;
			},
			10,
			2
		);

		$repository = new AddressRepository();
		$repository->get_saved( $this->user_id );

		$this->assertSame( array( array( 1, $this->user_id ) ), $seen );
	}

	/**
	 * The checkout-options filter receives the options, user, and selector type.
	 */
	public function test_checkout_address_options_filter_receives_all_three_arguments(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$seen = array();

		add_filter(
			'unwan_checkout_address_options',
			static function ( array $options, int $user_id, string $type ) use ( &$seen ): array {
				$seen[] = array( count( $options ), $user_id, $type );

				return array_slice( $options, 0, 0 );
			},
			10,
			3
		);

		$repository = new AddressRepository();

		$this->assertSame( array(), $repository->get_checkout_options( $this->user_id, 'shipping' ) );
		$this->assertSame( array( array( 1, $this->user_id, 'shipping' ) ), $seen );
	}

	/**
	 * The limit filter overrides the stored option and can restore unlimited.
	 */
	public function test_address_save_limit_filter_overrides_the_option(): void {
		update_option( 'unwan_address_save_limit', 0 );

		$seen = array();

		add_filter(
			'unwan_address_save_limit',
			static function ( int $limit, int $user_id ) use ( &$seen ): int {
				$seen[] = array( $limit, $user_id );

				return 1;
			},
			10,
			2
		);

		$this->repository->create( $this->user_id, $this->address() );

		$this->assertFalse( $this->repository->can_add( $this->user_id ) );
		$this->assertSame( array( 0, $this->user_id ), $seen[0] );

		$result = $this->repository->create(
			$this->user_id,
			$this->address( array( 'address_1' => '99 Oak Avenue' ) )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'unwan_address_limit', $result->get_error_code() );
	}

	/**
	 * The save action fires with the customer ID and the persisted records.
	 */
	public function test_addresses_saved_action_fires_with_the_records(): void {
		$calls = array();

		add_action(
			'unwan_addresses_saved',
			static function ( int $user_id, array $records ) use ( &$calls ): void {
				$calls[] = array( $user_id, array_keys( $records ) );
			},
			10,
			2
		);

		$id = $this->repository->create( $this->user_id, $this->address() );

		$this->assertSame( array( array( $this->user_id, array( $id ) ) ), $calls );

		$this->repository->delete( $this->user_id, $id );

		$this->assertSame( array( $this->user_id, array() ), $calls[1], 'Deletion persists an empty set' );
	}

	/**
	 * Enabling and disabling a selector is filterable per type.
	 */
	public function test_address_type_enabled_filter_is_per_type(): void {
		$settings = new \Unwan\AddressLibrary\Admin\Settings();

		$this->assertTrue( $settings->is_enabled( 'billing' ) );
		$this->assertTrue( $settings->is_enabled( 'shipping' ) );

		add_filter(
			'unwan_address_type_enabled',
			static fn( bool $enabled, string $type ): bool => 'shipping' !== $type && $enabled,
			10,
			2
		);

		$this->assertTrue( $settings->is_enabled( 'billing' ) );
		$this->assertFalse( $settings->is_enabled( 'shipping' ) );
	}

	/**
	 * The stored threshold is clamped to 0-100 before the filter sees it.
	 */
	public function test_address_search_threshold_option_is_clamped(): void {
		$settings = new \Unwan\AddressLibrary\Admin\Settings();

		$this->assertSame( 4, $settings->get_address_search_threshold(), 'Documented default' );

		update_option( 'unwan_address_search_threshold', 250 );
		$this->assertSame( 100, $settings->get_address_search_threshold() );

		update_option( 'unwan_address_search_threshold', 7 );
		$this->assertSame( 7, $settings->get_address_search_threshold() );
	}

	/**
	 * Returning zero from the filter makes search always visible.
	 */
	public function test_address_search_threshold_filter_can_force_search_on(): void {
		$settings = new \Unwan\AddressLibrary\Admin\Settings();

		update_option( 'unwan_address_search_threshold', 20 );

		add_filter( 'unwan_address_search_threshold', static fn(): int => 0 );

		$this->assertSame( 0, $settings->get_address_search_threshold() );
	}

	/**
	 * Checkout saving and default promotion are both filterable.
	 */
	public function test_checkout_saving_filters_are_respected(): void {
		$settings = new \Unwan\AddressLibrary\Admin\Settings();

		$this->assertTrue( $settings->should_save_checkout_addresses() );

		add_filter( 'unwan_save_checkout_addresses', '__return_false' );
		$this->assertFalse( $settings->should_save_checkout_addresses() );

		add_filter( 'unwan_update_checkout_default', '__return_true' );
		$this->assertTrue( $settings->should_update_checkout_default() );
	}

	/**
	 * An invalid filtered color scheme falls back to light.
	 */
	public function test_color_scheme_filter_falls_back_to_light(): void {
		$settings = new \Unwan\AddressLibrary\Admin\Settings();

		add_filter( 'unwan_color_scheme', static fn(): string => 'neon' );

		$this->assertSame( 'light', $settings->get_color_scheme() );
	}

	/**
	 * An invalid filtered accent falls back to the documented default.
	 */
	public function test_accent_color_filter_falls_back_to_the_default(): void {
		$settings = new \Unwan\AddressLibrary\Admin\Settings();

		add_filter( 'unwan_accent_color', static fn(): string => 'not-a-color' );

		$this->assertSame( '#6b3fa0', $settings->get_accent_color() );
	}

	/**
	 * Label filters override individual keys while leaving the rest intact.
	 */
	public function test_label_filters_override_individual_keys(): void {
		$settings = new \Unwan\AddressLibrary\Admin\Settings();

		add_filter(
			'unwan_account_labels',
			static function ( array $labels ): array {
				$labels['pageTitle'] = 'My places';

				return $labels;
			}
		);

		add_filter(
			'unwan_checkout_picker_labels',
			static function ( array $labels ): array {
				$labels['change'] = 'Swap';

				return $labels;
			}
		);

		$account = $settings->get_account_labels();
		$picker  = $settings->get_checkout_picker_labels();

		$this->assertSame( 'My places', $account['pageTitle'] );
		$this->assertSame( 'Add new address', $account['addAddress'], 'Untouched labels keep their default' );
		$this->assertSame( 'Swap', $picker['change'] );
		$this->assertSame( 'Default', $picker['default'] );
	}
}
