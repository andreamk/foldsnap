import {
	ACTION_TYPES,
	ROOT_PARENT_ID,
	DEFAULT_PER_PAGE,
	SEARCH_PER_PAGE,
} from './constants';

const apiFetch = ( request ) => ( { type: 'API_FETCH', request } );

/**
 * Seed the store with persisted UI state.
 *
 * Dispatched once at registration time by the persistence subscriber. Both
 * fields are optional — pass only what you have.
 *
 * @param {Object}   payload                  Hydration payload.
 * @param {number[]} [payload.expandedIds]    Persisted expanded folder IDs.
 * @param {boolean}  [payload.allMediaActive] Persisted "All Media" toggle.
 * @return {Object} Action.
 */
export const hydrate = ( { expandedIds, allMediaActive } = {} ) => ( {
	type: ACTION_TYPES.HYDRATE,
	expandedIds,
	allMediaActive,
} );

const buildChildrenPath = ( parentIds, page, perPage ) => {
	const params = new URLSearchParams();
	for ( const id of parentIds ) {
		params.append( 'parent_ids[]', String( id ) );
	}
	params.set( 'page', String( page ) );
	params.set( 'per_page', String( perPage ) );
	return `/foldsnap/v1/folders?${ params.toString() }`;
};

/**
 * Fetch (or refetch) the first page of children for a single parent.
 *
 * Dedupes: if the parent is already being fetched, the call is dropped.
 * Idempotent: callers can fire-and-forget on every chevron click.
 *
 * @param {number} parentId        Parent folder ID (0 = root).
 * @param {Object} options         Options.
 * @param {number} options.page    Page number (default 1).
 * @param {number} options.perPage Page size.
 * @return {Iterable} Action generator.
 */
export function* fetchChildren(
	parentId,
	{ page = 1, perPage = DEFAULT_PER_PAGE } = {}
) {
	yield { type: ACTION_TYPES.FETCH_CHILDREN_START, parentId };
	try {
		const response = yield apiFetch( {
			path: buildChildrenPath( [ parentId ], page, perPage ),
			method: 'GET',
		} );
		yield {
			type: ACTION_TYPES.FETCH_CHILDREN_SUCCESS,
			parentId,
			folders: response.folders,
			page: response.page ?? page,
			totalPages: response.total_pages ?? 1,
		};
		if ( response.root ) {
			yield { type: ACTION_TYPES.UPSERT_FOLDER, folder: response.root };
		}
	} catch ( error ) {
		yield {
			type: ACTION_TYPES.FETCH_CHILDREN_ERROR,
			parentId,
			error: error.message,
		};
	}
}

/**
 * Fetch the next page of children for an already-loaded parent.
 *
 * Reads current page from `parentsPagination` via select(); no-ops when the
 * caller is past the last page.
 *
 * @param {number} parentId        Parent folder ID.
 * @param {Object} options         Options.
 * @param {number} options.perPage Page size.
 * @return {Iterable} Action generator.
 */
export function* loadMoreChildren(
	parentId,
	{ perPage = DEFAULT_PER_PAGE } = {}
) {
	const pagination = yield {
		type: 'SELECT',
		selector: 'getParentPagination',
		args: [ parentId ],
	};
	const currentPage = pagination?.page ?? 0;
	const totalPages = pagination?.totalPages ?? 0;
	if ( currentPage >= totalPages ) {
		return;
	}
	const nextPage = currentPage + 1;

	yield { type: ACTION_TYPES.FETCH_CHILDREN_START, parentId };
	try {
		const response = yield apiFetch( {
			path: buildChildrenPath( [ parentId ], nextPage, perPage ),
			method: 'GET',
		} );
		yield {
			type: ACTION_TYPES.FETCH_CHILDREN_APPEND,
			parentId,
			folders: response.folders,
			page: response.page ?? nextPage,
			totalPages: response.total_pages ?? totalPages,
		};
	} catch ( error ) {
		yield {
			type: ACTION_TYPES.FETCH_CHILDREN_ERROR,
			parentId,
			error: error.message,
		};
	}
}

/**
 * Fetch the first page of children for many parents in a single REST call.
 *
 * Used by `expandPathTo` to inflate the breadcrumb in one round-trip.
 *
 * @param {number[]} parentIds       Parent IDs to fetch.
 * @param {Object}   options         Options.
 * @param {number}   options.perPage Page size.
 * @return {Iterable} Action generator.
 */
