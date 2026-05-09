import { ACTION_TYPES, ROOT_PARENT_ID } from '../constants';
import reducer from '../reducer';

const baseState = () => reducer( undefined, { type: '@@INIT' } );

describe( 'reducer', () => {
	it( 'has the default flat-state shape with empty persisted slices', () => {
		const state = baseState();
		expect( state.foldersByParent ).toEqual( {} );
		expect( state.foldersById ).toEqual( {} );
		expect( state.loadedParents ).toEqual( [] );
		expect( state.fetchingParents ).toEqual( [] );
		expect( state.parentsPagination ).toEqual( {} );
		expect( state.expandedIds ).toEqual( [] );
		expect( state.allMediaActive ).toBe( false );
		expect( state.searchQuery ).toBe( '' );
		expect( state.searchResults ).toEqual( [] );
	} );

	it( 'HYDRATE seeds expandedIds and allMediaActive', () => {
		const state = reducer( baseState(), {
			type: ACTION_TYPES.HYDRATE,
			expandedIds: [ 1, 2, 3 ],
			allMediaActive: true,
		} );
		expect( state.expandedIds ).toEqual( [ 1, 2, 3 ] );
		expect( state.allMediaActive ).toBe( true );
	} );

	it( 'HYDRATE leaves slices unchanged when payload is undefined', () => {
		const state = reducer( baseState(), {
			type: ACTION_TYPES.HYDRATE,
		} );
		expect( state.expandedIds ).toEqual( [] );
		expect( state.allMediaActive ).toBe( false );
	} );

	it( 'returns state unchanged for unknown actions', () => {
		const state = baseState();
		expect( reducer( state, { type: 'UNKNOWN' } ) ).toBe( state );
	} );

	describe( 'FETCH_CHILDREN_*', () => {
		it( 'tracks in-flight parents on START', () => {
			const state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_CHILDREN_START,
				parentId: 5,
			} );
			expect( state.fetchingParents ).toEqual( [ 5 ] );
		} );

		it( 'dedupes repeated START for the same parent', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_CHILDREN_START,
				parentId: 5,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.FETCH_CHILDREN_START,
				parentId: 5,
			} );
			expect( state.fetchingParents ).toEqual( [ 5 ] );
		} );

		it( 'fills foldersByParent + foldersById on SUCCESS and clears in-flight', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_CHILDREN_START,
				parentId: 0,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.FETCH_CHILDREN_SUCCESS,
				parentId: 0,
				folders: [
					{ id: 1, name: 'A', parent_id: 0 },
					{ id: 2, name: 'B', parent_id: 0 },
				],
				page: 1,
				totalPages: 1,
			} );
			expect( state.foldersByParent[ 0 ] ).toHaveLength( 2 );
			expect( state.foldersById[ 1 ].name ).toBe( 'A' );
			expect( state.loadedParents ).toEqual( [ 0 ] );
			expect( state.fetchingParents ).toEqual( [] );
			expect( state.parentsPagination[ 0 ] ).toEqual( {
				page: 1,
				totalPages: 1,
			} );
		} );

		it( 'APPEND adds new pages without losing the existing ones', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_CHILDREN_SUCCESS,
				parentId: 0,
				folders: [ { id: 1, name: 'A', parent_id: 0 } ],
				page: 1,
				totalPages: 2,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.FETCH_CHILDREN_APPEND,
				parentId: 0,
				folders: [ { id: 2, name: 'B', parent_id: 0 } ],
				page: 2,
				totalPages: 2,
			} );
			expect( state.foldersByParent[ 0 ].map( ( f ) => f.id ) ).toEqual( [
				1, 2,
			] );
			expect( state.parentsPagination[ 0 ].page ).toBe( 2 );
		} );

		it( 'records error on ERROR and clears in-flight', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_CHILDREN_START,
				parentId: 7,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.FETCH_CHILDREN_ERROR,
				parentId: 7,
				error: 'boom',
			} );
			expect( state.error ).toBe( 'boom' );
			expect( state.fetchingParents ).toEqual( [] );
		} );
	} );

	describe( 'EXPAND_FOLDER / COLLAPSE_FOLDER', () => {
		it( 'EXPAND adds the id to expandedIds', () => {
			const state = reducer( baseState(), {
				type: ACTION_TYPES.EXPAND_FOLDER,
				folderId: 42,
			} );
			expect( state.expandedIds ).toEqual( [ 42 ] );
		} );

		it( 'EXPAND is a no-op when already expanded', () => {
			const state = reducer( baseState(), {
				type: ACTION_TYPES.EXPAND_FOLDER,
				folderId: 42,
			} );
			const after = reducer( state, {
				type: ACTION_TYPES.EXPAND_FOLDER,
				folderId: 42,
			} );
			expect( after ).toBe( state );
		} );

		it( 'COLLAPSE removes the id from expandedIds', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.EXPAND_FOLDER,
				folderId: 42,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.COLLAPSE_FOLDER,
				folderId: 42,
			} );
			expect( state.expandedIds ).toEqual( [] );
		} );

		it( 'SET_EXPANDED_IDS replaces the whole list', () => {
			const state = reducer( baseState(), {
				type: ACTION_TYPES.SET_EXPANDED_IDS,
				ids: [ 1, 2, 3 ],
			} );
			expect( state.expandedIds ).toEqual( [ 1, 2, 3 ] );
		} );
	} );

	describe( 'SET_ALL_MEDIA', () => {
		it( 'flips allMediaActive on', () => {
			const state = reducer( baseState(), {
				type: ACTION_TYPES.SET_ALL_MEDIA,
				active: true,
			} );
			expect( state.allMediaActive ).toBe( true );
		} );

		it( 'is a no-op when already in the requested state', () => {
			const state = baseState();
			const after = reducer( state, {
				type: ACTION_TYPES.SET_ALL_MEDIA,
				active: false,
			} );
			expect( after ).toBe( state );
		} );
	} );

	describe( 'SET_SELECTED_FOLDER / SET_SEARCH_QUERY', () => {
		it( 'updates selectedFolderId', () => {
			const state = reducer( baseState(), {
				type: ACTION_TYPES.SET_SELECTED_FOLDER,
				folderId: 7,
			} );
			expect( state.selectedFolderId ).toBe( 7 );
		} );

		it( 'updates searchQuery', () => {
			const state = reducer( baseState(), {
				type: ACTION_TYPES.SET_SEARCH_QUERY,
				query: 'vac',
			} );
			expect( state.searchQuery ).toBe( 'vac' );
		} );
	} );

	describe( 'FETCH_SEARCH_*', () => {
		it( 'SUCCESS replaces results and pagination', () => {
			const state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_SEARCH_SUCCESS,
				results: [ { folder: { id: 1 }, breadcrumb: [] } ],
				page: 1,
				totalPages: 3,
				total: 50,
			} );
			expect( state.searchResults ).toHaveLength( 1 );
			expect( state.searchPage ).toBe( 1 );
			expect( state.searchTotalPages ).toBe( 3 );
			expect( state.searchTotal ).toBe( 50 );
		} );

		it( 'APPEND concatenates results and updates page', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_SEARCH_SUCCESS,
				results: [ { folder: { id: 1 }, breadcrumb: [] } ],
				page: 1,
				totalPages: 2,
				total: 2,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.FETCH_SEARCH_APPEND,
				results: [ { folder: { id: 2 }, breadcrumb: [] } ],
				page: 2,
				totalPages: 2,
				total: 2,
			} );
			expect( state.searchResults ).toHaveLength( 2 );
			expect( state.searchPage ).toBe( 2 );
		} );

		it( 'CLEAR_SEARCH wipes results', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_SEARCH_SUCCESS,
				results: [ { folder: { id: 1 }, breadcrumb: [] } ],
				page: 1,
				totalPages: 1,
				total: 1,
			} );
			state = reducer( state, { type: ACTION_TYPES.CLEAR_SEARCH } );
			expect( state.searchResults ).toEqual( [] );
			expect( state.searchPage ).toBe( 0 );
		} );
	} );

	describe( 'APPLY_AFFECTED_PARENTS', () => {
		it( 'updates has_children on existing folders only', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_CHILDREN_SUCCESS,
				parentId: ROOT_PARENT_ID,
				folders: [
					{ id: 1, name: 'A', parent_id: 0, has_children: false },
				],
				page: 1,
				totalPages: 1,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.APPLY_AFFECTED_PARENTS,
				affectedParents: [
					{ id: 1, has_children: true },
					{ id: 99, has_children: true },
				],
			} );
			expect( state.foldersById[ 1 ].has_children ).toBe( true );
			expect( state.foldersById[ 99 ] ).toBeUndefined();
			expect(
				state.foldersByParent[ 0 ].find( ( f ) => f.id === 1 )
					.has_children
			).toBe( true );
		} );
	} );

	describe( 'APPLY_PATH_TOTALS', () => {
		it( 'merges path folders into foldersById and updates them in slots', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_CHILDREN_SUCCESS,
				parentId: 0,
				folders: [
					{
						id: 1,
						name: 'A',
						parent_id: 0,
						total_media_count: 1,
					},
				],
				page: 1,
				totalPages: 1,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.APPLY_PATH_TOTALS,
				path: [
					{
						id: 1,
						name: 'A',
						parent_id: 0,
						total_media_count: 5,
					},
				],
			} );
			expect( state.foldersById[ 1 ].total_media_count ).toBe( 5 );
			expect( state.foldersByParent[ 0 ][ 0 ].total_media_count ).toBe(
				5
			);
		} );
	} );

	describe( 'UPSERT_FOLDER / REMOVE_FOLDER', () => {
		it( 'UPSERT inserts into existing parent slot', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_CHILDREN_SUCCESS,
				parentId: 0,
				folders: [],
				page: 1,
				totalPages: 1,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.UPSERT_FOLDER,
				folder: { id: 7, name: 'New', parent_id: 0 },
			} );
			expect( state.foldersById[ 7 ].name ).toBe( 'New' );
			expect( state.foldersByParent[ 0 ] ).toHaveLength( 1 );
		} );

		it( 'UPSERT skips foldersByParent when slot not loaded', () => {
			const state = reducer( baseState(), {
				type: ACTION_TYPES.UPSERT_FOLDER,
				folder: { id: 7, name: 'New', parent_id: 99 },
			} );
			expect( state.foldersById[ 7 ] ).toBeDefined();
			expect( state.foldersByParent[ 99 ] ).toBeUndefined();
		} );

		it( 'REMOVE drops folder, expands, loadedParents, and resets selection', () => {
			let state = reducer( baseState(), {
				type: ACTION_TYPES.FETCH_CHILDREN_SUCCESS,
				parentId: 0,
				folders: [ { id: 1, name: 'A', parent_id: 0 } ],
				page: 1,
				totalPages: 1,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.EXPAND_FOLDER,
				folderId: 1,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.SET_SELECTED_FOLDER,
				folderId: 1,
			} );
			state = reducer( state, {
				type: ACTION_TYPES.REMOVE_FOLDER,
				folderId: 1,
				parentId: 0,
			} );
			expect( state.foldersById[ 1 ] ).toBeUndefined();
			expect( state.foldersByParent[ 0 ] ).toEqual( [] );
			expect( state.expandedIds ).toEqual( [] );
			expect( state.selectedFolderId ).toBeNull();
		} );
	} );
} );
