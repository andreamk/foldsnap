import apiFetch from '@wordpress/api-fetch';

/**
 * Per-user UI preferences persistence.
 */

export const PREF_KEYS = Object.freeze( {
	EXPANDED_FOLDERS: 'expandedFolders',
	ALL_MEDIA: 'allMedia',
} );

const PREF_REST_PATH = '/foldsnap/v1/preferences';
const PREF_DEBOUNCE_MS = 800;
const CACHE_KEY = 'foldsnap.preferencesCache';

const DEFAULTS = Object.freeze( {
	[ PREF_KEYS.EXPANDED_FOLDERS ]: [],
	[ PREF_KEYS.ALL_MEDIA ]: false,
} );

const readCache = () => {
	try {
		const raw = window.localStorage?.getItem( CACHE_KEY );
		if ( ! raw ) {
			return {};
		}
		const parsed = JSON.parse( raw );
		if (
			! parsed ||
			typeof parsed !== 'object' ||
			Array.isArray( parsed )
		) {
			return {};
		}
		return parsed;
	} catch {
		return {};
	}
};

const writeCache = ( map ) => {
	try {
		window.localStorage?.setItem( CACHE_KEY, JSON.stringify( map ) );
	} catch {
		// localStorage unavailable / quota — degrade silently.
	}
};

// Per-key debounce: a save on key A never cancels a pending save on key B.
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
		// Promise.resolve wraps the transport so a mock returning undefined
		// (jest.fn default) still produces a thenable.
		await Promise.resolve(
			apiFetch( {
				path: `${ PREF_REST_PATH }/${ encodeURIComponent( key ) }`,
				method: 'PUT',
				data: { value },
			} )
		);
	} catch {
		// Server-side failure: cache already holds the new value, so the UI
		// state is not lost.
	}
};

/**
 * Synchronous read of cached preferences, filled with defaults for any
 * missing key. Never throws.
 *
 * @return {Object} Map of key → value, complete with all declared keys.
 */
export const readCachedPreferences = () => {
	const cache = readCache();
	const result = { ...DEFAULTS };
	for ( const key of Object.keys( DEFAULTS ) ) {
		if ( Object.prototype.hasOwnProperty.call( cache, key ) ) {
			result[ key ] = cache[ key ];
		}
	}
	return result;
};

/**
 * Fetch preferences from the server, refresh the cache, and return the
 * complete map. Falls back to cache (or defaults) on any error.
 *
 * @return {Promise<Object>} Preferences map.
 */
export const loadPreferences = async () => {
	try {
		const response = await apiFetch( { path: PREF_REST_PATH } );
		const server = response?.preferences;
		if ( ! server || typeof server !== 'object' ) {
			return readCachedPreferences();
		}
		const merged = { ...DEFAULTS, ...server };
		writeCache( merged );
		return merged;
	} catch {
		return readCachedPreferences();
	}
};

/**
 * Save a single preference: write-through cache + per-key debounced PUT.
 *
 * @param {string} key   Preference key (must be a value of PREF_KEYS).
 * @param {*}      value New value (server validates type).
 */
export const savePreference = ( key, value ) => {
	const cache = readCache();
	cache[ key ] = value;
	writeCache( cache );

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
