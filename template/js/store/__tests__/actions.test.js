import { ACTION_TYPES } from '../constants';
import {
	fetchChildren,
	fetchChildrenBatch,
	loadMoreChildren,
	expandFolder,
	collapseFolder,
	expandPathTo,
	setSearchQuery,
	clearSearch,
	searchFolders,
	loadMoreSearchResults,
	createFolder,
	updateFolder,
	deleteFolder,
	assignMedia,
	setSelectedFolder,
} from '../actions';

/**
 * Drive a generator manually, providing scripted responses for each yield.
 *
 * `responses` is an array; for each yield in order it provides the value
 * passed back into `next(value)`. SELECT yields consume an entry from the
 * same list. The collected yields (action objects) are returned for assertions.
 *
 * @param {Iterable} gen       Generator instance.
 * @param {Array}    responses Values passed back into the generator (in order).
 * @return {Object[]} Yielded values.
 */
const drive = ( gen, responses = [] ) => {
	const yields = [];
	let i = 0;
	let result = gen.next();
	while ( ! result.done ) {
		yields.push( result.value );
		const isSideEffect =
			result.value?.type === 'API_FETCH' ||
			result.value?.type === 'SELECT';
		const next = isSideEffect ? responses[ i++ ] : undefined;
		try {
			result = gen.next( next );
		} catch ( e ) {
			break;
		}
	}
	return yields;
};

describe( 'fetchChildren', () => {
	it( 'GETs paginated children and dispatches SUCCESS + root totals', () => {
		const yields = drive( fetchChildren( 0, { perPage: 50 } ), [
			{
				folders: [ { id: 1, parent_id: 0 } ],
				page: 1,
				total_pages: 2,
				root_media_count: 10,
				root_total_size: 1024,
			},
		] );
		expect( yields[ 0 ] ).toEqual( {
			type: ACTION_TYPES.FETCH_CHILDREN_START,
			parentId: 0,
		} );
		expect( yields[ 1 ].type ).toBe( 'API_FETCH' );
		expect( yields[ 1 ].request.path ).toBe(
			'/foldsnap/v1/folders?parent_ids%5B%5D=0&page=1&per_page=50'
		);
		expect( yields[ 2 ] ).toEqual( {
			type: ACTION_TYPES.FETCH_CHILDREN_SUCCESS,
			parentId: 0,
			folders: [ { id: 1, parent_id: 0 } ],
			page: 1,
			totalPages: 2,
		} );
	} );

	it( 'yields ERROR when API_FETCH throws', () => {
		const gen = fetchChildren( 5 );
		gen.next(); // START
		gen.next(); // API_FETCH
		const r = gen.throw( new Error( 'boom' ) );
		expect( r.value ).toEqual( {
			type: ACTION_TYPES.FETCH_CHILDREN_ERROR,
			parentId: 5,
			error: 'boom',
		} );
	} );
} );

describe( 'loadMoreChildren', () => {
	it( 'no-ops when already on last page', () => {
		const yields = drive( loadMoreChildren( 0 ), [
			{ page: 2, totalPages: 2 },
		] );
		// SELECT only — no FETCH_CHILDREN_START.
		expect( yields ).toHaveLength( 1 );
		expect( yields[ 0 ].type ).toBe( 'SELECT' );
	} );

	it( 'dispatches APPEND with the next page', () => {
		const yields = drive( loadMoreChildren( 0 ), [
			{ page: 1, totalPages: 3 },
			{
				folders: [ { id: 5, parent_id: 0 } ],
				page: 2,
				total_pages: 3,
			},
		] );
		const append = yields.find(
			( y ) => y.type === ACTION_TYPES.FETCH_CHILDREN_APPEND
		);
		expect( append ).toEqual( {
			type: ACTION_TYPES.FETCH_CHILDREN_APPEND,
			parentId: 0,
			folders: [ { id: 5, parent_id: 0 } ],
			page: 2,
			totalPages: 3,
		} );
	} );
} );

describe( 'fetchChildrenBatch', () => {
	it( 'is a no-op for an empty list', () => {
		const yields = drive( fetchChildrenBatch( [] ) );
		expect( yields ).toEqual( [] );
	} );

	it( 'fires one START per parent then groups response children by parent_id', () => {
		const yields = drive( fetchChildrenBatch( [ 0, 5 ] ), [
			{
				folders: [
					{ id: 1, parent_id: 0 },
					{ id: 6, parent_id: 5 },
					{ id: 7, parent_id: 5 },
				],
				root_media_count: 0,
				root_total_size: 0,
			},
		] );
		const successYields = yields.filter(
			( y ) => y.type === ACTION_TYPES.FETCH_CHILDREN_SUCCESS
		);
		expect(
			successYields.find( ( y ) => y.parentId === 0 ).folders
		).toHaveLength( 1 );
		expect(
			successYields.find( ( y ) => y.parentId === 5 ).folders
		).toHaveLength( 2 );
	} );
} );

