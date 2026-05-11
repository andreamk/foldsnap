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
	bootFromUrl,
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

	it( 'splits >10 parents into multiple chunked requests', () => {
		const parentIds = Array.from( { length: 23 }, ( _, i ) => i + 1 );
		const chunkResponse = ( ids ) => ( {
			folders: ids.map( ( id ) => ( { id: 1000 + id, parent_id: id } ) ),
			page: 1,
			total_pages: 1,
		} );
		const yields = drive( fetchChildrenBatch( parentIds ), [
			chunkResponse( parentIds.slice( 0, 10 ) ),
			chunkResponse( parentIds.slice( 10, 20 ) ),
			chunkResponse( parentIds.slice( 20, 23 ) ),
		] );

		const apiCalls = yields.filter( ( y ) => y.type === 'API_FETCH' );
		expect( apiCalls ).toHaveLength( 3 );
		expect( apiCalls[ 0 ].request.path ).toContain( 'parent_ids%5B%5D=1&' );
		expect( apiCalls[ 0 ].request.path ).toContain(
			'parent_ids%5B%5D=10&'
		);
		expect( apiCalls[ 0 ].request.path ).not.toContain(
			'parent_ids%5B%5D=11&'
		);
		expect( apiCalls[ 1 ].request.path ).toContain(
			'parent_ids%5B%5D=11&'
		);
		expect( apiCalls[ 1 ].request.path ).toContain(
			'parent_ids%5B%5D=20&'
		);
		expect( apiCalls[ 2 ].request.path ).toContain(
			'parent_ids%5B%5D=21&'
		);
		expect( apiCalls[ 2 ].request.path ).toContain(
			'parent_ids%5B%5D=23&'
		);

		const successYields = yields.filter(
			( y ) => y.type === ACTION_TYPES.FETCH_CHILDREN_SUCCESS
		);
		expect( successYields ).toHaveLength( 23 );
		for ( const id of parentIds ) {
			const success = successYields.find( ( y ) => y.parentId === id );
			expect( success.folders ).toEqual( [
				{ id: 1000 + id, parent_id: id },
			] );
		}
	} );

	it( 'requests the maximum per_page (200) by default', () => {
		const yields = drive( fetchChildrenBatch( [ 1 ] ), [
			{ folders: [], page: 1, total_pages: 1 },
		] );
		const apiCall = yields.find( ( y ) => y.type === 'API_FETCH' );
		expect( apiCall.request.path ).toContain( 'per_page=200' );
	} );

	it( 'follows total_pages within a chunk until exhausted', () => {
		const yields = drive( fetchChildrenBatch( [ 4 ] ), [
			{
				folders: [
					{ id: 100, parent_id: 4 },
					{ id: 101, parent_id: 4 },
				],
				page: 1,
				total_pages: 3,
			},
			{
				folders: [ { id: 102, parent_id: 4 } ],
				page: 2,
				total_pages: 3,
			},
			{
				folders: [ { id: 103, parent_id: 4 } ],
				page: 3,
				total_pages: 3,
			},
		] );
		const apiCalls = yields.filter( ( y ) => y.type === 'API_FETCH' );
		expect( apiCalls ).toHaveLength( 3 );
		expect( apiCalls[ 0 ].request.path ).toContain( 'page=1&' );
		expect( apiCalls[ 1 ].request.path ).toContain( 'page=2&' );
		expect( apiCalls[ 2 ].request.path ).toContain( 'page=3&' );

		const success = yields.find(
			( y ) => y.type === ACTION_TYPES.FETCH_CHILDREN_SUCCESS
		);
		expect( success.folders.map( ( f ) => f.id ) ).toEqual( [
			100, 101, 102, 103,
		] );
	} );

	it( 'combines chunking and pagination for wide deep batches', () => {
		const parentIds = Array.from( { length: 12 }, ( _, i ) => i + 1 );
		const yields = drive( fetchChildrenBatch( parentIds ), [
			// chunk 1 (parents 1..10), page 1 of 2
			{
				folders: [ { id: 50, parent_id: 1 } ],
				page: 1,
				total_pages: 2,
			},
			// chunk 1, page 2 of 2
			{
				folders: [ { id: 51, parent_id: 2 } ],
				page: 2,
				total_pages: 2,
			},
			// chunk 2 (parents 11..12), single page
			{
				folders: [
					{ id: 60, parent_id: 11 },
					{ id: 61, parent_id: 12 },
				],
				page: 1,
				total_pages: 1,
			},
		] );
		const apiCalls = yields.filter( ( y ) => y.type === 'API_FETCH' );
		expect( apiCalls ).toHaveLength( 3 );
		expect( apiCalls[ 0 ].request.path ).toContain( 'parent_ids%5B%5D=1&' );
		expect( apiCalls[ 0 ].request.path ).toContain( 'page=1&' );
		expect( apiCalls[ 1 ].request.path ).toContain( 'parent_ids%5B%5D=1&' );
		expect( apiCalls[ 1 ].request.path ).toContain( 'page=2&' );
		expect( apiCalls[ 2 ].request.path ).toContain(
			'parent_ids%5B%5D=11&'
		);
		expect( apiCalls[ 2 ].request.path ).toContain( 'page=1&' );

		const success = yields.filter(
			( y ) => y.type === ACTION_TYPES.FETCH_CHILDREN_SUCCESS
		);
		expect( success.find( ( y ) => y.parentId === 1 ).folders ).toEqual( [
			{ id: 50, parent_id: 1 },
		] );
		expect( success.find( ( y ) => y.parentId === 2 ).folders ).toEqual( [
			{ id: 51, parent_id: 2 },
		] );
		expect( success.find( ( y ) => y.parentId === 11 ).folders ).toEqual( [
			{ id: 60, parent_id: 11 },
		] );
		expect( success.find( ( y ) => y.parentId === 12 ).folders ).toEqual( [
			{ id: 61, parent_id: 12 },
		] );
	} );

	it( 'still errors all parents when a later chunk throws', () => {
		const parentIds = Array.from( { length: 15 }, ( _, i ) => i + 1 );
		const gen = fetchChildrenBatch( parentIds );
		// Drain the 15 START yields.
		for ( let i = 0; i < parentIds.length; i += 1 ) {
			expect( gen.next().value.type ).toBe(
				ACTION_TYPES.FETCH_CHILDREN_START
			);
		}
		// Chunk 1 succeeds.
		expect( gen.next().value.type ).toBe( 'API_FETCH' );
		expect(
			gen.next( {
				folders: [],
				page: 1,
				total_pages: 1,
			} ).value.type
		).toBe( 'API_FETCH' );
		// Chunk 2 throws.
		const errorYields = [];
		let step = gen.throw( new Error( 'kaboom' ) );
		while ( ! step.done ) {
			errorYields.push( step.value );
			step = gen.next();
		}
		expect( errorYields ).toHaveLength( parentIds.length );
		expect( errorYields.every( ( y ) => y.error === 'kaboom' ) ).toBe(
			true
		);
		expect(
			errorYields.map( ( y ) => y.parentId ).sort( ( a, b ) => a - b )
		).toEqual( parentIds );
	} );

	it( 'yields one ERROR per parent when API_FETCH throws', () => {
		const gen = fetchChildrenBatch( [ 3, 7 ] );
		expect( gen.next().value ).toEqual( {
			type: ACTION_TYPES.FETCH_CHILDREN_START,
			parentId: 3,
		} );
		expect( gen.next().value ).toEqual( {
			type: ACTION_TYPES.FETCH_CHILDREN_START,
			parentId: 7,
		} );
		// Next yield is the API_FETCH; throw into it.
		expect( gen.next().value.type ).toBe( 'API_FETCH' );
		const errorYields = [];
		let step = gen.throw( new Error( 'boom' ) );
		while ( ! step.done ) {
			errorYields.push( step.value );
			step = gen.next();
		}
		expect( errorYields ).toEqual( [
			{
				type: ACTION_TYPES.FETCH_CHILDREN_ERROR,
				parentId: 3,
				error: 'boom',
			},
			{
				type: ACTION_TYPES.FETCH_CHILDREN_ERROR,
				parentId: 7,
				error: 'boom',
			},
		] );
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

	it( 'expands the parent folder when parentId is non-root', () => {
		const yields = drive( createFolder( { name: 'Sub', parentId: 7 } ), [
			{
				folder: { id: 8, parent_id: 7 },
				paths: [],
				affected_parents: [],
				root_media_count: 0,
				root_total_size: 0,
			},
			{
				folders: [ { id: 8, parent_id: 7 } ],
				page: 1,
				total_pages: 1,
				root_media_count: 0,
				root_total_size: 0,
			},
		] );
		const expand = yields.find(
			( y ) => y.type === ACTION_TYPES.EXPAND_FOLDER
		);
		expect( expand ).toEqual( {
			type: ACTION_TYPES.EXPAND_FOLDER,
			folderId: 7,
		} );
	} );

	it( 'does not expand when parentId is root (0)', () => {
		const yields = drive( createFolder( { name: 'Top', parentId: 0 } ), [
			{
				folder: { id: 9, parent_id: 0 },
				paths: [],
				affected_parents: [],
				root_media_count: 0,
				root_total_size: 0,
			},
			{
				folders: [ { id: 9, parent_id: 0 } ],
				page: 1,
				total_pages: 1,
				root_media_count: 0,
				root_total_size: 0,
			},
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).not.toContain( ACTION_TYPES.EXPAND_FOLDER );
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
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).toContain( ACTION_TYPES.REMOVE_FOLDER );
		const fetchedIds = yields
			.filter( ( y ) => y.type === ACTION_TYPES.FETCH_CHILDREN_START )
			.map( ( y ) => y.parentId )
			.sort();
		expect( fetchedIds ).toEqual( [ 0, 5 ] );
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

	it( 'reparent dispatches EXPAND_FOLDER for new parent and reselects the moved folder', () => {
		const yields = drive( updateFolder( 7, { parentId: 5 } ), [
			{ id: 7, parent_id: 0 }, // getFolderById (previous)
			{ folder: { id: 7, parent_id: 5 } }, // PUT response
			{
				folders: [],
				page: 1,
				total_pages: 1,
				root_media_count: 0,
				root_total_size: 0,
			},
		] );
		const expand = yields.find(
			( y ) => y.type === ACTION_TYPES.EXPAND_FOLDER
		);
		expect( expand ).toEqual( {
			type: ACTION_TYPES.EXPAND_FOLDER,
			folderId: 5,
		} );
		const select = yields.find(
			( y ) => y.type === ACTION_TYPES.SET_SELECTED_FOLDER
		);
		expect( select ).toEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 7,
		} );
	} );

	it( 'no reparent: does not dispatch EXPAND_FOLDER or SET_SELECTED_FOLDER', () => {
		const yields = drive( updateFolder( 7, { name: 'X' } ), [
			{ id: 7, parent_id: 0 },
			{ folder: { id: 7, parent_id: 0 } },
		] );
		const types = yields.map( ( y ) => y.type );
		expect( types ).not.toContain( ACTION_TYPES.EXPAND_FOLDER );
		expect( types ).not.toContain( ACTION_TYPES.SET_SELECTED_FOLDER );
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

describe( 'bootFromUrl', () => {
	const setLocationSearch = ( search ) => {
		// jsdom defaults window.location to empty; replace is safest.
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { search },
		} );
	};

	const folderObj = ( id ) => ( { id, name: `f${ id }` } );

	it( 'fetches children for persisted-expanded folders that are not yet loaded', () => {
		setLocationSearch( '?foldsnap_folder_id=42' );
		const yields = drive( bootFromUrl(), [
			// expandPathTo: GET /folders/42/path
			{
				path: [
					{ id: 0, parent_id: 0, is_root: true },
					{ id: 5, parent_id: 0 },
					{ id: 42, parent_id: 5 },
				],
			},
			[], // expandPathTo getExpandedIds
			{ folders: [] }, // expandPathTo fetchChildrenBatch
			folderObj( 42 ), // fallback check getFolderById(42)
			[ 11, 12 ], // re-hydrate getExpandedIds
			false, // isFolderLoaded(11)
			false, // isFolderLoaded(12)
			{ folders: [] }, // fetchChildrenBatch
			[ 11, 12 ], // GC getExpandedIds
			folderObj( 11 ), // GC getFolderById(11)
			folderObj( 12 ), // GC getFolderById(12)
		] );
		const starts = yields.filter(
			( y ) =>
				y.type === ACTION_TYPES.FETCH_CHILDREN_START &&
				( y.parentId === 11 || y.parentId === 12 )
		);
		expect( starts ).toHaveLength( 2 );
		expect( yields ).toContainEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 42,
		} );
	} );

	it( 'skips re-hydration when every persisted-expanded folder is already loaded', () => {
		setLocationSearch( '?foldsnap_folder_id=42' );
		const yields = drive( bootFromUrl(), [
			{
				path: [
					{ id: 0, parent_id: 0, is_root: true },
					{ id: 42, parent_id: 0 },
				],
			},
			[], // expandPathTo getExpandedIds
			{ folders: [] },
			folderObj( 42 ), // fallback check
			[ 11 ], // re-hydrate getExpandedIds
			true, // isFolderLoaded(11)
			[ 11 ], // GC getExpandedIds
			folderObj( 11 ), // GC getFolderById(11)
		] );
		const starts = yields.filter(
			( y ) =>
				y.type === ACTION_TYPES.FETCH_CHILDREN_START &&
				y.parentId === 11
		);
		expect( starts ).toHaveLength( 0 );
		expect( yields ).toContainEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 42,
		} );
	} );

	it( 'fetches only the unloaded subset when persisted-expanded folders are mixed', () => {
		setLocationSearch( '?foldsnap_folder_id=42' );
		const yields = drive( bootFromUrl(), [
			{
				path: [
					{ id: 0, parent_id: 0, is_root: true },
					{ id: 42, parent_id: 0 },
				],
			},
			[],
			{ folders: [] },
			folderObj( 42 ), // fallback check
			[ 11, 12 ], // re-hydrate
			true, // isFolderLoaded(11)
			false, // isFolderLoaded(12)
			{ folders: [] },
			[ 11, 12 ], // GC getExpandedIds
			folderObj( 11 ),
			folderObj( 12 ),
		] );
		const rehydrateStarts = yields.filter(
			( y ) =>
				y.type === ACTION_TYPES.FETCH_CHILDREN_START &&
				( y.parentId === 11 || y.parentId === 12 )
		);
		expect( rehydrateStarts ).toHaveLength( 1 );
		expect( rehydrateStarts[ 0 ].parentId ).toBe( 12 );
	} );

	it( 'is a no-op for re-hydration when no folders are persisted-expanded', () => {
		setLocationSearch( '' );
		const yields = drive( bootFromUrl(), [
			// folderId === 0: expandPathTo and fallback check both no-op.
			[], // re-hydrate getExpandedIds → empty
			[], // GC getExpandedIds → empty
		] );
		const starts = yields.filter(
			( y ) => y.type === ACTION_TYPES.FETCH_CHILDREN_START
		);
		expect( starts ).toHaveLength( 0 );
		expect( yields ).toContainEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 0,
		} );
	} );

	it( 'falls back to the persisted preference when no URL param is present', () => {
		setLocationSearch( '' );
		window.foldsnap_data.preferences = { selectedFolderId: 7 };
		const yields = drive( bootFromUrl(), [
			{
				path: [
					{ id: 0, parent_id: 0, is_root: true },
					{ id: 7, parent_id: 0 },
				],
			},
			[],
			{ folders: [] },
			folderObj( 7 ), // fallback check
			[],
			[], // GC getExpandedIds
		] );
		expect( yields ).toContainEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 7,
		} );
		window.foldsnap_data.preferences = {};
	} );

	it( 'URL param wins over the persisted preference', () => {
		setLocationSearch( '?foldsnap_folder_id=42' );
		window.foldsnap_data.preferences = { selectedFolderId: 7 };
		const yields = drive( bootFromUrl(), [
			{
				path: [
					{ id: 0, parent_id: 0, is_root: true },
					{ id: 42, parent_id: 0 },
				],
			},
			[],
			{ folders: [] },
			folderObj( 42 ),
			[],
			[],
		] );
		expect( yields ).toContainEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 42,
		} );
		expect( yields ).not.toContainEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 7,
		} );
		window.foldsnap_data.preferences = {};
	} );

	it( 'falls back to Root when the linked folder no longer exists', () => {
		setLocationSearch( '?foldsnap_folder_id=999' );
		const yields = drive( bootFromUrl(), [
			{ path: [] }, // expandPathTo path GET → empty (deleted)
			null, // fallback check getFolderById(999) → missing
			[], // re-hydrate getExpandedIds
			[], // GC getExpandedIds
		] );
		expect( yields ).toContainEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 999,
		} );
		expect( yields ).toContainEqual( {
			type: ACTION_TYPES.SET_SELECTED_FOLDER,
			folderId: 0,
		} );
	} );

	it( 'GC drops persisted-expanded ids whose folders no longer exist', () => {
		setLocationSearch( '' );
		const yields = drive( bootFromUrl(), [
			[ 0, 11, 999 ], // re-hydrate getExpandedIds (also reused for GC)
			true, // isFolderLoaded(0)
			true, // isFolderLoaded(11)
			true, // isFolderLoaded(999)
			folderObj( 11 ), // GC getFolderById(11) → exists
			null, // GC getFolderById(999) → missing
		] );
		const setExpanded = yields.find(
			( y ) => y.type === ACTION_TYPES.SET_EXPANDED_IDS
		);
		expect( setExpanded ).toBeDefined();
		expect( setExpanded.ids ).toEqual( [ 0, 11 ] );
	} );
} );
