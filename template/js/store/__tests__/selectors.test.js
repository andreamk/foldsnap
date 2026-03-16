import {
	getFolders,
	getSelectedFolderId,
	isLoading,
	getError,
	getRootMediaCount,
	getRootTotalSize,
	getFolderById,
	getMedia,
	isMediaLoading,
	getMediaTotal,
	getMediaTotalPages,
	getSearchQuery,
	getFilteredFolders,
} from '../selectors';

const makeState = ( overrides = {} ) => ( {
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
	...overrides,
} );

const FOLDERS_TREE = [
	{
		id: 1,
		name: 'Photos',
		children: [
			{ id: 3, name: 'Vacation', children: [] },
			{ id: 4, name: 'Family', children: [] },
		],
	},
	{
		id: 2,
		name: 'Documents',
		children: [],
	},
];

describe( 'selectors', () => {
	describe( 'getFolders', () => {
		it( 'returns the folders array from state', () => {
			const state = makeState( { folders: FOLDERS_TREE } );
			expect( getFolders( state ) ).toBe( FOLDERS_TREE );
		} );

		it( 'returns empty array when no folders loaded', () => {
			expect( getFolders( makeState() ) ).toEqual( [] );
		} );
	} );

	describe( 'getSelectedFolderId', () => {
		it( 'returns null by default', () => {
			expect( getSelectedFolderId( makeState() ) ).toBeNull();
		} );

		it( 'returns the selected folder ID when set', () => {
			const state = makeState( { selectedFolderId: 5 } );
			expect( getSelectedFolderId( state ) ).toBe( 5 );
		} );
	} );

	describe( 'isLoading', () => {
		it( 'returns false by default', () => {
			expect( isLoading( makeState() ) ).toBe( false );
		} );

		it( 'returns true when loading', () => {
			expect( isLoading( makeState( { isLoading: true } ) ) ).toBe(
				true
			);
		} );
	} );

	describe( 'getError', () => {
		it( 'returns null by default', () => {
			expect( getError( makeState() ) ).toBeNull();
		} );

		it( 'returns the error string when set', () => {
			const state = makeState( { error: 'Network failure' } );
			expect( getError( state ) ).toBe( 'Network failure' );
		} );
	} );

	describe( 'getRootMediaCount', () => {
		it( 'returns 0 by default', () => {
			expect( getRootMediaCount( makeState() ) ).toBe( 0 );
		} );

		it( 'returns the root media count', () => {
			const state = makeState( { rootMediaCount: 42 } );
			expect( getRootMediaCount( state ) ).toBe( 42 );
		} );
	} );

	describe( 'getRootTotalSize', () => {
		it( 'returns 0 by default', () => {
			expect( getRootTotalSize( makeState() ) ).toBe( 0 );
		} );

		it( 'returns the root total size in bytes', () => {
			const state = makeState( { rootTotalSize: 1024000 } );
			expect( getRootTotalSize( state ) ).toBe( 1024000 );
		} );
	} );

	describe( 'getFolderById', () => {
		it( 'finds a root-level folder by ID', () => {
			const state = makeState( { folders: FOLDERS_TREE } );
			const folder = getFolderById( state, 2 );
			expect( folder ).toBeDefined();
			expect( folder.name ).toBe( 'Documents' );
		} );

		it( 'finds a nested folder by ID (recursive search)', () => {
			const state = makeState( { folders: FOLDERS_TREE } );
			const folder = getFolderById( state, 3 );
			expect( folder ).toBeDefined();
			expect( folder.name ).toBe( 'Vacation' );
		} );

		it( 'returns undefined for a non-existent ID', () => {
			const state = makeState( { folders: FOLDERS_TREE } );
			expect( getFolderById( state, 999 ) ).toBeUndefined();
		} );
	} );

	describe( 'getMedia', () => {
		it( 'returns empty array by default', () => {
			expect( getMedia( makeState() ) ).toEqual( [] );
		} );

		it( 'returns the media array from state', () => {
			const media = [ { id: 10 }, { id: 11 } ];
			const state = makeState( { media } );
			expect( getMedia( state ) ).toBe( media );
		} );
	} );

	describe( 'isMediaLoading', () => {
		it( 'returns false by default', () => {
			expect( isMediaLoading( makeState() ) ).toBe( false );
		} );

		it( 'returns true when media is loading', () => {
			expect(
				isMediaLoading( makeState( { mediaIsLoading: true } ) )
			).toBe( true );
		} );
	} );

	describe( 'getMediaTotal', () => {
		it( 'returns 0 by default', () => {
			expect( getMediaTotal( makeState() ) ).toBe( 0 );
		} );

		it( 'returns the total media count', () => {
			expect( getMediaTotal( makeState( { mediaTotal: 100 } ) ) ).toBe(
				100
			);
		} );
	} );

	describe( 'getMediaTotalPages', () => {
		it( 'returns 0 by default', () => {
			expect( getMediaTotalPages( makeState() ) ).toBe( 0 );
		} );

		it( 'returns the total pages', () => {
			expect(
				getMediaTotalPages( makeState( { mediaTotalPages: 3 } ) )
			).toBe( 3 );
		} );
	} );

	describe( 'getSearchQuery', () => {
		it( 'returns empty string by default', () => {
			expect( getSearchQuery( makeState() ) ).toBe( '' );
		} );

		it( 'returns the current search query', () => {
			expect(
				getSearchQuery( makeState( { searchQuery: 'vacation' } ) )
			).toBe( 'vacation' );
		} );
	} );

	describe( 'getFilteredFolders', () => {
		it( 'returns all folders when search query is empty', () => {
			const state = makeState( {
				folders: FOLDERS_TREE,
				searchQuery: '',
			} );
			expect( getFilteredFolders( state ) ).toBe( FOLDERS_TREE );
		} );

		it( 'returns all folders when search query is only whitespace', () => {
			const state = makeState( {
				folders: FOLDERS_TREE,
				searchQuery: '   ',
			} );
			expect( getFilteredFolders( state ) ).toBe( FOLDERS_TREE );
		} );

		it( 'filters folders by name (case-insensitive match)', () => {
			const state = makeState( {
				folders: FOLDERS_TREE,
				searchQuery: 'DOC',
			} );
			const result = getFilteredFolders( state );
			expect( result ).toHaveLength( 1 );
			expect( result[ 0 ].name ).toBe( 'Documents' );
		} );

		it( 'includes ancestor folders when only a child matches', () => {
			const state = makeState( {
				folders: FOLDERS_TREE,
				searchQuery: 'vacation',
			} );
			const result = getFilteredFolders( state );
			// Only the Photos parent (id=1) should be included, containing Vacation child
			expect( result ).toHaveLength( 1 );
			expect( result[ 0 ].id ).toBe( 1 );
			expect( result[ 0 ].children ).toHaveLength( 1 );
			expect( result[ 0 ].children[ 0 ].name ).toBe( 'Vacation' );
		} );

		it( 'returns empty array when nothing matches', () => {
			const state = makeState( {
				folders: FOLDERS_TREE,
				searchQuery: 'zzznomatch',
			} );
			expect( getFilteredFolders( state ) ).toHaveLength( 0 );
		} );
	} );
} );
