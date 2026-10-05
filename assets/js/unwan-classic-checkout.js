/**
 * Unwan classic checkout address selection.
 */

/**
 * Initialize classic checkout address selection.
 *
 * @param {Function} $      jQuery.
 * @param {Object}   config Address-book configuration.
 */
( function ( $, config ) {
	'use strict';

	if (
		! config ||
		! config.types ||
		typeof window.unwanAddressPicker?.mount !== 'function'
	) {
		return;
	}

	const matchKeys = [
		'first_name',
		'last_name',
		'country',
		'address_1',
		'address_2',
		'city',
		'state',
		'postcode',
	];
	const fieldKeys = Array.isArray( config.fieldKeys ) ? config.fieldKeys : [];
	const STORAGE_PREFIX = 'unwan-selection-';

	/**
	 * The customer's choice for an address type in this browser tab. Only an
	 * address ID or "new" is stored, never address data.
	 *
	 * @param {string} type Address type.
	 * @return {string} Stored choice, or ''.
	 */
	function readStoredSelection( type ) {
		try {
			return window.sessionStorage.getItem( STORAGE_PREFIX + type ) || '';
		} catch ( error ) {
			return '';
		}
	}

	/**
	 * Remember (or forget, with an empty value) the customer's choice.
	 *
	 * @param {string} type  Address type.
	 * @param {string} value Address ID, "new", or ''.
	 */
	function storeSelection( type, value ) {
		try {
			if ( value ) {
				window.sessionStorage.setItem( STORAGE_PREFIX + type, value );
			} else {
				window.sessionStorage.removeItem( STORAGE_PREFIX + type );
			}
		} catch ( error ) {
			// Remembering the choice across a reload is a convenience only.
		}
	}

	/**
	 * Normalize a field value for address comparisons.
	 *
	 * @param {*} value Field value.
	 * @return {string} Comparable value.
	 */
	function normalizeValue( value ) {
		return String( value || '' )
			.trim()
			.toLocaleLowerCase();
	}

	/**
	 * Read WooCommerce's current checkout fields.
	 *
	 * @param {string} type Address type.
	 * @return {Object} Current field values.
	 */
	function readFields( type ) {
		return fieldKeys.reduce( function ( fields, key ) {
			const $field = $( '#' + type + '_' + key );
			if ( $field.is( ':checkbox' ) ) {
				fields[ key ] = $field.is( ':checked' ) ? '1' : '';
			} else {
				fields[ key ] = $field.val() || '';
			}
			return fields;
		}, {} );
	}

	/**
	 * Whether an address contains meaningful postal data.
	 *
	 * @param {Object} fields Address fields.
	 * @return {boolean} Whether an address exists.
	 */
	function hasAddress( fields ) {
		// A country on its own is not an address: WooCommerce pre-fills it
		// from the store's base location or geolocation for every session.
		return [ 'address_1', 'city', 'postcode' ].some( function ( key ) {
			return normalizeValue( fields[ key ] ) !== '';
		} );
	}

	/**
	 * Compare a checkout address with a saved entry.
	 *
	 * @param {Object} current Current checkout fields.
	 * @param {Object} saved   Saved fields.
	 * @return {boolean} Whether all address-book fields match.
	 */
	function addressesMatch( current, saved ) {
		return matchKeys.every( function ( key ) {
			return (
				normalizeValue( current[ key ] ) ===
				normalizeValue( saved[ key ] )
			);
		} );
	}

	/**
	 * Comparable form of one field value.
	 *
	 * @param {string} key   Field key.
	 * @param {*}      value Field value.
	 * @return {string} Comparable value.
	 */
	function comparable( key, value ) {
		const normalized = normalizeValue( value );
		return key === 'postcode'
			? normalized.replace( /\s+/g, '' )
			: normalized;
	}

	/**
	 * Whether every stored field of a saved entry matches the form, including
	 * company and phone, which the identity match ignores.
	 *
	 * @param {Object} current Current checkout fields.
	 * @param {Object} saved   Saved fields.
	 * @return {boolean} Whether all stored fields match.
	 */
	function allFieldsMatch( current, saved ) {
		return fieldKeys
			.filter( function ( key ) {
				return key !== 'email';
			} )
			.every( function ( key ) {
				return (
					comparable( key, current[ key ] ) ===
					comparable( key, saved[ key ] )
				);
			} );
	}

	/**
	 * Update a checkout field while preserving WooCommerce's event flow.
	 *
	 * @param {string} type  Address type.
	 * @param {string} key   Field key.
	 * @param {string} value Field value.
	 */
	function setField( type, key, value ) {
		const $field = $( '#' + type + '_' + key );

		if ( ! $field.length ) {
			return;
		}

		if ( $field.is( ':checkbox' ) ) {
			$field.prop( 'checked', value === '1' ).trigger( 'change' );
			return;
		}

		$field.val( value ).trigger( 'change' );
	}

	/**
	 * Create summary text from the native checkout fields.
	 *
	 * @param {string} type   Address type.
	 * @param {Object} fields Address fields.
	 * @return {Object} Summary values.
	 */
	function summarizeFields( type, fields ) {
		const labels = config.labels || {};
		const name = [ fields.first_name, fields.last_name ]
			.filter( Boolean )
			.join( ' ' );
		const street = [ fields.address_1, fields.address_2 ]
			.filter( Boolean )
			.join( ', ' );
		const stateText =
			$( '#' + type + '_state option:selected' ).text() ||
			fields.state ||
			'';
		const countryText =
			$( '#' + type + '_country option:selected' ).text() ||
			fields.country ||
			'';
		const region = [ stateText, fields.postcode ]
			.filter( Boolean )
			.join( ' ' );

		return {
			name: name || fields.company || labels.address || 'Address',
			street: street || labels.address || 'Address',
			details: [ fields.city, region, countryText ]
				.filter( Boolean )
				.join( ', ' ),
		};
	}

	/**
	 * Labels for a component instance.
	 *
	 * @param {string} type Address type.
	 * @return {Object} Component labels.
	 */
	function pickerLabels( type ) {
		const labels = config.labels || {};

		return {
			compactHeading:
				labels[ type + 'CompactHeading' ] ||
				( type === 'billing' ? 'Billing to' : 'Delivering to' ),
			panelHeading:
				labels[ type + 'PanelHeading' ] ||
				( type === 'billing' ? 'Bill to' : 'Deliver to' ),
			savedAddress: labels.savedAddress || '%d saved address',
			savedAddresses: labels.savedAddresses || '%d saved addresses',
			moreAddress: labels.moreAddress || '%d more saved address',
			moreAddresses: labels.moreAddresses || '%d more saved addresses',
			searchLabel: labels.searchLabel || 'Search saved addresses',
			searchPlaceholder:
				labels.searchPlaceholder ||
				'Filter by street, city or postcode',
			noResults:
				labels.noResults || 'No saved addresses match your search.',
			newAddress: labels.newAddress || 'Enter a new address',
			default: labels.default || 'Default',
			change: labels.change || 'Change',
		};
	}

	/**
	 * Initialize one classic checkout picker.
	 *
	 * @param {HTMLElement} element Picker mount point.
	 */
	function initializePicker( element ) {
		const $mount = $( element );
		if ( $mount.data( 'unwanInitialized' ) ) {
			return;
		}

		const type = String( $mount.attr( 'data-unwan-address-type' ) || '' );
		const typeConfig = config.types[ type ];
		const addresses = Array.isArray( typeConfig?.addresses )
			? typeConfig.addresses
			: [];

		if ( ! typeConfig || ! addresses.length ) {
			$mount.remove();
			return;
		}

		$mount.data( 'unwanInitialized', true );

		const addressMap = addresses.reduce( function ( map, address ) {
			map[ address.id ] = address;
			return map;
		}, {} );
		let $selection = $( '#unwan_' + type + '_address_id' );

		// Checkout templates that print only their own fields leave out the
		// hidden selection input. The picker is on the page, so add the input
		// to the form; otherwise the customer's choice would not be posted.
		if ( ! $selection.length ) {
			$selection = $( '<input>', {
				type: 'hidden',
				id: 'unwan_' + type + '_address_id',
				name: 'unwan_' + type + '_address_id',
			} ).appendTo( $mount.closest( 'form' ) );
		}
		// Toggle only the rows Unwan actually manages (fieldKeys never
		// includes "email" — see AddressRepository::FIELD_KEYS). Toggling the
		// whole fieldset wrapper instead would also hide billing_email, and
		// any third-party fields other plugins add to the same fieldset.
		const $managedFieldRows = $(
			fieldKeys
				.filter( function ( key ) {
					return key !== 'email';
				} )
				.map( function ( key ) {
					return '#' + type + '_' + key + '_field';
				} )
				.join( ',' )
		);
		const currentFields = readFields( type );
		// WooCommerce's classic checkout keeps only the street, city,
		// postcode, state and country between page loads, not the name,
		// company or phone. After a reload the form can therefore hold a mix
		// of two addresses, so the customer's remembered choice wins.
		const storedSelection = readStoredSelection( type );
		const storedAddress = addressMap[ storedSelection ] || null;
		const matchingAddress =
			storedAddress &&
			addressesMatch( currentFields, storedAddress.fields || {} )
				? storedAddress
				: addresses.find( function ( address ) {
						return addressesMatch(
							currentFields,
							address.fields || {}
						);
				  } );
		const defaultAddress =
			addresses.find( function ( address ) {
				return address.isDefault;
			} ) || addresses[ 0 ];
		let initialMode = 'saved';
		if ( storedSelection === 'new' ) {
			initialMode = 'new';
		} else if (
			! storedAddress &&
			! matchingAddress &&
			hasAddress( currentFields )
		) {
			initialMode = 'custom';
		}
		const state = {
			mode: initialMode,
			selection:
				( initialMode === 'new' && 'new' ) ||
				storedAddress?.id ||
				matchingAddress?.id ||
				defaultAddress.id,
			isApplying: false,
			// Rows revealed because WooCommerce reports them required-but-empty
			// or invalid for the selected saved address. Only a new choice in
			// the picker clears them, so a row never collapses while typing.
			revealed: {},
		};
		const picker = document.createElement( 'div' );
		picker.id = 'unwan-' + type + '-picker';
		picker.className =
			'unwan-picker unwan-picker--classic unwan-picker--' + type;
		$mount.empty().append( picker );
		const pickerController = window.unwanAddressPicker.mount( picker );

		/**
		 * Whether WooCommerce needs the customer to look at a managed row: a
		 * required field the saved address leaves empty (a field editor or
		 * WooCommerce may have made phone or company required), or a field
		 * WooCommerce marked invalid. "Required" comes from the row's class or
		 * from the checkout field configuration the server sends, because the
		 * class can be missing for a while after the page loads.
		 *
		 * @param {HTMLElement} row Field row.
		 * @return {boolean} Whether the row must stay visible.
		 */
		function needsAttention( row ) {
			// WooCommerce hides some rows for the selected country itself.
			if ( row.style.display === 'none' ) {
				return false;
			}

			const $row = $( row );
			if ( $row.hasClass( 'woocommerce-invalid' ) ) {
				return true;
			}

			const key = row.id.slice( type.length + 1, -'_field'.length );

			// A value the order will use but the selected address doesn't hold
			// (WooCommerce can keep another address's company or phone in the
			// form) must stay visible.
			const savedFields = addressMap[ state.selection ]?.fields;
			if (
				savedFields &&
				comparable( key, $( '#' + type + '_' + key ).val() ) !==
					comparable( key, savedFields[ key ] )
			) {
				return true;
			}

			// What the order will be validated against for this country.
			const country = String( $( '#' + type + '_country' ).val() || '' );
			const requiredKeys = config.requiredKeys?.[ type ]?.[ country ];
			const required = Array.isArray( requiredKeys )
				? requiredKeys.indexOf( key ) !== -1
				: $row.hasClass( 'validate-required' );
			if ( ! required ) {
				return false;
			}

			const $control = $row
				.find( 'input, select, textarea' )
				.not( '[type="hidden"]' )
				.first();

			return (
				$control.length > 0 &&
				String( $control.val() || '' ).trim() === ''
			);
		}

		/**
		 * Collapse the native address rows only while a saved address stands
		 * in for them, and never a row that needs the customer. A "new" or
		 * "custom" address keeps every row visible.
		 *
		 * A class is used rather than inline display, so WooCommerce's own
		 * per-country show/hide of rows keeps working. Fields Unwan doesn't
		 * manage (billing_email, any third-party fields) are never touched.
		 */
		function updateFieldVisibility() {
			const collapse = state.mode === 'saved';

			$managedFieldRows.each( function () {
				if ( collapse && needsAttention( this ) ) {
					state.revealed[ this.id ] = true;
				}

				$( this ).toggleClass(
					'unwan-checkout__field--collapsed',
					collapse && ! state.revealed[ this.id ]
				);
			} );
		}

		/**
		 * Persist the current selection into checkout POST data.
		 */
		function updateHiddenSelection() {
			$selection.val( state.mode === 'saved' ? state.selection : 'new' );
		}

		/**
		 * Apply a saved or empty address to WooCommerce's form.
		 *
		 * @param {string} selection Saved ID or "new".
		 */
		function applySelection( selection ) {
			const address =
				selection === 'new'
					? {}
					: addressMap[ selection ]?.fields || {};

			state.isApplying = true;
			fieldKeys.forEach( function ( key ) {
				if ( key !== 'email' ) {
					const value =
						selection === 'new' && key === 'country'
							? config.baseCountry || ''
							: address[ key ] || '';
					setField( type, key, value );
				}
			} );
			state.isApplying = false;
			$( document.body ).trigger( 'update_checkout' );
		}

		/**
		 * Current summary data for the compact state.
		 *
		 * @return {Object} Summary display values.
		 */
		function getSummary() {
			if ( state.mode === 'saved' ) {
				return addressMap[ state.selection ] || defaultAddress;
			}

			return summarizeFields( type, readFields( type ) );
		}

		/**
		 * Push presentation state into the shared picker.
		 */
		function render() {
			updateHiddenSelection();
			storeSelection(
				type,
				state.mode === 'saved' ? state.selection : 'new'
			);
			pickerController.update( {
				type,
				addresses,
				// An address that isn't in the book is edited in the open
				// fields, so the picker shows "Enter a new address" as the
				// choice rather than a collapsed summary above open fields.
				selection: state.mode === 'saved' ? state.selection : 'new',
				summary: getSummary(),
				disabled: state.isApplying,
				searchThreshold: config.searchThreshold ?? 4,
				labels: pickerLabels( type ),
			} );
			updateFieldVisibility();
		}

		picker.addEventListener( 'unwan-selection-change', function ( event ) {
			const nextSelection = String( event.detail?.value || '' );

			// Choosing "Enter a new address" again must not clear what the
			// customer has already typed.
			if ( nextSelection === 'new' && state.mode !== 'saved' ) {
				render();
				return;
			}

			if ( nextSelection === 'new' ) {
				state.mode = 'new';
				state.selection = 'new';
			} else if ( addressMap[ nextSelection ] ) {
				state.mode = 'saved';
				state.selection = nextSelection;
			} else {
				return;
			}

			state.revealed = {};
			applySelection( nextSelection );
			render();
		} );

		// Re-check whenever a managed row's classes change. WooCommerce's
		// country rules, its validation, and field editors all mark rows
		// required or invalid through classes, some of them only after a
		// delay (ThemeHigh re-applies "required" after updated_checkout).
		// Toggling Unwan's own class only writes the attribute when it really
		// changes, so this settles after one extra pass.
		if ( typeof window.MutationObserver === 'function' ) {
			const rowObserver = new window.MutationObserver( function () {
				// Applying a saved address sets the country before the state,
				// so WooCommerce briefly rebuilds an empty state field.
				// render() checks the rows once every value is in place.
				if ( ! state.isApplying ) {
					updateFieldVisibility();
				}
			} );

			$managedFieldRows.each( function () {
				rowObserver.observe( this, {
					attributes: true,
					attributeFilter: [ 'class' ],
				} );
			} );
		}

		// A rejected order names the fields at fault in its notice
		// (data-id="billing_postcode"). Server-side checks such as postcode
		// format or a country the store doesn't ship to never mark the row
		// itself, so reveal every managed row the notice names.
		$( document.body ).on( 'checkout_error', function () {
			if ( state.isApplying ) {
				return;
			}

			$(
				'.woocommerce-error [data-id], .woocommerce-NoticeGroup [data-id]'
			).each( function () {
				const rowId =
					String( $( this ).attr( 'data-id' ) || '' ) + '_field';
				if ( $managedFieldRows.filter( '#' + rowId ).length ) {
					state.revealed[ rowId ] = true;
				}
			} );

			updateFieldVisibility();
		} );

		// Another script can blank the hidden input (it sits inside
		// WooCommerce's field wrapper). Write it again right before submit.
		$mount.closest( 'form' ).on( 'checkout_place_order', function () {
			updateHiddenSelection();
		} );

		if (
			storedAddress &&
			! allFieldsMatch( currentFields, storedAddress.fields || {} )
		) {
			// Fill in what WooCommerce forgot on reload (name, company, phone).
			applySelection( storedAddress.id );
		} else if (
			initialMode === 'saved' &&
			! matchingAddress &&
			! hasAddress( currentFields )
		) {
			applySelection( defaultAddress.id );
		}

		// A placed order ends this choice; the next checkout starts fresh.
		$mount
			.closest( 'form' )
			.on( 'checkout_place_order_success', function () {
				storeSelection( type, '' );
			} );

		render();
	}

	function initializeAll() {
		[ 'billing', 'shipping' ].forEach( function ( type ) {
			const typeConfig = config.types[ type ];
			if (
				! Array.isArray( typeConfig?.addresses ) ||
				! typeConfig.addresses.length
			) {
				return;
			}

			if (
				$(
					'.unwan-checkout__selector[data-unwan-address-type="' +
						type +
						'"]'
				).length
			) {
				return;
			}

			const $fieldWrapper = $(
				type === 'billing'
					? '.woocommerce-billing-fields__field-wrapper'
					: '.woocommerce-shipping-fields__field-wrapper'
			).first();

			if ( ! $fieldWrapper.length ) {
				return;
			}

			$( '<div>', {
				class: 'unwan-checkout__selector',
				'data-unwan-address-type': type,
			} ).insertBefore( $fieldWrapper );
		} );

		$( '.unwan-checkout__selector' ).each( function () {
			initializePicker( this );
		} );
	}

	$( initializeAll );
	$( document.body ).on( 'updated_checkout', initializeAll );
} )( window.jQuery, window.unwanClassicCheckout );
