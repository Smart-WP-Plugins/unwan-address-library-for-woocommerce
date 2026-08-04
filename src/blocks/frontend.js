/**
 * WooCommerce Checkout Block saved-address selectors.
 */

// These runtime-only packages are externalized by WooCommerce's webpack plugin.
/* eslint-disable import/no-unresolved */
import { registerCheckoutBlock } from '@woocommerce/blocks-checkout';
import {
	cartStore,
	checkoutStore,
	validationStore,
} from '@woocommerce/block-data';
import { getSetting } from '@woocommerce/settings';
/* eslint-enable import/no-unresolved */
import { useDispatch, useSelect } from '@wordpress/data';
import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n } from '@wordpress/i18n';

import billingMetadata from './billing/block.json';
import shippingMetadata from './shipping/block.json';

const NAMESPACE = 'unwan';
const MATCH_KEYS = [
	'first_name',
	'last_name',
	'country',
	'address_1',
	'address_2',
	'city',
	'state',
	'postcode',
];
const settings = getSetting( 'unwan_data', {} );

/**
 * Create the address object WooCommerce expects, with country first so its
 * locale-specific field set is resolved before state is applied.
 *
 * @param {Object} fields Address values.
 * @return {Object} Normalized checkout address.
 */
const normalizeAddress = ( fields = {} ) => {
	const address = {
		country: fields.country || settings.baseCountry || '',
	};
	const fieldKeys = Array.isArray( settings.fieldKeys )
		? settings.fieldKeys
		: [];

	fieldKeys.forEach( ( key ) => {
		if ( key !== 'country' && key !== 'state' ) {
			address[ key ] = fields[ key ] || '';
		}
	} );

	address.state = fields.state || '';

	return address;
};

/**
 * Normalize a field value for address comparisons.
 *
 * @param {*} value Field value.
 * @return {string} Comparable value.
 */
const normalizeValue = ( value ) =>
	String( value || '' )
		.trim()
		.toLocaleLowerCase();

/**
 * Check whether two checkout addresses contain the same address-book fields.
 *
 * @param {Object} current  Current WooCommerce address.
 * @param {Object} expected Normalized saved address.
 * @return {boolean} Whether all saved fields match.
 */
const addressMatches = ( current = {}, expected = {} ) =>
	MATCH_KEYS.every(
		( key ) =>
			normalizeValue( current[ key ] ) ===
			normalizeValue( expected[ key ] )
	);

/**
 * Compare every field supplied by a store synchronization operation.
 *
 * @param {Object} current  Current WooCommerce address.
 * @param {Object} expected Address being synchronized.
 * @return {boolean} Whether all supplied values match.
 */
const allAddressFieldsMatch = ( current = {}, expected = {} ) =>
	Object.keys( expected ).every(
		( key ) =>
			normalizeValue( current[ key ] ) ===
			normalizeValue( expected[ key ] )
	);

/**
 * Whether an address contains meaningful postal data.
 *
 * @param {Object} fields Address values.
 * @return {boolean} Whether a cart address exists.
 */
const hasAddress = ( fields = {} ) =>
	[ 'address_1', 'city', 'postcode', 'country' ].some(
		( key ) => normalizeValue( fields[ key ] ) !== ''
	);

/**
 * Detect an edited variant of a saved recipient/street combination.
 *
 * @param {Object} current Current checkout address.
 * @param {Object} saved   Saved address.
 * @return {boolean} Whether the address identity is shared.
 */
const hasSameIdentity = ( current = {}, saved = {} ) =>
	[ 'first_name', 'last_name', 'address_1' ].every(
		( key ) =>
			normalizeValue( current[ key ] ) !== '' &&
			normalizeValue( current[ key ] ) === normalizeValue( saved[ key ] )
	);

/**
 * Build summary text for a cart address that is not in the saved list.
 *
 * @param {Object} fields Address values.
 * @return {Object} Summary values.
 */
