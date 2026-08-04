/**
 * My Account address-list search and overflow menus.
 */
( function () {
	'use strict';

	const roots = document.querySelectorAll( '[data-unwan-account-list]' );

	roots.forEach( ( root ) => {
		const search = root.querySelector( '[data-unwan-account-search]' );
		const items = Array.from(
			root.querySelectorAll( '[data-unwan-address-item]' )
		);
		const empty = root.querySelector( '[data-unwan-account-empty]' );

		/**
		 * Close one overflow menu.
		 *
		 * @param {HTMLElement} toggle       Menu toggle.
		 * @param {boolean}     restoreFocus Whether to restore toggle focus.
		 */
		function closeMenu( toggle, restoreFocus = false ) {
			const menuId = toggle.getAttribute( 'aria-controls' );
			const menu = menuId ? document.getElementById( menuId ) : null;

			toggle.setAttribute( 'aria-expanded', 'false' );
			if ( menu ) {
				menu.hidden = true;
			}
			if ( restoreFocus ) {
				toggle.focus();
			}
		}

		/**
		 * Close every menu except an optional active toggle.
		 *
		 * @param {HTMLElement|null} except Toggle to preserve.
		 */
		function closeMenus( except = null ) {
			root.querySelectorAll( '[data-unwan-menu-toggle]' ).forEach(
				( toggle ) => {
					if ( toggle !== except ) {
						closeMenu( toggle );
					}
				}
			);
		}

		if ( search ) {
			search.addEventListener( 'input', () => {
				const query = search.value.trim().toLocaleLowerCase();
				let visible = 0;

				items.forEach( ( item ) => {
					const matches =
						! query ||
						String( item.dataset.unwanSearch || '' ).includes(
							query
						);
					item.hidden = ! matches;
					if ( matches ) {
						visible += 1;
					}
				} );

				if ( empty ) {
					empty.hidden = visible > 0;
				}
				closeMenus();
			} );
		}

		root.addEventListener( 'click', ( event ) => {
			const toggle = event.target.closest( '[data-unwan-menu-toggle]' );

			if ( ! toggle || ! root.contains( toggle ) ) {
				return;
			}

			const menuId = toggle.getAttribute( 'aria-controls' );
			const menu = menuId ? document.getElementById( menuId ) : null;
			const willOpen = toggle.getAttribute( 'aria-expanded' ) !== 'true';

			closeMenus( toggle );
			toggle.setAttribute( 'aria-expanded', String( willOpen ) );
			if ( menu ) {
				menu.hidden = ! willOpen;
				if ( willOpen ) {
					menu.querySelector(
						'[role="menuitem"]:not(:disabled)'
					)?.focus();
				}
			}
		} );

		root.addEventListener( 'keydown', ( event ) => {
			const menuItem = event.target.closest( '[role="menuitem"]' );
			const menu = menuItem?.closest( '[data-unwan-menu]' );
			const navigationKeys = [ 'ArrowDown', 'ArrowUp', 'Home', 'End' ];

			if ( menu && navigationKeys.includes( event.key ) ) {
				const menuItems = Array.from(
					menu.querySelectorAll( '[role="menuitem"]:not(:disabled)' )
				);
				const currentIndex = Math.max(
					menuItems.indexOf( menuItem ),
					0
				);
				let nextIndex = currentIndex;

				if ( event.key === 'Home' ) {
					nextIndex = 0;
				} else if ( event.key === 'End' ) {
					nextIndex = menuItems.length - 1;
				} else if ( event.key === 'ArrowDown' ) {
					nextIndex = ( currentIndex + 1 ) % menuItems.length;
				} else {
					nextIndex =
						( currentIndex - 1 + menuItems.length ) %
						menuItems.length;
				}

				event.preventDefault();
				menuItems[ nextIndex ]?.focus();
				return;
			}

			if ( event.key !== 'Escape' ) {
				return;
			}

			const openToggle = root.querySelector(
				'[data-unwan-menu-toggle][aria-expanded="true"]'
			);
			if ( openToggle ) {
				event.preventDefault();
				closeMenu( openToggle, true );
			}
		} );

		root.addEventListener( 'submit', ( event ) => {
			const form = event.target.closest( '[data-unwan-confirm]' );
			if ( form ) {
				// eslint-disable-next-line no-alert
				const confirmed = window.confirm(
					form.dataset.unwanConfirm || ''
				);
				if ( ! confirmed ) {
					event.preventDefault();
				}
			}
		} );

		document.addEventListener( 'click', ( event ) => {
			if ( ! root.contains( event.target ) ) {
				closeMenus();
				return;
			}

			if ( ! event.target.closest( '.unwan-address-item__menu' ) ) {
				closeMenus();
			}
		} );
	} );
} )();
