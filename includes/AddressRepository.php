<?php
/**
 * Address persistence and formatting.
 *
 * @package Unwan
 */

namespace Unwan\AddressLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Combines the customer's two WooCommerce defaults with one shared collection
 * of additional addresses.
 */
final class AddressRepository {

	/**
	 * Request-level caches. WordPress already caches raw user metadata; these
	 * avoid repeatedly normalizing and formatting the same address book for the
	 * billing selector, shipping selector, and My Account view.
	 *
	 * @var string[]|null
	 */
	private $field_keys_cache;

	/**
	 * Normalized shared extras, keyed by user ID.
	 *
	 * @var array<int,array<string,array<string,mixed>>>
	 */
	private $saved_cache = array();

	/**
	 * WooCommerce profile defaults, keyed by user ID then type.
	 *
	 * @var array<int,array<string,array<string,string>>>
	 */
	private $primary_cache = array();

	/**
	 * Combined address-book entries, keyed by user ID.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	private $address_book_cache = array();

	/**
	 * Checkout-ready options, keyed by user ID then selector type.
	 *
	 * @var array<int,array<string,array<int,array<string,mixed>>>>
	 */
	private $checkout_options_cache = array();

	/**
	 * Supported WooCommerce default-address roles.
	 */
	private const TYPES = array( 'billing', 'shipping' );

	/**
	 * Shared additional-address metadata key.
	 */
	private const META_KEY = '_unwan_addresses';

	/**
	 * Standard address fields persisted by the plugin.
	 */
	private const FIELD_KEYS = array(
		'first_name',
		'last_name',
		'company',
		'country',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
		'phone',
	);

	/**
	 * Normalize an address type.
	 *
	 * @param string $type Requested address type.
	 * @return string
	 */
	public function normalize_type( string $type ): string {
		return in_array( $type, self::TYPES, true ) ? $type : 'billing';
	}

	/**
	 * Return supported field keys.
	 *
	 * @return string[]
	 */
	public function get_field_keys(): array {
		if ( is_array( $this->field_keys_cache ) ) {
			return $this->field_keys_cache;
		}

		/**
		 * Filter fields saved for an address-book entry.
		 *
		 * Email is intentionally omitted because it belongs to the customer's
		 * account identity rather than a postal address.
		 *
		 * @param string[] $keys Field keys.
		 */
		$this->field_keys_cache = array_values(
			array_unique(
				array_map(
					'sanitize_key',
					(array) apply_filters( 'unwan_address_field_keys', self::FIELD_KEYS )
				)
			)
		);

		return $this->field_keys_cache;
	}

	/**
	 * Get the shared collection of additional addresses.
	 *
	 * @param int $user_id Customer ID.
	 * @return array<string,array<string,mixed>>
	 */
	public function get_saved( int $user_id ): array {
		if ( array_key_exists( $user_id, $this->saved_cache ) ) {
			return $this->saved_cache[ $user_id ];
		}

		$records = get_user_meta( $user_id, self::META_KEY, true );
		if ( ! is_array( $records ) ) {
			$this->saved_cache[ $user_id ] = array();

			return $this->saved_cache[ $user_id ];
		}

		$normalized = array();
		foreach ( $records as $id => $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			$id = $this->sanitize_id( (string) ( $record['id'] ?? $id ) );
			if ( '' === $id ) {
				continue;
			}

			$normalized[ $id ] = array(
				'id'         => $id,
				'fields'     => $this->sanitize_fields( (array) ( $record['fields'] ?? array() ) ),
				'created_at' => sanitize_text_field( (string) ( $record['created_at'] ?? '' ) ),
				'updated_at' => sanitize_text_field( (string) ( $record['updated_at'] ?? '' ) ),
			);
		}

		/**
		 * Filter a customer's shared additional-address collection.
		 *
		 * @param array<string,array<string,mixed>> $normalized Additional addresses keyed by ID.
		 * @param int                               $user_id    Customer ID.
		 */
		$this->saved_cache[ $user_id ] = (array) apply_filters(
			'unwan_saved_addresses',
			$normalized,
			$user_id
		);

		return $this->saved_cache[ $user_id ];
	}

