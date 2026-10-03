<?php
/**
 * WooCommerce Checkout Blocks registration and Store API handling.
 *
 * @package Unwan
 */

namespace Unwan\AddressLibrary\Checkout;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use Unwan\AddressLibrary\AddressRepository;
use Unwan\AddressLibrary\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers forced checkout inner blocks and handles their submitted extension
 * data without logging or leaking customer addresses.
 */
final class BlocksController {

	/**
	 * Store API extension namespace.
	 */
	private const NAMESPACE = 'unwan';

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
	 * Persisted defaults captured before WooCommerce saves checkout addresses.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $pending_primary_restores = array();

	/**
	 * Customer whose defaults are pending restoration.
	 *
	 * @var int
	 */
	private $pending_customer_id = 0;

	/**
	 * Address types explicitly submitted as "Use a new address".
	 *
	 * @var array<string,bool>
	 */
	private $pending_new_address_types = array();

	/**
	 * Final order addresses waiting to be added to the address book.
	 *
	 * @var array<string,array<string,string>>
	 */
	private $pending_new_addresses = array();

	/**
	 * Customer whose new order addresses are pending persistence.
	 *
	 * @var int
	 */
	private $pending_new_customer_id = 0;

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
	 * Register block and Store API hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block_types' ) );

		// WooCommerce may have completed its Blocks bootstrap before this plugin
		// boots on plugins_loaded. Register immediately in that case.
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_store_api_extension();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_extension' ) );
		}

		add_action(
			'woocommerce_blocks_checkout_block_registration',
			array( $this, 'register_blocks_integration' )
		);
		add_action(
			'woocommerce_store_api_checkout_update_customer_from_request',
			array( $this, 'handle_customer_update' ),
			20,
			2
		);
		add_action(
			'woocommerce_store_api_checkout_order_processed',
			array( $this, 'capture_processed_order_addresses' ),
			20,
			1
		);
		add_filter(
			'rest_request_after_callbacks',
			array( $this, 'restore_customer_defaults_after_request' ),
			20
		);
		add_action(
			'shutdown',
			array( $this, 'restore_customer_defaults_at_shutdown' ),
			PHP_INT_MAX
		);
	}

	/**
	 * Register block metadata for the editor and block parser.
	 *
	 * @return void
	 */
	public function register_block_types(): void {
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$path = UNWAN_PATH . "build/blocks/{$type}/block.json";
			if ( file_exists( $path ) ) {
				register_block_type( $path );
			}
		}
	}

	/**
	 * Register scripts/data through the Checkout integration registry.
	 *
	 * @param \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry $registry Integration registry.
	 * @return void
	 */
	public function register_blocks_integration( $registry ): void {
		$registry->register( new BlocksIntegration( $this->repository, $this->settings ) );
	}

	/**
	 * Register writable extension data on the Checkout endpoint.
	 *
	 * @return void
	 */
	public function register_store_api_extension(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => self::NAMESPACE,
				'data_callback'   => array( $this, 'extension_data' ),
				'schema_callback' => array( $this, 'extension_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * Default extension values.
	 *
	 * @return array<string,mixed>
	 */
	public function extension_data(): array {
		return array(
			'billing_selection'  => '',
			'shipping_selection' => '',
		);
	}

	/**
	 * Writable extension schema sent with checkout.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function extension_schema(): array {
		return array(
			'billing_selection'  => array(
				'description' => __( 'Selected billing address-book entry.', 'unwan-for-woocommerce' ),
				'type'        => array( 'string', 'null' ),
				'readonly'    => false,
			),
			'shipping_selection' => array(
				'description' => __( 'Selected shipping address-book entry.', 'unwan-for-woocommerce' ),
				'type'        => array( 'string', 'null' ),
				'readonly'    => false,
			),
		);
	}

	/**
	 * Capture defaults before WooCommerce saves order-specific checkout data.
	 *
	 * WooCommerce must keep the submitted address on its in-memory customer
	 * until the draft order has copied it. Restoring here would put the default
	 * on the order itself, so restoration happens after the complete Store API
	 * request, including WooCommerce's final customer synchronization.
	 *
	 * @param \WC_Customer     $customer Customer being updated.
	 * @param \WP_REST_Request $request Checkout request.
	 * @return void
	 */
	public function handle_customer_update( \WC_Customer $customer, \WP_REST_Request $request ): void {
		$this->capture_new_address_selections( $customer, $request );
		$this->capture_customer_defaults( $customer );
	}

	/**
	 * Remember which checkout selectors explicitly chose a new address.
	 *
	 * The actual address is copied from the processed order later, ensuring
	 * only complete, validated checkout data is added to the address book.
	 *
	 * @param \WC_Customer     $customer Customer being updated.
	 * @param \WP_REST_Request $request  Checkout request.
	 * @return void
	 */
	private function capture_new_address_selections( \WC_Customer $customer, \WP_REST_Request $request ): void {
		if (
			$customer->get_id() <= 0
			|| ! $this->settings->should_save_checkout_addresses()
		) {
			return;
		}

		$extensions = (array) $request->get_param( 'extensions' );
		$extension  = isset( $extensions[ self::NAMESPACE ] )
			? (array) $extensions[ self::NAMESPACE ]
			: array();

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if (
				$this->settings->is_enabled( $type )
				&& 'new' === (string) ( $extension[ "{$type}_selection" ] ?? '' )
			) {
				$this->pending_new_address_types[ $type ] = true;
			}
		}
	}

	/**
	 * Capture finalized addresses after Store API validation created the order.
	 *
	 * @param \WC_Order $order Processed checkout order.
	 * @return void
	 */
	public function capture_processed_order_addresses( \WC_Order $order ): void {
		$user_id = $order->get_customer_id();
		if ( $user_id <= 0 || empty( $this->pending_new_address_types ) ) {
			return;
		}

		$this->pending_new_customer_id = $user_id;

		foreach ( array_keys( $this->pending_new_address_types ) as $type ) {
			// Local pickup and carts that need no shipping send the billing
			// address as the shipping address. A shipping selection left over
			// from before the customer switched must not save it as one.
			if ( 'shipping' === $type && ! $order->needs_shipping_address() ) {
				continue;
			}

			$address = $this->repository->sanitize_fields( (array) $order->get_address( $type ) );

			if ( $this->repository->has_address( $address ) ) {
				$this->pending_new_addresses[ $type ] = $address;
			}
		}

		$this->pending_new_address_types = array();
	}

	/**
	 * Snapshot the persisted defaults before WooCommerce syncs the order into
	 * the customer profile.
	 *
	 * Every default is captured, whatever the request contains: WooCommerce
	 * also writes the profile for carts that need no shipping (copying the
	 * billing address into shipping) and for orders placed without Unwan's
	 * interface. The restore only writes back what actually changed. In
	 * update mode the old default is restored first and the new address is
	 * then promoted through make_primary(), which keeps the old one in the
	 * book.
	 *
	 * @param \WC_Customer $customer Customer being updated.
	 * @return void
	 */
	private function capture_customer_defaults( \WC_Customer $customer ): void {
		$user_id = $customer->get_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$this->pending_customer_id = $user_id;

		foreach ( array( 'billing', 'shipping' ) as $type ) {
			if ( ! $this->settings->is_enabled( $type ) ) {
				continue;
			}

			$snapshot = $this->repository->snapshot_primary( $user_id, $type );

			if ( $this->repository->has_address( $snapshot ) ) {
				$this->pending_primary_restores[ $type ] = $snapshot;
			}
		}
	}

	/**
	 * Restore defaults after checkout success, validation failure, or payment
	 * failure, once WooCommerce has finished all customer synchronization.
	 *
	 * @param mixed $response REST response.
	 * @return mixed
	 */
	public function restore_customer_defaults_after_request( $response ) {
		$this->restore_pending_customer_defaults();
		$this->save_pending_new_addresses();

		return $response;
	}

	/**
	 * Last-resort restoration after WooCommerce and WordPress have saved their
	 * request/session state.
	 *
	 * @return void
	 */
	public function restore_customer_defaults_at_shutdown(): void {
		$this->restore_pending_customer_defaults();
		$this->save_pending_new_addresses();
	}

	/**
	 * Apply captured defaults to the active customer and persist them.
	 *
	 * @param int $user_id Expected customer ID, when known.
	 * @return void
	 */
	private function restore_pending_customer_defaults( int $user_id = 0 ): void {
		if ( empty( $this->pending_primary_restores ) || $this->pending_customer_id <= 0 ) {
			return;
		}

		if ( $user_id > 0 && $user_id !== $this->pending_customer_id ) {
			return;
		}

		// The repository writes a separate DB-backed customer, so the Store API
		// session keeps the order-specific address while only the account
		// default is restored, and only where WooCommerce changed it.
		$this->repository->restore_primaries( $this->pending_customer_id, $this->pending_primary_restores );

		$this->pending_primary_restores = array();
		$this->pending_customer_id      = 0;
	}

	/**
	 * Add processed checkout addresses after account defaults are restored.
	 *
	 * AddressRepository::create() is idempotent and skips duplicates across
	 * both WooCommerce defaults and the shared additional collection.
	 *
	 * @return void
	 */
	private function save_pending_new_addresses(): void {
		if ( $this->pending_new_customer_id <= 0 || empty( $this->pending_new_addresses ) ) {
			return;
		}

		foreach ( $this->pending_new_addresses as $type => $address ) {
			$id = $this->repository->create(
				$this->pending_new_customer_id,
				$address
			);

			if ( ! $this->settings->should_update_checkout_default() ) {
				continue;
			}

			if ( is_wp_error( $id ) ) {
				// The extra-address limit is full. Promoting is still a swap, so
				// the displaced default is kept and the new address becomes it.
				$this->repository->promote_address( $this->pending_new_customer_id, (string) $type, $address );
			} else {
				$this->repository->make_primary(
					$this->pending_new_customer_id,
					(string) $type,
					(string) $id
				);
			}
		}

		$this->pending_new_addresses     = array();
		$this->pending_new_customer_id   = 0;
		$this->pending_new_address_types = array();
	}
}
