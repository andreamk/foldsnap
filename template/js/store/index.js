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
	loadExpandedIds,
	loadAllMediaActive,
	saveExpandedIds,
	saveAllMediaActive,
} from './persistence';

const store = createReduxStore( STORE_NAME, {
	reducer,
	actions,
	selectors,
	resolvers,
	controls,
} );

register( store );

// Persistence bridge — keeps the reducer pure. Hydrates from localStorage
// once at startup, then mirrors `expandedIds` and `allMediaActive` back
// to localStorage whenever they change. Skipped under SSR / when window
// is unavailable so the module is safe to import in tests.
if ( typeof window !== 'undefined' ) {
	dispatch( STORE_NAME ).hydrate( {
		expandedIds: loadExpandedIds(),
		allMediaActive: loadAllMediaActive(),
	} );

	let lastExpandedIds = select( STORE_NAME ).getExpandedIds?.() ?? [];
	let lastAllMediaActive = select( STORE_NAME ).isAllMediaActive?.() ?? false;

	subscribe( () => {
		const expandedIds = select( STORE_NAME ).getExpandedIds?.();
		if ( Array.isArray( expandedIds ) && expandedIds !== lastExpandedIds ) {
			lastExpandedIds = expandedIds;
			saveExpandedIds( expandedIds );
		}

		const allMediaActive = select( STORE_NAME ).isAllMediaActive?.();
		if (
			typeof allMediaActive === 'boolean' &&
			allMediaActive !== lastAllMediaActive
		) {
			lastAllMediaActive = allMediaActive;
			saveAllMediaActive( allMediaActive );
		}
	}, STORE_NAME );
}

export { STORE_NAME };
export default store;
