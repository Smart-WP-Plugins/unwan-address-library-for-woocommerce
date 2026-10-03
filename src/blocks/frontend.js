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
import { select as selectStore, useDispatch, useSelect } from '@wordpress/data';
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
 * Address keys Unwan manages. Only these rows are ever collapsed; every other
 * field in the form, including fields added by other plugins, is left alone.
 */
const MANAGED_KEYS = (
	Array.isArray( settings.fieldKeys ) ? settings.fieldKeys : []
).filter( ( key ) => key !== 'email' );

// "Force shipping to the customer billing address" makes billing the
// shipping address, exactly as local pickup does.
const FORCED_BILLING_ADDRESS = Boolean(
	getSetting( 'forcedBillingAddress', false )
);

/**
 * Wait for React to commit and WooCommerce to re-validate changed fields.
 *
 * @return {Promise<void>} Resolves on the next macrotask.
 */
const nextTick = () =>
	new Promise( ( resolve ) => window.setTimeout( resolve, 0 ) );

/**
 * The customer's choice per address type, kept for this browser tab so a
 * reload shows what they chose. Only an address ID or "new" is stored, never
 * address data. Storage can be unavailable (private modes, blocked storage).
 */
const STORAGE_PREFIX = 'unwan-selection-';

const readStoredSelection = ( type ) => {
	try {
		return window.sessionStorage.getItem( STORAGE_PREFIX + type ) || '';
	} catch {
		return '';
	}
};

const storeSelection = ( type, value ) => {
	try {
		if ( value ) {
			window.sessionStorage.setItem( STORAGE_PREFIX + type, value );
		} else {
			window.sessionStorage.removeItem( STORAGE_PREFIX + type );
		}
	} catch {
		// Remembering the choice across a reload is a convenience only.
	}
};

/**
 * Selection state kept outside the React tree, keyed by address type.
 *
 * Multi-step checkout extensions can unmount and remount WooCommerce's
 * checkout blocks while the customer is still filling in an address. Without
 * this, every remount re-ran initialization against the half-entered address,
 * resolved it to "custom", and collapsed the fields being typed into.
 *
 * @type {Object<string, {selection: string, customOrigin: string, initialized: boolean}>}
 */
const selectionState = {};

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
 * @param {Object}   current  Current WooCommerce address.
 * @param {Object}   expected Normalized saved address.
 * @param {string[]} keys     Fields to compare.
 * @return {boolean} Whether all compared fields match.
 */
