import { ROOT_PARENT_ID } from './constants';

const EMPTY_ARRAY = Object.freeze( [] );

/**
 * Returns the children of a parent folder, or an empty array if not loaded.
 *
 * Always returns the same frozen empty array reference for missing slots so
 * downstream useSelect comparisons stay stable.
 *
 * @param {Object} state    Store state.
 * @param {number} parentId Parent folder ID (0 = root).
 * @return {Array} Children folders.
 */
export const getChildrenOf = ( state, parentId ) =>
	state.foldersByParent[ parentId ] ?? EMPTY_ARRAY;

/**
 * Returns the root-level folders (children of parent 0).
 *
 * @param {Object} state Store state.
 * @return {Array} Root folders.
 */
export const getRootFolders = ( state ) =>
	getChildrenOf( state, ROOT_PARENT_ID );

/**
 * O(1) lookup of a folder by ID.
 *
 * @param {Object} state    Store state.
 * @param {number} folderId Folder ID.
 * @return {Object|undefined} Folder or undefined.
 */
export const getFolderById = ( state, folderId ) =>
	state.foldersById[ folderId ];

/**
 * Whether the user has expanded this folder in the tree.
 *
 * @param {Object} state    Store state.
 * @param {number} folderId Folder ID.
 * @return {boolean} Expansion flag.
 */
export const isFolderExpanded = ( state, folderId ) =>
	state.expandedIds.includes( folderId );

/**
 * Whether the children of this parent have been fetched at least once.
 *
 * @param {Object} state    Store state.
 * @param {number} parentId Parent folder ID.
 * @return {boolean} Loaded flag.
 */
export const isFolderLoaded = ( state, parentId ) =>
	state.loadedParents.includes( parentId );

/**
 * Whether a fetch for this parent's children is currently in flight.
 *
 * @param {Object} state    Store state.
 * @param {number} parentId Parent folder ID.
 * @return {boolean} Fetching flag.
 */
export const isFolderFetching = ( state, parentId ) =>
	state.fetchingParents.includes( parentId );

/**
 * Pagination state for a parent's children list.
 *
 * @param {Object} state    Store state.
 * @param {number} parentId Parent folder ID.
 * @return {{page: number, totalPages: number}|undefined} Pagination or undefined.
 */
export const getParentPagination = ( state, parentId ) =>
	state.parentsPagination[ parentId ];

/**
 * Currently selected folder ID (null = root / All Media).
 *
 * @param {Object} state Store state.
 * @return {number|null} Selected ID.
 */
export const getSelectedFolderId = ( state ) => state.selectedFolderId;

/**
 * Cached root media count (unassigned attachments).
 *
 * @param {Object} state Store state.
 * @return {number} Root media count.
 */
export const getRootMediaCount = ( state ) => state.rootMediaCount;

/**
 * Cached total bytes of unassigned media.
 *
 * @param {Object} state Store state.
 * @return {number} Root size in bytes.
 */
export const getRootTotalSize = ( state ) => state.rootTotalSize;

/**
 * Current search query string.
 *
 * @param {Object} state Store state.
 * @return {string} Query.
 */
export const getSearchQuery = ( state ) => state.searchQuery;

/**
 * Current search result entries (each: { folder, breadcrumb }).
 *
 * @param {Object} state Store state.
 * @return {Array} Results.
 */
export const getSearchResults = ( state ) => state.searchResults;

/**
 * Whether the search request is currently in flight.
 *
 * @param {Object} state Store state.
 * @return {boolean} Loading flag.
 */
export const isSearchLoading = ( state ) => state.searchIsLoading;

/**
 * Pagination state for the active search.
 *
 * @param {Object} state Store state.
 * @return {{page: number, totalPages: number, total: number}} Pagination.
 */
export const getSearchPagination = ( state ) => ( {
	page: state.searchPage,
	totalPages: state.searchTotalPages,
	total: state.searchTotal,
} );

/**
 * Last error message, or null.
 *
 * @param {Object} state Store state.
 * @return {string|null} Error.
 */
export const getError = ( state ) => state.error;

/**
 * Current page of media items.
 *
 * @param {Object} state Store state.
 * @return {Array} Media items.
 */
export const getMedia = ( state ) => state.media;

/**
 * Whether media is currently loading.
 *
 * @param {Object} state Store state.
 * @return {boolean} Loading flag.
 */
export const isMediaLoading = ( state ) => state.mediaIsLoading;

/**
 * Total media count for the active folder/query.
 *
 * @param {Object} state Store state.
 * @return {number} Total.
 */
export const getMediaTotal = ( state ) => state.mediaTotal;

/**
 * Total pages for the active media query.
 *
 * @param {Object} state Store state.
 * @return {number} Total pages.
 */
export const getMediaTotalPages = ( state ) => state.mediaTotalPages;
