<?php
/**
 * My Account rendering: structure, escaping, and search visibility.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\AccountController;
use Unwan\AddressLibrary\Admin\Settings;

/**
 * Renders the address-book endpoint through the real template stack, which is
 * also where a WordPress deprecation would surface first.
 */
class AccountRenderingTest extends UnwanTestCase {

	/**
	 * System under test.
	 *
	 * @var AccountController
	 */
	private $controller;

	/**
	 * Sign the customer in and build the controller.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( $this->user_id );

		$this->controller = new AccountController( $this->repository, new Settings() );
	}

	/**
	 * Reset request state between tests.
	 */
	public function tear_down(): void {
		unset( $_GET['unwan_edit'], $_GET['unwan_role'] );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Capture the rendered endpoint.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		$this->controller->render_endpoint();

		return (string) ob_get_clean();
	}

	/**
	 * A signed-out visitor gets nothing at all.
	 */
	public function test_nothing_renders_for_a_signed_out_visitor(): void {
		wp_set_current_user( 0 );

		$this->assertSame( '', $this->render() );
	}

	/**
	 * An empty book renders the empty state, not a list of addresses.
	 */
	public function test_an_empty_book_renders_the_empty_state(): void {
		$output = $this->render();

		$this->assertStringContainsString( 'unwan-empty-state', $output );
		$this->assertStringNotContainsString( 'unwan-address-item"', $output );
	}

	/**
	 * The listing renders the documented stable roots.
	 */
	public function test_the_listing_renders_the_stable_roots(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$output = $this->render();

		$this->assertStringContainsString( 'id="unwan-account-ui"', $output );
		$this->assertStringContainsString( 'id="unwan-account-list"', $output );
		$this->assertStringContainsString( 'unwan-address-item', $output );
		$this->assertStringContainsString( 'data-unwan-ui', $output );
	}

	/**
	 * Listing and editor views are mutually exclusive.
	 */
	public function test_the_editor_replaces_the_listing(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$_GET['unwan_edit'] = 'new';

		$output = $this->render();

		$this->assertStringContainsString( 'id="unwan-account-ui"', $output );
		$this->assertStringNotContainsString( 'id="unwan-account-list"', $output );
	}

	/**
	 * Editing an existing entry pre-fills the form with its values.
	 */
	public function test_editing_an_entry_pre_fills_the_form(): void {
		$id = $this->repository->create( $this->user_id, $this->address() );

		$_GET['unwan_edit'] = $id;

		$output = $this->render();

		$this->assertStringContainsString( '12 Maple Street', $output );
		$this->assertStringNotContainsString( 'id="unwan-account-list"', $output );
	}

	/**
	 * A stranger's address ID never opens an editor for it.
	 */
	public function test_another_customers_address_does_not_open_in_the_editor(): void {
		$other    = self::factory()->user->create( array( 'role' => 'customer' ) );
		$other_id = $this->repository->create( $other, $this->other_address() );

		$_GET['unwan_edit'] = $other_id;

		$output = $this->render();

		$this->assertStringNotContainsString( '990 Oak Avenue', $output );
		$this->assertStringContainsString(
			'id="unwan-account-list"',
			$output,
			'The request falls back to the listing'
		);
	}

	/**
	 * Search appears only once the shared threshold is exceeded.
	 */
	public function test_search_appears_only_above_the_threshold(): void {
		update_option( 'unwan_address_search_threshold', 2 );

		$this->repository->create( $this->user_id, $this->address( array( 'address_1' => '1 Maple Street' ) ) );
		$this->repository->create( $this->user_id, $this->address( array( 'address_1' => '2 Maple Street' ) ) );

		$this->assertStringNotContainsString(
			'unwan-address-search',
			$this->render(),
			'Two addresses do not exceed a threshold of two'
		);

		$this->repository->create( $this->user_id, $this->address( array( 'address_1' => '3 Maple Street' ) ) );

		$this->assertStringContainsString( 'unwan-address-search', $this->render() );
	}

