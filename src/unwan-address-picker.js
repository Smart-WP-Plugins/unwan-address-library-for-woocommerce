/**
 * Shared standard-DOM address picker used by classic and block checkout.
 *
 * Presentation belongs exclusively to assets/css/unwan.css. This module
 * owns markup, interaction, filtering, and the selection event only.
 */
( function () {
	'use strict';

	if ( window.unwanAddressPicker ) {
		return;
	}

	let pickerSequence = 0;
	const instances = new WeakMap();

	/**
	 * Escape a value for safe insertion into generated markup.
	 *
	 * @param {*} value Raw value.
	 * @return {string} Escaped text.
	 */
	function escapeHtml( value ) {
		return String( value || '' )
			.replaceAll( '&', '&amp;' )
			.replaceAll( '<', '&lt;' )
			.replaceAll( '>', '&gt;' )
			.replaceAll( '"', '&quot;' )
			.replaceAll( "'", '&#039;' );
	}

	/**
	 * Replace the count placeholder in a translated label.
	 *
	 * @param {string} template Label containing %d.
	 * @param {number} count    Count.
	 * @return {string} Formatted label.
	 */
	function countLabel( template, count ) {
		return String( template || '' ).replace( '%d', String( count ) );
	}

	/**
	 * Run work after the current render.
	 *
	 * @param {Function} callback Deferred work.
	 */
	function nextFrame( callback ) {
		if ( typeof window.requestAnimationFrame === 'function' ) {
			window.requestAnimationFrame( callback );
			return;
		}

		window.setTimeout( callback, 0 );
	}

	/**
	 * Standard-DOM picker controller.
	 */
	class UnwanPicker {
		/**
		 * Create a controller for one mount element.
		 *
		 * @param {HTMLElement} element Mount element.
		 */
		constructor( element ) {
			this.element = element;
			this.data = {};
			this.open = false;
			this.search = '';
			this.id = `unwan-picker-${ ++pickerSequence }`;
			this.element.setAttribute( 'data-unwan-ui', '' );

			this.onClick = this.onClick.bind( this );
			this.onInput = this.onInput.bind( this );
			this.onKeyDown = this.onKeyDown.bind( this );

			this.element.addEventListener( 'click', this.onClick );
			this.element.addEventListener( 'input', this.onInput );
			this.element.addEventListener( 'keydown', this.onKeyDown );
		}

		/**
		 * Update serializable picker data.
		 *
		 * @param {Object} value Picker data.
		 */
		update( value ) {
			const previousSelection = this.data.selection;
			this.data = value && typeof value === 'object' ? value : {};

			if (
				previousSelection &&
				previousSelection !== this.data.selection
			) {
				this.open = false;
				this.search = '';
			}

			this.render();
		}

		/**
		 * Remove listeners and generated markup.
		 */
		destroy() {
			this.element.removeEventListener( 'click', this.onClick );
			this.element.removeEventListener( 'input', this.onInput );
			this.element.removeEventListener( 'keydown', this.onKeyDown );
			this.element.replaceChildren();
			this.element.removeAttribute( 'data-unwan-ui' );
			instances.delete( this.element );
		}

		/**
		 * Handle picker buttons.
		 *
		 * @param {MouseEvent} event Click event.
		 */
		onClick( event ) {
			const button = event.target.closest( 'button[data-unwan-action]' );

			if ( ! button || ! this.element.contains( button ) ) {
				return;
			}

			const action = button.dataset.unwanAction;

			if ( action === 'open' ) {
				this.openChooser();
				return;
			}

			if ( action === 'select' ) {
				this.select( String( button.dataset.unwanValue || '' ) );
			}
		}

		/**
		 * Filter saved options as the search value changes.
		 *
		 * @param {InputEvent} event Input event.
		 */
		onInput( event ) {
			if ( ! event.target.matches( '.unwan-address-search__input' ) ) {
				return;
			}

			this.search = event.target.value;
			this.filterOptions();
		}

		/**
		 * Support conventional radio-group arrow-key behavior.
		 *
		 * @param {KeyboardEvent} event Keyboard event.
		 */
		onKeyDown( event ) {
			const option = event.target.closest(
				'.unwan-address-item[role="radio"]'
			);
			const supportedKeys = [
				'ArrowDown',
				'ArrowRight',
				'ArrowUp',
				'ArrowLeft',
				'Home',
				'End',
			];

			if (
				! option ||
				! this.element.contains( option ) ||
				! supportedKeys.includes( event.key )
			) {
				return;
			}

			const options = Array.from(
				this.element.querySelectorAll(
					'.unwan-address-item[role="radio"]:not([hidden]):not(:disabled)'
				)
			);

			if ( options.length === 0 ) {
				return;
			}

			event.preventDefault();

			const currentIndex = Math.max( options.indexOf( option ), 0 );
			let nextIndex = currentIndex;

			if ( event.key === 'Home' ) {
				nextIndex = 0;
			} else if ( event.key === 'End' ) {
				nextIndex = options.length - 1;
			} else if (
				event.key === 'ArrowDown' ||
				event.key === 'ArrowRight'
			) {
				nextIndex = ( currentIndex + 1 ) % options.length;
			} else {
				nextIndex =
					( currentIndex - 1 + options.length ) % options.length;
			}

			const next = options[ nextIndex ];
			const nextValue = String( next.dataset.unwanValue || '' );
			this.select( nextValue );
			nextFrame( () => {
				Array.from(
					this.element.querySelectorAll(
						'.unwan-address-item[data-unwan-value]'
					)
				)
					.find(
						( candidate ) =>
							candidate.dataset.unwanValue === nextValue
					)
					?.focus();
			} );
		}

		/**
		 * Open the complete chooser.
		 */
		openChooser() {
			this.open = true;
			this.search = '';
			this.render();
			nextFrame( () => {
				const search = this.element.querySelector(
					'.unwan-address-search__input'
				);
				const selected = this.element.querySelector(
					'.unwan-address-item--selected'
				);
				const firstOption = this.element.querySelector(
					'.unwan-address-item[role="radio"]'
				);

				( search || selected || firstOption )?.focus();
			} );
		}

		/**
		 * Select an address and notify the checkout adapter.
		 *
		 * @param {string} value Saved ID or "new".
		 */
		select( value ) {
			if ( ! value || this.data.disabled ) {
				return;
			}

			const selectedAddress = Array.isArray( this.data.addresses )
				? this.data.addresses.find(
						( address ) => address.id === value
				  )
				: null;

			this.data = {
				...this.data,
				selection: value,
				summary: selectedAddress || this.data.summary,
			};
			this.open = false;
			this.search = '';
			this.render();
			this.element.dispatchEvent(
				new CustomEvent( 'unwan-selection-change', {
					bubbles: true,
					detail: { value },
				} )
			);
		}

		/**
		 * Build one saved-address button.
		 *
		 * @param {Object}  address           Address option.
		 * @param {boolean} focusableFallback Whether this is the fallback tab stop.
		 * @return {string} Option markup.
		 */
		optionMarkup( address, focusableFallback = false ) {
			const selected = this.data.selection === address.id;
			const searchText = [
				address.name,
				address.street,
				address.details,
				address.fields?.postcode,
			]
				.join( ' ' )
				.toLocaleLowerCase();

			return `
				<button
					class="unwan-address-item unwan-address-item--selectable${
						selected ? ' unwan-address-item--selected' : ''
					}"
					type="button"
					role="radio"
					aria-checked="${ selected ? 'true' : 'false' }"
					tabindex="${ selected || focusableFallback ? '0' : '-1' }"
					data-unwan-action="select"
					data-unwan-value="${ escapeHtml( address.id ) }"
					data-unwan-search="${ escapeHtml( searchText ) }"
					${ this.data.disabled ? 'disabled' : '' }
				>
					<span class="unwan-address-item__control" aria-hidden="true"></span>
					<span class="unwan-address-item__content">
						<span class="unwan-address-item__street">${ escapeHtml(
							address.street || address.description
						) }</span>
						<span class="unwan-address-item__details">${ escapeHtml(
							address.name
						) } <span aria-hidden="true">—</span> ${ escapeHtml(
							address.details || address.description
						) }</span>
					</span>
					<span class="unwan-address-item__meta">
						${
							address.isDefault
								? `<span class="unwan-address-label">${ escapeHtml(
										this.data.labels?.default
								  ) }</span>`
								: ''
						}
					</span>
				</button>
			`;
		}

		/**
		 * Build the compact selected-address summary.
		 *
		 * @return {string} Summary markup.
		 */
		compactMarkup() {
			const summary = this.data.summary || {};
			const labels = this.data.labels || {};

			return `
				<div class="unwan-picker__summary">
					<div class="unwan-picker__summary-copy">
						<span class="unwan-picker__eyebrow">${ escapeHtml(
							labels.compactHeading
						) }</span>
						<span class="unwan-picker__summary-street">${ escapeHtml(
							summary.street || summary.description
						) }</span>
						<span class="unwan-picker__summary-details">${ escapeHtml(
							summary.name
						) } <span aria-hidden="true">—</span> ${ escapeHtml(
							summary.details || summary.description
						) }</span>
					</div>
					<button class="unwan-picker__change" type="button" data-unwan-action="open">
						${ escapeHtml( labels.change ) }
					</button>
				</div>
			`;
		}

		/**
		 * Build the expanded or condensed chooser.
		 *
		 * @return {string} Chooser markup.
		 */
		chooserMarkup() {
			const addresses = Array.isArray( this.data.addresses )
				? this.data.addresses
				: [];
			const labels = this.data.labels || {};
			const isNewState = this.data.selection === 'new' && ! this.open;
			const visibleAddresses = isNewState
				? addresses.slice( 0, 1 )
				: addresses;
			const savedLabel = countLabel(
				addresses.length === 1
					? labels.savedAddress
					: labels.savedAddresses,
				addresses.length
			);
			const hiddenCount = Math.max( addresses.length - 1, 0 );
			const moreLabel = countLabel(
				hiddenCount === 1 ? labels.moreAddress : labels.moreAddresses,
				hiddenCount
			);
			const configuredThreshold = Number( this.data.searchThreshold );
			const threshold = Number.isFinite( configuredThreshold )
				? Math.max( 0, configuredThreshold )
				: 4;
			const showSearch = this.open && addresses.length > threshold;
			const hasSelectedSaved = addresses.some(
				( address ) => address.id === this.data.selection
			);
			const newSelected = this.data.selection === 'new';
			const options = visibleAddresses
				.map( ( address, index ) =>
					this.optionMarkup(
						address,
						! hasSelectedSaved && ! newSelected && index === 0
					)
				)
				.join( '' );

			return `
				<div class="unwan-address-list unwan-picker__panel">
					<div class="unwan-address-list__header">
						<span class="unwan-picker__panel-title">${ escapeHtml(
							labels.panelHeading
						) }</span>
						<span class="unwan-address-list__count">${ escapeHtml( savedLabel ) }</span>
					</div>
					${
						showSearch
							? `<div class="unwan-address-search">
								<input
									class="unwan-address-search__input"
									type="search"
									name="${ escapeHtml( `${ this.id }-filter` ) }"
									value="${ escapeHtml( this.search ) }"
									aria-label="${ escapeHtml( labels.searchLabel ) }"
									placeholder="${ escapeHtml( labels.searchPlaceholder ) }"
									autocomplete="new-search"
									autocapitalize="none"
									autocorrect="off"
									spellcheck="false"
									aria-autocomplete="none"
									inputmode="search"
									enterkeyhint="search"
									data-form-type="other"
									data-lpignore="true"
									data-1p-ignore="true"
								>
							</div>`
							: ''
					}
					<div class="unwan-address-list__items" role="radiogroup" aria-label="${ escapeHtml(
						labels.panelHeading
					) }">
						<div class="unwan-address-list__saved${
							this.open
								? ' unwan-address-list__saved--scrollable'
								: ''
						}">
							${ options }
							<div class="unwan-address-list__empty" hidden>${ escapeHtml(
								labels.noResults
							) }</div>
							${
								isNewState && hiddenCount > 0
									? `<button class="unwan-picker__more" type="button" data-unwan-action="open">${ escapeHtml(
											moreLabel
									  ) }</button>`
									: ''
							}
						</div>
						<button
							class="unwan-address-item unwan-address-item--selectable unwan-address-item--new${
								newSelected
									? ' unwan-address-item--selected'
									: ''
							}"
							type="button"
							role="radio"
							aria-checked="${ newSelected ? 'true' : 'false' }"
							tabindex="${ newSelected ? '0' : '-1' }"
							data-unwan-action="select"
							data-unwan-value="new"
							${ this.data.disabled ? 'disabled' : '' }
						>
							<span class="unwan-address-item__control" aria-hidden="true"></span>
							<span class="unwan-address-item__content">
								<span class="unwan-address-item__street">${ escapeHtml(
									labels.newAddress
								) }</span>
							</span>
						</button>
					</div>
				</div>
			`;
		}

		/**
		 * Filter saved-address buttons without rebuilding the search field.
		 */
		filterOptions() {
			const query = String( this.search || '' )
				.trim()
				.toLocaleLowerCase();
			let visible = 0;

			this.element
				.querySelectorAll(
					'.unwan-address-list__saved .unwan-address-item'
				)
				.forEach( ( option ) => {
					const matches =
						! query ||
						String( option.dataset.unwanSearch || '' ).includes(
							query
						);
					option.hidden = ! matches;
					if ( matches ) {
						visible += 1;
					}
				} );

			const empty = this.element.querySelector(
				'.unwan-address-list__empty'
			);
			if ( empty ) {
				empty.hidden = visible > 0;
			}
		}

		/**
		 * Render current state into the mount element.
		 */
		render() {
			const addresses = Array.isArray( this.data.addresses )
				? this.data.addresses
				: [];

			if ( addresses.length === 0 ) {
				this.element.replaceChildren();
				this.element.hidden = true;
				return;
			}

			const showCompact = ! this.open && this.data.selection !== 'new';

			this.element.hidden = false;
			this.element.dataset.selection = String(
				this.data.selection || ''
			);
			this.element.setAttribute(
				'aria-busy',
				this.data.disabled ? 'true' : 'false'
			);
			this.element.innerHTML = showCompact
				? this.compactMarkup()
				: this.chooserMarkup();
		}
	}

	window.unwanAddressPicker = {
		/**
		 * Mount or retrieve a picker controller.
		 *
		 * @param {HTMLElement} element Mount element.
		 * @param {Object}      data    Optional initial data.
		 * @return {Object|null} Controller.
		 */
		mount( element, data ) {
			if ( ! ( element instanceof window.HTMLElement ) ) {
				return null;
			}

			let instance = instances.get( element );
			if ( ! instance ) {
				instance = new UnwanPicker( element );
				instances.set( element, instance );
			}

			if ( data ) {
				instance.update( data );
			}

			return instance;
		},

		/**
		 * Destroy a mounted picker.
		 *
		 * @param {HTMLElement} element Mount element.
		 */
		destroy( element ) {
			instances.get( element )?.destroy();
		},
	};
} )();
