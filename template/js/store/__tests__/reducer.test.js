import { ACTION_TYPES } from '../constants';
import reducer from '../reducer';

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

describe( 'reducer', () => {
	it( 'returns the default state when called with undefined state', () => {
		const state = reducer( undefined, { type: '@@INIT' } );
		expect( state ).toEqual( DEFAULT_STATE );
	} );

	it( 'returns state unchanged for unknown action types', () => {
		const state = reducer( DEFAULT_STATE, { type: 'UNKNOWN_ACTION' } );
		expect( state ).toBe( DEFAULT_STATE );
	} );

	describe( 'FETCH_FOLDERS_START', () => {
		it( 'sets isLoading to true and clears error', () => {
			const initial = { ...DEFAULT_STATE, error: 'previous error' };
			const state = reducer( initial, {
				type: ACTION_TYPES.FETCH_FOLDERS_START,
			} );
			expect( state.isLoading ).toBe( true );
			expect( state.error ).toBeNull();
		} );

		it( 'preserves other state fields', () => {
			const initial = {
				...DEFAULT_STATE,
				searchQuery: 'docs',
				selectedFolderId: 5,
			};
			const state = reducer( initial, {
				type: ACTION_TYPES.FETCH_FOLDERS_START,
			} );
			expect( state.searchQuery ).toBe( 'docs' );
			expect( state.selectedFolderId ).toBe( 5 );
		} );
	} );

	describe( 'FETCH_FOLDERS_SUCCESS', () => {
		it( 'sets folders, rootMediaCount, and rootTotalSize; clears isLoading', () => {
			const folders = [ { id: 1, name: 'Photos', children: [] } ];
			const state = reducer(
				{ ...DEFAULT_STATE, isLoading: true },
				{
					type: ACTION_TYPES.FETCH_FOLDERS_SUCCESS,
					folders,
					rootMediaCount: 12,
					rootTotalSize: 4096,
				}
			);
			expect( state.isLoading ).toBe( false );
			expect( state.folders ).toBe( folders );
			expect( state.rootMediaCount ).toBe( 12 );
			expect( state.rootTotalSize ).toBe( 4096 );
		} );
	} );

	describe( 'FETCH_FOLDERS_ERROR', () => {
		it( 'sets error message and clears isLoading', () => {
			const state = reducer(
				{ ...DEFAULT_STATE, isLoading: true },
				{
					type: ACTION_TYPES.FETCH_FOLDERS_ERROR,
					error: 'Network error',
				}
			);
			expect( state.isLoading ).toBe( false );
			expect( state.error ).toBe( 'Network error' );
		} );
	} );

	describe( 'SET_SELECTED_FOLDER', () => {
		it( 'updates selectedFolderId', () => {
			const state = reducer( DEFAULT_STATE, {
				type: ACTION_TYPES.SET_SELECTED_FOLDER,
				folderId: 7,
			} );
			expect( state.selectedFolderId ).toBe( 7 );
		} );

		it( 'allows setting selectedFolderId to null', () => {
			const initial = { ...DEFAULT_STATE, selectedFolderId: 7 };
			const state = reducer( initial, {
				type: ACTION_TYPES.SET_SELECTED_FOLDER,
				folderId: null,
			} );
			expect( state.selectedFolderId ).toBeNull();
		} );
	} );

	describe( 'SET_SEARCH_QUERY', () => {
		it( 'updates searchQuery', () => {
			const state = reducer( DEFAULT_STATE, {
				type: ACTION_TYPES.SET_SEARCH_QUERY,
				query: 'vacation',
			} );
			expect( state.searchQuery ).toBe( 'vacation' );
		} );
	} );

	describe( 'FETCH_MEDIA_START', () => {
		it( 'sets mediaIsLoading to true', () => {
			const state = reducer( DEFAULT_STATE, {
				type: ACTION_TYPES.FETCH_MEDIA_START,
			} );
			expect( state.mediaIsLoading ).toBe( true );
		} );

		it( 'preserves other state fields', () => {
			const initial = { ...DEFAULT_STATE, selectedFolderId: 3 };
			const state = reducer( initial, {
				type: ACTION_TYPES.FETCH_MEDIA_START,
			} );
			expect( state.selectedFolderId ).toBe( 3 );
		} );
	} );

	describe( 'FETCH_MEDIA_SUCCESS', () => {
		it( 'sets media, mediaTotal, mediaTotalPages and clears mediaIsLoading', () => {
			const media = [ { id: 10 }, { id: 11 } ];
			const state = reducer(
				{ ...DEFAULT_STATE, mediaIsLoading: true },
				{
					type: ACTION_TYPES.FETCH_MEDIA_SUCCESS,
					media,
					total: 50,
					totalPages: 2,
				}
			);
			expect( state.mediaIsLoading ).toBe( false );
			expect( state.media ).toBe( media );
			expect( state.mediaTotal ).toBe( 50 );
			expect( state.mediaTotalPages ).toBe( 2 );
		} );
	} );

	describe( 'FETCH_MEDIA_ERROR', () => {
		it( 'sets error message and clears mediaIsLoading', () => {
			const state = reducer(
				{ ...DEFAULT_STATE, mediaIsLoading: true },
				{ type: ACTION_TYPES.FETCH_MEDIA_ERROR, error: 'Timeout' }
			);
			expect( state.mediaIsLoading ).toBe( false );
			expect( state.error ).toBe( 'Timeout' );
		} );
	} );
} );