	/**
	 * Get a WooCommerce profile default.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $type    Billing or shipping.
	 * @return array<string,string>
	 */
	public function get_primary( int $user_id, string $type ): array {
		$type = $this->normalize_type( $type );
		if ( isset( $this->primary_cache[ $user_id ][ $type ] ) ) {
			return $this->primary_cache[ $user_id ][ $type ];
		}

		$customer = new \WC_Customer( $user_id );
		$fields   = array();

		foreach ( $this->get_field_keys() as $key ) {
			$getter         = "get_{$type}_{$key}";
			$fields[ $key ] = is_callable( array( $customer, $getter ) )
				? (string) $customer->{$getter}()
				: (string) get_user_meta( $user_id, "{$type}_{$key}", true );
		}

		$this->primary_cache[ $user_id ][ $type ] = $this->sanitize_fields( $fields );

		return $this->primary_cache[ $user_id ][ $type ];
	}

	/**
	 * Read a persisted WooCommerce default directly from user metadata.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $type    Billing or shipping.
	 * @return array<string,string>
	 */
	public function get_persisted_primary( int $user_id, string $type ): array {
		$type   = $this->normalize_type( $type );
		$fields = array();

		foreach ( $this->get_field_keys() as $key ) {
			$fields[ $key ] = (string) get_user_meta( $user_id, "{$type}_{$key}", true );
		}

		return $this->sanitize_fields( $fields );
	}

	/**
	 * Save a WooCommerce profile default.
	 *
	 * @param int                 $user_id Customer ID.
	 * @param string              $type    Billing or shipping.
	 * @param array<string,mixed> $fields  Address fields.
	 * @return bool
	 */
	public function save_primary( int $user_id, string $type, array $fields ): bool {
		$type     = $this->normalize_type( $type );
		$fields   = $this->sanitize_fields( $fields );
		$customer = new \WC_Customer( $user_id );

		foreach ( $fields as $key => $value ) {
			$setter = "set_{$type}_{$key}";
			if ( is_callable( array( $customer, $setter ) ) ) {
				$customer->{$setter}( $value );
			} else {
				$customer->update_meta_data( "{$type}_{$key}", $value );
			}
		}

		$customer->save();
		$this->invalidate_user_cache( $user_id );

		return true;
	}

	/**
	 * Clear a WooCommerce profile default.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $type    Billing or shipping.
	 * @return bool
	 */
	public function clear_primary( int $user_id, string $type ): bool {
		return $this->save_primary( $user_id, $type, array() );
	}

	/**
	 * Get the recipient name displayed for an address.
	 *
	 * @param array<string,mixed> $fields Address fields.
	 * @return string
	 */
	public function get_recipient_name( array $fields ): string {
		$fields = $this->sanitize_fields( $fields );
		$name   = trim(
			implode(
				' ',
				array_filter(
					array(
						$fields['first_name'] ?? '',
						$fields['last_name'] ?? '',
					)
				)
			)
		);

		if ( '' !== $name ) {
			return $name;
		}

		if ( ! empty( $fields['company'] ) ) {
			return $fields['company'];
		}

		return __( 'Address', 'unwan-for-woocommerce' );
	}

