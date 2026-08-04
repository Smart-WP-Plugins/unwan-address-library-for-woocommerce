<?php
/**
 * Classic checkout integration.
 *
 * @package Unwan
 */

namespace Unwan\AddressLibrary\Checkout;

use Unwan\AddressLibrary\AddressRepository;
use Unwan\AddressLibrary\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds address selectors to the shortcode checkout without coupling account
 * storage to WooCommerce's checkout field internals.
 */
final class ClassicCheckout {

	/**
	 * Address repository.
	 *
	 * @var AddressRepository
	 */
	private $repository;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param AddressRepository $repository Address repository.
	 * @param Settings          $settings   Plugin settings.
	 */
	public function __construct( AddressRepository $repository, Settings $settings ) {
		$this->repository = $repository;
		$this->settings   = $settings;
	}

	/**
	 * Register classic checkout hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'add_fields' ), 20 );
		add_action( 'woocommerce_before_checkout_billing_form', array( $this, 'render_billing_selector' ) );
		add_action( 'woocommerce_before_checkout_shipping_form', array( $this, 'render_shipping_selector' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_selection' ), 10, 2 );
		add_filter( 'woocommerce_checkout_update_customer_data', array( $this, 'control_customer_update' ), 20 );
		add_action( 'woocommerce_checkout_update_user_meta', array( $this, 'save_checkout_choices' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the hidden selection fields posted by the custom checkout interface.
	 *
	 * @param array<string,array> $fields Checkout fields.
	 * @return array<string,array>
	 */
	public function add_fields( array $fields ): array {
		if ( ! is_user_logged_in() ) {
			return $fields;
		}

		$user_id = get_current_user_id();

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! $this->settings->is_enabled( $type ) || ! isset( $fields[ $type ] ) ) {
				continue;
			}

			$checkout_data = $this->repository->get_checkout_options( $user_id, $type );
			if ( empty( $checkout_data ) ) {
				continue;
			}

			$address_ids = wp_list_pluck( $checkout_data, 'id' );
			$default     = (string) reset( $address_ids );

