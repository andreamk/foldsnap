import { ACTION_TYPES } from './constants';

const DEFAULT_STATE = {
	folders: [],
	isLoading: false,
	error: null,
	selectedFolderId: null,
	rootMediaCount: 0,
	rootTotalSize: 0,
	media: [],
	mediaTotal: 0,
	mediaTotalPages: 0,
	mediaIsLoading: false,
	searchQuery: '',
};

const reducer = ( state = DEFAULT_STATE, action ) => {
	switch ( action.type ) {
		case ACTION_TYPES.FETCH_FOLDERS_START:
			return { ...state, isLoading: true, error: null };

		case ACTION_TYPES.FETCH_FOLDERS_SUCCESS:
			return {
				...state,
				isLoading: false,
				folders: action.folders,
				rootMediaCount: action.rootMediaCount,
				rootTotalSize: action.rootTotalSize,
			};

		case ACTION_TYPES.FETCH_FOLDERS_ERROR:
			return { ...state, isLoading: false, error: action.error };

		case ACTION_TYPES.SET_SELECTED_FOLDER:
			return { ...state, selectedFolderId: action.folderId };

		case ACTION_TYPES.SET_SEARCH_QUERY:
			return { ...state, searchQuery: action.query };

		case ACTION_TYPES.FETCH_MEDIA_START:
			return { ...state, mediaIsLoading: true };

		case ACTION_TYPES.FETCH_MEDIA_SUCCESS:
			return {
				...state,
				mediaIsLoading: false,
				media: action.media,
				mediaTotal: action.total,
				mediaTotalPages: action.totalPages,
			};

		case ACTION_TYPES.FETCH_MEDIA_ERROR:
			return { ...state, mediaIsLoading: false, error: action.error };

		default:
			return state;
	}
};

export default reducer;