	/**
	 * Return the complete address book: two defaults plus shared extras.
	 *
	 * Matching billing and shipping defaults collapse into one entry carrying
	 * both roles.
	 *
	 * @param int $user_id Customer ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_address_book( int $user_id ): array {
		if ( array_key_exists( $user_id, $this->address_book_cache ) ) {
			return $this->address_book_cache[ $user_id ];
		}

		$entries    = array();
		$signatures = array();

		foreach ( self::TYPES as $type ) {
			$fields = $this->get_primary( $user_id, $type );
			if ( ! $this->has_address( $fields ) ) {
				continue;
			}

			$signature = $this->duplicate_signature( $fields );
			if ( '' !== $signature && isset( $signatures[ $signature ] ) ) {
				$index                        = $signatures[ $signature ];
				$entries[ $index ]['roles'][] = $type;
				continue;
			}

			$index     = count( $entries );
			$entries[] = array(
				'id'         => "default_{$type}",
				'fields'     => $fields,
				'roles'      => array( $type ),
				'is_default' => true,
				'created_at' => '',
				'updated_at' => '',
			);

			if ( '' !== $signature ) {
				$signatures[ $signature ] = $index;
			}
		}

		foreach ( $this->get_saved( $user_id ) as $record ) {
			$signature = $this->duplicate_signature( (array) $record['fields'] );
			if ( '' !== $signature && isset( $signatures[ $signature ] ) ) {
				continue;
			}

			$index     = count( $entries );
			$entries[] = array(
				'id'         => $record['id'],
				'fields'     => $record['fields'],
				'roles'      => array(),
				'is_default' => false,
				'created_at' => $record['created_at'],
				'updated_at' => $record['updated_at'],
			);

			if ( '' !== $signature ) {
				$signatures[ $signature ] = $index;
			}
		}

		usort(
			$entries,
			static function ( array $first, array $second ): int {
				if ( $first['is_default'] !== $second['is_default'] ) {
					return $first['is_default'] ? -1 : 1;
				}

				return strcmp(
					(string) ( $second['updated_at'] ?? '' ),
					(string) ( $first['updated_at'] ?? '' )
				);
			}
		);

		$this->address_book_cache[ $user_id ] = $entries;

		return $this->address_book_cache[ $user_id ];
	}

	/**
	 * Find any visible address-book entry.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $id      Entry ID.
	 * @return array<string,mixed>|null
	 */
	public function get_entry( int $user_id, string $id ) {
		$id = $this->sanitize_id( $id );
		foreach ( $this->get_address_book( $user_id ) as $entry ) {
			if ( $id === $entry['id'] ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Get the combined entry currently occupying a default role.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $type    Billing or shipping.
	 * @return array<string,mixed>|null
	 */
	public function get_default_entry( int $user_id, string $type ) {
		$type = $this->normalize_type( $type );
		foreach ( $this->get_address_book( $user_id ) as $entry ) {
			if ( in_array( $type, (array) $entry['roles'], true ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Create an additional shared address.
	 *
	 * @param int                 $user_id Customer ID.
	 * @param array<string,mixed> $fields  Address fields.
	 * @return string|\WP_Error
	 */
	public function create( int $user_id, array $fields ) {
		$fields       = $this->sanitize_fields( $fields );
		$duplicate_id = $this->find_duplicate( $user_id, $fields );
		if ( '' !== $duplicate_id ) {
			return $duplicate_id;
		}

		if ( ! $this->can_add( $user_id ) ) {
			return new \WP_Error(
				'unwan_address_limit',
				__( 'Your address book has reached its saved-address limit.', 'unwan-for-woocommerce' )
			);
		}

		$records = $this->get_saved( $user_id );
		$id      = $this->new_id( $records );
		$now     = gmdate( 'c' );

		$records[ $id ] = array(
			'id'         => $id,
			'fields'     => $fields,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$this->save_records( $user_id, $records );

		return $id;
	}

	/**
	 * Update an additional shared address.
	 *
	 * @param int                 $user_id Customer ID.
	 * @param string              $id      Address ID.
	 * @param array<string,mixed> $fields  Address fields.
	 * @return bool|\WP_Error
	 */
	public function update( int $user_id, string $id, array $fields ) {
		$id      = $this->sanitize_id( $id );
		$records = $this->get_saved( $user_id );

		if ( ! isset( $records[ $id ] ) ) {
			return new \WP_Error(
				'unwan_address_not_found',
				__( 'That saved address could not be found.', 'unwan-for-woocommerce' )
			);
		}

		$fields       = $this->sanitize_fields( $fields );
		$duplicate_id = $this->find_duplicate( $user_id, $fields, $id );
		if ( '' !== $duplicate_id ) {
			return new \WP_Error(
				'unwan_address_duplicate',
				__( 'That address is already in your address book.', 'unwan-for-woocommerce' )
			);
		}

		$records[ $id ]['fields']     = $fields;
		$records[ $id ]['updated_at'] = gmdate( 'c' );

		$this->save_records( $user_id, $records );

		return true;
	}

	/**
	 * Save an account editor entry and its selected default roles.
	 *
	 * @param int                 $user_id Customer ID.
	 * @param string              $id      Existing entry ID or "new".
	 * @param array<string,mixed> $fields  Address fields.
	 * @param string[]            $roles   Default roles assigned to the entry.
	 * @return string|bool|\WP_Error
	 */
	public function save_entry( int $user_id, string $id, array $fields, array $roles ) {
		$id     = $this->sanitize_id( $id );
		$fields = $this->sanitize_fields( $fields );
		$roles  = array_values(
			array_intersect(
				self::TYPES,
				array_map( 'sanitize_key', $roles )
			)
		);

		$existing       = 'new' === $id ? null : $this->get_entry( $user_id, $id );
		$existing_roles = is_array( $existing ) ? (array) $existing['roles'] : array();

		if ( 'new' !== $id && null === $existing ) {
			return new \WP_Error(
				'unwan_address_not_found',
				__( 'That saved address could not be found.', 'unwan-for-woocommerce' )
			);
		}

		foreach ( array_diff( $existing_roles, $roles ) as $removed_role ) {
			$this->clear_primary( $user_id, (string) $removed_role );
		}

		foreach ( $roles as $role ) {
			if ( in_array( $role, $existing_roles, true ) ) {
				// Editing an address in a role it already owns is an in-place
				// update, not a role reassignment.
				$this->save_primary( $user_id, $role, $fields );
			} else {
				$this->assign_primary( $user_id, $role, $fields );
			}
		}

		if ( ! empty( $roles ) ) {
			$this->remove_saved_id( $user_id, $id );
			$this->remove_saved_duplicate( $user_id, $fields );

			return "default_{$roles[0]}";
		}

		if ( 'new' === $id || ! empty( $existing_roles ) ) {
			return $this->create( $user_id, $fields );
		}

		return $this->update( $user_id, $id, $fields );
	}

	/**
	 * Delete an additional address.
	 *
	 * Defaults must be reassigned or edited before deletion.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $id      Address ID.
	 * @return bool|\WP_Error
	 */
	public function delete( int $user_id, string $id ) {
		$entry = $this->get_entry( $user_id, $id );
		if ( null === $entry ) {
			return new \WP_Error(
				'unwan_address_not_found',
				__( 'That saved address could not be found.', 'unwan-for-woocommerce' )
			);
		}

		if ( ! empty( $entry['roles'] ) ) {
			return new \WP_Error(
				'unwan_default_address',
				__( 'Change this address’s default roles before deleting it.', 'unwan-for-woocommerce' )
			);
		}

		$records = $this->get_saved( $user_id );
		unset( $records[ $id ] );
		$this->save_records( $user_id, $records );

		return true;
	}

	/**
	 * Assign an existing address to one WooCommerce default role.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $type    Billing or shipping.
	 * @param string $id      Address-book entry ID.
	 * @return bool|\WP_Error
	 */
	public function make_primary( int $user_id, string $type, string $id ) {
		$type  = $this->normalize_type( $type );
		$entry = $this->get_entry( $user_id, $id );

		if ( null === $entry ) {
			return new \WP_Error(
				'unwan_address_not_found',
				__( 'That saved address could not be found.', 'unwan-for-woocommerce' )
			);
		}

		if ( in_array( $type, (array) $entry['roles'], true ) ) {
			return true;
		}

		$this->assign_primary( $user_id, $type, (array) $entry['fields'] );
		$this->remove_saved_id( $user_id, $id );
		$this->remove_saved_duplicate( $user_id, (array) $entry['fields'] );

		return true;
	}

	/**
	 * Find a duplicate across both defaults and all shared extras.
	 *
	 * @param int                 $user_id  Customer ID.
	 * @param array<string,mixed> $fields   Candidate fields.
	 * @param string              $exclude  Entry ID to exclude.
	 * @return string Existing entry ID or an empty string.
	 */
	public function find_duplicate( int $user_id, array $fields, string $exclude = '' ): string {
		$signature = $this->duplicate_signature( $fields );
		if ( '' === $signature ) {
			return '';
		}

		foreach ( self::TYPES as $type ) {
			$id = "default_{$type}";
			if (
				$id !== $exclude
				&& $signature === $this->duplicate_signature( $this->get_persisted_primary( $user_id, $type ) )
			) {
				return $id;
			}
		}

		foreach ( $this->get_saved( $user_id ) as $id => $record ) {
			if (
				$id !== $exclude
				&& $signature === $this->duplicate_signature( (array) $record['fields'] )
			) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * Whether another additional address may be saved.
	 *
	 * @param int $user_id Customer ID.
	 * @return bool
	 */
	public function can_add( int $user_id ): bool {
		$limit = absint( get_option( 'unwan_address_save_limit', 0 ) );

		/**
		 * Filter the shared additional-address limit for a customer.
		 *
		 * Zero means unlimited.
		 *
		 * @param int $limit   Configured limit.
		 * @param int $user_id Customer ID.
		 */
		$limit = absint(
			apply_filters(
				'unwan_address_save_limit',
				$limit,
				$user_id
			)
		);

		return 0 === $limit || count( $this->get_saved( $user_id ) ) < $limit;
	}

	/**
	 * Whether an address contains meaningful postal data.
	 *
	 * @param array<string,mixed> $fields Address fields.
	 * @return bool
	 */
	public function has_address( array $fields ): bool {
		foreach ( array( 'address_1', 'city', 'postcode' ) as $key ) {
			if ( ! empty( $fields[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the same combined address set for either checkout selector.
	 *
	 * The requested type changes only which entry is first and receives the
	 * Default badge.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $type    Billing or shipping selector context.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_checkout_options( int $user_id, string $type ): array {
		$type = $this->normalize_type( $type );
		if ( isset( $this->checkout_options_cache[ $user_id ][ $type ] ) ) {
			return $this->checkout_options_cache[ $user_id ][ $type ];
		}

		$entries = $this->get_address_book( $user_id );

		usort(
			$entries,
			static function ( array $first, array $second ) use ( $type ): int {
				$first_default  = in_array( $type, (array) $first['roles'], true );
				$second_default = in_array( $type, (array) $second['roles'], true );

				if ( $first_default !== $second_default ) {
					return $first_default ? -1 : 1;
				}

				if ( $first['is_default'] !== $second['is_default'] ) {
					return $first['is_default'] ? -1 : 1;
				}

				return strcmp(
					(string) ( $second['updated_at'] ?? '' ),
					(string) ( $first['updated_at'] ?? '' )
				);
			}
		);

		$options = array();
		foreach ( $entries as $entry ) {
			$fields     = (array) $entry['fields'];
			$formatted  = $this->format_address_text( $fields );
			$name       = $this->get_recipient_name( $fields );
			$is_default = in_array( $type, (array) $entry['roles'], true );
			$options[]  = array(
				'id'           => $entry['id'],
				'name'         => $name,
				'description'  => $formatted,
				'street'       => $this->format_street_text( $fields ),
				'details'      => $this->format_location_text( $fields ),
				'isDefault'    => $is_default,
				'defaultRoles' => array_values( (array) $entry['roles'] ),
				'selectLabel'  => $is_default
					? sprintf(
						/* translators: 1: recipient name, 2: formatted postal address. */
						__( '%1$s (default) — %2$s', 'unwan-for-woocommerce' ),
						$name,
						$formatted
					)
					: $name . ' — ' . $formatted,
				'fields'       => $fields,
			);
		}

		/**
		 * Filter checkout-ready options from the combined address book.
		 *
		 * @param array<int,array<string,mixed>> $options Checkout options.
		 * @param int                            $user_id Customer ID.
		 * @param string                         $type    Selector context.
		 */
		$this->checkout_options_cache[ $user_id ][ $type ] = array_values(
			(array) apply_filters(
				'unwan_checkout_address_options',
				$options,
				$user_id,
				$type
			)
		);

		return $this->checkout_options_cache[ $user_id ][ $type ];
	}

	/**
	 * Validate a checkout option ID against the combined book.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $type    Selector context.
	 * @param string $id      Submitted option ID.
	 * @return bool
	 */
	public function checkout_option_exists( int $user_id, string $type, string $id ): bool {
		foreach ( $this->get_checkout_options( $user_id, $type ) as $option ) {
			if ( $id === $option['id'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Format an address as plain text.
	 *
	 * @param array<string,mixed> $fields Address fields.
	 * @return string
	 */
	public function format_address_text( array $fields ): string {
		$formatted = WC()->countries->get_formatted_address(
			array_intersect_key(
				$this->sanitize_fields( $fields ),
				array_flip(
					array(
						'company',
						'address_1',
						'address_2',
						'city',
						'state',
						'postcode',
						'country',
					)
				)
			)
		);

		return trim(
			wp_strip_all_tags(
				(string) preg_replace( '#<br\s*/?>#i', ', ', $formatted )
			)
		);
	}

	/**
	 * Format the primary street line used by address summaries.
	 *
	 * @param array<string,mixed> $fields Address fields.
	 * @return string
	 */
	public function format_street_text( array $fields ): string {
		$fields = $this->sanitize_fields( $fields );

		return implode(
			', ',
			array_filter(
				array(
					$fields['address_1'] ?? '',
					$fields['address_2'] ?? '',
				)
			)
		);
	}

	/**
	 * Format the non-street location displayed after the recipient.
	 *
	 * @param array<string,mixed> $fields Address fields.
	 * @return string
	 */
	public function format_location_text( array $fields ): string {
		$fields       = $this->sanitize_fields( $fields );
		$country_code = (string) ( $fields['country'] ?? '' );
		$state_code   = (string) ( $fields['state'] ?? '' );
		$countries    = WC()->countries->get_countries();
		$states       = WC()->countries->get_states( $country_code );
		$country      = (string) ( $countries[ $country_code ] ?? $country_code );
		$state        = is_array( $states )
			? (string) ( $states[ $state_code ] ?? $state_code )
			: $state_code;
		$region       = trim(
			implode(
				' ',
				array_filter(
					array(
						$state,
						$fields['postcode'] ?? '',
					)
				)
			)
		);

		return implode(
			', ',
			array_filter(
				array(
					$fields['city'] ?? '',
					$region,
					$country,
				)
			)
		);
	}

	/**
	 * Sanitize address fields.
	 *
	 * @param array<string,mixed> $fields Raw address fields.
	 * @return array<string,string>
	 */
	public function sanitize_fields( array $fields ): array {
		$clean = array();

		foreach ( $this->get_field_keys() as $key ) {
			$value = $fields[ $key ] ?? '';
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = '';
			}

			$value = sanitize_text_field( (string) $value );

			if ( 'country' === $key ) {
				$value = strtoupper( substr( $value, 0, 2 ) );
			} elseif ( 'postcode' === $key && ! empty( $fields['country'] ) ) {
				$value = wc_format_postcode( $value, (string) $fields['country'] );
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Build the normalized identity used to reject duplicates.
	 *
	 * @param array<string,mixed> $fields Address fields.
	 * @return string Empty when no street address is present.
	 */
	private function duplicate_signature( array $fields ): string {
		$fields  = $this->sanitize_fields( $fields );
		$address = $this->normalize_duplicate_part( $fields['address_1'] ?? '' );

		if ( '' === $address ) {
			return '';
		}

		return implode(
			'|',
			array(
				$this->normalize_duplicate_part( $fields['first_name'] ?? '' ),
				$this->normalize_duplicate_part( $fields['last_name'] ?? '' ),
				$address,
			)
		);
	}

	/**
	 * Normalize a duplicate-comparison value.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function normalize_duplicate_part( string $value ): string {
		$value = remove_accents( sanitize_text_field( $value ) );
		$value = (string) preg_replace( '/\s+/', ' ', trim( $value ) );

		return function_exists( 'mb_strtolower' )
			? mb_strtolower( $value, 'UTF-8' )
			: strtolower( $value );
	}

	/**
	 * Remove one additional record by ID.
	 *
	 * @param int    $user_id Customer ID.
	 * @param string $id      Address ID.
	 * @return void
	 */
	private function remove_saved_id( int $user_id, string $id ): void {
		$records = $this->get_saved( $user_id );
		if ( ! isset( $records[ $id ] ) ) {
			return;
		}

		unset( $records[ $id ] );
		$this->save_records( $user_id, $records );
	}

	/**
	 * Remove an additional record that duplicates a newly assigned default.
	 *
	 * @param int                 $user_id Customer ID.
	 * @param array<string,mixed> $fields  Default fields.
	 * @return void
	 */
	private function remove_saved_duplicate( int $user_id, array $fields ): void {
		$signature = $this->duplicate_signature( $fields );
		if ( '' === $signature ) {
			return;
		}

		$records = $this->get_saved( $user_id );
		$changed = false;

		foreach ( $records as $id => $record ) {
			if ( $signature === $this->duplicate_signature( (array) $record['fields'] ) ) {
				unset( $records[ $id ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			$this->save_records( $user_id, $records );
		}
	}

	/**
	 * Assign an address to a default role without losing the displaced default.
	 *
	 * Once the profile slot has been updated, the former default is added to
	 * the shared collection only when it is no longer represented by the other
	 * default role or an existing shared record.
	 *
	 * @param int                 $user_id Customer ID.
	 * @param string              $type    Billing or shipping.
	 * @param array<string,mixed> $fields  New default fields.
	 * @return void
	 */
	private function assign_primary( int $user_id, string $type, array $fields ): void {
		$type      = $this->normalize_type( $type );
		$fields    = $this->sanitize_fields( $fields );
		$displaced = $this->get_persisted_primary( $user_id, $type );

		$this->save_primary( $user_id, $type, $fields );

		if (
			! $this->has_address( $displaced )
			|| $this->duplicate_signature( $displaced ) === $this->duplicate_signature( $fields )
		) {
			return;
		}

		$this->preserve_as_extra( $user_id, $displaced );
	}

	/**
	 * Preserve a displaced default as a shared extra without applying the
	 * new-address limit.
	 *
	 * A default reassignment is a swap of existing address-book entries, so it
	 * must remain lossless even when the configured extra-address limit is
	 * already full.
	 *
	 * @param int                 $user_id Customer ID.
	 * @param array<string,mixed> $fields  Displaced default fields.
	 * @return string Existing or newly created address ID.
	 */
	private function preserve_as_extra( int $user_id, array $fields ): string {
		$fields       = $this->sanitize_fields( $fields );
		$duplicate_id = $this->find_duplicate( $user_id, $fields );

		if ( '' !== $duplicate_id ) {
			return $duplicate_id;
		}

		$records = $this->get_saved( $user_id );
		$id      = $this->new_id( $records );
		$now     = gmdate( 'c' );

		$records[ $id ] = array(
			'id'         => $id,
			'fields'     => $fields,
			'created_at' => $now,
			'updated_at' => $now,
		);

		$this->save_records( $user_id, $records );

		return $id;
	}

	/**
	 * Persist normalized shared records.
	 *
	 * @param int                               $user_id Customer ID.
	 * @param array<string,array<string,mixed>> $records Address records.
	 * @return void
	 */
	private function save_records( int $user_id, array $records ): void {
		update_user_meta( $user_id, self::META_KEY, $records );
		$this->invalidate_user_cache( $user_id );

		/**
		 * Fires after shared additional addresses have been saved.
		 *
		 * @param int   $user_id Customer ID.
		 * @param array $records Saved records.
		 */
		do_action( 'unwan_addresses_saved', $user_id, $records );
	}

	/**
	 * Invalidate derived request caches after an address mutation.
	 *
	 * @param int $user_id Customer ID.
	 * @return void
	 */
	private function invalidate_user_cache( int $user_id ): void {
		unset(
			$this->saved_cache[ $user_id ],
			$this->primary_cache[ $user_id ],
			$this->address_book_cache[ $user_id ],
			$this->checkout_options_cache[ $user_id ]
		);
	}

	/**
	 * Generate a collision-resistant shared address ID.
	 *
	 * @param array<string,array<string,mixed>> $records Existing records.
	 * @return string
	 */
	private function new_id( array $records ): string {
		do {
			$id = 'unwan_a_' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 16 );
		} while ( isset( $records[ $id ] ) );

		return $id;
	}

	/**
	 * Normalize an address ID.
	 *
	 * @param string $id Address ID.
	 * @return string
	 */
	private function sanitize_id( string $id ): string {
		$id = sanitize_key( $id );

		return preg_match( '/^[a-z0-9_-]{1,64}$/', $id ) ? $id : '';
	}
}