export function* fetchChildrenBatch(
	parentIds,
	{ perPage = DEFAULT_PER_PAGE } = {}
) {
	if ( parentIds.length === 0 ) {
		return;
	}
	for ( const id of parentIds ) {
		yield { type: ACTION_TYPES.FETCH_CHILDREN_START, parentId: id };
	}
	try {
		const response = yield apiFetch( {
			path: buildChildrenPath( parentIds, 1, perPage ),
			method: 'GET',
		} );
		// API returns folders flat; group by parent_id client-side.
		const grouped = {};
		for ( const id of parentIds ) {
			grouped[ id ] = [];
		}
		for ( const folder of response.folders ) {
			const pid = folder.parent_id ?? ROOT_PARENT_ID;
			if ( grouped[ pid ] ) {
				grouped[ pid ].push( folder );
			}
		}
		for ( const id of parentIds ) {
			yield {
				type: ACTION_TYPES.FETCH_CHILDREN_SUCCESS,
				parentId: id,
				folders: grouped[ id ],
				page: 1,
				totalPages: 1,
			};
		}
		if ( response.root ) {
			yield { type: ACTION_TYPES.UPSERT_FOLDER, folder: response.root };
		}
	} catch ( error ) {
		for ( const id of parentIds ) {
			yield {
				type: ACTION_TYPES.FETCH_CHILDREN_ERROR,
				parentId: id,
				error: error.message,
			};
		}
	}
}

/**
 * Mark a folder as expanded and ensure its children are loaded.
 *
 * @param {number} folderId Folder ID.
 * @return {Iterable} Action generator.
 */
export function* expandFolder( folderId ) {
	yield { type: ACTION_TYPES.EXPAND_FOLDER, folderId };
	const isLoaded = yield {
		type: 'SELECT',
		selector: 'isFolderLoaded',
		args: [ folderId ],
	};
	const isFetching = yield {
		type: 'SELECT',
		selector: 'isFolderFetching',
		args: [ folderId ],
	};
	if ( ! isLoaded && ! isFetching ) {
		yield* fetchChildren( folderId );
	}
}

/**
 * Mark a folder as collapsed (children remain in cache).
 *
 * @param {number} folderId Folder ID.
 * @return {Object} Action object.
 */
export const collapseFolder = ( folderId ) => ( {
	type: ACTION_TYPES.COLLAPSE_FOLDER,
	folderId,
} );

/**
 * Inflate the breadcrumb to a target folder.
 *
 * GETs the path, expands every ancestor, and fetches all their children in
 * a single batched call.
 *
 * @param {number} folderId Folder to surface.
 * @return {Iterable} Action generator.
 */
export function* expandPathTo( folderId ) {
	if ( ! folderId ) {
		return;
	}
	try {
		const response = yield apiFetch( {
			path: `/foldsnap/v1/folders/${ folderId }/path`,
			method: 'GET',
		} );
		const path = response.path ?? [];
		if ( path.length === 0 ) {
			return;
		}
		// Ancestors of the target = path minus the target itself. The
		// backend always prepends Root to every non-empty path, so the
		// resulting list already starts with ROOT_PARENT_ID.
		const ancestorIds = path.slice( 0, -1 ).map( ( f ) => f.id );

		// Merge into existing expandedIds so unrelated branches the user
		// already opened do not collapse when surfacing a deep-linked folder.
		const currentExpanded = yield {
			type: 'SELECT',
			selector: 'getExpandedIds',
			args: [],
		};
		const merged = Array.from(
			new Set( [ ...( currentExpanded ?? [] ), ...ancestorIds ] )
		);
		yield {
			type: ACTION_TYPES.SET_EXPANDED_IDS,
			ids: merged,
		};
		yield {
			type: ACTION_TYPES.APPLY_PATH_TOTALS,
			path,
		};
		yield* fetchChildrenBatch( ancestorIds );
	} catch ( error ) {
		yield {
			type: ACTION_TYPES.FETCH_CHILDREN_ERROR,
			parentId: ROOT_PARENT_ID,
			error: error.message,
		};
	}
}

/**
 * Set the search query string. Components debounce before dispatching.
 *
 * @param {string} query Search string.
 * @return {Object} Action object.
 */
export const setSearchQuery = ( query ) => ( {
	type: ACTION_TYPES.SET_SEARCH_QUERY,
	query,
} );

/**
 * Clear search results (called when query becomes empty).
 *
 * @return {Object} Action object.
 */
export const clearSearch = () => ( { type: ACTION_TYPES.CLEAR_SEARCH } );

const buildSearchPath = ( query, page, perPage ) => {
	const params = new URLSearchParams();
	params.set( 'search', query );
	params.set( 'page', String( page ) );
	params.set( 'per_page', String( perPage ) );
	return `/foldsnap/v1/folders?${ params.toString() }`;
};

