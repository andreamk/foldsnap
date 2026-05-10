import { fetchChildren, expandPathTo } from './actions';
import { ROOT_PARENT_ID } from './constants';

/**
 * Auto-fetch the root folder list the first time `getRootFolders` is read.
 *
 * After hydrating root, walks the already-hydrated expansion set so
 * previously open branches refill themselves without a user action.
 *
 * @return {Iterable} Action generator.
 */
export function* getRootFolders() {
	yield* fetchChildren( ROOT_PARENT_ID );
	const persisted = yield {
		type: 'SELECT',
		selector: 'getExpandedIds',
		args: [],
	};
	if ( Array.isArray( persisted ) ) {
		for ( const id of persisted ) {
			yield* fetchChildren( id );
		}
	}
}

/**
 * Auto-fetch when `getFolderById` is called for an unknown folder.
 *
 * Skips work entirely if the folder is already present in the store: this
 * resolver is the only @wordpress/data resolver attached to a getter that
 * many components hit per render, so it must be a strict no-op when the
 * data is already there. Otherwise a `useSelect` for an already-loaded
 * folder would side-effect the global expansion state via expandPathTo.
 *
 * - Folder already in `foldersById`: do nothing.
 * - id 0 (virtual Root) not yet hydrated: fetch root children; the response
 *   envelope carries the Root model and populates `foldersById[0]`.
 * - Positive id missing from the cache: walk the path via expandPathTo so
 *   ancestors get fetched too (deep-link scenario).
 *
 * @param {number} folderId Folder ID.
 * @return {Iterable} Action generator.
 */
export function* getFolderById( folderId ) {
	if ( typeof folderId !== 'number' || folderId < 0 ) {
		return;
	}

	const existing = yield {
		type: 'SELECT',
		selector: 'getFolderById',
		args: [ folderId ],
	};
	if ( existing !== undefined ) {
		return;
	}

	if ( folderId === ROOT_PARENT_ID ) {
		yield* fetchChildren( ROOT_PARENT_ID );
		return;
	}
	yield* expandPathTo( folderId );
}
