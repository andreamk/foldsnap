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
					ids.length + ( ids.length === 1 ? ' item' : ' items' )
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
						.assignMedia( folderId, mediaIds );
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
	 * Observe DOM mutations to handle dynamically loaded attachments
	 * (infinite scroll) and re-rendered folder items (React updates).
	 */
	function observeMutations() {
		const observer = new MutationObserver( ( mutations ) => {
			let needDraggables = false;
			let needDroppables = false;

			for ( let i = 0; i < mutations.length; i++ ) {
				for ( let j = 0; j < mutations[ i ].addedNodes.length; j++ ) {
					const node = mutations[ i ].addedNodes[ j ];
					if ( node.nodeType !== Node.ELEMENT_NODE ) {
						continue;
					}
					if ( node.classList.contains( 'attachment' ) ) {
						needDraggables = true;
					}
					if (
						node.classList.contains( 'foldsnap-folder-item' ) ||
						( node.querySelector &&
							node.querySelector( '.foldsnap-folder-item' ) )
					) {
						needDroppables = true;
					}
					if (
						node.querySelector &&
						node.querySelector( '.attachment' )
					) {
						needDraggables = true;
					}
				}
			}

			if ( needDraggables ) {
				initDraggables();
			}
			if ( needDroppables ) {
				initDroppables();
			}
		} );

		observer.observe( document.body, {
			childList: true,
			subtree: true,
		} );
	}

	$( () => {
		setTimeout( () => {
			initDraggables();
			initDroppables();
			observeMutations();
		}, 500 );
	} );
} )( jQuery, window.wp );
