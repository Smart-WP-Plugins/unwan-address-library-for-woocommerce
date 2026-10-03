<?php
/**
 * WordPress personal data export and erasure for the address book.
 *
 * @package Unwan
 */

namespace Unwan\AddressLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the shared additional addresses to WordPress's personal data tools
 * (Tools > Export Personal Data and Erase Personal Data).
 *
 * The two profile defaults are WooCommerce customer data, which WooCommerce's
 * own exporter and eraser already cover.
 */
final class Privacy {

	/**
	 * Exporter and eraser ID, also the export group ID.
	 */
	private const ID = 'unwan-address-book';

	/**
	 * Address repository.
	 *
	 * @var AddressRepository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param AddressRepository $repository Address repository.
	 */
	public function __construct( AddressRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Register the exporter and eraser.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Add the address book exporter.
	 *
	 * @param array<string,array<string,mixed>> $exporters Registered exporters.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_exporter( $exporters ): array {
		$exporters             = (array) $exporters;
		$exporters[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Address book', 'unwan-for-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Add the address book eraser.
	 *
	 * @param array<string,array<string,mixed>> $erasers Registered erasers.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_eraser( $erasers ): array {
		$erasers             = (array) $erasers;
		$erasers[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Address book', 'unwan-for-woocommerce' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export a customer's additional addresses.
	 *
	 * Everything fits on one page, so the page number WordPress also passes is
	 * not needed.
	 *
	 * @param string $email_address Requester's email address.
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( $email_address ): array {
		$user = get_user_by( 'email', (string) $email_address );
		$data = array();

		if ( $user instanceof \WP_User ) {
			$labels = $this->field_labels();

			foreach ( $this->repository->get_saved( $user->ID ) as $id => $record ) {
				$fields = array();

				foreach ( (array) ( $record['fields'] ?? array() ) as $key => $value ) {
					if ( '' === (string) $value ) {
						continue;
					}

					$fields[] = array(
						'name'  => $labels[ $key ] ?? (string) $key,
						'value' => (string) $value,
					);
				}

				if ( ! empty( $fields ) ) {
					$data[] = array(
						'group_id'    => self::ID,
						'group_label' => __( 'Address book', 'unwan-for-woocommerce' ),
						'item_id'     => self::ID . '-' . $id,
						'data'        => $fields,
					);
				}
			}
		}

		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase a customer's additional addresses.
	 *
	 * Everything is erased at once, so the page number WordPress also passes is
	 * not needed.
	 *
	 * @param string $email_address Requester's email address.
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( $email_address ): array {
		$user    = get_user_by( 'email', (string) $email_address );
		$removed = $user instanceof \WP_User && $this->repository->erase_saved( $user->ID );

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * WooCommerce's translated labels for the stored field keys.
	 *
	 * @return array<string,string>
	 */
	private function field_labels(): array {
		$labels = array();

		if ( function_exists( 'WC' ) && WC()->countries ) {
			foreach ( WC()->countries->get_address_fields( '', '' ) as $key => $field ) {
				$labels[ (string) $key ] = wp_strip_all_tags( (string) ( $field['label'] ?? $key ) );
			}
		}

		return $labels;
	}
}