/**
 * Run a search query against the API.
 *
 * @param {string} query           Non-empty search string.
 * @param {Object} options         Options.
 * @param {number} options.perPage Page size.
 * @return {Iterable} Action generator.
 */
export function* searchFolders( query, { perPage = SEARCH_PER_PAGE } = {} ) {
	const trimmed = query.trim();
	if ( trimmed === '' ) {
		yield { type: ACTION_TYPES.CLEAR_SEARCH };
		return;
	}
	yield { type: ACTION_TYPES.FETCH_SEARCH_START };
	try {
		const response = yield apiFetch( {
			path: buildSearchPath( trimmed, 1, perPage ),
			method: 'GET',
		} );
		yield {
			type: ACTION_TYPES.FETCH_SEARCH_SUCCESS,
			results: response.results ?? [],
			page: response.page ?? 1,
			totalPages: response.total_pages ?? 0,
			total: response.total ?? 0,
		};
	} catch ( error ) {
		yield { type: ACTION_TYPES.FETCH_SEARCH_ERROR, error: error.message };
	}
}

/**
 * Load the next page of search results.
 *
 * @param {Object} options         Options.
 * @param {number} options.perPage Page size.
 * @return {Iterable} Action generator.
 */
export function* loadMoreSearchResults( { perPage = SEARCH_PER_PAGE } = {} ) {
	const query = yield {
		type: 'SELECT',
		selector: 'getSearchQuery',
		args: [],
	};
	const trimmed = ( query ?? '' ).trim();
	if ( trimmed === '' ) {
		return;
	}
	const pagination = yield {
		type: 'SELECT',
		selector: 'getSearchPagination',
		args: [],
	};
	const currentPage = pagination?.page ?? 0;
	const totalPages = pagination?.totalPages ?? 0;
	if ( currentPage >= totalPages ) {
		return;
	}
	const nextPage = currentPage + 1;
	yield { type: ACTION_TYPES.FETCH_SEARCH_START };
	try {
		const response = yield apiFetch( {
			path: buildSearchPath( trimmed, nextPage, perPage ),
			method: 'GET',
		} );
		yield {
			type: ACTION_TYPES.FETCH_SEARCH_APPEND,
			results: response.results ?? [],
			page: response.page ?? nextPage,
			totalPages: response.total_pages ?? totalPages,
			total: response.total ?? 0,
		};
	} catch ( error ) {
		yield { type: ACTION_TYPES.FETCH_SEARCH_ERROR, error: error.message };
	}
}

const applyMutationEnvelope = function* ( envelope ) {
	if ( envelope.folder ) {
		yield { type: ACTION_TYPES.UPSERT_FOLDER, folder: envelope.folder };
	}
	if ( envelope.root ) {
		yield { type: ACTION_TYPES.UPSERT_FOLDER, folder: envelope.root };
	}
	if ( Array.isArray( envelope.paths ) ) {
		// Each path is an independent ancestor chain (destination + any
		// origin folders touched). The reducer merges totals per-chain.
		for ( const chain of envelope.paths ) {
			if ( Array.isArray( chain ) && chain.length > 0 ) {
				yield { type: ACTION_TYPES.APPLY_PATH_TOTALS, path: chain };
			}
		}
	}
	if ( Array.isArray( envelope.affected_parents ) ) {
		yield {
			type: ACTION_TYPES.APPLY_AFFECTED_PARENTS,
			affectedParents: envelope.affected_parents,
		};
	}
};

/**
 * Create a folder under the given parent.
 *
 * On success, refreshes the parent's children slot so the new folder shows
 * up immediately; affected_parents/paths keep ancestor totals coherent.
 *
 * @param {Object} params          Folder parameters.
 * @param {string} params.name     Folder name.
 * @param {number} params.parentId Parent ID (0 = root).
 * @param {string} params.color    Hex color (optional).
 * @param {number} params.position Position (optional).
 * @return {Iterable} Action generator.
 */
export function* createFolder( {
	name,
	parentId = ROOT_PARENT_ID,
	color = '',
	position = 0,
} ) {
	const response = yield apiFetch( {
		path: '/foldsnap/v1/folders',
		method: 'POST',
		data: { name, parent_id: parentId, color, position },
	} );
	yield* applyMutationEnvelope( response );
	// Refresh the parent slot to reflect the new sibling order from the server.
	yield* fetchChildren( parentId );
}

