import { subscribe } from '@wordpress/data';
import { STORE_NAME } from '../store/constants';

/**
 * Apply folder filter to the native Media Library Backbone grid (grid mode).
 *
 * Sets or unsets foldsnap_folder_id on the Backbone Attachments collection,
 * which triggers a re-fetch via AJAX. The PHP filter
 * (MediaLibraryController::filterAttachmentsByFolder) reads this parameter.
 *
 * @param {number|null} folderId Folder to filter by, or null for all media.
 * @return {boolean} Whether the filter was applied (frame ready).
 */
const applyGridFilter = ( folderId ) => {
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

/**
 * Redirect to upload.php with foldsnap_folder_id parameter (list mode).
 *
 * @param {number|null} folderId Folder to filter by, or null for all media.
 */
const redirectListMode = ( folderId ) => {
	const url = new URL( window.location.href );
	if ( folderId === null ) {
		url.searchParams.delete( 'foldsnap_folder_id' );
	} else {
		url.searchParams.set( 'foldsnap_folder_id', folderId );
	}
	window.location.href = url.toString();
};

/**
 * Update the grid/list mode toggle links to preserve the current folder
 * when switching between modes.
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

/**
 * Initialize the bridge between the React folder sidebar and the native
 * WordPress media library (Backbone grid or server-rendered list table).
 *
 * - Reads foldsnap_folder_id from URL and pre-selects the folder in the store.
 * - Subscribes to store changes and filters the native grid accordingly.
 * - In grid mode, polls until wp.media.frame is ready, then applies once.
 * - In list mode, redirects with URL parameter on folder change.
 * - Updates the grid/list mode toggle links to preserve folder across switches.
 */
export default function initMediaModeBridge() {
	const isListMode = window.foldsnap_data?.mediaMode === 'list';

	// Pre-select folder from URL parameter (persisted across page reloads).
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

	// Deferred: update mode toggle links once the DOM is fully rendered.
	setTimeout( () => updateModeToggleLinks( lastFolderId ), 500 );

	// React store → native WP grid synchronisation.
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
			applyGridFilter( folderId );
		} else {
			redirectListMode( folderId );
		}
	} );

	// Grid mode only: poll until wp.media.frame is ready, then apply once.
	// Give up after 10 seconds (40 × 250 ms) to avoid leaking the interval.
	if ( ! isListMode ) {
		let pollAttempts = 0;
		const pollFrame = setInterval( () => {
			pollAttempts++;
			if ( applyGridFilter( lastFolderId ) || pollAttempts >= 40 ) {
				clearInterval( pollFrame );
			}
		}, 250 );
	}
}
