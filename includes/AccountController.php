<?php
/**
 * My Account address-book endpoint.
 *
 * @package Unwan
 */

namespace Unwan\AddressLibrary;

use Unwan\AddressLibrary\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provides one accessible address book containing both profile defaults and
 * every shared additional address.
 */
final class AccountController {

	/**
	 * My Account endpoint slug.
	 */
	public const ENDPOINT = 'address-book';

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
	 * Register account hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'register_woocommerce_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the account rewrite endpoint.
	 *
	 * @return void
	 */
	public function register_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Add the endpoint to WooCommerce's endpoint registry.
	 *
	 * @param array<string,string> $query_vars WooCommerce endpoint variables.
	 * @return array<string,string>
	 */
	public function register_woocommerce_query_var( array $query_vars ): array {
		$query_vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $query_vars;
	}

	/**
	 * Replace WooCommerce's basic Addresses item with Address book.
	 *
	 * @param array<string,string> $items Account navigation.
	 * @return array<string,string>
	 */
	public function add_menu_item( array $items ): array {
		$updated  = array();
		$inserted = false;

		foreach ( $items as $key => $label ) {
			if ( 'edit-address' === $key ) {
				$updated[ self::ENDPOINT ] = __( 'Address book', 'unwan-for-woocommerce' );
				$inserted                  = true;
				continue;
			}

			$updated[ $key ] = $label;
		}

		if ( ! $inserted ) {
			$updated[ self::ENDPOINT ] = __( 'Address book', 'unwan-for-woocommerce' );
		}

		return $updated;
	}

	/**
	 * Render the combined address-book page.
	 *
	 * @return void
	 */
	public function render_endpoint(): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only query args that choose which entry to display; no state changes here.
		$edit_id = isset( $_GET['unwan_edit'] )
			? sanitize_key( wp_unslash( $_GET['unwan_edit'] ) )
			: '';
		$role    = isset( $_GET['unwan_role'] )
			? $this->repository->normalize_type( sanitize_key( wp_unslash( $_GET['unwan_role'] ) ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$is_adding = 'new' === $edit_id;
		$record    = null;

		if ( $is_adding ) {
			$record = array(
				'id'     => 'new',
				'fields' => $this->repository->sanitize_fields( array() ),
				'roles'  => '' !== $role ? array( $role ) : array(),
			);
		} elseif ( '' !== $edit_id ) {
			$record = $this->repository->get_entry( $user_id, $edit_id );
			if ( null === $record ) {
				wc_add_notice( __( 'That saved address could not be found.', 'unwan-for-woocommerce' ), 'error' );
				$edit_id = '';
			}
		}

		$form_data = WC()->session ? WC()->session->get( 'unwan_account_form_data' ) : null;
		if (
			is_array( $form_data )
			&& ( $form_data['id'] ?? '' ) === $edit_id
			&& is_array( $record )
		) {
			$record['fields'] = $this->repository->sanitize_fields( (array) ( $form_data['fields'] ?? array() ) );
			$record['roles']  = array_values( (array) ( $form_data['roles'] ?? array() ) );
			WC()->session->__unset( 'unwan_account_form_data' );
		}

		$country = is_array( $record ) ? (string) ( $record['fields']['country'] ?? '' ) : '';
		if ( '' === $country ) {
			$country = WC()->countries->get_base_country();
		}

		$entries = $this->repository->get_address_book( $user_id );

		wc_get_template(
			'myaccount/address-book.php',
			array(
				'controller'       => $this,
				'repository'       => $this->repository,
				'user_id'          => $user_id,
				'defaults'         => array(
					'shipping' => $this->repository->get_default_entry( $user_id, 'shipping' ),
					'billing'  => $this->repository->get_default_entry( $user_id, 'billing' ),
				),
				'entries'          => $entries,
				'can_add'          => $this->repository->can_add( $user_id ),
				'search_threshold' => $this->settings->get_address_search_threshold(),
				'picker_labels'    => $this->settings->get_checkout_picker_labels(),
				'account_labels'   => $this->settings->get_account_labels(),
				'edit_id'          => $edit_id,
				'edit_record'      => $record,
				'form_fields'      => $this->get_form_fields( $country ),
			),
			'',
			UNWAN_PATH . 'templates/'
		);
	}

	/**
	 * Process address actions before output.
	 *
	 * @return void
	 */
	public function handle_post(): void {
		if (
			! is_user_logged_in()
			|| ! is_account_page()
			|| ! is_wc_endpoint_url( self::ENDPOINT )
			|| 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) )
		) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Only dispatches to a handler; each handle_* method verifies its own nonce via check_admin_referer() before acting.
		$action = isset( $_POST['unwan_action'] )
			? sanitize_key( wp_unslash( $_POST['unwan_action'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		switch ( $action ) {
			case 'save':
				$this->handle_save();
				break;
			case 'delete':
				$this->handle_delete();
				break;
			case 'make_primary':
				$this->handle_make_primary();
				break;
		}
	}

	/**
	 * Save an account editor form.
	 *
	 * @return void
	 */
	private function handle_save(): void {
		check_admin_referer( 'unwan_save_address', 'unwan_nonce' );

		$user_id = get_current_user_id();
		$id      = isset( $_POST['unwan_id'] )
			? sanitize_key( wp_unslash( $_POST['unwan_id'] ) )
			: 'new';
		$country = isset( $_POST['billing_country'] )
			? sanitize_text_field( wp_unslash( $_POST['billing_country'] ) )
			: WC()->countries->get_base_country();
		$fields  = $this->get_form_fields( $country );
		$values  = array();
		$errors  = array();
		$roles   = array();

		foreach ( array( 'billing', 'shipping' ) as $role ) {
			if ( isset( $_POST[ "unwan_default_{$role}" ] ) ) {
				$roles[] = $role;
			}
		}

		foreach ( $fields as $key => $field ) {
			$suffix = substr( $key, strlen( 'billing_' ) );
			$value  = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			$value  = is_scalar( $value ) ? (string) $value : '';

			if ( ! empty( $field['required'] ) && '' === trim( $value ) ) {
				$errors[] = sprintf(
					/* translators: %s: address field label. */
					__( '%s is required.', 'unwan-for-woocommerce' ),
					wp_strip_all_tags( (string) ( $field['label'] ?? $suffix ) )
				);
			}

			if ( 'phone' === $suffix && '' !== $value && ! \WC_Validation::is_phone( $value ) ) {
				$errors[] = __( 'Please enter a valid phone number.', 'unwan-for-woocommerce' );
			}

			$values[ $suffix ] = $value;
		}

		$values = $this->repository->sanitize_fields( $values );

		if ( ! $this->repository->has_address( $values ) ) {
			$errors[] = __( 'Please enter a complete postal address.', 'unwan-for-woocommerce' );
		}

		if ( ! empty( $errors ) ) {
			if ( WC()->session ) {
				WC()->session->set(
					'unwan_account_form_data',
					array(
						'id'     => $id,
						'fields' => $values,
						'roles'  => $roles,
					)
				);
			}

			foreach ( array_unique( $errors ) as $error ) {
				wc_add_notice( $error, 'error' );
			}
			$this->redirect( $id );
		}

		$result = $this->repository->save_entry( $user_id, $id, $values, $roles );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			$this->redirect( $id );
		}

		wc_add_notice( __( 'Address saved.', 'unwan-for-woocommerce' ), 'success' );
		$this->redirect();
	}

	/**
	 * Delete an additional address.
	 *
	 * @return void
	 */
	private function handle_delete(): void {
		check_admin_referer( 'unwan_delete_address', 'unwan_nonce' );

		$id     = isset( $_POST['unwan_id'] ) ? sanitize_key( wp_unslash( $_POST['unwan_id'] ) ) : '';
		$result = $this->repository->delete( get_current_user_id(), $id );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		} else {
			wc_add_notice( __( 'Address deleted.', 'unwan-for-woocommerce' ), 'success' );
		}

		$this->redirect();
	}