	/**
	 * The delete action is disabled while an address holds a default role.
	 */
	public function test_delete_is_disabled_for_an_address_holding_a_role(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );

		$this->assertStringContainsString( 'disabled', $this->render() );
	}

	/**
	 * Every mutating form on the page carries a nonce.
	 */
	public function test_every_form_carries_a_nonce(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->create( $this->user_id, $this->other_address() );

		$output = $this->render();

		$forms = preg_match_all( '/<form\b[^>]*method=["\']post["\']/i', $output );
		$this->assertGreaterThan( 0, $forms, 'The page has POST forms' );

		$nonces = preg_match_all( '/name=["\']unwan_nonce["\']/', $output );
		$this->assertGreaterThanOrEqual( $forms, $nonces, 'Every POST form has a nonce field' );
	}

	/**
	 * Stored address values are escaped on output.
	 */
	public function test_stored_address_values_are_escaped(): void {
		$this->repository->create(
			$this->user_id,
			$this->address(
				array(
					'first_name' => '<script>alert(1)</script>',
					'address_1'  => '9 "Quote" Street',
					'address_2'  => "O'Brien & Sons",
					'city'       => '<img src=x onerror=alert(1)>',
				)
			)
		);

		$output = $this->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
		$this->assertStringNotContainsString( '<img src=x', $output );
		$this->assertStringNotContainsString( 'onerror=', $output );
		$this->assertStringContainsString( '&amp;', $output, 'Ampersands are entity-encoded' );
	}

	/**
	 * Merchant label copy is escaped on output too.
	 */
	public function test_merchant_label_copy_is_escaped(): void {
		update_option( 'unwan_label_add_address', '<script>alert(1)</script>' );

		$output = $this->render();

		$this->assertStringNotContainsString( '<script>', $output );
	}

	/**
	 * The account form drops the email field, which is account identity.
	 */
	public function test_the_form_omits_the_email_field(): void {
		$fields = $this->controller->get_form_fields( 'US' );

		$this->assertArrayNotHasKey( 'billing_email', $fields );
		$this->assertArrayHasKey( 'billing_address_1', $fields );
		$this->assertArrayHasKey( 'billing_country', $fields );
	}

	/**
	 * Endpoint URLs carry only sanitized query arguments.
	 */
	public function test_endpoint_urls_carry_sanitized_arguments(): void {
		$this->assertStringNotContainsString( 'unwan_edit', $this->controller->get_url() );
		$this->assertStringContainsString( 'unwan_edit=new', $this->controller->get_url( 'new' ) );
		$this->assertStringContainsString( 'unwan_role=billing', $this->controller->get_url( 'new', 'billing' ) );
		$this->assertStringNotContainsString(
			'unwan_role',
			$this->controller->get_url( 'new', 'gift' ),
			'An unsupported role is dropped rather than reflected'
		);
	}

	/**
	 * The endpoint replaces WooCommerce's own Addresses item in place.
	 */
	public function test_the_endpoint_replaces_the_woocommerce_addresses_item(): void {
		$items = $this->controller->add_menu_item(
			array(
				'dashboard'       => 'Dashboard',
				'edit-address'    => 'Addresses',
				'customer-logout' => 'Log out',
			)
		);

		$this->assertSame(
			array( 'dashboard', AccountController::ENDPOINT, 'customer-logout' ),
			array_keys( $items ),
			'Address book takes the old item\'s position; nothing else moves'
		);
		$this->assertArrayNotHasKey( 'edit-address', $items );
	}

	/**
	 * With no Addresses item to replace, the endpoint is appended.
	 */
	public function test_the_endpoint_is_appended_when_there_is_nothing_to_replace(): void {
		$items = $this->controller->add_menu_item(
			array(
				'dashboard'       => 'Dashboard',
				'customer-logout' => 'Log out',
			)
		);

		$this->assertSame(
			array( 'dashboard', 'customer-logout', AccountController::ENDPOINT ),
			array_keys( $items )
		);
	}
}
