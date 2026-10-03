<?php
/**
 * Checkout Blocks: account defaults across a Store API checkout request.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\Admin\Settings;
use Unwan\AddressLibrary\Checkout\BlocksController;

/**
 * Drives BlocksController through the same steps WooCommerce takes during a
 * Store API checkout: the customer-update hook, WooCommerce syncing the order
 * into the profile, the processed-order hook, and the end of the request.
 */
class BlocksCheckoutDefaultsTest extends UnwanTestCase {

	/**
	 * Store API controller.
	 *
	 * @var BlocksController
	 */
	private $controller;

	/**
	 * Build the controller.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->controller = new BlocksController( $this->repository, new Settings() );
	}

	/**
	 * A third distinct address.
	 *
	 * @return array<string,string>
	 */
	private function third_address(): array {
		return $this->other_address(
			array(
				'first_name' => 'Katherine',
				'last_name'  => 'Johnson',
				'address_1'  => '7 Langley Road',
			)
		);
	}

	/**
	 * Run the customer-update hook for a checkout request.
	 *
	 * @param array<string,string> $extension Unwan extension data.
	 * @return void
	 */
	private function start_checkout( array $extension = array() ): void {
		$request = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_param( 'extensions', array( 'unwan' => $extension ) );

		$this->controller->handle_customer_update( new WC_Customer( $this->user_id ), $request );
	}

	/**
	 * What WooCommerce's sync_customer_data_with_order() does to the profile.
	 *
	 * @param string               $type   Billing or shipping.
	 * @param array<string,string> $fields Order address.
	 * @return void
	 */
	private function woocommerce_writes_default( string $type, array $fields ): void {
		$customer = new WC_Customer( $this->user_id );
		foreach ( $fields as $key => $value ) {
			$customer->{"set_{$type}_{$key}"}( $value );
		}
		$customer->save();
		$this->repository = new Unwan\AddressLibrary\AddressRepository();
	}

	/**
	 * Create a processed order with a shipping address and one shipping line.
	 *
	 * @param array<string,string> $shipping  Shipping address.
	 * @param string               $method_id Shipping method ID.
	 * @return WC_Order
	 */
	private function order( array $shipping, string $method_id ): WC_Order {
		$order = wc_create_order( array( 'customer_id' => $this->user_id ) );
		$order->set_address( $this->address(), 'billing' );
		$order->set_address( $shipping, 'shipping' );

		$item = new WC_Order_Item_Shipping();
		$item->set_method_id( $method_id );
		$order->add_item( $item );
		$order->save();

		return $order;
	}

	/**
	 * Finish the Store API request.
	 *
	 * @return void
	 */
	private function finish_request(): void {
		$this->controller->restore_customer_defaults_after_request( null );
		$this->repository = new Unwan\AddressLibrary\AddressRepository();
	}

	/**
	 * For a cart that needs no shipping, WooCommerce copies the billing address
	 * into the shipping default. The old shipping default must come back.
	 */
	public function test_a_virtual_order_keeps_the_shipping_default(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );

		$this->start_checkout();
		$this->woocommerce_writes_default( 'shipping', $this->address() );
		$this->finish_request();

		$this->assertSameAddress(
			$this->other_address(),
			$this->repository->get_primary( $this->user_id, 'shipping' )
		);
	}

	/**
	 * Restoring an unchanged profile must not save it: wp_update_user() bumps
	 * last_update, which makes WooCommerce discard the session's customer.
	 */
	public function test_an_unchanged_profile_is_not_saved(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );
		update_user_meta( $this->user_id, 'last_update', '1000' );

		$this->start_checkout();
		$this->finish_request();

		$this->assertSame( '1000', get_user_meta( $this->user_id, 'last_update', true ) );
	}

	/**
	 * Picking an address at checkout sends cart/update-customer, which only
	 * updates the session. The plugin's registered hooks must not save the
	 * profile for it, or WooCommerce discards the session's customer data and
	 * prices shipping and tax for the default address on the next request.
	 */
	public function test_a_cart_customer_update_never_saves_the_profile(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );
		update_user_meta( $this->user_id, 'last_update', '1000' );
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', '/wc/store/v1/cart/update-customer' );
		$request->set_param( 'billing_address', $this->third_address() );

		do_action( 'woocommerce_store_api_cart_update_customer_from_request', new WC_Customer( $this->user_id ), $request );
		apply_filters( 'rest_request_after_callbacks', new WP_REST_Response(), array(), $request );

		$this->assertSame( '1000', get_user_meta( $this->user_id, 'last_update', true ) );
		wp_set_current_user( 0 );
	}

	/**
	 * In update mode a new shipping address becomes the default and the one it
	 * replaces stays in the book, even though WooCommerce already wrote the new
	 * address into the profile.
	 */
	public function test_update_mode_keeps_the_displaced_default(): void {
		update_option( 'unwan_checkout_default_behavior', 'update' );

		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );

		$this->start_checkout( array( 'shipping_selection' => 'new' ) );
		$this->woocommerce_writes_default( 'shipping', $this->third_address() );
		$this->controller->capture_processed_order_addresses( $this->order( $this->third_address(), 'flat_rate' ) );
		$this->finish_request();

		$this->assertSameAddress(
			$this->third_address(),
			$this->repository->get_primary( $this->user_id, 'shipping' ),
			'The new address is the default'
		);

		$saved = array_values( $this->repository->get_saved( $this->user_id ) );
		$this->assertCount( 1, $saved );
		$this->assertSameAddress( $this->other_address(), $saved[0]['fields'], 'The old default is kept' );
	}

	/**
	 * A shipping selection left at "new" from before the customer switched to
	 * local pickup must not save the order's shipping address, which
	 * WooCommerce fills with the billing address for pickup.
	 */
	public function test_a_pickup_order_does_not_save_a_shipping_address(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );

		$this->start_checkout( array( 'shipping_selection' => 'new' ) );
		// The Blocks 'pickup_location' method is only registered when local
		// pickup is enabled; WooCommerce treats every method supporting
		// local-pickup the same way, and 'local_pickup' always does.
		$this->controller->capture_processed_order_addresses( $this->order( $this->third_address(), 'local_pickup' ) );
		$this->finish_request();

		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ) );
		$this->assertSameAddress(
			$this->other_address(),
			$this->repository->get_primary( $this->user_id, 'shipping' )
		);
	}

	/**
	 * A delivery order with "new" selected still saves its shipping address.
	 */
	public function test_a_delivery_order_saves_its_new_shipping_address(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$this->start_checkout( array( 'shipping_selection' => 'new' ) );
		$this->controller->capture_processed_order_addresses( $this->order( $this->third_address(), 'flat_rate' ) );
		$this->finish_request();

		$saved = array_values( $this->repository->get_saved( $this->user_id ) );
		$this->assertCount( 1, $saved );
		$this->assertSameAddress( $this->third_address(), $saved[0]['fields'] );
	}
}
