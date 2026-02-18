import { ACTION_TYPES } from './constants';

/**
 * Fetches the full folder tree from the REST API.
 *
 * @return {void}
 */
export function* fetchFolders() {
	yield { type: ACTION_TYPES.FETCH_FOLDERS_START };
	try {
		const response = yield {
			type: 'API_FETCH',
			request: { path: '/foldsnap/v1/folders', method: 'GET' },
		};
		yield {
			type: ACTION_TYPES.FETCH_FOLDERS_SUCCESS,
			folders: response.folders,
			rootMediaCount: response.root_media_count,
			rootTotalSize: response.root_total_size,
		};
	} catch ( error ) {
		yield { type: ACTION_TYPES.FETCH_FOLDERS_ERROR, error: error.message };
	}
}

/**
 * Creates a new folder, then re-fetches the tree.
 *
 * @param {Object} params          Folder parameters.
 * @param {string} params.name     Folder name.
 * @param {number} params.parentId Parent folder ID (0 for root).
 * @param {string} params.color    Hex color string.
 * @param {number} params.position Sort position.
 * @return {void}
 */
export function* createFolder( {
	name,
	parentId = 0,
	color = '',
	position = 0,
} ) {
	yield {
		type: 'API_FETCH',
		request: {
			path: '/foldsnap/v1/folders',
			method: 'POST',
			data: { name, parent_id: parentId, color, position },
		},
	};
	yield* fetchFolders();
}

/**
 * Updates an existing folder, then re-fetches the tree.
 *
 * @param {number} id              Folder term ID.
 * @param {Object} params          Fields to update.
 * @param {string} params.name     New name.
 * @param {number} params.parentId New parent ID (-1 = unchanged).
 * @param {string} params.color    New hex color.
 * @param {number} params.position New position (-1 = unchanged).
 * @return {void}
 */
export function* updateFolder(
	id,
	{ name, parentId = -1, color, position = -1 }
) {
	yield {
		type: 'API_FETCH',
		request: {
			path: `/foldsnap/v1/folders/${ id }`,
			method: 'PUT',
			data: { name, parent_id: parentId, color, position },
		},
	};
	yield* fetchFolders();
}

/**
 * Deletes a folder (media return to root), then re-fetches the tree.
 *
 * @param {number} id Folder term ID.
 * @return {void}
 */
export function* deleteFolder( id ) {
	yield {
		type: 'API_FETCH',
		request: {
			path: `/foldsnap/v1/folders/${ id }`,
			method: 'DELETE',
		},
	};
	yield* fetchFolders();
}

/**
 * Assigns media items to a folder, then re-fetches tree and media list.
 *
 * @param {number}   folderId Folder term ID.
 * @param {number[]} mediaIds Attachment IDs.
 * @return {void}
 */
export function* assignMedia( folderId, mediaIds ) {
	yield {
		type: 'API_FETCH',
		request: {
			path: `/foldsnap/v1/folders/${ folderId }/media`,
			method: 'POST',
			data: { media_ids: mediaIds },
		},
	};
	yield* fetchFolders();
	yield* fetchMedia( folderId );
}

/**
 * Removes media items from a folder, then re-fetches tree and media list.
 *
 * @param {number}   folderId Folder term ID.
 * @param {number[]} mediaIds Attachment IDs.
 * @return {void}
 */
export function* removeMedia( folderId, mediaIds ) {
	yield {
		type: 'API_FETCH',
		request: {
			path: `/foldsnap/v1/folders/${ folderId }/media`,
			method: 'DELETE',
			data: { media_ids: mediaIds },
		},
	};
	yield* fetchFolders();
	yield* fetchMedia( folderId );
}

/**
 * Sets the currently selected folder.
 *
 * @param {number|null} folderId Folder term ID or null for root.
 * @return {Object} Action object.
 */
export function setSelectedFolder( folderId ) {
	return { type: ACTION_TYPES.SET_SELECTED_FOLDER, folderId };
}

/**
 * Fetches paginated media for a given folder.
 *
 * @param {number|null} folderId Folder term ID (null or 0 = unassigned).
 * @param {number}      page     Page number.
 * @param {number}      perPage  Items per page.
 * @return {void}
 */
export function* fetchMedia( folderId, page = 1, perPage = 40 ) {
	yield { type: ACTION_TYPES.FETCH_MEDIA_START };
	try {
		const id = folderId ?? 0;
		const response = yield {
			type: 'API_FETCH',
			request: {
				path: `/foldsnap/v1/media?folder_id=${ id }&page=${ page }&per_page=${ perPage }`,
				method: 'GET',
			},
		};
		yield {
			type: ACTION_TYPES.FETCH_MEDIA_SUCCESS,
			media: response.media,
			total: response.total,
			totalPages: response.total_pages,
		};
	} catch ( error ) {
		yield { type: ACTION_TYPES.FETCH_MEDIA_ERROR, error: error.message };
	}
}

/**
 * Sets the folder search query.
 *
 * @param {string} query Search string.
 * @return {Object} Action object.
 */
export function setSearchQuery( query ) {
	return { type: ACTION_TYPES.SET_SEARCH_QUERY, query };
}
