const STORAGE_KEY = 'foldsnap.expandedFolders';

/**
 * Read the persisted set of expanded folder IDs from localStorage.
 *
 * Tolerates: missing storage (SSR / disabled), malformed JSON, non-array
 * payloads, and non-numeric entries — in every failure mode an empty array
 * is returned so the UI boots collapsed instead of crashing.
 *
 * @return {number[]} Expanded folder IDs (deduped, positive integers only).
 */
export const loadExpandedIds = () => {
	try {
		const raw = window.localStorage?.getItem( STORAGE_KEY );
		if ( ! raw ) {
			return [];
		}
		const parsed = JSON.parse( raw );
		if ( ! Array.isArray( parsed ) ) {
			return [];
		}
		const ids = [];
		for ( const value of parsed ) {
			const id = parseInt( value, 10 );
			if ( Number.isFinite( id ) && id > 0 && ! ids.includes( id ) ) {
				ids.push( id );
			}
		}
		return ids;
	} catch {
		return [];
	}
};

/**
 * Persist the set of expanded folder IDs to localStorage.
 *
 * Silently swallows quota errors and missing-storage cases — losing the
 * expansion state across reloads is acceptable; throwing is not.
 *
 * @param {number[]} ids Expanded folder IDs.
 */
export const saveExpandedIds = ( ids ) => {
	try {
		window.localStorage?.setItem( STORAGE_KEY, JSON.stringify( ids ) );
	} catch {
		// localStorage unavailable or quota exceeded — degrade silently.
	}
};

export { STORAGE_KEY };