const addressMatches = ( current = {}, expected = {}, keys = MATCH_KEYS ) =>
	keys.every( ( key ) => {
		// WooCommerce reformats some postcodes (for example adding a space).
		const clean = ( value ) =>
			key === 'postcode'
				? normalizeValue( value ).replace( /\s+/g, '' )
				: normalizeValue( value );

		return clean( current[ key ] ) === clean( expected[ key ] );
	} );

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
	const persistedState = selectionState[ type ];
	const [ selection, setSelection ] = useState(
		() => persistedState?.selection || defaultSelection
	);
	const [ customOrigin, setCustomOrigin ] = useState(
		() => persistedState?.customOrigin || ''
	);
	// Managed rows revealed because WooCommerce reports them invalid for the
	// selected saved address. Only a new choice in the picker clears them, so
	// a row never collapses while the customer is filling it in.
	const [ revealedKeys, setRevealedKeys ] = useState(
		() => persistedState?.revealedKeys || []
	);
	const [ isUpdating, setIsUpdating ] = useState( false );
	const isMounted = useRef( true );
	const hasInitializedAddress = useRef(
		Boolean( persistedState?.initialized )
	);
	const pickerRef = useRef( null );
	const { setBillingAddress, setShippingAddress } = useDispatch( cartStore );
	const { setEditingBillingAddress, setEditingShippingAddress } =
		useDispatch( checkoutStore );
	const setExtensionData = checkoutExtensionData?.setExtensionData;
	const {
		currentAddress,
		billingEmail,
		useShippingAsBilling,
		prefersCollection,
		validationErrors,
		cartReady,
		checkoutComplete,
	} = useSelect(
		( select ) => {
			const customerData = select( cartStore ).getCustomerData();
			const checkout = select( checkoutStore );

			return {
				currentAddress:
					type === 'billing'
						? customerData.billingAddress
						: customerData.shippingAddress,
				billingEmail: customerData.billingAddress?.email || '',
				useShippingAsBilling:
					checkout.getUseShippingAsBilling?.() ?? false,
				prefersCollection: checkout.prefersCollection?.() ?? false,
				validationErrors:
					select( validationStore ).getValidationErrors?.() || {},
				// Until the cart has loaded, the store holds empty placeholder
				// addresses that must not be classified.
				cartReady:
					select( cartStore ).hasFinishedResolution?.(
						'getCartData'
					) ?? true,
				checkoutComplete: checkout.isComplete?.() ?? false,
			};
		},
		[ type ]
	);
	// WooCommerce makes the billing address the shipping address for local
	// pickup and for "Force shipping to the customer billing address".
	const billingIsShipping = FORCED_BILLING_ADDRESS || prefersCollection;
	// With "Use same address for billing" on a delivery order, WooCommerce
	// unmounts the billing step and copies shipping into billing itself.
	const shippingIsBilling = useShippingAsBilling && ! prefersCollection;
	const isSavedSelection = Boolean( addressMap[ selection ] );
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
	// When this selector is mounted it owns its address type, so the form is
	// always editable; Unwan collapses individual rows instead.
	useEffect( () => {
		if ( ! shouldRender ) {
			return;
		}

		if ( type === 'billing' ) {
			setEditingBillingAddress( true );
		} else {
			setEditingShippingAddress( true );
		}
	}, [
		setEditingBillingAddress,
		setEditingShippingAddress,
		shouldRender,
		type,
	] );

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

			if ( type === 'billing' ) {
				setEditingBillingAddress( true );
				setBillingAddress( nextAddress );

				// WooCommerce's own billing form copies billing into shipping
				// whenever billing is the shipping address. Do the same, so
				// shipping rates and taxes follow the chosen address.
				if ( billingIsShipping ) {
					const nextShipping = { ...nextAddress };
					delete nextShipping.email;
					setShippingAddress( nextShipping );
				}
			} else {
				setEditingShippingAddress( true );
				setShippingAddress( nextAddress );

				// With "Use same address for billing", WooCommerce's shipping
				// form copies every change into billing. Do the same, or the
				// order is billed to the previous address.
				if ( shippingIsBilling ) {
					setBillingAddress( {
						...nextAddress,
						email: billingEmail,
					} );
				}
			}

			// WooCommerce sends the changed address to the server itself. Give
			// it time to re-validate the new values before the reveal check
			// reads its validation state, so errors left over from the
			// previous address never count.
			await nextTick();
			await nextTick();

			if ( nextSelection === 'new' ) {
				focusCountryField();
			}

			if ( isMounted.current ) {
				setIsUpdating( false );
			}
		},
		[
			billingEmail,
			billingIsShipping,
			buildAddress,
			focusCountryField,
			setBillingAddress,
			setEditingBillingAddress,
			setEditingShippingAddress,
			setShippingAddress,
			shippingIsBilling,
			type,
		]
	);

	// Initialize from the cart/customer address instead of replacing it.
	useEffect( () => {
		if (
			! shouldRender ||
			! cartReady ||
			isUpdating ||
			hasInitializedAddress.current
		) {
			return;
		}

		hasInitializedAddress.current = true;

		// A reload keeps the customer's choice: a saved address is selected
		// (and re-applied if the cart lost part of it), "Enter a new address"
		// stays open.
		const storedSelection = readStoredSelection( type );

		if ( storedSelection === 'new' ) {
			setSelection( 'new' );
			setCustomOrigin( 'new' );
			return;
		}

		if ( addressMap[ storedSelection ] ) {
			setSelection( storedSelection );
			if (
				! addressMatches(
					currentAddress,
					normalizeAddress(
						addressMap[ storedSelection ].fields || {}
					)
				)
			) {
				applyAddress( storedSelection );
			}
			return;
		}

		const matchingAddress = addresses.find( ( address ) =>
			addressMatches(
				currentAddress,
				normalizeAddress( address.fields || {} )
			)
		);

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
		addressMap,
		addresses,
		applyAddress,
		cartReady,
		currentAddress,
		defaultSelection,
		isUpdating,
		shouldRender,
		type,
	] );

	// Re-check a saved selection against the cart after a remount restored it
	// and after the delivery mode changed. WooCommerce copies addresses
	// between billing and shipping when the mode changes (local pickup, "Use
	// same address for billing"), so the cart can hold a different address
	// than the saved one the picker still shows. When it does, show the cart's
	// address as a custom one, with its fields, so the summary never differs
	// from what the order will use.
	const needsReconcile = useRef( Boolean( persistedState?.initialized ) );
	const previousMode = useRef( null );
	// The saved address already put back once after an outside change.
	const reappliedFor = useRef( '' );

	useEffect( () => {
		const mode = `${ prefersCollection }|${ useShippingAsBilling }`;
		if ( previousMode.current !== null && previousMode.current !== mode ) {
			needsReconcile.current = true;
		}
		previousMode.current = mode;
	}, [ prefersCollection, useShippingAsBilling ] );

	useEffect( () => {
		if (
			! shouldRender ||
			isUpdating ||
			! hasInitializedAddress.current ||
			( ! needsReconcile.current && ! addressMap[ selection ] )
		) {
			return undefined;
		}

		// Read the cart after WooCommerce's own copy for the mode change.
		const timer = window.setTimeout( () => {
			const triggered = needsReconcile.current;
			needsReconcile.current = false;

			const saved = addressMap[ selection ];
			if ( ! saved ) {
				return;
			}

			const customerData = selectStore( cartStore ).getCustomerData();
			const cartAddress =
				type === 'billing'
					? customerData.billingAddress
					: customerData.shippingAddress;
			// Revealed rows are the customer's to edit; any other identity
			// field can only change from outside.
			const lockedKeys = MATCH_KEYS.filter(
				( key ) => ! revealedKeys.includes( key )
			);

			if (
				! hasAddress( cartAddress ) ||
				addressMatches(
					cartAddress,
					normalizeAddress( saved.fields || {} ),
					triggered ? MATCH_KEYS : lockedKeys
				)
			) {
				return;
			}

			// Something outside the picker replaced the address behind a
			// saved selection: WooCommerce refreshing the cart from the server
			// (it does not send an address with errors, so the two can
			// differ), or another plugin. Put the customer's choice back once;
			// if it is replaced again, follow the cart instead, so the
			// summary never shows one address while the order uses another.
			if ( ! triggered && reappliedFor.current !== selection ) {
				reappliedFor.current = selection;
				applyAddress( selection );
				return;
			}

			// With a separate billing address, WooCommerce copied billing into
			// shipping only as a side effect of local pickup: put back the
			// customer's delivery choice.
			if ( type === 'shipping' && ! shippingIsBilling ) {
				setRevealedKeys( [] );
				applyAddress( selection );
				return;
			}

			// The cart now holds another saved address (for example, with
			// "Use same address for billing", the billing address chosen
			// during local pickup): select it, collapsed.
			const matchingAddress = addresses.find( ( address ) =>
				addressMatches(
					cartAddress,
					normalizeAddress( address.fields || {} )
				)
			);

			if ( matchingAddress ) {
				setSelection( matchingAddress.id );
				setCustomOrigin( '' );
				setRevealedKeys( [] );
				return;
			}

			const relatedAddress = addresses.find( ( address ) =>
				hasSameIdentity( cartAddress, address.fields || {} )
			);
			setSelection( 'custom' );
			setCustomOrigin( relatedAddress ? 'edited' : 'cart' );
			setRevealedKeys( [] );
		}, 0 );

		return () => window.clearTimeout( timer );
	}, [
		addressMap,
		addresses,
		applyAddress,
		currentAddress,
		isUpdating,
		prefersCollection,
		revealedKeys,
		selection,
		shippingIsBilling,
		shouldRender,
		type,
		useShippingAsBilling,
	] );

	// Reveal managed rows WooCommerce reports invalid for a saved address: a
	// value the store now requires that the address lacks (a field editor or
	// WooCommerce made phone or company required), or a value the current
	// rules reject. WooCommerce registers these errors as soon as the fields
	// mount, so the rows show before the customer clicks Place order.
	useEffect( () => {
		if (
			! shouldRender ||
			! isSavedSelection ||
			isUpdating ||
			! hasInitializedAddress.current
		) {
			return undefined;
		}

		// Read the store after WooCommerce's inputs have re-validated in this
		// commit rather than the snapshot taken at render time.
		const timer = window.setTimeout( () => {
			const errors =
				selectStore( validationStore ).getValidationErrors?.() || {};
			const customerData = selectStore( cartStore ).getCustomerData();
			const cartAddress =
				( type === 'billing'
					? customerData.billingAddress
					: customerData.shippingAddress ) || {};
			const savedFields = addressMap[ selection ]?.fields || {};
			const invalid = MANAGED_KEYS.filter(
				( key ) =>
					errors[ `${ type }_${ key }` ] ||
					errors[ `${ type }-${ key }` ] ||
					// A value the order will use but the saved address doesn't
					// hold (for example a company typed into a revealed row
					// before a reload) must stay visible. The identity keys
					// already decide which address is selected.
					( ! MATCH_KEYS.includes( key ) &&
						normalizeValue( cartAddress[ key ] ) !==
							normalizeValue( savedFields[ key ] ) )
			);

			if ( ! invalid.length ) {
				return;
			}

			setRevealedKeys( ( previous ) => {
				const next = Array.from(
					new Set( [ ...previous, ...invalid ] )
				);
				return next.length === previous.length ? previous : next;
			} );
		}, 0 );

		return () => window.clearTimeout( timer );
	}, [
		addressMap,
		currentAddress,
		isSavedSelection,
		isUpdating,
		selection,
		shouldRender,
		type,
		validationErrors,
	] );

	// Survive a third-party remount mid-entry (see selectionState).
	useEffect( () => {
		if ( ! shouldRender ) {
			return;
		}

		selectionState[ type ] = {
			selection,
			customOrigin,
			revealedKeys,
			initialized: hasInitializedAddress.current,
		};

		if ( hasInitializedAddress.current ) {
			const remembered = selection === 'custom' ? 'new' : selection;
			storeSelection( type, remembered );

			// Keep the other address's remembered choice in step with
			// WooCommerce's own mirroring, so a reload restores what is in
			// effect: with "Use same address for billing", billing follows
			// the delivery address; during local pickup with that box ticked,
			// the delivery address follows billing.
			if ( type === 'shipping' && shippingIsBilling ) {
				storeSelection( 'billing', remembered );
			}
			if (
				type === 'billing' &&
				prefersCollection &&
				useShippingAsBilling
			) {
				storeSelection( 'shipping', remembered );
			}
		}
	}, [
		customOrigin,
		prefersCollection,
		revealedKeys,
		selection,
		shippingIsBilling,
		shouldRender,
		type,
		useShippingAsBilling,
	] );

	// A placed order ends this choice; the next checkout starts fresh.
	useEffect( () => {
		if ( checkoutComplete ) {
			storeSelection( type, '' );
		}
	}, [ checkoutComplete, type ] );

	useEffect( () => {
		if ( ! shouldRender ) {
			return;
		}

		let submittedSelection = selection;

		if ( selection === 'custom' ) {
			submittedSelection = customOrigin === 'edited' ? '' : 'new';
		}

		setExtensionData(
			NAMESPACE,
			`${ type }_selection`,
			submittedSelection
		);

		// The billing step is unmounted and WooCommerce copies shipping into
		// billing, so no billing choice of Unwan's applies.
		if ( type === 'shipping' && shippingIsBilling ) {
			setExtensionData( NAMESPACE, 'billing_selection', '' );
		}

		// Local pickup unmounts the shipping step and sends the billing address
		// as the shipping address. A shipping choice made before switching
		// must not apply; the shipping selector re-sends its own when it
		// mounts again.
		if ( type === 'billing' && prefersCollection ) {
			setExtensionData( NAMESPACE, 'shipping_selection', '' );
		}
	}, [
		customOrigin,
		prefersCollection,
		selection,
		setExtensionData,
		shippingIsBilling,
		shouldRender,
		type,
	] );

	const onSelectionChange = useCallback(
		( event ) => {
			const nextSelection = String( event.detail?.value || '' );
			if ( isUpdating || ! nextSelection ) {
				return;
			}

			// Choosing "Enter a new address" again must not clear what the
			// customer has already typed.
			if (
				nextSelection === 'new' &&
				( selection === 'new' || selection === 'custom' )
			) {
				return;
			}

			setSelection( nextSelection );
			setCustomOrigin( nextSelection === 'new' ? 'new' : '' );
			setRevealedKeys( [] );
			reappliedFor.current = '';
			applyAddress( nextSelection );
		},
		[ applyAddress, isUpdating, selection ]
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
			// An address that isn't in the book is edited in the open fields,
			// so the picker shows "Enter a new address" as the choice rather
			// than a collapsed summary above open fields.
			selection: selection === 'custom' ? 'new' : selection,
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

	// Only a saved address stands in for the native fields, and only for rows
	// that need nothing from the customer. The list lives on Unwan's own
	// element: WooCommerce re-renders its fieldset's class list (for example
	// while an order is processing), which would erase anything added there.
	// assets/css/unwan.css hides each listed row with :has().
	const collapsedKeys = isSavedSelection
		? MANAGED_KEYS.filter( ( key ) => ! revealedKeys.includes( key ) )
		: [];

	return (
		<div
			id={ `unwan-${ type }-picker` }
			ref={ pickerRef }
			className={ `unwan-picker unwan-picker--block unwan-picker--${ type }` }
			data-unwan-collapse={ collapsedKeys.join( ' ' ) || undefined }
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
