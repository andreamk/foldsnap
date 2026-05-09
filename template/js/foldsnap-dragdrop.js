/* global jQuery, MutationObserver, Node */

/**
 * FoldSnap Drag & Drop Bridge
 *
 * Uses jQuery UI draggable/droppable to enable dragging native WordPress
 * media grid attachments onto folder items in the React sidebar.
 *
 * Communicates with the React app via wp.data store:
 *   wp.data.dispatch('foldsnap/folders').assignMedia(folderId, mediaIds)
 *
 * @param {Object} $  jQuery instance.
 * @param {Object} wp WordPress global object.
 */
( function ( $, wp ) {
	if ( ! $ || ! wp || ! wp.data ) {
		return;
	}

	const STORE_NAME = 'foldsnap/folders';

	/**
	 * Collect selected attachment IDs from the native WP grid.
	 *
	 * @param {Object} $draggedItem The attachment element being dragged.
	 * @return {number[]} Array of attachment IDs.
	 */
	function getSelectedIds( $draggedItem ) {
		let $selected = $( '.attachments-browser .attachment.selected' );

		if ( $selected.length < 2 ) {
			$selected = $draggedItem;
		}

		const ids = [];
		$selected.each( function () {
			const id = parseInt( $( this ).attr( 'data-id' ), 10 );
			if ( ! isNaN( id ) ) {
				ids.push( id );
			}
		} );
		return ids;
	}

	/**
	 * Make a single .attachment element draggable via jQuery UI.
	 *
	 * @param {HTMLElement} el The attachment DOM element.
	 */
	function makeDraggable( el ) {
		const $el = $( el );

		if ( $el.data( 'foldsnap-draggable' ) ) {
			return;
		}
		$el.data( 'foldsnap-draggable', true );

		$el.draggable( {
			helper() {
				const ids = getSelectedIds( $el );
				const $helper = $( '<div class="foldsnap-drag-helper"></div>' );
				$helper.text(
					wp.i18n.sprintf(
						// translators: %d is the number of media items being dragged.
						wp.i18n._n(
							'%d item',
							'%d items',
							ids.length,
							'foldsnap'
						),
						ids.length
					)
				);
				$helper.attr( 'data-ids', ids.join( ',' ) );
				return $helper;
			},
			appendTo: 'body',
			cursor: 'move',
			cursorAt: { left: 10, top: 10 },
			distance: 8,
			revert: 'invalid',
			revertDuration: 200,
			zIndex: 100000,
			start() {
				$el.addClass( 'foldsnap-dragging' );
			},
			stop() {
				$el.removeClass( 'foldsnap-dragging' );
			},
		} );
	}

	/**
	 * Make folder items in the sidebar droppable via jQuery UI.
	 * Re-scanned when the React tree re-renders (MutationObserver).
	 */
	function initDroppables() {
		$( '.foldsnap-folder-item' ).each( function () {
			const $folder = $( this );

			if ( $folder.data( 'foldsnap-droppable' ) ) {
				return;
			}
			$folder.data( 'foldsnap-droppable', true );

			$folder.droppable( {
				accept: 'li.attachment',
				greedy: true,
				hoverClass: 'foldsnap-folder-item--drag-over',
				tolerance: 'pointer',
				drop( event, ui ) {
					const rawIds = ui.helper.attr( 'data-ids' );
					if ( ! rawIds ) {
						return;
					}

					const mediaIds = rawIds
						.split( ',' )
						.map( ( s ) => parseInt( s, 10 ) )
						.filter( ( n ) => ! isNaN( n ) );

					if ( ! mediaIds.length ) {
						return;
					}

					const folderId = parseInt(
						$folder.attr( 'data-folder-id' ),
						10
					);
					if ( isNaN( folderId ) ) {
						return;
					}

					wp.data
						.dispatch( STORE_NAME )
						.assignMedia( folderId, mediaIds )
						.then( () => {
							window.foldsnap?.refreshGrid?.();
						} );
				},
			} );
		} );
	}

	/**
	 * Initialise draggables on existing attachment elements.
	 */
	function initDraggables() {
		$( '.attachments-browser .attachment' ).each( function () {
			makeDraggable( this );
		} );
	}

	/**
	 * Watch a container for added subtrees. Calls `onAdd()` once per
	 * mutation batch when at least one added node matches `selector`.
	 *
	 * @param {Element}  container Container to observe.
	 * @param {string}   selector  CSS selector to match against added nodes.
	 * @param {Function} onAdd     Callback to run on match.
	 */
	function observeAdditions( container, selector, onAdd ) {
		if ( ! container ) {
			return;
		}
		const observer = new MutationObserver( ( mutations ) => {
			for ( const mutation of mutations ) {
				for ( const node of mutation.addedNodes ) {
					if ( node.nodeType !== Node.ELEMENT_NODE ) {
						continue;
					}
					if (
						node.matches?.( selector ) ||
						node.querySelector?.( selector )
					) {
						onAdd();
						return;
					}
				}
			}
		} );
		observer.observe( container, { childList: true, subtree: true } );
	}

	/**
	 * Wait for an element to appear (created by Backbone or React after
	 * page load), up to ~10s. Calls `onReady(element)` once when found.
	 *
	 * @param {Function} getEl   Selector callback returning the element or null.
	 * @param {Function} onReady Callback receiving the element.
	 */
	function whenReady( getEl, onReady ) {
		let attempts = 0;
		const timer = setInterval( () => {
			const el = getEl();
			if ( el || ++attempts >= 40 ) {
				clearInterval( timer );
				if ( el ) {
					onReady( el );
				}
			}
		}, 250 );
	}

	$( () => {
		whenReady(
			() => document.querySelector( '.attachments-browser' ),
			( container ) => {
				initDraggables();
				observeAdditions( container, '.attachment', initDraggables );
			}
		);
		whenReady(
			() => document.getElementById( 'foldsnap-sidebar' ),
			( container ) => {
				initDroppables();
				observeAdditions(
					container,
					'.foldsnap-folder-item',
					initDroppables
				);
			}
		);
	} );
} )( jQuery, window.wp );
