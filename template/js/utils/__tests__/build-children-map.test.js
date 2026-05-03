import {
	setChildrenForParent,
	appendChildrenForParent,
	removeFolderFromMap,
	replaceFolderInMap,
} from '../build-children-map';

describe( 'build-children-map', () => {
	const folder = ( id, name = `f${ id }`, extra = {} ) => ( {
		id,
		name,
		...extra,
	} );

	describe( 'setChildrenForParent', () => {
		it( 'sets children for a new parent slot', () => {
			const next = setChildrenForParent( {}, 0, [ folder( 1 ) ] );
			expect( next ).toEqual( { 0: [ { id: 1, name: 'f1' } ] } );
		} );

		it( 'replaces existing children for the same parent', () => {
			const initial = { 0: [ folder( 1 ) ], 5: [ folder( 7 ) ] };
			const next = setChildrenForParent( initial, 0, [ folder( 2 ) ] );
			expect( next[ 0 ] ).toEqual( [ { id: 2, name: 'f2' } ] );
			expect( next[ 5 ] ).toBe( initial[ 5 ] );
		} );

		it( 'returns a new outer object reference', () => {
			const initial = { 0: [] };
			const next = setChildrenForParent( initial, 0, [ folder( 1 ) ] );
			expect( next ).not.toBe( initial );
		} );
	} );

	describe( 'appendChildrenForParent', () => {
		it( 'appends to an empty slot', () => {
			const next = appendChildrenForParent( {}, 0, [
				folder( 1 ),
				folder( 2 ),
			] );
			expect( next[ 0 ] ).toEqual( [
				{ id: 1, name: 'f1' },
				{ id: 2, name: 'f2' },
			] );
		} );

		it( 'appends new folders after existing ones', () => {
			const initial = { 0: [ folder( 1 ), folder( 2 ) ] };
			const next = appendChildrenForParent( initial, 0, [ folder( 3 ) ] );
			expect( next[ 0 ].map( ( f ) => f.id ) ).toEqual( [ 1, 2, 3 ] );
		} );

		it( 'replaces folders with same id (refresh from latest page)', () => {
			const initial = { 0: [ folder( 1, 'old' ), folder( 2 ) ] };
			const next = appendChildrenForParent( initial, 0, [
				folder( 1, 'new' ),
			] );
			const updated = next[ 0 ].find( ( f ) => f.id === 1 );
			expect( updated.name ).toBe( 'new' );
			expect( next[ 0 ] ).toHaveLength( 2 );
		} );
	} );

	describe( 'removeFolderFromMap', () => {
		it( 'removes the folder from its parent slot', () => {
			const initial = { 0: [ folder( 1 ), folder( 2 ) ] };
			const next = removeFolderFromMap( initial, 0, 1 );
			expect( next[ 0 ] ).toEqual( [ { id: 2, name: 'f2' } ] );
		} );

		it( 'deletes the folder own slot to release loaded descendants', () => {
			const initial = {
				0: [ folder( 1 ) ],
				1: [ folder( 10 ), folder( 11 ) ],
			};
			const next = removeFolderFromMap( initial, 0, 1 );
			expect( next[ 1 ] ).toBeUndefined();
		} );

		it( 'is a no-op on missing parent slot', () => {
			const next = removeFolderFromMap( {}, 0, 1 );
			expect( next ).toEqual( {} );
		} );
	} );

	describe( 'replaceFolderInMap', () => {
		it( 'replaces the folder wherever it lives', () => {
			const initial = {
				0: [ folder( 1, 'old', { total_size: 100 } ), folder( 2 ) ],
			};
			const next = replaceFolderInMap(
				initial,
				folder( 1, 'new', { total_size: 500 } )
			);
			expect( next[ 0 ][ 0 ] ).toEqual( {
				id: 1,
				name: 'new',
				total_size: 500,
			} );
			expect( next[ 0 ][ 1 ] ).toBe( initial[ 0 ][ 1 ] );
		} );

		it( 'returns the same reference if folder not present (avoids re-renders)', () => {
			const initial = { 0: [ folder( 1 ) ] };
			const next = replaceFolderInMap( initial, folder( 99 ) );
			expect( next ).toBe( initial );
		} );
	} );
} );