const summarizeAddress = ( fields = {} ) => {
	const name =
		[ fields.first_name, fields.last_name ].filter( Boolean ).join( ' ' ) ||
		fields.company ||
		__( 'Address', 'unwan-for-woocommerce' );
	const street =
		[ fields.address_1, fields.address_2 ].filter( Boolean ).join( ', ' ) ||
		__( 'Address', 'unwan-for-woocommerce' );
	const region = [ fields.state, fields.postcode ]
		.filter( Boolean )
		.join( ' ' );

	return {
		name,
		street,
		details: [ fields.city, region, fields.country ]
			.filter( Boolean )
			.join( ', ' ),
	};
};

/**
 * Labels passed to the shared standard-DOM picker.
 *
 * @param {string} type Billing or shipping.
 * @return {Object} Translated picker labels.
 */
const getLabels = ( type ) => {
	const filtered = settings.labels || {};

	return {
		compactHeading:
			filtered[ `${ type }CompactHeading` ] ||
			( type === 'billing'
				? __( 'Billing to', 'unwan-for-woocommerce' )
				: __( 'Delivering to', 'unwan-for-woocommerce' ) ),
		panelHeading:
			filtered[ `${ type }PanelHeading` ] ||
			( type === 'billing'
				? __( 'Bill to', 'unwan-for-woocommerce' )
				: __( 'Deliver to', 'unwan-for-woocommerce' ) ),
		savedAddress:
			filtered.savedAddress ||
			/* translators: %d: number of saved addresses. */
			_n(
				'%d saved address',
				'%d saved addresses',
				1,
				'unwan-for-woocommerce'
			),
		savedAddresses:
			filtered.savedAddresses ||
			/* translators: %d: number of saved addresses. */
			_n(
				'%d saved address',
				'%d saved addresses',
				2,
				'unwan-for-woocommerce'
			),
		moreAddress:
			filtered.moreAddress ||
			/* translators: %d: number of additional saved addresses. */
			_n(
				'%d more saved address',
				'%d more saved addresses',
				1,
				'unwan-for-woocommerce'
			),
		moreAddresses:
			filtered.moreAddresses ||
			/* translators: %d: number of additional saved addresses. */
			_n(
				'%d more saved address',
				'%d more saved addresses',
				2,
				'unwan-for-woocommerce'
			),
		searchLabel:
			filtered.searchLabel ||
			__( 'Search saved addresses', 'unwan-for-woocommerce' ),
		searchPlaceholder:
			filtered.searchPlaceholder ||
			__( 'Filter by street, city or postcode', 'unwan-for-woocommerce' ),
		noResults:
			filtered.noResults ||
			__(
				'No saved addresses match your search.',
				'unwan-for-woocommerce'
			),
		newAddress:
			filtered.newAddress ||
			__( 'Enter a new address', 'unwan-for-woocommerce' ),
		default: filtered.default || __( 'Default', 'unwan-for-woocommerce' ),
		change: filtered.change || __( 'Change', 'unwan-for-woocommerce' ),
	};
};

/**
 * Checkout address-book selector.
 *
 * @param {Object} props                       Checkout block properties.
 * @param {Object} props.checkoutExtensionData Checkout extension data API.
 * @param {string} props.type                  Billing or shipping.
 * @return {Element|null} Selector.
 */
