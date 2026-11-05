<?php
/**
 * Saved-address dropdown on the admin order screen.
 *
 * @package Unwan
 */

namespace Unwan\AddressLibrary\Admin;

use Unwan\AddressLibrary\AddressRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Lets staff fill an order's billing or shipping address from the customer's
 * address book while creating or editing an order.
 *
 * Choosing an address only fills the order's fields. Nothing is saved to the
 * address book and the customer's billing and shipping defaults never change:
 * WooCommerce's own order screen does not write the order's addresses to the
 * customer, and Unwan adds no hook to the order save.
 */
final class OrderAddresses {

	/**
	 * Script handle.
	 */
	private const HANDLE = 'unwan-admin-order';

	/**
	 * Address repository.
	 *
	 * @var AddressRepository
	 */
	private $repository;

	/**
	 * Runtime settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param AddressRepository $repository Address repository.
	 * @param Settings          $settings   Runtime settings.
	 */
	public function __construct( AddressRepository $repository, Settings $settings ) {
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// WooCommerce's customer lookup on the order screen already checks its
		// nonce and the edit_shop_orders capability before this filter runs.
		add_filter( 'woocommerce_ajax_get_customer_details', array( $this, 'add_customer_addresses' ), 10, 3 );
		// Runs only while the order data meta box renders, on both order
		// storage screens, and passes the order being edited.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the customer's address book to WooCommerce's customer lookup.
	 *
	 * @param mixed        $data     Customer data sent to the order screen.
	 * @param \WC_Customer $customer Customer.
	 * @param int          $user_id  Customer ID.
	 * @return mixed
	 */
	public function add_customer_addresses( $data, $customer = null, $user_id = 0 ) {
		if ( ! is_array( $data ) || ! $this->settings->is_admin_order_dropdown_enabled() ) {
			return $data;
		}

		$data['unwan_addresses'] = $this->get_addresses( absint( $user_id ) );

		return $data;
	}

	/**
	 * Load the order-screen script with the order customer's address book.
	 *
	 * @param mixed $order Order being edited.
	 * @return void
	 */
	public function enqueue_assets( $order ): void {
		if ( ! current_user_can( 'edit_shop_orders' ) || ! $this->settings->is_admin_order_dropdown_enabled() ) {
			return;
		}

		$user_id = $order instanceof \WC_Order ? $order->get_customer_id() : 0;
		$script  = UNWAN_PATH . 'assets/js/unwan-admin-order.js';
		$version = UNWAN_VERSION;
		if ( file_exists( $script ) ) {
			$version .= '-' . (string) filemtime( $script );
		}

		wp_enqueue_script(
			self::HANDLE,
			UNWAN_URL . 'assets/js/unwan-admin-order.js',
			array( 'jquery', 'wc-admin-order-meta-boxes' ),
			$version,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'unwanAdminOrder',
			array(
				'addresses' => $this->get_addresses( $user_id ),
				'labels'    => array(
					'savedAddresses' => __( 'Saved addresses', 'unwan-for-woocommerce' ),
					'choose'         => __( 'Choose a saved address…', 'unwan-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * The customer's address book, per address type, as dropdown options.
	 *
	 * Each type lists its own default first, the same order checkout uses.
	 * Addresses in countries the store does not sell to (billing) or ship to
	 * (shipping) are left out: WooCommerce's country field could not take
	 * them, so choosing one would fill everything but the country.
	 * An entry that holds a default role uses that role's own stored fields:
	 * one entry stands for both defaults when they share a name, street and
	 * postcode, yet they can still differ, for example in the apartment.
	 *
	 * @param int $user_id Customer ID.
	 * @return array{billing:array<int,array<string,mixed>>,shipping:array<int,array<string,mixed>>}
	 */
	public function get_addresses( int $user_id ): array {
		$addresses = array(
			'billing'  => array(),
			'shipping' => array(),
		);

		if ( ! $this->is_visible_customer( $user_id ) ) {
			return $addresses;
		}

		foreach ( array_keys( $addresses ) as $type ) {
			$countries = $this->get_allowed_countries( $type );

			foreach ( $this->repository->get_checkout_options( $user_id, $type ) as $option ) {
				$fields = (array) ( $option['fields'] ?? array() );
				$label  = (string) ( $option['selectLabel'] ?? '' );

				if ( in_array( $type, (array) ( $option['defaultRoles'] ?? array() ), true ) ) {
					$fields = $this->repository->get_primary( $user_id, $type );
					$label  = sprintf(
						/* translators: 1: recipient name, 2: formatted postal address. */
						__( '%1$s (default) — %2$s', 'unwan-for-woocommerce' ),
						$this->repository->get_recipient_name( $fields ),
						$this->repository->format_address_text( $fields )
					);
				}

				$country = (string) ( $fields['country'] ?? '' );
				if ( null !== $countries && '' !== $country && ! isset( $countries[ $country ] ) ) {
					continue;
				}

				$addresses[ $type ][] = array(
					'id'     => (string) ( $option['id'] ?? '' ),
					// WooCommerce's address formatter escapes HTML, so labels
					// arrive as entities ("O&#039;Connell"); the script writes
					// them as plain text.
					'label'  => html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ),
					'fields' => $fields,
				);
			}
		}

		return $addresses;
	}

	/**
	 * Countries the store accepts for an address type.
	 *
	 * @param string $type Billing or shipping.
	 * @return array<string,string>|null Country names by code, or null when unknown.
	 */
	private function get_allowed_countries( string $type ): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return null;
		}

		return 'shipping' === $type
			? (array) WC()->countries->get_shipping_countries()
			: (array) WC()->countries->get_allowed_countries();
	}

	/**
	 * Whether the current user may see this customer's details.
	 *
	 * The same rule WooCommerce applies on the order screen: on multisite, a
	 * customer who is not a member of this site is hidden unless the viewer
	 * manages network users or customers are shared network-wide.
	 *
	 * @param int $user_id Customer ID.
	 * @return bool
	 */
	private function is_visible_customer( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return false;
		}

		return ! (
			is_multisite()
			&& ! is_user_member_of_blog( $user_id )
			&& ! current_user_can( 'manage_network_users' )
			&& 'yes' !== get_site_option( 'woocommerce_network_wide_customers', 'no' )
		);
	}
}
