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
	getInitialPreferences,
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

if ( typeof window !== 'undefined' ) {
	const initial = getInitialPreferences();
	dispatch( STORE_NAME ).hydrate( {
		expandedIds: initial[ PREF_KEYS.EXPANDED_FOLDERS ],
		allMediaActive: initial[ PREF_KEYS.ALL_MEDIA ],
	} );

	let lastExpandedIds = select( STORE_NAME ).getExpandedIds?.() ?? [];
	let lastAllMediaActive = select( STORE_NAME ).isAllMediaActive?.() ?? false;
	let lastSelectedFolderId =
		select( STORE_NAME ).getSelectedFolderId?.() ?? null;

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

		const selectedFolderId = select( STORE_NAME ).getSelectedFolderId?.();
		if (
			typeof selectedFolderId === 'number' &&
			selectedFolderId !== lastSelectedFolderId
		) {
			lastSelectedFolderId = selectedFolderId;
			savePreference( PREF_KEYS.SELECTED_FOLDER_ID, selectedFolderId );
		}
	}, STORE_NAME );
}

export { STORE_NAME };
export default store;
