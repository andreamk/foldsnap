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
// Cached reference to the live grid collection. Captured the first time
// `browse.collection` is reachable. Persists across modal open/close,
// where `browse.collection` is transiently null but the underlying
// Backbone collection (which the DOM grid observes) is still alive.
let cachedGridCollection = null;

const findLiveCollection = () => {
	const frame = window.wp?.media?.frame;
	if ( ! frame ) {
		return null;
	}
	const fromBrowse = frame.content?.get?.( 'browse' )?.collection;
	if ( fromBrowse ) {
		return fromBrowse;
	}
	// `frame.states` is a Backbone collection of state models keyed by id.
	const libState = frame.states?.get?.( 'library' );
	return libState?.get?.( 'library' ) || null;
};

const applyGridFilter = ( folderId ) => {
	const candidate = findLiveCollection();
	if ( candidate ) {
		cachedGridCollection = candidate;
	}
	const target = cachedGridCollection;
	if ( ! target?.props ) {
		return false;
	}
	if ( folderId === null ) {
		target.props.unset( 'foldsnap_folder_id' );
	} else {
		target.props.set( { foldsnap_folder_id: folderId } );
	}
	return true;
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

	// Expose a stable grid refresh entry-point for non-bundled scripts
	// (e.g. the jQuery dragdrop bridge) so they can trigger a re-fetch
	// of the live Backbone collection without duplicating lookup logic.
	window.foldsnap = window.foldsnap || {};
	window.foldsnap.refreshGrid = () => {
		const target = findLiveCollection() || cachedGridCollection;
		target?._requery?.( true );
	};

	// Pre-select folder from URL parameter, or fall back to root (id 0)
	// so the grid is never silently unfiltered on first load. The
	// "All Media" toggle is the explicit opt-in for an unfiltered view.
	const urlFolderId = new URLSearchParams( window.location.search ).get(
		'foldsnap_folder_id'
	);
	const initialFolderId =
		urlFolderId !== null ? parseInt( urlFolderId, 10 ) : 0;
	const dispatch = window.wp?.data?.dispatch( STORE_NAME );
	dispatch?.setSelectedFolder( initialFolderId );
	dispatch?.expandPathTo?.( initialFolderId );

	/**
	 * Read the effective folder filter from the store.
	 *
	 * When "All Media" is on, the sidebar is bypassed: return null so the
	 * native grid is unfiltered regardless of the user's last selection.
	 *
	 * @return {number|null} Folder ID to filter by, or null for unfiltered.
	 */
	const readEffectiveFolderId = () => {
		const store = window.wp?.data?.select( STORE_NAME );
		if ( ! store ) {
			return null;
		}
		if ( store.isAllMediaActive() ) {
			return null;
		}
		return store.getSelectedFolderId() ?? null;
	};

	let lastFolderId = initialFolderId;

	// Script enqueued in footer: `.view-switch` links already exist in DOM.
	updateModeToggleLinks( lastFolderId );

	// React store → native WP grid synchronisation.
	subscribe( () => {
		const folderId = readEffectiveFolderId();
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
			if (
				applyGridFilter( readEffectiveFolderId() ) ||
				pollAttempts >= 40
			) {
				clearInterval( pollFrame );
			}
		}, 250 );
	}
}