const AddressSelector = ( { checkoutExtensionData, type } ) => {
	const typeSettings = settings.types?.[ type ] || {};
	const addresses = useMemo(
		() =>
			Array.isArray( typeSettings.addresses )
				? typeSettings.addresses
				: [],
		[ typeSettings.addresses ]
	);
	const addressMap = useMemo(
		() =>
			addresses.reduce( ( map, address ) => {
				map[ address.id ] = address;
				return map;
			}, {} ),
		[ addresses ]
	);
	const defaultAddress =
		addresses.find( ( address ) => address.isDefault ) || addresses[ 0 ];
	const defaultSelection = defaultAddress?.id || 'new';
	const [ selection, setSelection ] = useState( defaultSelection );
	const [ customOrigin, setCustomOrigin ] = useState( '' );
	const [ isUpdating, setIsUpdating ] = useState( false );
	const isMounted = useRef( true );
	const hasInitializedAddress = useRef( false );
	const pickerRef = useRef( null );
	const { setBillingAddress, setShippingAddress, updateCustomerData } =
		useDispatch( cartStore );
	const { setEditingBillingAddress, setEditingShippingAddress } =
		useDispatch( checkoutStore );
	const { clearValidationError } = useDispatch( validationStore );
	const setExtensionData = checkoutExtensionData?.setExtensionData;
	const { currentAddress, shippingAddress, useShippingAsBilling } = useSelect(
		( select ) => {
			const customerData = select( cartStore ).getCustomerData();

			return {
				currentAddress:
					type === 'billing'
						? customerData.billingAddress
						: customerData.shippingAddress,
				shippingAddress: customerData.shippingAddress,
				useShippingAsBilling:
					select( checkoutStore ).getUseShippingAsBilling?.() ??
					false,
			};
		},
		[ type ]
	);
	const shouldRender =
		Boolean( settings.isLoggedIn ) &&
		Boolean( typeSettings.enabled ) &&
		addresses.length > 0 &&
		typeof setExtensionData === 'function' &&
		typeof window.unwanAddressPicker?.mount === 'function';

	useEffect( () => {
		isMounted.current = true;

		return () => {
			isMounted.current = false;
		};
	}, [] );

	// Keep WooCommerce's native form mounted as the checkout source of truth.
	useEffect( () => {
		if ( ! shouldRender ) {
			return;
		}

		if ( type === 'billing' ) {
			setEditingBillingAddress( ! useShippingAsBilling );
		} else {
			setEditingShippingAddress( true );
		}
	}, [
		setEditingBillingAddress,
		setEditingShippingAddress,
		shouldRender,
		type,
		useShippingAsBilling,
	] );

	useLayoutEffect( () => {
		if ( ! shouldRender ) {
			return undefined;
		}

		const checkoutStep = pickerRef.current?.closest(
			'.wc-block-components-checkout-step'
		);

		if ( ! checkoutStep ) {
			return undefined;
		}

		checkoutStep.classList.toggle(
			'unwan-checkout-step--fields-hidden',
			selection !== 'new'
		);

		return () => {
			checkoutStep.classList.remove(
				'unwan-checkout-step--fields-hidden'
			);
		};
	}, [ selection, shouldRender ] );

	const buildAddress = useCallback(
		( nextSelection ) => {
			const selectedAddress =
				nextSelection === 'new'
					? normalizeAddress()
					: normalizeAddress(
							addressMap[ nextSelection ]?.fields || {}
					  );

			if ( type === 'billing' && currentAddress?.email ) {
				selectedAddress.email = currentAddress.email;
			}

			return selectedAddress;
		},
		[ addressMap, currentAddress, type ]
	);

	const focusCountryField = useCallback( () => {
		window.requestAnimationFrame( () => {
			document.getElementById( `${ type }-country` )?.focus();
		} );
	}, [ type ] );

	const applyAddress = useCallback(
		async ( nextSelection ) => {
			const nextAddress = buildAddress( nextSelection );

			setIsUpdating( true );

			try {
				if ( type === 'billing' ) {
					setEditingBillingAddress( true );
					setBillingAddress( nextAddress );
					await updateCustomerData( {
						billingAddress: nextAddress,
					} );
				} else {
					setEditingShippingAddress( true );
					setShippingAddress( nextAddress );
					await updateCustomerData( {
						shippingAddress: nextAddress,
					} );
				}

				if ( nextSelection === 'new' ) {
					focusCountryField();
				}
			} catch {
				// WooCommerce owns checkout notices for customer-update errors.
			} finally {
				if ( isMounted.current ) {
					setIsUpdating( false );
				}
			}
		},
		[
			buildAddress,
			focusCountryField,
			setBillingAddress,
			setEditingBillingAddress,
			setEditingShippingAddress,
			setShippingAddress,
			type,
			updateCustomerData,
		]
	);

	// Initialize from the cart/customer address instead of replacing it.
	useEffect( () => {
		if (
			! shouldRender ||
			isUpdating ||
			hasInitializedAddress.current ||
			( type === 'billing' && useShippingAsBilling )
		) {
			return;
		}

		const matchingAddress = addresses.find( ( address ) =>
			addressMatches(
				currentAddress,
				normalizeAddress( address.fields || {} )
			)
		);

		hasInitializedAddress.current = true;

		if ( matchingAddress ) {
			setSelection( matchingAddress.id );
			return;
		}

		if ( hasAddress( currentAddress ) ) {
			const relatedAddress = addresses.find( ( address ) =>
				hasSameIdentity( currentAddress, address.fields || {} )
			);
			setSelection( 'custom' );
			setCustomOrigin( relatedAddress ? 'edited' : 'cart' );
			return;
		}

		setSelection( defaultSelection );
		applyAddress( defaultSelection );
	}, [
		addresses,
		applyAddress,
		currentAddress,
		defaultSelection,
		isUpdating,
		shouldRender,
		type,
		useShippingAsBilling,
	] );

	useEffect( () => {
		if ( ! shouldRender ) {
			return;
		}

		let submittedSelection = selection;

		if ( type === 'billing' && useShippingAsBilling ) {
			submittedSelection = '';
		} else if ( selection === 'custom' ) {
			submittedSelection = customOrigin === 'edited' ? '' : 'new';
		}

		setExtensionData(
			NAMESPACE,
			`${ type }_selection`,
			submittedSelection
		);

		if ( type === 'shipping' && useShippingAsBilling ) {
			setExtensionData( NAMESPACE, 'billing_selection', '' );
		}
	}, [
		customOrigin,
		selection,
		setExtensionData,
		shouldRender,
		type,
		useShippingAsBilling,
	] );

	// Enforce shipping-to-billing synchronization and clear hidden errors.
	useEffect( () => {
		if ( ! shouldRender || type !== 'billing' || ! useShippingAsBilling ) {
			return;
		}

		const nextBillingAddress = {
			...shippingAddress,
			email: currentAddress?.email || '',
		};

		setEditingBillingAddress( false );

		if ( ! allAddressFieldsMatch( currentAddress, nextBillingAddress ) ) {
			setBillingAddress( nextBillingAddress );
		}

		const fieldKeys = Array.isArray( settings.fieldKeys )
			? settings.fieldKeys
			: [];

		fieldKeys.forEach( ( key ) => {
			clearValidationError?.( `billing_${ key }` );
		} );
	}, [
		clearValidationError,
		currentAddress,
		setBillingAddress,
		setEditingBillingAddress,
		shippingAddress,
		shouldRender,
		type,
		useShippingAsBilling,
	] );

	const onSelectionChange = useCallback(
		( event ) => {
			const nextSelection = String( event.detail?.value || '' );
			if ( isUpdating || ! nextSelection ) {
				return;
			}

			setSelection( nextSelection );
			setCustomOrigin( nextSelection === 'new' ? 'new' : '' );
			applyAddress( nextSelection );
		},
		[ applyAddress, isUpdating ]
	);

	useEffect( () => {
		const picker = pickerRef.current;
		if ( ! picker ) {
			return undefined;
		}

		picker.addEventListener( 'unwan-selection-change', onSelectionChange );

		return () =>
			picker.removeEventListener(
				'unwan-selection-change',
				onSelectionChange
			);
	}, [ onSelectionChange, shouldRender ] );

	const summary =
		selection === 'custom'
			? summarizeAddress( currentAddress )
			: addressMap[ selection ] || defaultAddress;

	useLayoutEffect( () => {
		if ( ! shouldRender || ! pickerRef.current ) {
			return;
		}

		window.unwanAddressPicker.mount( pickerRef.current, {
			type,
			addresses,
			selection,
			summary,
			disabled: isUpdating,
			searchThreshold: settings.searchThreshold ?? 4,
			labels: getLabels( type ),
		} );
	}, [ addresses, isUpdating, selection, shouldRender, summary, type ] );

	useEffect( () => {
		const picker = pickerRef.current;

		return () => {
			if ( picker ) {
				window.unwanAddressPicker?.destroy( picker );
			}
		};
	}, [] );

	if ( ! shouldRender ) {
		return null;
	}

	return (
		<div
			id={ `unwan-${ type }-picker` }
			ref={ pickerRef }
			className={ `unwan-picker unwan-picker--block unwan-picker--${ type }` }
		/>
	);
};

registerCheckoutBlock( {
	metadata: billingMetadata,
	component: ( props ) => <AddressSelector { ...props } type="billing" />,
} );

registerCheckoutBlock( {
	metadata: shippingMetadata,
	component: ( props ) => <AddressSelector { ...props } type="shipping" />,
} );
