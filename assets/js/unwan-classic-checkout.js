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
		return [ 'address_1', 'city', 'postcode', 'country' ].some(
			function ( key ) {
				return normalizeValue( fields[ key ] ) !== '';
			}
		);
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
		const $selection = $( '#unwan_' + type + '_address_id' );
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
		const matchingAddress = addresses.find( function ( address ) {
			return addressesMatch( currentFields, address.fields || {} );
		} );
		const defaultAddress =
			addresses.find( function ( address ) {
				return address.isDefault;
			} ) || addresses[ 0 ];
		const state = {
			mode:
				! matchingAddress && hasAddress( currentFields )
					? 'custom'
					: 'saved',
			selection: matchingAddress?.id || defaultAddress.id,
			isApplying: false,
		};
		const picker = document.createElement( 'div' );
		picker.id = 'unwan-' + type + '-picker';
		picker.className =
			'unwan-picker unwan-picker--classic unwan-picker--' + type;
		$mount.empty().append( picker );
		const pickerController = window.unwanAddressPicker.mount( picker );

		/**
		 * Show native address fields only while a new address is entered.
		 * Fields Unwan doesn't manage (billing_email, any third-party
		 * fields) are left untouched and stay visible/editable throughout.
		 */
		function updateFieldVisibility() {
			$managedFieldRows.toggle( state.mode === 'new' );
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
			pickerController.update( {
				type,
				addresses,
				selection: state.mode === 'custom' ? 'custom' : state.selection,
				summary: getSummary(),
				disabled: state.isApplying,
				searchThreshold: config.searchThreshold ?? 4,
				labels: pickerLabels( type ),
			} );
			updateFieldVisibility();
		}

		picker.addEventListener( 'unwan-selection-change', function ( event ) {
			const nextSelection = String( event.detail?.value || '' );

			if ( nextSelection === 'new' ) {
				state.mode = 'new';
				state.selection = 'new';
			} else if ( addressMap[ nextSelection ] ) {
				state.mode = 'saved';
				state.selection = nextSelection;
			} else {
				return;
			}

			applySelection( nextSelection );
			render();
		} );

		if ( ! matchingAddress && ! hasAddress( currentFields ) ) {
			applySelection( defaultAddress.id );
		}

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
