import { ACTION_TYPES, ROOT_PARENT_ID } from '../constants';
import { getRootFolders, getFolderById } from '../resolvers';

/**
 * Drive a generator manually, providing scripted responses for each yield.
 *
 * Mirrors the helper in actions.test.js: SELECT and API_FETCH yields each
 * consume one entry from `responses`; pure action yields do not.
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

describe( 'getRootFolders', () => {
	it( 'fetches root then yields a SELECT for getExpandedIds', () => {
		const yields = drive( getRootFolders(), [
			{ folders: [], page: 1, total_pages: 1 },
			[],
		] );

		expect( yields[ 0 ] ).toEqual( {
			type: ACTION_TYPES.FETCH_CHILDREN_START,
			parentId: ROOT_PARENT_ID,
		} );
		const selectYield = yields.find( ( y ) => y?.type === 'SELECT' );
		expect( selectYield ).toEqual( {
			type: 'SELECT',
			selector: 'getExpandedIds',
			args: [],
		} );
	} );

	it( 'refills children for every persisted expanded id after root loads', () => {
		const yields = drive( getRootFolders(), [
			{ folders: [], page: 1, total_pages: 1 },
			[ 7, 9 ],
			{ folders: [], page: 1, total_pages: 1 },
			{ folders: [], page: 1, total_pages: 1 },
		] );

		const startIds = yields
			.filter( ( y ) => y?.type === ACTION_TYPES.FETCH_CHILDREN_START )
			.map( ( y ) => y.parentId );
		expect( startIds ).toEqual( [ ROOT_PARENT_ID, 7, 9 ] );
	} );

	it( 'skips the refill loop when persisted ids are not an array', () => {
		const yields = drive( getRootFolders(), [
			{ folders: [], page: 1, total_pages: 1 },
			null,
		] );

		const fetchStarts = yields.filter(
			( y ) => y?.type === ACTION_TYPES.FETCH_CHILDREN_START
		);
		expect( fetchStarts ).toHaveLength( 1 );
		expect( fetchStarts[ 0 ].parentId ).toBe( ROOT_PARENT_ID );
	} );
} );

describe( 'getFolderById', () => {
	it( 'is a no-op when the folder is already in the store', () => {
		const yields = drive( getFolderById( 42 ), [
			{ id: 42, parent_id: 0 },
		] );

		// Strict: exactly one yield, and it is the existence-probe SELECT.
		// Catches regressions that add a stray side-effect yield after the
		// early-return check.
		expect( yields ).toHaveLength( 1 );
		expect( yields[ 0 ] ).toEqual( {
			type: 'SELECT',
			selector: 'getFolderById',
			args: [ 42 ],
		} );
	} );

	it( 'fetches root children when id 0 is missing', () => {
		const yields = drive( getFolderById( ROOT_PARENT_ID ), [
			undefined,
			{ folders: [], page: 1, total_pages: 1 },
		] );

		const startIds = yields
			.filter( ( y ) => y?.type === ACTION_TYPES.FETCH_CHILDREN_START )
			.map( ( y ) => y.parentId );
		expect( startIds ).toEqual( [ ROOT_PARENT_ID ] );
	} );

	it( 'walks the path via expandPathTo when a positive id is missing', () => {
		const yields = drive( getFolderById( 5 ), [
			undefined,
			{ path: [], paths: [] },
		] );

		const apiFetches = yields.filter( ( y ) => y?.type === 'API_FETCH' );
		expect( apiFetches[ 0 ].request.path ).toBe(
			'/foldsnap/v1/folders/5/path'
		);
	} );

	it( 'rejects non-numeric or negative ids without yielding', () => {
		expect( drive( getFolderById( -1 ) ) ).toHaveLength( 0 );
		expect( drive( getFolderById( 'abc' ) ) ).toHaveLength( 0 );
	} );
} );
