import { subscribe, select } from '@wordpress/data';
import { STORE_NAME } from '../store/constants';

// Cached so the grid stays reachable while the attachment-details modal
// is open: in that state `frame.content.get('browse').collection` is null
// even though the underlying Backbone collection (which the DOM observes)
// is still alive.
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
	// Fallback for when the modal is open: pick the library state by name,
	// not the active state (which is `edit-attachment`).
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

const redirectListMode = ( folderId ) => {
	const url = new URL( window.location.href );
	if ( folderId === null ) {
		url.searchParams.delete( 'foldsnap_folder_id' );
	} else {
		url.searchParams.set( 'foldsnap_folder_id', folderId );
	}
	window.location.href = url.toString();
};

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

export default function initMediaModeBridge() {
	const isListMode = window.foldsnap_data?.mediaMode === 'list';

	// Cross-bundle entry point: the non-bundled jQuery dragdrop script
	// uses this to refresh the visible grid after assigning media.
	window.foldsnap = window.foldsnap || {};
	window.foldsnap.refreshGrid = () => {
		const target = findLiveCollection() || cachedGridCollection;
		target?._requery?.( true );
	};

	const readEffectiveFolderId = () => {
		const store = select( STORE_NAME );
		if ( store.isAllMediaActive() ) {
			return null;
		}
		return store.getSelectedFolderId() ?? null;
	};

	let lastFolderId = readEffectiveFolderId();
	updateModeToggleLinks( lastFolderId );

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

	// `wp.media.frame` is constructed asynchronously by Backbone after our
	// script runs, so we poll until it exists (max 10s) before applying
	// the initial filter.
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
