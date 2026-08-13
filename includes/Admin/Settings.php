<?php
/**
 * WooCommerce account settings.
 *
 * @package Unwan
 */

namespace Unwan\AddressLibrary\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Unwan as a dedicated Accounts & Privacy subsection and exposes
 * the small set of runtime preferences used by the storefront.
 */
final class Settings {

	/**
	 * Settings subsection identifier.
	 */
	public const SECTION = 'unwan';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_sections_account', array( $this, 'add_section' ) );
		add_filter( 'woocommerce_get_settings_account', array( $this, 'get_settings' ), 10, 2 );
		add_filter(
			'woocommerce_admin_settings_sanitize_option_unwan_accent_color',
			array( $this, 'sanitize_accent_color' )
		);
	}

	/**
	 * Add the Unwan subsection beneath Accounts & Privacy.
	 *
	 * @param array<string,string> $sections Existing subsection labels.
	 * @return array<string,string>
	 */
	public function add_section( array $sections ): array {
		$sections[ self::SECTION ] = __( 'Unwan', 'unwan-for-woocommerce' );

		return $sections;
	}

	/**
	 * Supply settings only while the Unwan subsection is open.
	 *
	 * @param array<int,array<string,mixed>> $settings        Existing settings.
	 * @param string                         $current_section Current subsection.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_settings( array $settings, $current_section = '' ): array {
		if ( self::SECTION !== $current_section ) {
			return $settings;
		}

		$settings = array(
			array(
				'title' => __( 'Address book', 'unwan-for-woocommerce' ),
				'desc'  => __( 'Control where Unwan appears and how checkout addresses are stored.', 'unwan-for-woocommerce' ),
				'id'    => 'unwan_general_options',
				'type'  => 'title',
			),
			array(
				'title'   => __( 'Billing checkout selector', 'unwan-for-woocommerce' ),
				'desc'    => __( 'Let customers choose any address-book entry for billing', 'unwan-for-woocommerce' ),
				'id'      => 'unwan_billing_enable',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Shipping checkout selector', 'unwan-for-woocommerce' ),
				'desc'    => __( 'Let customers choose any address-book entry for shipping', 'unwan-for-woocommerce' ),
				'id'      => 'unwan_shipping_enable',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'             => __( 'Additional address limit', 'unwan-for-woocommerce' ),
				'desc_tip'          => __( 'Maximum additional addresses per customer. Billing and shipping defaults are not counted. Use 0 for unlimited.', 'unwan-for-woocommerce' ),
				'id'                => 'unwan_address_save_limit',
				'type'              => 'number',
				'default'           => '0',
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => 0,
					'step' => 1,
				),
			),
			array(
				'title'             => __( 'Address search threshold', 'unwan-for-woocommerce' ),
				'desc_tip'          => __( 'Show search in checkout and My Account when a customer has more than this many addresses. Use 0 to always show it.', 'unwan-for-woocommerce' ),
				'id'                => 'unwan_address_search_threshold',
				'type'              => 'number',
				'default'           => '4',
				'css'               => 'width: 90px;',
				'custom_attributes' => array(
					'min'  => 0,
					'max'  => 100,
					'step' => 1,
				),
			),
			array(
				'title'   => __( 'Save checkout addresses', 'unwan-for-woocommerce' ),
				'desc'    => __( 'Add a new address entered at checkout to the customer’s address book', 'unwan-for-woocommerce' ),
				'id'      => 'unwan_save_checkout_addresses',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'    => __( 'New address defaults', 'unwan-for-woocommerce' ),
				'desc_tip' => __( 'Choose whether a new address entered at checkout should replace the matching billing or shipping profile default.', 'unwan-for-woocommerce' ),
				'id'       => 'unwan_checkout_default_behavior',
				'type'     => 'select',
				'default'  => 'preserve',
				'options'  => array(
					'preserve' => __( 'Keep the current defaults', 'unwan-for-woocommerce' ),
					'update'   => __( 'Make the new address the matching default', 'unwan-for-woocommerce' ),
				),
			),
			array(
				'id'   => 'unwan_general_options',
				'type' => 'sectionend',
			),
			array(
				'title' => __( 'Appearance', 'unwan-for-woocommerce' ),
				'desc'  => __( 'These values are exposed through scoped Unwan CSS variables and do not restyle the active theme.', 'unwan-for-woocommerce' ),
				'id'    => 'unwan_appearance_options',
				'type'  => 'title',
			),
			array(
				'title'   => __( 'Color mode', 'unwan-for-woocommerce' ),
				'id'      => 'unwan_color_scheme',
				'type'    => 'select',
				'default' => 'light',
				'options' => array(
					'light'  => __( 'Light', 'unwan-for-woocommerce' ),
					'dark'   => __( 'Dark', 'unwan-for-woocommerce' ),
					'system' => __( 'Follow the customer’s device', 'unwan-for-woocommerce' ),
				),
			),
			array(
				'title'   => __( 'Accent color', 'unwan-for-woocommerce' ),
				'id'      => 'unwan_accent_color',
				'type'    => 'color',
				'default' => '#6b3fa0',
				'css'     => 'width: 100px;',
			),
			array(
				'id'   => 'unwan_appearance_options',
				'type' => 'sectionend',
			),
			array(
				'title' => __( 'Text and labels', 'unwan-for-woocommerce' ),
				'desc'  => __( 'Customize customer-facing interface copy. Translations can still override the defaults.', 'unwan-for-woocommerce' ),
				'id'    => 'unwan_label_options',
				'type'  => 'title',
			),
			$this->text_setting( 'unwan_label_account_title', __( 'Address-book page title', 'unwan-for-woocommerce' ), __( 'Addresses', 'unwan-for-woocommerce' ) ),
			$this->text_setting(
				'unwan_label_account_description',
				__( 'Address-book description', 'unwan-for-woocommerce' ),
				__( 'Your billing default, shipping default, and additional addresses form one address book. Every address can be used anywhere at checkout.', 'unwan-for-woocommerce' )
			),
			$this->text_setting( 'unwan_label_add_address', __( 'Add-address button', 'unwan-for-woocommerce' ), __( 'Add new address', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_add_heading', __( 'Add-address heading', 'unwan-for-woocommerce' ), __( 'Add a new address', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_edit_heading', __( 'Edit-address heading', 'unwan-for-woocommerce' ), __( 'Edit address', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_back', __( 'Back link', 'unwan-for-woocommerce' ), __( 'Back to addresses', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_save', __( 'Save button', 'unwan-for-woocommerce' ), __( 'Save address', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_cancel', __( 'Cancel action', 'unwan-for-woocommerce' ), __( 'Cancel', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_empty_heading', __( 'Empty-state heading', 'unwan-for-woocommerce' ), __( 'You haven’t saved an address yet', 'unwan-for-woocommerce' ) ),
			$this->text_setting(
				'unwan_label_empty_description',
				__( 'Empty-state description', 'unwan-for-woocommerce' ),
				__( 'Add one now and it will be available to both billing and shipping at checkout.', 'unwan-for-woocommerce' )
			),
			$this->text_setting( 'unwan_label_billing_compact', __( 'Billing summary heading', 'unwan-for-woocommerce' ), __( 'Billing to', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_shipping_compact', __( 'Shipping summary heading', 'unwan-for-woocommerce' ), __( 'Delivering to', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_billing_panel', __( 'Billing picker heading', 'unwan-for-woocommerce' ), __( 'Bill to', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_shipping_panel', __( 'Shipping picker heading', 'unwan-for-woocommerce' ), __( 'Deliver to', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_search', __( 'Search placeholder', 'unwan-for-woocommerce' ), __( 'Filter by street, city or postcode', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_new_address', __( 'New-address choice', 'unwan-for-woocommerce' ), __( 'Enter a new address', 'unwan-for-woocommerce' ) ),
			$this->text_setting( 'unwan_label_change', __( 'Change action', 'unwan-for-woocommerce' ), __( 'Change', 'unwan-for-woocommerce' ) ),
			array(
				'id'   => 'unwan_label_options',
				'type' => 'sectionend',
			),
			array(
				'title' => __( 'Data removal', 'unwan-for-woocommerce' ),
				'desc'  => __( 'Choose whether customer address-book data and plugin settings should remain after uninstalling Unwan.', 'unwan-for-woocommerce' ),
				'id'    => 'unwan_uninstall_options',
				'type'  => 'title',
			),
			array(
				'title'    => __( 'Uninstall cleanup', 'unwan-for-woocommerce' ),
				'desc'     => __( 'Permanently remove all Unwan settings and additional customer addresses when the plugin is deleted', 'unwan-for-woocommerce' ),
				'desc_tip' => __( 'WooCommerce billing and shipping profile addresses are not removed.', 'unwan-for-woocommerce' ),
				'id'       => 'unwan_remove_data_on_uninstall',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'id'   => 'unwan_uninstall_options',
				'type' => 'sectionend',
			),
		);

		/**
		 * Filter settings shown in the dedicated Unwan subsection.
		 *
		 * @param array<int,array<string,mixed>> $settings Unwan settings.
		 */
		return (array) apply_filters( 'unwan_admin_settings', $settings );
	}

	/**
	 * Build a standard WooCommerce text setting.
	 *
	 * @param string $id      Option identifier.
	 * @param string $title   Admin label.
	 * @param string $default Default value.
	 * @return array<string,mixed>
	 */
	private function text_setting( string $id, string $title, string $default ): array {
		return array(
			'title'             => $title,
			'id'                => $id,
			'type'              => 'text',
			'default'           => $default,
			'custom_attributes' => array(
				'autocomplete' => 'off',
			),
		);
	}

	/**
	 * Ensure the saved accent value is a complete hexadecimal color.
	 *
	 * @param mixed $value Submitted setting value.
	 * @return string
	 */
	public function sanitize_accent_color( $value ): string {
		$color = sanitize_hex_color( is_scalar( $value ) ? (string) $value : '' );
		if ( $color && 4 === strlen( $color ) ) {
			$color = sprintf(
				'#%1$s%1$s%2$s%2$s%3$s%3$s',
				$color[1],
				$color[2],
				$color[3]
			);
		}

		return $color ? $color : '#6b3fa0';
	}

	/**
	 * Whether an address type is enabled.
	 *
	 * @param string $type Billing or shipping.
	 * @return bool
	 */
	public function is_enabled( string $type ): bool {
		$type    = in_array( $type, array( 'billing', 'shipping' ), true ) ? $type : 'billing';
		$enabled = 'yes' === get_option( "unwan_{$type}_enable", 'yes' );

		/**
		 * Filter whether an address type is enabled.
		 *
		 * @param bool   $enabled Whether the type is enabled.
		 * @param string $type    Billing or shipping.
		 */
		return (bool) apply_filters( 'unwan_address_type_enabled', $enabled, $type );
	}

	/**
	 * Whether checkout-created addresses should enter the address book.
	 *
	 * @return bool
	 */
	public function should_save_checkout_addresses(): bool {
		$enabled = 'yes' === get_option( 'unwan_save_checkout_addresses', 'yes' );

		return (bool) apply_filters( 'unwan_save_checkout_addresses', $enabled );
	}

	/**
	 * Whether a new checkout address should replace its matching default.
	 *
	 * @return bool
	 */
	public function should_update_checkout_default(): bool {
		$update = $this->should_save_checkout_addresses()
			&& 'update' === get_option( 'unwan_checkout_default_behavior', 'preserve' );

		return (bool) apply_filters( 'unwan_update_checkout_default', $update );
	}

	/**
	 * Address count after which checkout and account search become visible.
	 *
	 * @return int
	 */
	public function get_address_search_threshold(): int {
		$threshold = max(
			0,
			min( 100, absint( get_option( 'unwan_address_search_threshold', 4 ) ) )
		);

		return max(
			0,
			absint( apply_filters( 'unwan_address_search_threshold', $threshold ) )
		);
	}

	/**
	 * Selected storefront color mode.
	 *
	 * @return string
	 */
	public function get_color_scheme(): string {
		$scheme = sanitize_key( (string) get_option( 'unwan_color_scheme', 'light' ) );
		if ( ! in_array( $scheme, array( 'light', 'dark', 'system' ), true ) ) {
			$scheme = 'light';
		}

		$scheme = sanitize_key( (string) apply_filters( 'unwan_color_scheme', $scheme ) );

		return in_array( $scheme, array( 'light', 'dark', 'system' ), true ) ? $scheme : 'light';
	}

	/**
	 * Storefront accent color.
	 *
	 * @return string
	 */
	public function get_accent_color(): string {
		$color = $this->sanitize_accent_color( get_option( 'unwan_accent_color', '#6b3fa0' ) );

		return $this->sanitize_accent_color( apply_filters( 'unwan_accent_color', $color ) );
	}

	/**
	 * Storefront accent color as comma-separated RGB channels.
	 *
	 * @return string
	 */
	public function get_accent_rgb(): string {
		$hex = ltrim( $this->get_accent_color(), '#' );

		return implode(
			', ',
			array(
				(string) hexdec( substr( $hex, 0, 2 ) ),
				(string) hexdec( substr( $hex, 2, 2 ) ),
				(string) hexdec( substr( $hex, 4, 2 ) ),
			)
		);
	}

	/**
	 * My Account labels.
	 *
	 * @return array<string,string>
	 */
	public function get_account_labels(): array {
		$labels = array(
			'pageTitle'        => $this->label( 'unwan_label_account_title', __( 'Addresses', 'unwan-for-woocommerce' ) ),
			'pageDescription'  => $this->label(
				'unwan_label_account_description',
				__( 'Your billing default, shipping default, and additional addresses form one address book. Every address can be used anywhere at checkout.', 'unwan-for-woocommerce' )
			),
			'addAddress'       => $this->label( 'unwan_label_add_address', __( 'Add new address', 'unwan-for-woocommerce' ) ),
			'addHeading'       => $this->label( 'unwan_label_add_heading', __( 'Add a new address', 'unwan-for-woocommerce' ) ),
			'editHeading'      => $this->label( 'unwan_label_edit_heading', __( 'Edit address', 'unwan-for-woocommerce' ) ),
			'backToAddresses'  => $this->label( 'unwan_label_back', __( 'Back to addresses', 'unwan-for-woocommerce' ) ),
			'saveAddress'      => $this->label( 'unwan_label_save', __( 'Save address', 'unwan-for-woocommerce' ) ),
			'cancel'           => $this->label( 'unwan_label_cancel', __( 'Cancel', 'unwan-for-woocommerce' ) ),
			'emptyHeading'     => $this->label( 'unwan_label_empty_heading', __( 'You haven’t saved an address yet', 'unwan-for-woocommerce' ) ),
			'emptyDescription' => $this->label(
				'unwan_label_empty_description',
				__( 'Add one now and it will be available to both billing and shipping at checkout.', 'unwan-for-woocommerce' )
			),
		);

		$filtered = (array) apply_filters( 'unwan_account_labels', $labels );

		return array_map( 'sanitize_text_field', array_merge( $labels, $filtered ) );
	}

	/**
	 * Translatable labels shared by classic and block checkout pickers.
	 *
	 * @return array<string,string>
	 */
	public function get_checkout_picker_labels(): array {
		$labels = array(
			'address'                => __( 'Address', 'unwan-for-woocommerce' ),
			'billingCompactHeading'  => $this->label( 'unwan_label_billing_compact', __( 'Billing to', 'unwan-for-woocommerce' ) ),
			'shippingCompactHeading' => $this->label( 'unwan_label_shipping_compact', __( 'Delivering to', 'unwan-for-woocommerce' ) ),
			'billingPanelHeading'    => $this->label( 'unwan_label_billing_panel', __( 'Bill to', 'unwan-for-woocommerce' ) ),
			'shippingPanelHeading'   => $this->label( 'unwan_label_shipping_panel', __( 'Deliver to', 'unwan-for-woocommerce' ) ),
			/* translators: %d: number of saved addresses. */
			'savedAddress'           => _n( '%d saved address', '%d saved addresses', 1, 'unwan-for-woocommerce' ),
			/* translators: %d: number of saved addresses. */
			'savedAddresses'         => _n( '%d saved address', '%d saved addresses', 2, 'unwan-for-woocommerce' ),
			/* translators: %d: number of additional saved addresses not currently shown. */
			'moreAddress'            => _n( '%d more saved address', '%d more saved addresses', 1, 'unwan-for-woocommerce' ),
			/* translators: %d: number of additional saved addresses not currently shown. */
			'moreAddresses'          => _n( '%d more saved address', '%d more saved addresses', 2, 'unwan-for-woocommerce' ),
			'searchLabel'            => __( 'Search saved addresses', 'unwan-for-woocommerce' ),
			'searchPlaceholder'      => $this->label( 'unwan_label_search', __( 'Filter by street, city or postcode', 'unwan-for-woocommerce' ) ),
			'noResults'              => __( 'No saved addresses match your search.', 'unwan-for-woocommerce' ),
			'newAddress'             => $this->label( 'unwan_label_new_address', __( 'Enter a new address', 'unwan-for-woocommerce' ) ),
			'default'                => __( 'Default', 'unwan-for-woocommerce' ),
			'change'                 => $this->label( 'unwan_label_change', __( 'Change', 'unwan-for-woocommerce' ) ),
		);

		$filtered = (array) apply_filters( 'unwan_checkout_picker_labels', $labels );

		return array_map( 'sanitize_text_field', array_merge( $labels, $filtered ) );
	}

	/**
	 * Read a customer-facing text option without allowing blank UI controls.
	 *
	 * @param string $option  Option identifier.
	 * @param string $default Translated default.
	 * @return string
	 */
	private function label( string $option, string $default ): string {
		$value = sanitize_text_field( (string) get_option( $option, $default ) );

		return '' !== $value ? $value : $default;
	}
}
