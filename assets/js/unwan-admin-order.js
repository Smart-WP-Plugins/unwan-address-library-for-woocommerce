/**
 * Unwan saved-address dropdown on the admin order screen.
 */

/**
 * Initialize the order-screen address dropdowns.
 *
 * @param {Function} $      jQuery.
 * @param {Object}   config Address book and labels.
 */
( function ( $, config ) {
	'use strict';

	if ( ! config || ! config.labels ) {
		return;
	}

	const TYPES = [ 'billing', 'shipping' ];
	let addresses = config.addresses || {};
	let request = null;

	/**
	 * The editable address fields of one order column.
	 *
	 * @param {string} type Address type.
	 * @return {Object} jQuery collection.
	 */
	function editArea( type ) {
		// Found from a field, not the column: WooCommerce before 11.0 and
		// the Subscriptions edit screen give the columns no type class.
		const $field = $( ':input#_' + type + '_first_name' );
		const $known = $field.length
			? $field
			: $( ':input[name^="_' + type + '_"]' ).first();

		return $known.closest( 'div.edit_address' );
	}

	/**
	 * Set one order field and let WooCommerce react to it.
	 *
	 * The state field is looked up each time because WooCommerce replaces it
	 * whenever the country changes.
	 *
	 * @param {string} type  Address type.
	 * @param {string} key   Field key.
	 * @param {string} value Field value.
	 */
	function setField( type, key, value ) {
		const $field = $( ':input#_' + type + '_' + key );

		if ( $field.length ) {
			$field.val( value === undefined ? '' : value ).trigger( 'change' );
		}
	}

	/**
	 * Fill an order address from a saved entry.
	 *
	 * Only the address fields are written. Nothing is saved to the address
	 * book and the customer's defaults are not touched.
	 *
	 * @param {string} type   Address type.
	 * @param {Object} fields Saved address fields.
	 */
	function fill( type, fields ) {
		// Country first: WooCommerce rebuilds the state field for it.
		if ( Object.prototype.hasOwnProperty.call( fields, 'country' ) ) {
			setField( type, 'country', fields.country );
		}

		Object.keys( fields ).forEach( function ( key ) {
			if ( key !== 'country' ) {
				setField( type, key, fields[ key ] );
			}
		} );
	}

	/**
	 * Render or remove the dropdown for one address type.
	 *
	 * @param {string} type Address type.
	 */
	function render( type ) {
		const $area = editArea( type );
		const id = 'unwan-order-' + type + '-address';
		const options = Array.isArray( addresses[ type ] )
			? addresses[ type ]
			: [];

		$area.find( '.unwan-order-address' ).remove();

		if ( ! $area.length || ! options.length ) {
			return;
		}

		const $select = $( '<select></select>' )
			.attr( 'id', id )
			.append(
				$( '<option></option>' ).val( '' ).text( config.labels.choose )
			);

		options.forEach( function ( option ) {
			$select.append(
				$( '<option></option>' ).val( option.id ).text( option.label )
			);
		} );

		/**
		 * Fill the order from the chosen entry, then reset the dropdown so
		 * the same entry can fill the fields again later.
		 */
		function applyChoice() {
			const chosen = options.find( function ( option ) {
				return option.id === $select.val();
			} );

			pending = false;
			viaKeyboard = false;

			if ( chosen ) {
				fill( type, chosen.fields || {} );
			}

			$select.val( '' );
		}

		// A mouse choice fills at once. On Windows and Linux, arrow keys and
		// type-ahead change a closed select on every key press, so keyboard
		// choices fill on Enter or when focus leaves the dropdown; otherwise
		// one exploratory key press would overwrite the order's fields.
		let viaKeyboard = false;
		let pending = false;

		$select.on( 'mousedown pointerdown', function () {
			viaKeyboard = false;
		} );

		$select.on( 'keydown', function ( event ) {
			if ( event.key === 'Enter' ) {
				// Chromium and WebKit on macOS submit the order form on Enter
				// in a select; here Enter only confirms a choice.
				event.preventDefault();
				if ( pending ) {
					applyChoice();
				}
				return;
			}

			if ( event.key !== 'Tab' && event.key !== 'Shift' ) {
				viaKeyboard = true;
			}
		} );

		$select.on( 'change', function () {
			if ( viaKeyboard ) {
				pending = true;
				return;
			}

			applyChoice();
		} );

		$select.on( 'blur', function () {
			if ( pending ) {
				applyChoice();
			}
		} );

		$( '<p></p>' )
			.addClass( 'form-field form-field-wide unwan-order-address' )
			.append(
				$( '<label></label>' )
					.attr( 'for', id )
					.text( config.labels.savedAddresses )
			)
			.append( $select )
			.prependTo( $area );
	}

	/**
	 * Render both dropdowns.
	 */
	function renderAll() {
		TYPES.forEach( render );
	}

	/**
	 * The customer currently chosen on the order.
	 *
	 * @return {number} Customer ID, or 0 for a guest.
	 */
	function currentCustomer() {
		return parseInt( $( '#customer_user' ).val(), 10 ) || 0;
	}

	/**
	 * Show the address book from a customer lookup reply.
	 *
	 * @param {number} userId   Customer the reply is for.
	 * @param {Object} response WooCommerce's customer lookup reply.
	 * @return {boolean} Whether the reply carried the address book.
	 */
	function applyResponse( userId, response ) {
		if ( ! response || ! response.unwan_addresses ) {
			return false;
		}

		// Re-render only on new data, so a reply to WooCommerce's own lookup
		// does not rebuild a dropdown that staff are using.
		if (
			userId === currentCustomer() &&
			JSON.stringify( response.unwan_addresses ) !==
				JSON.stringify( addresses )
		) {
			addresses = response.unwan_addresses;
			renderAll();
		}

		return true;
	}

	/**
	 * Reload the address book after the order's customer changes.
	 *
	 * Uses WooCommerce's own customer lookup, which checks its nonce and the
	 * edit_shop_orders capability; Unwan adds the address book to its reply.
	 * A failed lookup is retried once, after a pause.
	 *
	 * @param {number} attempt Attempt number, from 1.
	 */
	function reloadForCustomer( attempt ) {
		const userId = currentCustomer();
		const meta = window.woocommerce_admin_meta_boxes || {};
		const tries = typeof attempt === 'number' ? attempt : 1;

		if ( request ) {
			request.abort();
			request = null;
		}

		if ( tries === 1 ) {
			addresses = {};
			renderAll();
		}

		if ( ! userId || ! meta.ajax_url ) {
			return;
		}

		request = $.ajax( {
			url: meta.ajax_url,
			type: 'POST',
			data: {
				action: 'woocommerce_get_customer_details',
				user_id: userId,
				security: meta.get_customer_details_nonce,
			},
			success( response ) {
				applyResponse( userId, response );
			},
			error( xhr, status ) {
				if (
					status !== 'abort' &&
					tries < 2 &&
					userId === currentCustomer()
				) {
					window.setTimeout( function () {
						if ( userId === currentCustomer() ) {
							reloadForCustomer( tries + 1 );
						}
					}, 1500 );
				}
			},
			complete() {
				request = null;
			},
		} );
	}

	/**
	 * Take the address book from WooCommerce's own customer lookups too
	 * ("Load billing address" and friends), so a reply that succeeds there
	 * is never wasted.
	 *
	 * @param {Object} event    jQuery event.
	 * @param {Object} xhr      Request.
	 * @param {Object} settings Request settings.
	 */
	function onAjaxSuccess( event, xhr, settings ) {
		const data =
			settings && typeof settings.data === 'string' ? settings.data : '';

		if (
			data.indexOf( 'action=woocommerce_get_customer_details' ) === -1
		) {
			return;
		}

		const match = data.match( /(?:^|&)user_id=(\d+)/ );
		if ( match ) {
			applyResponse( parseInt( match[ 1 ], 10 ), xhr.responseJSON );
		}
	}

	$( function () {
		renderAll();
		$( '#customer_user' ).on( 'change', function () {
			reloadForCustomer( 1 );
		} );
		$( document ).on( 'ajaxSuccess', onAjaxSuccess );
	} );
} )( window.jQuery, window.unwanAdminOrder );
