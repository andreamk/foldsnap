import { ACTION_TYPES } from '../constants';
import {
	fetchFolders,
	createFolder,
	updateFolder,
	deleteFolder,
	assignMedia,
	removeMedia,
	setSelectedFolder,
	fetchMedia,
	setSearchQuery,
} from '../actions';

/**
 * Step through a generator, resolving API_FETCH yields with the given response.
 *
 * @param {Object} gen         Generator instance.
 * @param {*}      apiResponse Value to return for API_FETCH yields.
 * @return {Object[]} Array of all yielded actions.
 */
const collectYields = ( gen, apiResponse = {} ) => {
	const yields = [];
	let result = gen.next();
	while ( ! result.done ) {
		yields.push( result.value );
		// Resolve API_FETCH yields with apiResponse; pass undefined otherwise.
		result =
			result.value?.type === 'API_FETCH'
				? gen.next( apiResponse )
				: gen.next();
	}
	return yields;
};

describe( 'fetchFolders', () => {
	it( 'yields FETCH_FOLDERS_START then API_FETCH then FETCH_FOLDERS_SUCCESS', () => {
		const apiResponse = {
			folders: [ { id: 1 } ],
			root_media_count: 5,
			root_total_size: 2048,
		};
		const yields = collectYields( fetchFolders(), apiResponse );

		expect( yields[ 0 ] ).toEqual( {
			type: ACTION_TYPES.FETCH_FOLDERS_START,
		} );
		expect( yields[ 1 ] ).toEqual( {
			type: 'API_FETCH',
			request: { path: '/foldsnap/v1/folders', method: 'GET' },
		} );
		expect( yields[ 2 ] ).toEqual( {
			type: ACTION_TYPES.FETCH_FOLDERS_SUCCESS,
			folders: [ { id: 1 } ],
			rootMediaCount: 5,
			rootTotalSize: 2048,
		} );
	} );

	it( 'yields FETCH_FOLDERS_ERROR when API_FETCH throws', () => {
		const gen = fetchFolders();
		gen.next(); // FETCH_FOLDERS_START
		gen.next(); // API_FETCH yield

		const errorResult = gen.throw( new Error( 'Network failed' ) );
		expect( errorResult.value ).toEqual( {
			type: ACTION_TYPES.FETCH_FOLDERS_ERROR,
			error: 'Network failed',
		} );
	} );
} );

describe( 'createFolder', () => {
	it( 'yields API_FETCH POST then re-fetches folders', () => {
		const gen = createFolder( {
			name: 'Photos',
			parentId: 0,
			color: '#ff0000',
			position: 1,
		} );
		const first = gen.next();

		expect( first.value ).toEqual( {
			type: 'API_FETCH',
			request: {
				path: '/foldsnap/v1/folders',
				method: 'POST',
				data: {
					name: 'Photos',
					parent_id: 0,
					color: '#ff0000',
					position: 1,
				},
			},
		} );

		// After the POST, it delegates to fetchFolders via yield*
		const yields = collectYields( gen, {
			folders: [],
			root_media_count: 0,
			root_total_size: 0,
		} );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.FETCH_FOLDERS_START );
	} );
} );

describe( 'updateFolder', () => {
	it( 'yields API_FETCH PUT with correct path and data', () => {
		const gen = updateFolder( 7, {
			name: 'Renamed',
			parentId: 2,
			color: '#00ff00',
			position: 3,
		} );
		const first = gen.next();

		expect( first.value ).toEqual( {
			type: 'API_FETCH',
			request: {
				path: '/foldsnap/v1/folders/7',
				method: 'PUT',
				data: {
					name: 'Renamed',
					parent_id: 2,
					color: '#00ff00',
					position: 3,
				},
			},
		} );
	} );
} );

describe( 'deleteFolder', () => {
	it( 'yields API_FETCH DELETE then re-fetches folders', () => {
		const gen = deleteFolder( 5 );
		const first = gen.next();

		expect( first.value ).toEqual( {
			type: 'API_FETCH',
			request: {
				path: '/foldsnap/v1/folders/5',
				method: 'DELETE',
			},
		} );

		const yields = collectYields( gen, {
			folders: [],
			root_media_count: 0,
			root_total_size: 0,
		} );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.FETCH_FOLDERS_START );
	} );
} );

