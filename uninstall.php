<?php
/**
 * Unwan uninstall cleanup.
 *
 * @package Unwan
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Options owned exclusively by Unwan.
 *
 * @return string[]
 */
function unwan_uninstall_option_names(): array {
	return array(
		'unwan_plugin_version',
		'unwan_billing_enable',
		'unwan_shipping_enable',
		'unwan_address_save_limit',
		'unwan_address_search_threshold',
		'unwan_save_checkout_addresses',
		'unwan_checkout_default_behavior',
		'unwan_color_scheme',
		'unwan_accent_color',
		'unwan_label_account_title',
		'unwan_label_account_description',
		'unwan_label_add_address',
		'unwan_label_add_heading',
		'unwan_label_edit_heading',
		'unwan_label_back',
		'unwan_label_save',
		'unwan_label_cancel',
		'unwan_label_empty_heading',
		'unwan_label_empty_description',
		'unwan_label_billing_compact',
		'unwan_label_shipping_compact',
		'unwan_label_billing_panel',
		'unwan_label_shipping_panel',
		'unwan_label_search',
		'unwan_label_new_address',
		'unwan_label_change',
		'unwan_remove_data_on_uninstall',
	);
}

/**
 * Remove settings for the current site when it has opted into cleanup.
 *
 * @return bool Whether cleanup was enabled for this site.
 */
function unwan_uninstall_current_site(): bool {
	if ( 'yes' !== get_option( 'unwan_remove_data_on_uninstall', 'no' ) ) {
		return false;
	}

	foreach ( unwan_uninstall_option_names() as $option ) {
		delete_option( $option );
	}

	return true;
}

$unwan_remove_customer_data = false;

if ( is_multisite() ) {
	$unwan_offset = 0;
	$unwan_limit  = 100;

	do {
		$unwan_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $unwan_limit,
				'offset' => $unwan_offset,
			)
		);

		foreach ( $unwan_site_ids as $unwan_site_id ) {
			switch_to_blog( (int) $unwan_site_id );
			$unwan_remove_customer_data = unwan_uninstall_current_site() || $unwan_remove_customer_data;
			restore_current_blog();
		}

		$unwan_offset    += $unwan_limit;
		$unwan_site_count = count( $unwan_site_ids );
	} while ( $unwan_site_count === $unwan_limit );
} else {
	$unwan_remove_customer_data = unwan_uninstall_current_site();
}

if ( $unwan_remove_customer_data ) {
	delete_metadata( 'user', 0, '_unwan_addresses', '', true );
}