describe( 'expandFolder / collapseFolder', () => {
	it( 'expandFolder marks expanded and triggers fetch when not loaded', () => {
		const yields = drive( expandFolder( 7 ), [
			false, // isFolderLoaded
			false, // isFolderFetching
			{
				folders: [],
				page: 1,
				total_pages: 1,
				root_media_count: 0,
				root_total_size: 0,
			},
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.EXPAND_FOLDER );
		expect( types ).toContain( ACTION_TYPES.FETCH_CHILDREN_START );
	} );

	it( 'expandFolder skips fetch when already loaded', () => {
		const yields = drive( expandFolder( 7 ), [
			true, // isFolderLoaded
			false, // isFolderFetching
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.EXPAND_FOLDER );
		expect( types ).not.toContain( ACTION_TYPES.FETCH_CHILDREN_START );
	} );

	it( 'collapseFolder is a plain action', () => {
		expect( collapseFolder( 7 ) ).toEqual( {
			type: ACTION_TYPES.COLLAPSE_FOLDER,
			folderId: 7,
		} );
	} );
} );

describe( 'expandPathTo', () => {
	it( 'no-ops on falsy folderId', () => {
		const yields = drive( expandPathTo( 0 ) );
		expect( yields ).toEqual( [] );
	} );

	it( 'fetches path, merges ancestors into expanded ids, and batches children', () => {
		const yields = drive( expandPathTo( 42 ), [
			// API_FETCH /path response.
			{
				path: [
					{ id: 0, parent_id: 0, is_root: true },
					{ id: 5, parent_id: 0 },
					{ id: 42, parent_id: 5 },
				],
			},
			// SELECT getExpandedIds — caller already had branch [9] open.
			[ 9 ],
			// API_FETCH children batch response.
			{
				folders: [],
				root_media_count: 0,
				root_total_size: 0,
			},
		] );
		const setExpanded = yields.find(
			( y ) => y.type === ACTION_TYPES.SET_EXPANDED_IDS
		);
		expect( setExpanded.ids.sort() ).toEqual( [ 0, 5, 9 ] );
		const applyPath = yields.find(
			( y ) => y.type === ACTION_TYPES.APPLY_PATH_TOTALS
		);
		expect( applyPath.path ).toHaveLength( 3 );
	} );
} );

describe( 'search actions', () => {
	it( 'setSearchQuery and clearSearch are plain actions', () => {
		expect( setSearchQuery( 'vac' ) ).toEqual( {
			type: ACTION_TYPES.SET_SEARCH_QUERY,
			query: 'vac',
		} );
		expect( clearSearch() ).toEqual( { type: ACTION_TYPES.CLEAR_SEARCH } );
	} );

	it( 'searchFolders clears when query is empty', () => {
		const yields = drive( searchFolders( '   ' ) );
		expect( yields ).toEqual( [ { type: ACTION_TYPES.CLEAR_SEARCH } ] );
	} );

	it( 'searchFolders dispatches SUCCESS with results', () => {
		const yields = drive( searchFolders( 'vac' ), [
			{
				results: [ { folder: { id: 1 }, breadcrumb: [] } ],
				page: 1,
				total_pages: 2,
				total: 100,
			},
		] );
		const success = yields.find(
			( y ) => y.type === ACTION_TYPES.FETCH_SEARCH_SUCCESS
		);
		expect( success.results ).toHaveLength( 1 );
		expect( success.totalPages ).toBe( 2 );
	} );

	it( 'loadMoreSearchResults stops when out of pages', () => {
		const yields = drive( loadMoreSearchResults(), [
			'vac', // getSearchQuery
			2, // getSearchPage
			2, // getSearchTotalPages
		] );
		const fetchStart = yields.find(
			( y ) => y.type === ACTION_TYPES.FETCH_SEARCH_START
		);
		expect( fetchStart ).toBeUndefined();
	} );
} );

describe( 'createFolder', () => {
	it( 'POSTs and applies envelope, then refreshes the parent slot', () => {
		const yields = drive( createFolder( { name: 'Photos', parentId: 0 } ), [
			{
				folder: { id: 7, parent_id: 0 },
				paths: [ [ { id: 7, parent_id: 0 } ] ],
				affected_parents: [ { id: 0, has_children: true } ],
				root_media_count: 5,
				root_total_size: 100,
			},
			// fetchChildren response
			{
				folders: [ { id: 7, parent_id: 0 } ],
				page: 1,
				total_pages: 1,
				root_media_count: 5,
				root_total_size: 100,
			},
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.UPSERT_FOLDER );
		expect( types ).toContain( ACTION_TYPES.APPLY_PATH_TOTALS );
		expect( types ).toContain( ACTION_TYPES.APPLY_AFFECTED_PARENTS );
		expect( types ).toContain( ACTION_TYPES.FETCH_CHILDREN_START );
	} );
} );

describe( 'updateFolder', () => {
	it( 'detects reparent and refreshes both old and new slots', () => {
		const yields = drive( updateFolder( 7, { name: 'X', parentId: 5 } ), [
			{ id: 7, parent_id: 0 }, // getFolderById (previous)
			{ folder: { id: 7, parent_id: 5 } }, // PUT response
			{
				folders: [],
				page: 1,
				total_pages: 1,
				root_media_count: 0,
				root_total_size: 0,
			},
			{
				folders: [],
				page: 1,
				total_pages: 1,
				root_media_count: 0,
				root_total_size: 0,
			},
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.REMOVE_FOLDER );
		expect(
			types.filter( ( t ) => t === ACTION_TYPES.FETCH_CHILDREN_START )
		).toHaveLength( 2 );
	} );

	it( 'no reparent: applies envelope and skips refetch', () => {
		const yields = drive( updateFolder( 7, { name: 'X' } ), [
			{ id: 7, parent_id: 0 },
			{ folder: { id: 7, parent_id: 0 } },
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).not.toContain( ACTION_TYPES.REMOVE_FOLDER );
		expect( types ).not.toContain( ACTION_TYPES.FETCH_CHILDREN_START );
		expect( types ).toContain( ACTION_TYPES.UPSERT_FOLDER );
	} );
} );

describe( 'deleteFolder', () => {
	it( 'deletes, removes from store, applies affected_parents and root totals', () => {
		const yields = drive( deleteFolder( 7 ), [
			{ id: 7, parent_id: 0 },
			{
				deleted: true,
				id: 7,
				affected_parents: [ { id: 0, has_children: false } ],
				root_media_count: 1,
				root_total_size: 1,
			},
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.REMOVE_FOLDER );
		expect( types ).toContain( ACTION_TYPES.APPLY_AFFECTED_PARENTS );
	} );
} );

describe( 'assignMedia', () => {
	it( 'POSTs media_ids and applies envelope', () => {
		const yields = drive( assignMedia( 5, [ 10, 11 ] ), [
			{
				assigned: true,
				folder: { id: 5, parent_id: 0 },
				paths: [ [ { id: 5, parent_id: 0 } ] ],
				affected_parents: [ { id: 0, has_children: true } ],
				root_media_count: 0,
				root_total_size: 0,
			},
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.UPSERT_FOLDER );
		expect( types ).toContain( ACTION_TYPES.APPLY_AFFECTED_PARENTS );
	} );

	it( 'applies one APPLY_PATH_TOTALS per chain in paths', () => {
		const yields = drive( assignMedia( 5, [ 10 ] ), [
			{
				assigned: true,
				folder: { id: 5, parent_id: 0 },
				paths: [
					[ { id: 5, parent_id: 0, total_media_count: 1 } ],
					[ { id: 9, parent_id: 0, total_media_count: 0 } ],
				],
				affected_parents: [],
				root_media_count: 0,
				root_total_size: 0,
			},
		] );
		const pathTotals = yields.filter(
			( y ) => y.type === ACTION_TYPES.APPLY_PATH_TOTALS
		);
		expect( pathTotals ).toHaveLength( 2 );
		expect( pathTotals[ 0 ].path[ 0 ].id ).toBe( 5 );
		expect( pathTotals[ 1 ].path[ 0 ].id ).toBe( 9 );
	} );
} );

describe( 'setSelectedFolder', () => {
	it( 'is a plain action', () => {
		expect( setSelectedFolder( 5 ) ).toEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 5,
		} );
		expect( setSelectedFolder( null ).folderId ).toBeNull();
	} );
} );
