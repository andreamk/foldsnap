import { ACTION_TYPES, ROOT_PARENT_ID } from './constants';
import {
	setChildrenForParent,
	appendChildrenForParent,
	removeFolderFromMap,
	replaceFolderInMap,
} from '../utils/build-children-map';

const indexById = ( folders ) => {
	const map = {};
	for ( const folder of folders ) {
		map[ folder.id ] = folder;
	}
	return map;
};

const mergeById = ( current, folders ) => {
	const next = { ...current };
	for ( const folder of folders ) {
		next[ folder.id ] = folder;
	}
	return next;
};

const without = ( list, value ) => list.filter( ( id ) => id !== value );

const DEFAULT_STATE = {
	foldersByParent: {},
	foldersById: {},
	loadedParents: [],
	fetchingParents: [],
	parentsPagination: {},
	expandedIds: [],

	selectedFolderId: null,
	allMediaActive: false,

	searchQuery: '',
	searchResults: [],
	searchPage: 0,
	searchTotalPages: 0,
	searchTotal: 0,
	searchIsLoading: false,

	error: null,
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case ACTION_TYPES.HYDRATE:
			return {
				...state,
				expandedIds: action.expandedIds ?? state.expandedIds,
				allMediaActive: action.allMediaActive ?? state.allMediaActive,
			};

		case ACTION_TYPES.FETCH_CHILDREN_START:
			return {
				...state,
				fetchingParents: state.fetchingParents.includes(
					action.parentId
				)
					? state.fetchingParents
					: [ ...state.fetchingParents, action.parentId ],
				error: null,
			};

		case ACTION_TYPES.FETCH_CHILDREN_SUCCESS: {
			const { parentId, folders, page, totalPages } = action;
			return {
				...state,
				foldersByParent: setChildrenForParent(
					state.foldersByParent,
					parentId,
					folders
				),
				foldersById: mergeById( state.foldersById, folders ),
				loadedParents: state.loadedParents.includes( parentId )
					? state.loadedParents
					: [ ...state.loadedParents, parentId ],
				fetchingParents: without( state.fetchingParents, parentId ),
				parentsPagination: {
					...state.parentsPagination,
					[ parentId ]: { page, totalPages },
				},
			};
		}

		case ACTION_TYPES.FETCH_CHILDREN_APPEND: {
			const { parentId, folders, page, totalPages } = action;
			return {
				...state,
				foldersByParent: appendChildrenForParent(
					state.foldersByParent,
					parentId,
					folders
				),
				foldersById: mergeById( state.foldersById, folders ),
				fetchingParents: without( state.fetchingParents, parentId ),
				parentsPagination: {
					...state.parentsPagination,
					[ parentId ]: { page, totalPages },
				},
			};
		}

		case ACTION_TYPES.FETCH_CHILDREN_ERROR:
			return {
				...state,
				fetchingParents: without(
					state.fetchingParents,
					action.parentId
				),
				error: action.error,
			};

		case ACTION_TYPES.EXPAND_FOLDER: {
			if ( state.expandedIds.includes( action.folderId ) ) {
				return state;
			}
			return {
				...state,
				expandedIds: [ ...state.expandedIds, action.folderId ],
			};
		}

		case ACTION_TYPES.COLLAPSE_FOLDER: {
			if ( ! state.expandedIds.includes( action.folderId ) ) {
				return state;
			}
			return {
				...state,
				expandedIds: without( state.expandedIds, action.folderId ),
			};
		}

		case ACTION_TYPES.SET_EXPANDED_IDS:
			return { ...state, expandedIds: action.ids };

		case ACTION_TYPES.SET_SELECTED_FOLDER:
			return { ...state, selectedFolderId: action.folderId };

		case ACTION_TYPES.SET_ALL_MEDIA: {
			if ( state.allMediaActive === action.active ) {
				return state;
			}
			return { ...state, allMediaActive: action.active };
		}

		case ACTION_TYPES.SET_SEARCH_QUERY:
			return { ...state, searchQuery: action.query };

		case ACTION_TYPES.FETCH_SEARCH_START:
			return { ...state, searchIsLoading: true, error: null };

		case ACTION_TYPES.FETCH_SEARCH_SUCCESS:
			return {
				...state,
				searchIsLoading: false,
				searchResults: action.results,
				searchPage: action.page,
				searchTotalPages: action.totalPages,
				searchTotal: action.total,
			};

		case ACTION_TYPES.FETCH_SEARCH_APPEND:
			return {
				...state,
				searchIsLoading: false,
				searchResults: [ ...state.searchResults, ...action.results ],
				searchPage: action.page,
				searchTotalPages: action.totalPages,
				searchTotal: action.total,
			};

		case ACTION_TYPES.FETCH_SEARCH_ERROR:
			return { ...state, searchIsLoading: false, error: action.error };

		case ACTION_TYPES.CLEAR_SEARCH:
			return {
				...state,
				searchResults: [],
				searchPage: 0,
				searchTotalPages: 0,
				searchTotal: 0,
				searchIsLoading: false,
			};

		case ACTION_TYPES.APPLY_AFFECTED_PARENTS: {
			let foldersByParent = state.foldersByParent;
			let foldersById = state.foldersById;
			for ( const entry of action.affectedParents ) {
				const existing = foldersById[ entry.id ];
				if ( ! existing ) {
					continue;
				}
				const updated = {
					...existing,
					has_children: entry.has_children,
				};
				foldersById = { ...foldersById, [ entry.id ]: updated };
				foldersByParent = replaceFolderInMap(
					foldersByParent,
					updated
				);
			}
			if (
				foldersByParent === state.foldersByParent &&
				foldersById === state.foldersById
			) {
				return state;
			}
			return { ...state, foldersByParent, foldersById };
		}

		case ACTION_TYPES.APPLY_PATH_TOTALS: {
			let foldersByParent = state.foldersByParent;
			const foldersById = mergeById( state.foldersById, action.path );
			for ( const folder of action.path ) {
				foldersByParent = replaceFolderInMap( foldersByParent, folder );
			}
			return { ...state, foldersByParent, foldersById };
		}

		case ACTION_TYPES.UPSERT_FOLDER: {
			const { folder } = action;
			const parentId = folder.parent_id ?? ROOT_PARENT_ID;
			const foldersById = { ...state.foldersById, [ folder.id ]: folder };

			let foldersByParent = state.foldersByParent;
			// Root has parent_id = 0 by convention but is not its own child;
			// only mutate parent slots for non-root folders.
			if ( ! folder.is_root && foldersByParent[ parentId ] ) {
				const slot = foldersByParent[ parentId ];
				const idx = slot.findIndex( ( f ) => f.id === folder.id );
				const next = idx === -1 ? [ ...slot, folder ] : slot.slice();
				if ( idx !== -1 ) {
					next[ idx ] = folder;
				}
				foldersByParent = { ...foldersByParent, [ parentId ]: next };
			}
			return { ...state, foldersById, foldersByParent };
		}

		case ACTION_TYPES.REMOVE_FOLDER: {
			const { folderId, parentId } = action;
			const foldersById = { ...state.foldersById };
			delete foldersById[ folderId ];
			return {
				...state,
				foldersById,
				foldersByParent: removeFolderFromMap(
					state.foldersByParent,
					parentId,
					folderId
				),
				expandedIds: without( state.expandedIds, folderId ),
				loadedParents: without( state.loadedParents, folderId ),
				fetchingParents: without( state.fetchingParents, folderId ),
				selectedFolderId:
					state.selectedFolderId === folderId
						? null
						: state.selectedFolderId,
			};
		}

		default:
			return state;
	}
};

export { DEFAULT_STATE, indexById };
export default reducer;