describe( 'assignMedia', () => {
	it( 'yields API_FETCH POST then re-fetches folders and media', () => {
		const gen = assignMedia( 3, [ 10, 11 ] );
		const first = gen.next();

		expect( first.value ).toEqual( {
			type: 'API_FETCH',
			request: {
				path: '/foldsnap/v1/folders/3/media',
				method: 'POST',
				data: { media_ids: [ 10, 11 ] },
			},
		} );

		const yields = collectYields( gen, {
			folders: [],
			root_media_count: 0,
			root_total_size: 0,
			media: [],
			total: 0,
			total_pages: 0,
		} );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.FETCH_FOLDERS_START );
		expect( types ).toContain( ACTION_TYPES.FETCH_MEDIA_START );
	} );
} );

describe( 'removeMedia', () => {
	it( 'yields API_FETCH DELETE then re-fetches folders and media', () => {
		const gen = removeMedia( 3, [ 10 ] );
		const first = gen.next();

		expect( first.value ).toEqual( {
			type: 'API_FETCH',
			request: {
				path: '/foldsnap/v1/folders/3/media',
				method: 'DELETE',
				data: { media_ids: [ 10 ] },
			},
		} );

		const yields = collectYields( gen, {
			folders: [],
			root_media_count: 0,
			root_total_size: 0,
			media: [],
			total: 0,
			total_pages: 0,
		} );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.FETCH_FOLDERS_START );
		expect( types ).toContain( ACTION_TYPES.FETCH_MEDIA_START );
	} );
} );

describe( 'setSelectedFolder', () => {
	it( 'returns SET_SELECTED_FOLDER action with folderId', () => {
		expect( setSelectedFolder( 5 ) ).toEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 5,
		} );
	} );

	it( 'returns SET_SELECTED_FOLDER action with null', () => {
		expect( setSelectedFolder( null ) ).toEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: null,
		} );
	} );
} );

describe( 'fetchMedia', () => {
	it( 'yields FETCH_MEDIA_START then API_FETCH then FETCH_MEDIA_SUCCESS', () => {
		const apiResponse = {
			media: [ { id: 10 } ],
			total: 1,
			total_pages: 1,
		};
		const yields = collectYields( fetchMedia( 3, 2, 20 ), apiResponse );

		expect( yields[ 0 ] ).toEqual( {
			type: ACTION_TYPES.FETCH_MEDIA_START,
		} );
		expect( yields[ 1 ] ).toEqual( {
			type: 'API_FETCH',
			request: {
				path: '/foldsnap/v1/media?folder_id=3&page=2&per_page=20',
				method: 'GET',
			},
		} );
		expect( yields[ 2 ] ).toEqual( {
			type: ACTION_TYPES.FETCH_MEDIA_SUCCESS,
			media: [ { id: 10 } ],
			total: 1,
			totalPages: 1,
		} );
	} );

	it( 'uses folder_id=0 when folderId is null', () => {
		const gen = fetchMedia( null );
		gen.next(); // FETCH_MEDIA_START
		const apiFetch = gen.next();

		expect( apiFetch.value.request.path ).toContain( 'folder_id=0' );
	} );

	it( 'uses default page=1 and per_page=40', () => {
		const gen = fetchMedia( 5 );
		gen.next(); // FETCH_MEDIA_START
		const apiFetch = gen.next();

		expect( apiFetch.value.request.path ).toBe(
			'/foldsnap/v1/media?folder_id=5&page=1&per_page=40'
		);
	} );

	it( 'yields FETCH_MEDIA_ERROR when API_FETCH throws', () => {
		const gen = fetchMedia( 1 );
		gen.next(); // FETCH_MEDIA_START
		gen.next(); // API_FETCH yield

		const errorResult = gen.throw( new Error( 'Timeout' ) );
		expect( errorResult.value ).toEqual( {
			type: ACTION_TYPES.FETCH_MEDIA_ERROR,
			error: 'Timeout',
		} );
	} );
} );

describe( 'setSearchQuery', () => {
	it( 'returns SET_SEARCH_QUERY action', () => {
		expect( setSearchQuery( 'vacation' ) ).toEqual( {
			type: ACTION_TYPES.SET_SEARCH_QUERY,
			query: 'vacation',
		} );
	} );
} );
