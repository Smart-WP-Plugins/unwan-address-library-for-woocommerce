<?php
/**
 * Uninstall cleanup: opt-in gating and the option inventory.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\Admin\Settings;

/**
 * Uninstall must stay opt-in, must cover every option the settings screen
 * writes, and must never touch WooCommerce's own profile fields.
 */
class UninstallTest extends UnwanTestCase {

	/**
	 * Load the uninstall routine's helpers once per process.
	 *
	 * The file guards on WP_UNINSTALL_PLUGIN and its top-level body is inert
	 * while the opt-in option is unset, so including it here is safe.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'unwan_uninstall_option_names' ) ) {
			$this->assertNotSame(
				'yes',
				get_option( 'unwan_remove_data_on_uninstall', 'no' ),
				'Guard: the opt-in must be off before including the uninstall routine'
			);

			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'unwan-for-woocommerce/unwan-for-woocommerce.php' );
			}

			require_once dirname( __DIR__, 2 ) . '/uninstall.php';
		}
	}

	/**
	 * Option IDs the settings screen actually persists.
	 *
	 * @return string[]
	 */
	private function settings_option_ids(): array {
		$ids = array();

		foreach ( ( new Settings() )->get_settings( array(), Settings::SECTION ) as $field ) {
			if ( in_array( $field['type'], array( 'title', 'sectionend' ), true ) ) {
				continue;
			}

			$ids[] = $field['id'];
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Every option the settings screen writes is removed on uninstall.
	 *
	 * This is the drift check: adding a setting without adding it to
	 * unwan_uninstall_option_names() leaves data behind after deletion.
	 */
	public function test_every_settings_option_is_covered_by_uninstall(): void {
		$missing = array_diff( $this->settings_option_ids(), unwan_uninstall_option_names() );

		$this->assertSame(
			array(),
			array_values( $missing ),
			'These settings are written but never cleaned up: ' . implode( ', ', $missing )
		);
	}

	/**
	 * The uninstall list carries no options that nothing owns.
	 */
	public function test_the_uninstall_list_has_no_stale_entries(): void {
		// unwan_plugin_version is written by activation rather than by the
		// settings screen, so it is expected in the list but not in the form.
		$expected = array_merge( $this->settings_option_ids(), array( 'unwan_plugin_version' ) );
		$stale    = array_diff( unwan_uninstall_option_names(), $expected );

		$this->assertSame(
			array(),
			array_values( $stale ),
			'These options are removed but no longer written: ' . implode( ', ', $stale )
		);
	}

	/**
	 * Every listed option is plugin-owned.
	 */
	public function test_every_removed_option_is_plugin_owned(): void {
		foreach ( unwan_uninstall_option_names() as $option ) {
			$this->assertStringStartsWith( 'unwan_', $option );
		}
	}

	/**
	 * Cleanup is off unless the merchant opted in.
	 */
	public function test_cleanup_is_skipped_unless_opted_in(): void {
		update_option( 'unwan_color_scheme', 'dark' );
		update_option( 'unwan_accent_color', '#123abc' );

		$this->assertFalse( unwan_uninstall_current_site(), 'Cleanup is opt-in' );
		$this->assertSame( 'dark', get_option( 'unwan_color_scheme' ) );
		$this->assertSame( '#123abc', get_option( 'unwan_accent_color' ) );
	}

	/**
	 * An explicit "no" is still not consent.
	 */
	public function test_an_explicit_no_does_not_trigger_cleanup(): void {
		update_option( 'unwan_remove_data_on_uninstall', 'no' );
		update_option( 'unwan_color_scheme', 'dark' );

		$this->assertFalse( unwan_uninstall_current_site() );
		$this->assertSame( 'dark', get_option( 'unwan_color_scheme' ) );
	}

	/**
	 * Opting in removes every plugin-owned option.
	 */
	public function test_opting_in_removes_every_plugin_option(): void {
		foreach ( unwan_uninstall_option_names() as $option ) {
			update_option( $option, 'seeded' );
		}

		update_option( 'unwan_remove_data_on_uninstall', 'yes' );

		$this->assertTrue( unwan_uninstall_current_site() );

		foreach ( unwan_uninstall_option_names() as $option ) {
			$this->assertFalse(
				get_option( $option, false ),
				"{$option} was left behind"
			);
		}
	}

	/**
	 * Cleanup must never remove WooCommerce's own billing or shipping profile
	 * data, nor any other plugin's options.
	 */
	public function test_cleanup_leaves_woocommerce_and_third_party_data_intact(): void {
		$this->repository->save_primary( $this->user_id, 'billing', $this->address() );
		$this->repository->save_primary( $this->user_id, 'shipping', $this->other_address() );

		update_option( 'woocommerce_default_country', 'US:CA' );
		update_option( 'some_other_plugin_setting', 'keep me' );
		update_option( 'unwan_remove_data_on_uninstall', 'yes' );

		unwan_uninstall_current_site();

		$this->assertSame( '12 Maple Street', get_user_meta( $this->user_id, 'billing_address_1', true ) );
		$this->assertSame( '990 Oak Avenue', get_user_meta( $this->user_id, 'shipping_address_1', true ) );
		$this->assertSame( 'US:CA', get_option( 'woocommerce_default_country' ) );
		$this->assertSame( 'keep me', get_option( 'some_other_plugin_setting' ) );

		delete_option( 'some_other_plugin_setting' );
	}

	/**
	 * No WooCommerce-owned key can slip into the removal list.
	 */
	public function test_no_woocommerce_option_is_in_the_removal_list(): void {
		foreach ( unwan_uninstall_option_names() as $option ) {
			$this->assertStringStartsNotWith( 'woocommerce_', $option );
			$this->assertStringStartsNotWith( 'billing_', $option );
			$this->assertStringStartsNotWith( 'shipping_', $option );
		}
	}
}
