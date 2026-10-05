<?php
/**
 * Settings sanitization, defaults, and the uninstall option inventory.
 *
 * @package Unwan
 */

use Unwan\AddressLibrary\Admin\Settings;

/**
 * Covers the merchant-facing preferences and the guarantee that every option
 * the settings screen writes is also one the uninstall routine removes.
 */
class SettingsTest extends UnwanTestCase {

	/**
	 * System under test.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Build a fresh settings object per test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->settings = new Settings();
	}

	/**
	 * The subsection is added under Accounts & Privacy without displacing
	 * WooCommerce's own entries.
	 */
	public function test_the_subsection_is_added_without_removing_others(): void {
		$sections = $this->settings->add_section( array( '' => 'General' ) );

		$this->assertSame( 'General', $sections[''] );
		$this->assertSame( 'Unwan', $sections['unwan'] );
	}

	/**
	 * Settings are supplied only while the Unwan subsection is open.
	 */
	public function test_settings_are_scoped_to_the_unwan_subsection(): void {
		$existing = array( array( 'id' => 'woocommerce_option' ) );

		$this->assertSame( $existing, $this->settings->get_settings( $existing, '' ) );
		$this->assertSame( $existing, $this->settings->get_settings( $existing, 'other' ) );
		$this->assertNotSame( $existing, $this->settings->get_settings( $existing, Settings::SECTION ) );
	}

	/**
	 * Every field group opened by a title is closed by a matching sectionend.
	 */
	public function test_every_settings_group_is_closed(): void {
		$settings = $this->settings->get_settings( array(), Settings::SECTION );

		$open = array();
		foreach ( $settings as $field ) {
			if ( 'title' === $field['type'] ) {
				$open[] = $field['id'];
			} elseif ( 'sectionend' === $field['type'] ) {
				$this->assertSame( array_pop( $open ), $field['id'], 'sectionend matches its title' );
			}
		}

		$this->assertSame( array(), $open, 'No settings group is left open' );
	}

	/**
	 * A three-digit hexadecimal accent expands to its six-digit form.
	 */
	public function test_short_hex_accents_expand_to_six_digits(): void {
		$this->assertSame( '#aabbcc', $this->settings->sanitize_accent_color( '#abc' ) );
		$this->assertSame( '#000000', $this->settings->sanitize_accent_color( '#000' ) );
	}

	/**
	 * A valid six-digit accent is preserved.
	 */
	public function test_full_hex_accents_are_preserved(): void {
		$this->assertSame( '#123abc', $this->settings->sanitize_accent_color( '#123abc' ) );
	}

	/**
	 * Anything unusable falls back to the documented default.
	 *
	 * @dataProvider provide_invalid_accents
	 *
	 * @param mixed $value Submitted value.
	 */
	public function test_invalid_accents_fall_back_to_the_default( $value ): void {
		$this->assertSame( '#6b3fa0', $this->settings->sanitize_accent_color( $value ) );
	}

	/**
	 * Values the color picker must never persist.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_invalid_accents(): array {
		return array(
			'empty string' => array( '' ),
			'missing hash' => array( '6b3fa0' ),
			'not hex'      => array( '#zzzzzz' ),
			'css function' => array( 'rgb(1,2,3)' ),
			'markup'       => array( '<script>alert(1)</script>' ),
			'array'        => array( array( '#fff' ) ),
			'null'         => array( null ),
		);
	}

	/**
	 * The accent is also exposed as RGB channels for alpha-based surfaces.
	 */
	public function test_the_accent_is_exposed_as_rgb_channels(): void {
		$this->assertSame( '107, 63, 160', $this->settings->get_accent_rgb(), 'Documented default' );

		update_option( 'unwan_accent_color', '#ffffff' );
		$this->assertSame( '255, 255, 255', $this->settings->get_accent_rgb() );

		update_option( 'unwan_accent_color', '#000000' );
		$this->assertSame( '0, 0, 0', $this->settings->get_accent_rgb() );
	}

	/**
	 * Only the three documented color modes are accepted.
	 */
	public function test_only_documented_color_modes_are_accepted(): void {
		$this->assertSame( 'light', $this->settings->get_color_scheme(), 'Documented default' );

		foreach ( array( 'light', 'dark', 'system' ) as $scheme ) {
			update_option( 'unwan_color_scheme', $scheme );
			$this->assertSame( $scheme, $this->settings->get_color_scheme() );
		}

		update_option( 'unwan_color_scheme', 'sepia' );
		$this->assertSame( 'light', $this->settings->get_color_scheme() );
	}

	/**
	 * Both selectors are on by default and independently switchable.
	 */
	public function test_selectors_are_enabled_by_default_and_switch_independently(): void {
		$this->assertTrue( $this->settings->is_enabled( 'billing' ) );
		$this->assertTrue( $this->settings->is_enabled( 'shipping' ) );

		update_option( 'unwan_shipping_enable', 'no' );

		$this->assertTrue( $this->settings->is_enabled( 'billing' ) );
		$this->assertFalse( $this->settings->is_enabled( 'shipping' ) );
	}

	/**
	 * An unknown type is treated as billing rather than silently enabled.
	 */
	public function test_an_unknown_selector_type_resolves_to_billing(): void {
		update_option( 'unwan_billing_enable', 'no' );

		$this->assertFalse( $this->settings->is_enabled( 'gift' ) );
	}

