import { fetchFolders } from './actions';

/**
 * Auto-fetches folders the first time `getFolders` is called.
 *
 * @return {void}
 */
export function* getFolders() {
	yield* fetchFolders();
}
