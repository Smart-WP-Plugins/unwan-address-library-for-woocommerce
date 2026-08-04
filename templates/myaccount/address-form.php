<?php
/**
 * Shared address editor.
 *
 * @package Unwan
 *
 * @var \Unwan\AddressLibrary\AccountController $controller
 * @var string                                            $edit_id
 * @var array<string,mixed>                               $edit_record
 * @var array<string,array>                               $form_fields
 * @var array<string,string>                              $labels
 */

defined( 'ABSPATH' ) || exit;

$unwan_is_new = 'new' === $edit_id;
$unwan_roles  = array_values( (array) ( $edit_record['roles'] ?? array() ) );
?>

<div class="unwan-editor">
	<header class="unwan-editor__header">
		<h2>
			<?php echo esc_html( $unwan_is_new ? $labels['addHeading'] : $labels['editHeading'] ); ?>
		</h2>
		<a href="<?php echo esc_url( $controller->get_url() ); ?>">
			<?php echo esc_html( $labels['backToAddresses'] ); ?>
		</a>
	</header>

	<form method="post" class="woocommerce-address-fields unwan-address-form" action="<?php echo esc_url( $controller->get_url() ); ?>">
		<div class="woocommerce-address-fields__field-wrapper">
			<?php
			foreach ( $form_fields as $unwan_key => $unwan_field ) {
				$unwan_suffix = substr( $unwan_key, strlen( 'billing_' ) );
				$unwan_value  = (string) ( $edit_record['fields'][ $unwan_suffix ] ?? '' );

				woocommerce_form_field( $unwan_key, $unwan_field, $unwan_value );
			}
			?>
		</div>

		<fieldset class="unwan-role-selector">
			<legend class="unwan-role-selector__legend"><?php esc_html_e( 'Use this address for', 'unwan-for-woocommerce' ); ?></legend>
			<label class="unwan-role-selector__option">
				<input
					type="checkbox"
					name="unwan_default_shipping"
					value="1"
					<?php checked( in_array( 'shipping', $unwan_roles, true ) ); ?>
				>
				<span><?php esc_html_e( 'Default shipping address', 'unwan-for-woocommerce' ); ?></span>
			</label>
			<label class="unwan-role-selector__option">
				<input
					type="checkbox"
					name="unwan_default_billing"
					value="1"
					<?php checked( in_array( 'billing', $unwan_roles, true ) ); ?>
				>
				<span><?php esc_html_e( 'Default billing address', 'unwan-for-woocommerce' ); ?></span>
			</label>
			<p class="unwan-role-selector__help">
				<?php esc_html_e( 'Leave both unchecked to keep this as an additional address available everywhere at checkout.', 'unwan-for-woocommerce' ); ?>
			</p>
		</fieldset>

		<div class="unwan-editor__actions">
			<button type="submit" class="button unwan-button unwan-button--primary">
				<?php echo esc_html( $labels['saveAddress'] ); ?>
			</button>
			<a class="button" href="<?php echo esc_url( $controller->get_url() ); ?>">
				<?php echo esc_html( $labels['cancel'] ); ?>
			</a>
		</div>

		<input type="hidden" name="unwan_action" value="save">
		<input type="hidden" name="unwan_id" value="<?php echo esc_attr( $edit_id ); ?>">
		<?php wp_nonce_field( 'unwan_save_address', 'unwan_nonce' ); ?>
	</form>
</div>