	/**
	 * Promotion of a checkout address requires saving to be on at all.
	 */
	public function test_default_promotion_requires_checkout_saving(): void {
		update_option( 'unwan_checkout_default_behavior', 'update' );

		$this->assertTrue( $this->settings->should_update_checkout_default() );

		update_option( 'unwan_save_checkout_addresses', 'no' );

		$this->assertFalse(
			$this->settings->should_update_checkout_default(),
			'The default-update setting has no effect when saving is off'
		);
	}

	/**
	 * The shipped behavior preserves existing defaults.
	 */
	public function test_checkout_defaults_are_preserved_out_of_the_box(): void {
		$this->assertTrue( $this->settings->should_save_checkout_addresses() );
		$this->assertFalse( $this->settings->should_update_checkout_default() );
	}

	/**
	 * A blank label falls back to its translated default rather than rendering
	 * an empty control.
	 */
	public function test_blank_labels_fall_back_to_their_defaults(): void {
		update_option( 'unwan_label_change', '' );
		update_option( 'unwan_label_account_title', '   ' );

		$this->assertSame( 'Change', $this->settings->get_checkout_picker_labels()['change'] );
		$this->assertSame( 'Addresses', $this->settings->get_account_labels()['pageTitle'] );
	}

	/**
	 * Merchant-supplied label copy is sanitized before it reaches the UI.
	 */
	public function test_label_copy_is_sanitized(): void {
		update_option( 'unwan_label_change', '<script>alert(1)</script>Swap' );

		$change = $this->settings->get_checkout_picker_labels()['change'];

		$this->assertStringNotContainsString( '<script>', $change );
		$this->assertStringNotContainsString( 'alert(1)', $change );
	}

	/**
	 * Every documented label key is present in both label sets.
	 */
	public function test_all_documented_label_keys_are_present(): void {
		$this->assertSame(
			array(
				'pageTitle',
				'pageDescription',
				'addAddress',
				'addHeading',
				'editHeading',
				'backToAddresses',
				'saveAddress',
				'cancel',
				'emptyHeading',
				'emptyDescription',
			),
			array_keys( $this->settings->get_account_labels() )
		);

		$this->assertSame(
			array(
				'address',
				'billingCompactHeading',
				'shippingCompactHeading',
				'billingPanelHeading',
				'shippingPanelHeading',
				'savedAddress',
				'savedAddresses',
				'moreAddress',
				'moreAddresses',
				'searchLabel',
				'searchPlaceholder',
				'noResults',
				'newAddress',
				'default',
				'change',
			),
			array_keys( $this->settings->get_checkout_picker_labels() )
		);
	}

	/**
	 * Count labels must keep their placeholder so the picker can fill it in.
	 */
	public function test_count_labels_retain_their_placeholder(): void {
		$labels = $this->settings->get_checkout_picker_labels();

		foreach ( array( 'savedAddress', 'savedAddresses', 'moreAddress', 'moreAddresses' ) as $key ) {
			$this->assertStringContainsString( '%d', $labels[ $key ], "{$key} keeps its %d placeholder" );
		}
	}

	/**
	 * Label fields carry their translated default as a placeholder, never as
	 * a value WooCommerce would save in the admin's language.
	 */
	public function test_label_fields_use_their_default_as_a_placeholder(): void {
		$labels = array_filter(
			$this->settings->get_settings( array(), Settings::SECTION ),
			static function ( $field ) {
				return 0 === strpos( (string) ( $field['id'] ?? '' ), 'unwan_label_' ) && 'text' === $field['type'];
			}
		);

		$this->assertCount( 17, $labels );
		foreach ( $labels as $field ) {
			$this->assertSame( '', $field['default'], $field['id'] );
			$this->assertNotSame( '', $field['placeholder'], $field['id'] );
		}
	}

	/**
	 * Saving a label that matches its default stores it as empty; a changed
	 * label and other settings are left alone.
	 */
	public function test_a_label_matching_its_default_is_saved_empty(): void {
		$field = array( 'id' => 'unwan_label_change' );

		$this->assertSame( '', $this->settings->sanitize_label( 'Change', $field ) );
		$this->assertSame( '', $this->settings->sanitize_label( ' Change ', $field ) );
		$this->assertSame( 'Swap', $this->settings->sanitize_label( 'Swap', $field ) );
		$this->assertSame( 'Change', $this->settings->sanitize_label( 'Change', array( 'id' => 'woocommerce_other' ) ) );
	}

	/**
	 * The save pipeline applies the filter to the label options.
	 */
	public function test_the_settings_save_pipeline_empties_default_labels(): void {
		$value = apply_filters( 'woocommerce_admin_settings_sanitize_option', 'Bill to', array( 'id' => 'unwan_label_billing_panel' ), 'Bill to' );

		$this->assertSame( '', $value );
		$this->assertSame( 'Bill to', $this->settings->get_checkout_picker_labels()['billingPanelHeading'] );
	}

	/**
	 * wpml-config.xml offers every label setting, and only those, to WPML and
	 * Polylang for translation.
	 */
	public function test_wpml_config_lists_every_label_setting(): void {
		$ids = array();
		foreach ( $this->settings->get_settings( array(), Settings::SECTION ) as $field ) {
			if ( 'text' === $field['type'] && 0 === strpos( $field['id'], 'unwan_label_' ) ) {
				$ids[] = $field['id'];
			}
		}

		$xml  = simplexml_load_file( UNWAN_PATH . 'wpml-config.xml' );
		$keys = array();
		foreach ( $xml->{'admin-texts'}->key as $key ) {
			$keys[] = (string) $key['name'];
		}

		sort( $ids );
		sort( $keys );
		$this->assertSame( $ids, $keys );
	}
}