	/**
	 * Assign an address to one default role.
	 *
	 * @return void
	 */
	private function handle_make_primary(): void {
		check_admin_referer( 'unwan_make_primary', 'unwan_nonce' );

		$id   = isset( $_POST['unwan_id'] ) ? sanitize_key( wp_unslash( $_POST['unwan_id'] ) ) : '';
		$role = isset( $_POST['unwan_role'] )
			? $this->repository->normalize_type( sanitize_key( wp_unslash( $_POST['unwan_role'] ) ) )
			: 'billing';

		$result = $this->repository->make_primary( get_current_user_id(), $role, $id );

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		} else {
			wc_add_notice( __( 'Default address updated.', 'unwan-for-woocommerce' ), 'success' );
		}

		$this->redirect();
	}

	/**
	 * Get locale-aware WooCommerce fields using a neutral billing prefix.
	 *
	 * @param string $country Country code.
	 * @return array<string,array<string,mixed>>
	 */
	public function get_form_fields( string $country ): array {
		$fields = WC()->countries->get_address_fields( $country, 'billing_' );

		unset( $fields['billing_email'] );

		return $fields;
	}

	/**
	 * Get the account endpoint URL.
	 *
	 * @param string $edit_id Optional address ID.
	 * @param string $role    Optional default role to preselect.
	 * @return string
	 */
	public function get_url( string $edit_id = '', string $role = '' ): string {
		$url = wc_get_account_endpoint_url( self::ENDPOINT );

		if ( '' !== $edit_id ) {
			$url = add_query_arg( 'unwan_edit', sanitize_key( $edit_id ), $url );
		}
		if ( in_array( $role, array( 'billing', 'shipping' ), true ) ) {
			$url = add_query_arg( 'unwan_role', $role, $url );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Enqueue account assets only on this endpoint.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( ! is_account_page() || ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}

		wp_enqueue_script( 'wc-country-select' );
		wp_enqueue_script( 'wc-address-i18n' );

		$script  = UNWAN_PATH . 'assets/js/unwan-account.js';
		$version = UNWAN_VERSION;
		if ( file_exists( $script ) ) {
			$version .= '-' . (string) filemtime( $script );
		}

		wp_enqueue_script(
			'unwan-account',
			UNWAN_URL . 'assets/js/unwan-account.js',
			array(),
			$version,
			true
		);
	}

	/**
	 * Redirect back to the endpoint.
	 *
	 * @param string $edit_id Optional editor to reopen.
	 * @return void
	 */
	private function redirect( string $edit_id = '' ): void {
		wp_safe_redirect( $this->get_url( $edit_id ) );
		exit;
	}
}
