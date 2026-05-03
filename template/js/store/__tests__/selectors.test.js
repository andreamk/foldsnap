import {
	getRootFolders,
	getChildrenOf,
	getFolderById,
	isFolderExpanded,
	isFolderLoaded,
	isFolderFetching,
	getParentPagination,
	getSelectedFolderId,
	getRootMediaCount,
	getRootTotalSize,
	getSearchQuery,
	getSearchResults,
	isSearchLoading,
	getSearchPagination,
	getError,
	getMedia,
	isMediaLoading,
	getMediaTotal,
	getMediaTotalPages,
} from '../selectors';

const makeState = ( overrides = {} ) => ( {
	foldersByParent: {},
	foldersById: {},
	loadedParents: [],
	fetchingParents: [],
	parentsPagination: {},
	expandedIds: [],
	selectedFolderId: null,
	rootMediaCount: 0,
	rootTotalSize: 0,
	searchQuery: '',
	searchResults: [],
	searchPage: 0,
	searchTotalPages: 0,
	searchTotal: 0,
	searchIsLoading: false,
	error: null,
	media: [],
	mediaTotal: 0,
	mediaTotalPages: 0,
	mediaIsLoading: false,
	...overrides,
} );

describe( 'selectors', () => {
	describe( 'getChildrenOf / getRootFolders', () => {
		it( 'returns the children list when present', () => {
			const folders = [ { id: 1 }, { id: 2 } ];
			const state = makeState( { foldersByParent: { 0: folders } } );
			expect( getRootFolders( state ) ).toBe( folders );
			expect( getChildrenOf( state, 0 ) ).toBe( folders );
		} );

		it( 'returns a frozen empty array for missing parents', () => {
			const a = getChildrenOf( makeState(), 99 );
			const b = getChildrenOf( makeState(), 99 );
			expect( a ).toEqual( [] );
			expect( a ).toBe( b );
		} );
	} );

	describe( 'getFolderById', () => {
		it( 'returns the folder by id (O(1) lookup)', () => {
			const state = makeState( {
				foldersById: { 5: { id: 5, name: 'Photos' } },
			} );
			expect( getFolderById( state, 5 ).name ).toBe( 'Photos' );
		} );

		it( 'returns undefined when not found', () => {
			expect( getFolderById( makeState(), 99 ) ).toBeUndefined();
		} );
	} );

	describe( 'isFolderExpanded / isFolderLoaded / isFolderFetching', () => {
		it( 'reads boolean flags from membership lists', () => {
			const state = makeState( {
				expandedIds: [ 1, 2 ],
				loadedParents: [ 0, 1 ],
				fetchingParents: [ 5 ],
			} );
			expect( isFolderExpanded( state, 1 ) ).toBe( true );
			expect( isFolderExpanded( state, 99 ) ).toBe( false );
			expect( isFolderLoaded( state, 0 ) ).toBe( true );
			expect( isFolderLoaded( state, 99 ) ).toBe( false );
			expect( isFolderFetching( state, 5 ) ).toBe( true );
			expect( isFolderFetching( state, 1 ) ).toBe( false );
		} );
	} );

	describe( 'getParentPagination', () => {
		it( 'returns pagination for a parent or undefined', () => {
			const state = makeState( {
				parentsPagination: { 0: { page: 2, totalPages: 5 } },
			} );
			expect( getParentPagination( state, 0 ) ).toEqual( {
				page: 2,
				totalPages: 5,
			} );
			expect( getParentPagination( state, 99 ) ).toBeUndefined();
		} );
	} );

	describe( 'simple field selectors', () => {
		it( 'returns the corresponding state slice', () => {
			const state = makeState( {
				selectedFolderId: 7,
				rootMediaCount: 10,
				rootTotalSize: 2048,
				searchQuery: 'vac',
				searchResults: [ { folder: { id: 1 }, breadcrumb: [] } ],
				searchIsLoading: true,
				searchPage: 1,
				searchTotalPages: 3,
				searchTotal: 50,
				error: 'boom',
				media: [ { id: 99 } ],
				mediaIsLoading: true,
				mediaTotal: 1,
				mediaTotalPages: 1,
			} );
			expect( getSelectedFolderId( state ) ).toBe( 7 );
			expect( getRootMediaCount( state ) ).toBe( 10 );
			expect( getRootTotalSize( state ) ).toBe( 2048 );
			expect( getSearchQuery( state ) ).toBe( 'vac' );
			expect( getSearchResults( state ) ).toHaveLength( 1 );
			expect( isSearchLoading( state ) ).toBe( true );
			expect( getSearchPagination( state ) ).toEqual( {
				page: 1,
				totalPages: 3,
				total: 50,
			} );
			expect( getError( state ) ).toBe( 'boom' );
			expect( getMedia( state ) ).toHaveLength( 1 );
			expect( isMediaLoading( state ) ).toBe( true );
			expect( getMediaTotal( state ) ).toBe( 1 );
			expect( getMediaTotalPages( state ) ).toBe( 1 );
		} );
	} );
} );
