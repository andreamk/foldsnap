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
 * Returns the full set of expanded folder IDs.
 *
 * @param {Object} state Store state.
 * @return {number[]} Expanded folder IDs.
 */
export const getExpandedIds = ( state ) => state.expandedIds;

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
 * Whether the "All Media" override is active.
 *
 * When true the sidebar is rendered inert and the native media grid stops
 * being filtered by folder.
 *
 * @param {Object} state Store state.
 * @return {boolean} Active flag.
 */
export const isAllMediaActive = ( state ) => state.allMediaActive;

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
 * Current page of the active search.
 *
 * @param {Object} state Store state.
 * @return {number} Page number.
 */
export const getSearchPage = ( state ) => state.searchPage;

/**
 * Total pages of the active search.
 *
 * @param {Object} state Store state.
 * @return {number} Total pages.
 */
export const getSearchTotalPages = ( state ) => state.searchTotalPages;

/**
 * Total result count of the active search.
 *
 * @param {Object} state Store state.
 * @return {number} Total results.
 */
export const getSearchTotal = ( state ) => state.searchTotal;

/**
 * Last error message, or null.
 *
 * @param {Object} state Store state.
 * @return {string|null} Error.
 */
export const getError = ( state ) => state.error;
