import apiFetch from '@wordpress/api-fetch';

/**
 * Per-user UI preferences persistence.
 */

export const PREF_KEYS = Object.freeze( {
	EXPANDED_FOLDERS: 'expandedFolders',
	ALL_MEDIA: 'allMedia',
	SIDEBAR_WIDTH: 'sidebarWidth',
} );

const PREF_REST_PATH = '/foldsnap/v1/preferences';
const PREF_DEBOUNCE_MS = 800;

/**
 * Read the preferences blob localised by PHP at enqueue time.
 *
 * @return {Object} Map of key → value, complete with all declared keys.
 */
export const getInitialPreferences = () =>
	( typeof window !== 'undefined' && window.foldsnap_data?.preferences ) ||
	{};

const pendingTimers = new Map();
const pendingValues = new Map();

const flushKey = async ( key ) => {
	if ( ! pendingValues.has( key ) ) {
		return;
	}
	const value = pendingValues.get( key );
	pendingValues.delete( key );
	pendingTimers.delete( key );
	try {
		await Promise.resolve(
			apiFetch( {
				path: `${ PREF_REST_PATH }/${ encodeURIComponent( key ) }`,
				method: 'PUT',
				data: { value },
			} )
		);
	} catch {
		// Server-side failure: next page load will re-fetch the authoritative
		// values from PHP, so a missed PUT is recovered automatically.
	}
};

/**
 * Save a single preference: per-key debounced PUT to the REST endpoint.
 *
 * @param {string} key   Preference key (must be a value of PREF_KEYS).
 * @param {*}      value New value (server validates type).
 */
export const savePreference = ( key, value ) => {
	pendingValues.set( key, value );
	const existing = pendingTimers.get( key );
	if ( existing ) {
		clearTimeout( existing );
	}
	const timer = setTimeout( () => flushKey( key ), PREF_DEBOUNCE_MS );
	pendingTimers.set( key, timer );
};

/**
 * Force every pending PUT to fire immediately. Test helper.
 *
 * @return {Promise<void>}
 */
export const flushPendingSaves = async () => {
	const keys = Array.from( pendingTimers.keys() );
	for ( const key of keys ) {
		const timer = pendingTimers.get( key );
		if ( timer ) {
			clearTimeout( timer );
		}
	}
	await Promise.all( keys.map( ( key ) => flushKey( key ) ) );
};
