import { createRoot } from '@wordpress/element';
import { subscribe } from '@wordpress/data';
import FolderSidebar from './components/FolderSidebar';
import { STORE_NAME } from './store/constants';
import './store'; // Ensure store is registered

/**
 * Create the sidebar container, insert it before #wpbody-content inside
 * #wpbody, then mount the React app on it.
 */
const wpbodyContent = document.getElementById( 'wpbody-content' );
if ( wpbodyContent && wpbodyContent.parentNode ) {
	const container = document.createElement( 'div' );
	container.id = 'foldsnap-sidebar';
	wpbodyContent.parentNode.insertBefore( container, wpbodyContent );

	const root = createRoot( container );
	root.render( <FolderSidebar /> );

	/**
	 * Apply folder filter to the native Media Library Backbone grid.
	 *
	 * @param {number|null} folderId Folder to filter by, or null for all media.
	 * @return {boolean} Whether the filter was applied (frame ready).
	 */
	const applyFolderFilter = ( folderId ) => {
		try {
			const browse = window.wp?.media?.frame?.content?.get( 'browse' );
			if ( ! browse?.collection ) {
				return false;
			}
			if ( folderId === null ) {
				browse.collection.props.unset( 'foldsnap_folder_id' );
			} else {
				browse.collection.props.set( { foldsnap_folder_id: folderId } );
			}
			return true;
		} catch {
			return false;
		}
	};

	// Pre-select folder from URL parameter (list mode reload).
	const urlFolderId = new URLSearchParams( window.location.search ).get(
		'foldsnap_folder_id'
	);
	if ( urlFolderId !== null ) {
		window.wp?.data
			?.dispatch( STORE_NAME )
			?.setSelectedFolder( parseInt( urlFolderId, 10 ) );
	}

	let lastFolderId =
		urlFolderId !== null ? parseInt( urlFolderId, 10 ) : null;
	const isListMode = window.foldsnap_data?.mediaMode === 'list';

	/**
	 * Update the grid/list mode toggle links to preserve the current folder.
	 *
	 * @param {number|null} folderId Current folder ID.
	 */
	const updateModeToggleLinks = ( folderId ) => {
		document.querySelectorAll( '.view-switch a' ).forEach( ( link ) => {
			const linkUrl = new URL( link.href );
			if ( folderId === null ) {
				linkUrl.searchParams.delete( 'foldsnap_folder_id' );
			} else {
				linkUrl.searchParams.set( 'foldsnap_folder_id', folderId );
			}
			link.href = linkUrl.toString();
		} );
	};

	// Set initial folder on mode toggle links (deferred for DOM readiness).
	setTimeout( () => updateModeToggleLinks( lastFolderId ), 500 );

	// Subscribe to store changes.
	subscribe( () => {
		const folderId =
			window.wp?.data?.select( STORE_NAME )?.getSelectedFolderId() ??
			null;
		if ( folderId === lastFolderId ) {
			return;
		}
		lastFolderId = folderId;
		updateModeToggleLinks( folderId );

		if ( ! isListMode ) {
			// Grid mode: filter via Backbone AJAX.
			applyFolderFilter( folderId );
		} else {
			// List mode: redirect with URL parameter.
			const url = new URL( window.location.href );
			if ( folderId === null ) {
				url.searchParams.delete( 'foldsnap_folder_id' );
			} else {
				url.searchParams.set( 'foldsnap_folder_id', folderId );
			}
			window.location.href = url.toString();
		}
	} );

	// Grid mode only: poll until wp.media.frame is ready, then apply once.
	if ( ! isListMode ) {
		const pollFrame = setInterval( () => {
			if ( applyFolderFilter( lastFolderId ) ) {
				clearInterval( pollFrame );
			}
		}, 250 );
	}
}