			$fields[ $type ][ "unwan_{$type}_address_id" ] = array(
				'type'     => 'hidden',
				'default'  => $default,
				'required' => false,
				'class'    => array( 'unwan-checkout__selection-field' ),
				'priority' => 1,
			);
		}

		return $fields;
	}

	/**
	 * Render the billing selector mount point.
	 *
	 * @return void
	 */
	public function render_billing_selector(): void {
		$this->render_selector( 'billing' );
	}

	/**
	 * Render the shipping selector mount point.
	 *
	 * @return void
	 */
	public function render_shipping_selector(): void {
		$this->render_selector( 'shipping' );
	}

	/**
	 * Validate IDs against the current customer's repository.
	 *
	 * @param array<string,mixed> $data   Parsed checkout data.
	 * @param \WP_Error           $errors Checkout validation errors.
	 * @return void
	 */
	public function validate_selection( array $data, \WP_Error $errors ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! $this->settings->is_enabled( $type ) ) {
				continue;
			}

			if ( 'shipping' === $type && empty( $data['ship_to_different_address'] ) ) {
				continue;
			}

			$key       = "unwan_{$type}_address_id";
			$selection = sanitize_key( (string) ( $data[ $key ] ?? '' ) );

			if ( 'new' === $selection ) {
				continue;
			}

			if (
				'' === $selection
				|| ! $this->repository->checkout_option_exists( $user_id, $type, $selection )
			) {
				$errors->add(
					'unwan_invalid_address',
					__( 'Please choose a valid saved address.', 'unwan-for-woocommerce' )
				);
			}
		}
	}

	/**
	 * Prevent WooCommerce from overwriting defaults; this class persists only
	 * the address types the shopper explicitly selected as default.
	 *
	 * @param bool $should_update WooCommerce's original decision.
	 * @return bool
	 */
	public function control_customer_update( bool $should_update ): bool {
		if ( ! is_user_logged_in() ) {
			return $should_update;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Runs inside WooCommerce's own checkout submission, which WooCommerce has already nonce-verified by this point; this only reads which selector fields are present.
		if ( isset( $_POST['unwan_billing_address_id'] ) || isset( $_POST['unwan_shipping_address_id'] ) ) {
			return false;
		}

		return $should_update;
	}

	/**
	 * Persist explicit checkout choices after WooCommerce has created the order.
	 *
	 * @param int                 $user_id Customer ID.
	 * @param array<string,mixed> $data    Parsed checkout data.
	 * @return void
	 */
	public function save_checkout_choices( int $user_id, array $data ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		if ( ! $this->settings->should_save_checkout_addresses() ) {
			$this->sync_customer_name( $user_id, $data );
			return;
		}

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! $this->settings->is_enabled( $type ) ) {
				continue;
			}

			if ( 'shipping' === $type && empty( $data['ship_to_different_address'] ) ) {
				continue;
			}

			$selection = sanitize_key( (string) ( $data[ "unwan_{$type}_address_id" ] ?? '' ) );
			$fields    = $this->extract_address( $data, $type );

			if ( 'new' !== $selection ) {
				continue;
			}

			$id = $this->repository->create( $user_id, $fields );

			if ( $this->settings->should_update_checkout_default() && ! is_wp_error( $id ) ) {
				$this->repository->make_primary( $user_id, $type, (string) $id );
			}
		}

		$this->sync_customer_name( $user_id, $data );
	}

	/**
	 * Enqueue the classic checkout controller and its private address data.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! is_checkout() || ! is_user_logged_in() || $this->is_checkout_block_page() ) {
			return;
		}

		$user_id = get_current_user_id();
		$script  = UNWAN_PATH . 'assets/js/unwan-classic-checkout.js';
		$data    = array(
			'types'           => array(),
			'fieldKeys'       => $this->repository->get_field_keys(),
			'baseCountry'     => WC()->countries->get_base_country(),
			'searchThreshold' => $this->settings->get_address_search_threshold(),
			'labels'          => $this->settings->get_checkout_picker_labels(),
		);

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! $this->settings->is_enabled( $type ) ) {
				continue;
			}

			$addresses = $this->repository->get_checkout_options( $user_id, $type );

			$data['types'][ $type ] = array(
				'addresses' => $addresses,
				'canSave'   => $this->repository->can_add( $user_id ),
			);
		}

		/**
		 * Filter data exposed to the classic checkout picker.
		 *
		 * @param array<string,mixed> $data    Picker data.
		 * @param int                 $user_id Customer ID.
		 */
		$data = (array) apply_filters(
			'unwan_classic_checkout_picker_data',
			$data,
			$user_id
		);

		$picker_asset_path = UNWAN_PATH . 'build/unwan-address-picker.asset.php';
		$picker_script     = UNWAN_PATH . 'build/unwan-address-picker.js';
		$picker_asset      = file_exists( $picker_asset_path )
			? require $picker_asset_path
			: array(
				'dependencies' => array(),
				'version'      => UNWAN_VERSION,
			);
		$picker_version    = (string) ( $picker_asset['version'] ?? UNWAN_VERSION );
		if ( file_exists( $picker_script ) ) {
			$picker_version .= '-' . (string) filemtime( $picker_script );
		}

		wp_enqueue_script(
			'unwan-address-picker',
			UNWAN_URL . 'build/unwan-address-picker.js',
			(array) ( $picker_asset['dependencies'] ?? array() ),
			$picker_version,
			true
		);

		wp_enqueue_script(
			'unwan-classic-checkout',
			UNWAN_URL . 'assets/js/unwan-classic-checkout.js',
			array( 'jquery', 'wc-checkout', 'unwan-address-picker' ),
			UNWAN_VERSION . ( file_exists( $script ) ? '-' . (string) filemtime( $script ) : '' ),
			true
		);
		wp_localize_script( 'unwan-classic-checkout', 'unwanClassicCheckout', $data );
	}

	/**
	 * Render a selector only when the customer has saved addresses.
	 *
	 * @param string $type Billing or shipping.
	 * @return void
	 */
	private function render_selector( string $type ): void {
		if (
			! is_user_logged_in()
			|| ! $this->settings->is_enabled( $type )
			|| empty( $this->repository->get_checkout_options( get_current_user_id(), $type ) )
		) {
			return;
		}

		printf(
			'<div class="unwan-checkout__selector" data-unwan-address-type="%s"></div>',
			esc_attr( $type )
		);
	}

	/**
	 * Extract address values from parsed checkout data.
	 *
	 * @param array<string,mixed> $data Checkout data.
	 * @param string              $type Billing or shipping.
	 * @return array<string,string>
	 */
	private function extract_address( array $data, string $type ): array {
		$fields = array();
		foreach ( $this->repository->get_field_keys() as $field ) {
			$fields[ $field ] = $data[ "{$type}_{$field}" ] ?? '';
		}

		return $this->repository->sanitize_fields( $fields );
	}

	/**
	 * Populate the WordPress profile name when it is still empty.
	 *
	 * @param int                 $user_id Customer ID.
	 * @param array<string,mixed> $data    Checkout data.
	 * @return void
	 */
	private function sync_customer_name( int $user_id, array $data ): void {
		$customer = new \WC_Customer( $user_id );

		if ( '' === $customer->get_first_name() && ! empty( $data['billing_first_name'] ) ) {
			$customer->set_first_name( sanitize_text_field( (string) $data['billing_first_name'] ) );
		}
		if ( '' === $customer->get_last_name() && ! empty( $data['billing_last_name'] ) ) {
			$customer->set_last_name( sanitize_text_field( (string) $data['billing_last_name'] ) );
		}
		if ( is_email( $customer->get_display_name() ) ) {
			$customer->set_display_name( trim( $customer->get_first_name() . ' ' . $customer->get_last_name() ) );
		}

		$customer->save();
	}

	/**
	 * Detect the block checkout page.
	 *
	 * @return bool
	 */
	private function is_checkout_block_page(): bool {
		$page_id = get_queried_object_id();
		$page    = $page_id > 0 ? get_post( $page_id ) : null;

		return $page instanceof \WP_Post && has_block( 'woocommerce/checkout', $page );
	}
}
