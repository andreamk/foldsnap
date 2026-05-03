import { fetchChildren, expandPathTo } from './actions';
import { ROOT_PARENT_ID } from './constants';
import { loadExpandedIds } from './persistence';

/**
 * Auto-fetch the root folder list the first time `getRootFolders` is read.
 *
 * After hydrating root, walks the persisted expansion set so previously
 * open branches refill themselves without a user action.
 *
 * @return {Iterable} Action generator.
 */
export function* getRootFolders() {
	yield* fetchChildren( ROOT_PARENT_ID );
	const persisted = loadExpandedIds();
	for ( const id of persisted ) {
		yield* fetchChildren( id );
	}
}

/**
 * Auto-fetch a folder's path when `getFolderById` is read for an unknown ID.
 *
 * `expandPathTo` is itself a generator, so we delegate to its action; if the
 * folder is already loaded, the resolver still runs but the fetch dedupes.
 *
 * @param {number} folderId Folder ID.
 * @return {Iterable} Action generator.
 */
export function* getFolderById( folderId ) {
	if ( ! folderId ) {
		return;
	}
	yield* expandPathTo( folderId );
}
