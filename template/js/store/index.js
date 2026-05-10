import {
	createReduxStore,
	register,
	dispatch,
	select,
	subscribe,
} from '@wordpress/data';

import { STORE_NAME } from './constants';
import reducer from './reducer';
import * as actions from './actions';
import * as selectors from './selectors';
import * as resolvers from './resolvers';
import controls from './controls';
import {
	readCachedPreferences,
	loadPreferences,
	savePreference,
	PREF_KEYS,
} from '../preferences';

const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
	resolvers,
	controls,
} );

register( store );

const arraysEqual = ( a, b ) => {
	if ( a === b ) {
		return true;
	}
	if (
		! Array.isArray( a ) ||
		! Array.isArray( b ) ||
		a.length !== b.length
	) {
		return false;
	}
	for ( let i = 0; i < a.length; i++ ) {
		if ( a[ i ] !== b[ i ] ) {
			return false;
		}
	}
	return true;
};

// Preferences bridge: sync hydrate from cache, async refresh from server,
// subscriber mirrors changes back via debounced PUT. Window-guarded so the
// module stays importable in unit tests.
if ( typeof window !== 'undefined' ) {
	const cached = readCachedPreferences();
	dispatch( STORE_NAME ).hydrate( {
		expandedIds: cached[ PREF_KEYS.EXPANDED_FOLDERS ],
		allMediaActive: cached[ PREF_KEYS.ALL_MEDIA ],
	} );

	let lastExpandedIds = select( STORE_NAME ).getExpandedIds?.() ?? [];
	let lastAllMediaActive = select( STORE_NAME ).isAllMediaActive?.() ?? false;

	loadPreferences()
		.then( ( server ) => {
			const serverExpanded = server[ PREF_KEYS.EXPANDED_FOLDERS ];
			const serverAllMedia = server[ PREF_KEYS.ALL_MEDIA ];
			const currentExpanded = select( STORE_NAME ).getExpandedIds() ?? [];
			const currentAllMedia =
				select( STORE_NAME ).isAllMediaActive() ?? false;
			if (
				! arraysEqual( serverExpanded, currentExpanded ) ||
				serverAllMedia !== currentAllMedia
			) {
				// Adopt the server values as the new baseline BEFORE the
				// hydrate dispatch fires the subscriber, so the subscriber
				// doesn't echo the server's own values back as a PUT.
				lastExpandedIds = serverExpanded;
				lastAllMediaActive = serverAllMedia;
				dispatch( STORE_NAME ).hydrate( {
					expandedIds: serverExpanded,
					allMediaActive: serverAllMedia,
				} );
			}
		} )
		.catch( () => {
			// Network failure / REST down: keep the cache-hydrated state.
		} );

	subscribe( () => {
		const expandedIds = select( STORE_NAME ).getExpandedIds?.();
		if ( Array.isArray( expandedIds ) && expandedIds !== lastExpandedIds ) {
			lastExpandedIds = expandedIds;
			savePreference( PREF_KEYS.EXPANDED_FOLDERS, expandedIds );
		}

		const allMediaActive = select( STORE_NAME ).isAllMediaActive?.();
		if (
			typeof allMediaActive === 'boolean' &&
			allMediaActive !== lastAllMediaActive
		) {
			lastAllMediaActive = allMediaActive;
			savePreference( PREF_KEYS.ALL_MEDIA, allMediaActive );
		}
	}, STORE_NAME );
}

export { STORE_NAME };
export default store;
