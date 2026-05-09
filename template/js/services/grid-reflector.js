// Reflects the React store's selected folder onto the native Backbone
// media grid by writing `foldsnap_folder_id` on the live Attachments
// collection's props (which triggers an AJAX refetch).

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

export const applyGridFilter = ( folderId ) => {
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

// `wp.media.frame` is constructed asynchronously by Backbone after our
// script runs; this polls until it exists (max 10s) and then runs `apply`.
export const pollUntilGridReady = ( apply ) => {
	let attempts = 0;
	const timer = setInterval( () => {
		attempts++;
		if ( apply() || attempts >= 40 ) {
			clearInterval( timer );
		}
	}, 250 );
};

// Cross-bundle entry point: the non-bundled jQuery dragdrop script uses
// this to refresh the visible grid after assigning media.
export const installRefreshGridGlobal = () => {
	window.foldsnap = window.foldsnap || {};
	window.foldsnap.refreshGrid = () => {
		const target = findLiveCollection() || cachedGridCollection;
		target?._requery?.( true );
	};
};
