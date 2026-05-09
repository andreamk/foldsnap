import { subscribe, select } from '@wordpress/data';
import { STORE_NAME } from '../store/constants';
import {
	applyGridFilter,
	pollUntilGridReady,
	installRefreshGridGlobal,
} from './grid-reflector';
import { redirectListMode } from './list-mode-redirector';
import { updateModeToggleLinks } from './view-switch-links';

// Orchestrates the side-effects that mirror the React store onto the
// native WordPress media library. Each effect lives in its own module;
// this file decides which one to run on each store change.
export default function initMediaModeBridge() {
	const isListMode = window.foldsnap_data?.mediaMode === 'list';

	installRefreshGridGlobal();

	const readEffectiveFolderId = () => {
		const store = select( STORE_NAME );
		// "All Media" is the explicit opt-in for an unfiltered view; it
		// bypasses the folder selection entirely.
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

		if ( isListMode ) {
			redirectListMode( folderId );
		} else {
			applyGridFilter( folderId );
		}
	} );

	if ( ! isListMode ) {
		pollUntilGridReady( () => applyGridFilter( readEffectiveFolderId() ) );
	}
}