/**
 * Update an existing folder.
 *
 * Reparenting is detected from the response envelope; both old and new
 * parent slots are refreshed so the tree stays consistent.
 *
 * @param {number} id              Folder ID.
 * @param {Object} params          Fields to update.
 * @param {string} params.name     New name.
 * @param {number} params.parentId New parent ID (-1 = unchanged).
 * @param {string} params.color    New color.
 * @param {number} params.position New position (-1 = unchanged).
 * @return {Iterable} Action generator.
 */
export function* updateFolder(
	id,
	{ name, parentId = -1, color, position = -1 }
) {
	const previous = yield {
		type: 'SELECT',
		selector: 'getFolderById',
		args: [ id ],
	};
	const oldParentId = previous?.parent_id ?? null;

	const response = yield apiFetch( {
		path: `/foldsnap/v1/folders/${ id }`,
		method: 'PUT',
		data: { name, parent_id: parentId, color, position },
	} );

	const newParentId = response.folder?.parent_id ?? null;

	if (
		oldParentId !== null &&
		newParentId !== null &&
		oldParentId !== newParentId
	) {
		// Reparented: drop from old slot before applying envelope so the new
		// parent's slot can host it (envelope's UPSERT_FOLDER inserts there).
		yield {
			type: ACTION_TYPES.REMOVE_FOLDER,
			folderId: id,
			parentId: oldParentId,
		};
		yield* applyMutationEnvelope( response );
		yield* fetchChildren( oldParentId );
		yield* fetchChildren( newParentId );
	} else {
		yield* applyMutationEnvelope( response );
	}
}

/**
 * Delete a folder. Media inside return to root.
 *
 * @param {number} id Folder ID.
 * @return {Iterable} Action generator.
 */
export function* deleteFolder( id ) {
	const previous = yield {
		type: 'SELECT',
		selector: 'getFolderById',
		args: [ id ],
	};
	const oldParentId = previous?.parent_id ?? ROOT_PARENT_ID;

	const response = yield apiFetch( {
		path: `/foldsnap/v1/folders/${ id }`,
		method: 'DELETE',
	} );

	yield {
		type: ACTION_TYPES.REMOVE_FOLDER,
		folderId: id,
		parentId: oldParentId,
	};
	yield* applyMutationEnvelope( response );
}

/**
 * Assign media items to a folder.
 *
 * @param {number}   folderId Folder ID.
 * @param {number[]} mediaIds Attachment IDs.
 * @return {Iterable} Action generator.
 */
export function* assignMedia( folderId, mediaIds ) {
	const response = yield apiFetch( {
		path: `/foldsnap/v1/folders/${ folderId }/media`,
		method: 'POST',
		data: { media_ids: mediaIds },
	} );
	yield* applyMutationEnvelope( response );
	yield* fetchMedia( folderId );
}

/**
 * Remove media items from a folder.
 *
 * @param {number}   folderId Folder ID.
 * @param {number[]} mediaIds Attachment IDs.
 * @return {Iterable} Action generator.
 */
export function* removeMedia( folderId, mediaIds ) {
	const response = yield apiFetch( {
		path: `/foldsnap/v1/folders/${ folderId }/media`,
		method: 'DELETE',
		data: { media_ids: mediaIds },
	} );
	yield* applyMutationEnvelope( response );
	yield* fetchMedia( folderId );
}

/**
 * Set the currently selected folder (null = root).
 *
 * @param {number|null} folderId Folder ID.
 * @return {Object} Action object.
 */
export const setSelectedFolder = ( folderId ) => ( {
	type: ACTION_TYPES.SET_SELECTED_FOLDER,
	folderId,
} );

/**
 * Toggle the "All Media" mode that bypasses the folder sidebar.
 *
 * When active, the sidebar is rendered inert and the native WordPress media
 * grid stops being filtered by `foldsnap_folder_id`.
 *
 * @param {boolean} active Whether the toggle is on.
 * @return {Object} Action object.
 */
export const setAllMedia = ( active ) => ( {
	type: ACTION_TYPES.SET_ALL_MEDIA,
	active: Boolean( active ),
} );

/**
 * Fetch a paginated page of media for a folder (or root if null/0).
 *
 * @param {number|null} folderId Folder ID (null/0 = root).
 * @param {number}      page     Page number.
 * @param {number}      perPage  Items per page.
 * @return {Iterable} Action generator.
 */
export function* fetchMedia( folderId, page = 1, perPage = 40 ) {
	yield { type: ACTION_TYPES.FETCH_MEDIA_START };
	try {
		const id = folderId ?? 0;
		const response = yield apiFetch( {
			path: `/foldsnap/v1/media?folder_id=${ id }&page=${ page }&per_page=${ perPage }`,
			method: 'GET',
		} );
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
