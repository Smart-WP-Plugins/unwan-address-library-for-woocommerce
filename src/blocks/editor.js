/**
 * Checkout block editor registration.
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import billingMetadata from './billing/block.json';
import shippingMetadata from './shipping/block.json';

/**
 * Static editor preview. The live component is registered separately through
 * WooCommerce's checkout integration registry.
 *
 * @param {Object} props      Component properties.
 * @param {string} props.type Address type.
 * @return {Element} Preview.
 */
const EditorPreview = ( { type } ) => {
	const blockProps = useBlockProps( {
		className: 'unwan-block-editor-preview',
	} );
	const label =
		type === 'billing'
			? __( 'Billing address book', 'unwan-for-woocommerce' )
			: __( 'Shipping address book', 'unwan-for-woocommerce' );

	return (
		<div { ...blockProps }>
			<strong>{ label }</strong>
			<p className="unwan-block-editor-preview__description">
				{ __(
					'Signed-in customers can choose a saved address here.',
					'unwan-for-woocommerce'
				) }
			</p>
		</div>
	);
};

registerBlockType( billingMetadata.name, {
	...billingMetadata,
	edit: () => <EditorPreview type="billing" />,
	save: () => null,
} );

registerBlockType( shippingMetadata.name, {
	...shippingMetadata,
	edit: () => <EditorPreview type="shipping" />,
	save: () => null,
} );
