<?php
/**
 * WordPress personal data export and erasure.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\Privacy;

/**
 * The shared additional addresses take part in WordPress's privacy tools.
 */
class PrivacyTest extends UnwanTestCase {

	/**
	 * Privacy service.
	 *
	 * @var Privacy
	 */
	private $privacy;

	/**
	 * Build the service.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->privacy = new Privacy( $this->repository );
	}

	/**
	 * The customer's email address.
	 *
	 * @return string
	 */
	private function email(): string {
		return get_userdata( $this->user_id )->user_email;
	}

	/**
	 * The plugin registers an exporter and an eraser.
	 */
	public function test_the_exporter_and_eraser_are_registered(): void {
		$this->assertArrayHasKey( 'unwan-address-book', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'unwan-address-book', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	/**
	 * Every additional address is exported with its values.
	 */
	public function test_additional_addresses_are_exported(): void {
		$this->repository->create( $this->user_id, $this->address() );
		$this->repository->create( $this->user_id, $this->other_address() );

		$export = $this->privacy->export( $this->email() );

		$this->assertTrue( $export['done'] );
		$this->assertCount( 2, $export['data'] );

		$values = wp_list_pluck( $export['data'][0]['data'], 'value' );
		$this->assertContains( '12 Maple Street', $values );
		$this->assertContains( 'Springfield', $values );
	}

	/**
	 * Erasing removes the additional addresses and leaves the WooCommerce
	 * defaults to WooCommerce's own eraser.
	 */
	public function test_erasure_removes_additional_addresses_only(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->create( $this->user_id, $this->other_address() );

		$result = $this->privacy->erase( $this->email() );

		$this->assertTrue( $result['items_removed'] );
		$this->assertSame( array(), $this->repository->get_saved( $this->user_id ) );
		$this->assertSameAddress( $this->address(), $this->repository->get_primary( $this->user_id, 'billing' ) );

		$this->assertFalse( $this->privacy->erase( $this->email() )['items_removed'], 'Nothing left to remove' );
	}

	/**
	 * An unknown email exports and erases nothing.
	 */
	public function test_an_unknown_email_returns_nothing(): void {
		$this->assertSame( array(), $this->privacy->export( 'nobody@example.invalid' )['data'] );
		$this->assertFalse( $this->privacy->erase( 'nobody@example.invalid' )['items_removed'] );
	}
}
