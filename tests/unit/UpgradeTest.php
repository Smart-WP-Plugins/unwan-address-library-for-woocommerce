<?php
/**
 * Steps that run once per plugin version.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\Plugin;

/**
 * Plugin::maybe_upgrade() runs on each site's first load of a new version.
 */
class UpgradeTest extends UnwanTestCase {

	/**
	 * Pretend the site last ran an older version.
	 */
	public function set_up(): void {
		parent::set_up();

		update_option( 'unwan_plugin_version', '0.0.1' );
	}

	/**
	 * A site whose stored version differs (network activation never ran the
	 * activation hook there, or the plugin was updated) rebuilds its rewrite
	 * rules once, so the Address book endpoint does not 404.
	 */
	public function test_rewrite_rules_are_rebuilt_once_per_version(): void {
		update_option( 'rewrite_rules', array( 'stale' => 'index.php' ) );

		Plugin::instance()->maybe_upgrade();
		$this->assertSame( UNWAN_VERSION, get_option( 'unwan_plugin_version' ) );
		$this->assertArrayNotHasKey( 'stale', (array) get_option( 'rewrite_rules' ), 'Rules rebuilt' );

		update_option( 'rewrite_rules', array( 'kept' => 'index.php' ) );
		Plugin::instance()->maybe_upgrade();
		$this->assertArrayHasKey( 'kept', (array) get_option( 'rewrite_rules' ), 'No second flush for the same version' );
	}

	/**
	 * Earlier versions saved every default label as a value, freezing the
	 * language of whoever saved the settings. Those values are removed; the
	 * merchant's own copy is kept.
	 */
	public function test_saved_default_labels_are_removed_and_custom_ones_kept(): void {
		update_option( 'unwan_label_change', 'Change' );
		update_option( 'unwan_label_empty_heading', 'You haven’t saved an address yet' );
		update_option( 'unwan_label_account_title', 'My addresses' );

		Plugin::instance()->maybe_upgrade();

		$this->assertFalse( get_option( 'unwan_label_change' ) );
		$this->assertFalse( get_option( 'unwan_label_empty_heading' ) );
		$this->assertSame( 'My addresses', get_option( 'unwan_label_account_title' ) );
	}
}
