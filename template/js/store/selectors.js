/**
 * Returns the full flat-to-tree folder array.
 *
 * @param {Object} state Store state.
 * @return {Array} Folder tree.
 */
export const getFolders = ( state ) => state.folders;

/**
 * Returns the currently selected folder ID (null = root / all media).
 *
 * @param {Object} state Store state.
 * @return {number|null} Selected folder ID.
 */
export const getSelectedFolderId = ( state ) => state.selectedFolderId;

/**
 * Returns whether the folder tree is loading.
 *
 * @param {Object} state Store state.
 * @return {boolean} Loading flag.
 */
export const isLoading = ( state ) => state.isLoading;

/**
 * Returns the last error message, or null if none.
 *
 * @param {Object} state Store state.
 * @return {string|null} Error message.
 */
export const getError = ( state ) => state.error;

/**
 * Returns the count of media items not assigned to any folder (root).
 *
 * @param {Object} state Store state.
 * @return {number} Root media count.
 */
export const getRootMediaCount = ( state ) => state.rootMediaCount;

/**
 * Returns the total size (bytes) of media not assigned to any folder (root).
 *
 * @param {Object} state Store state.
 * @return {number} Root total size in bytes.
 */
export const getRootTotalSize = ( state ) => state.rootTotalSize;

/**
 * Finds a folder by ID within the tree (recursive search).
 *
 * @param {Object} state    Store state.
 * @param {number} folderId Folder term ID.
 * @return {Object|undefined} Folder object or undefined.
 */
export const getFolderById = ( state, folderId ) => {
	const search = ( folders ) => {
		for ( const folder of folders ) {
			if ( folder.id === folderId ) {
				return folder;
			}
			if ( folder.children?.length ) {
				const found = search( folder.children );
				if ( found ) {
					return found;
				}
			}
		}
		return undefined;
	};
	return search( state.folders );
};

/**
 * Returns the current page of media items.
 *
 * @param {Object} state Store state.
 * @return {Array} Media items.
 */
export const getMedia = ( state ) => state.media;

/**
 * Returns whether media is currently loading.
 *
 * @param {Object} state Store state.
 * @return {boolean} Loading flag.
 */
export const isMediaLoading = ( state ) => state.mediaIsLoading;

/**
 * Returns the total number of media items for the current folder/query.
 *
 * @param {Object} state Store state.
 * @return {number} Total media count.
 */
export const getMediaTotal = ( state ) => state.mediaTotal;

/**
 * Returns the total number of pages for the current media query.
 *
 * @param {Object} state Store state.
 * @return {number} Total pages.
 */
export const getMediaTotalPages = ( state ) => state.mediaTotalPages;

/**
 * Returns the current folder search query string.
 *
 * @param {Object} state Store state.
 * @return {string} Search query.
 */
export const getSearchQuery = ( state ) => state.searchQuery;

/**
 * Filters the folder tree by the current search query.
 * If a child matches, its ancestors are included too.
 *
 * @param {Object} state Store state.
 * @return {Array} Filtered folder tree.
 */
export const getFilteredFolders = ( state ) => {
	const query = state.searchQuery.trim().toLowerCase();
	if ( ! query ) {
		return state.folders;
	}

	const filterTree = ( folders ) => {
		const result = [];
		for ( const folder of folders ) {
			const filteredChildren = folder.children?.length
				? filterTree( folder.children )
				: [];
			const nameMatches = folder.name.toLowerCase().includes( query );
			if ( nameMatches || filteredChildren.length ) {
				result.push( { ...folder, children: filteredChildren } );
			}
		}
		return result;
	};

	return filterTree( state.folders );
};
