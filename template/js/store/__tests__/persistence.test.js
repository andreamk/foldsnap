import {
	loadExpandedIds,
	saveExpandedIds,
	STORAGE_KEY,
	loadAllMediaActive,
	saveAllMediaActive,
	ALL_MEDIA_KEY,
} from '../persistence';

describe( 'persistence', () => {
	beforeEach( () => {
		window.localStorage.clear();
	} );

	describe( 'loadExpandedIds', () => {
		it( 'returns empty array when nothing is stored', () => {
			expect( loadExpandedIds() ).toEqual( [] );
		} );

		it( 'returns the persisted array of integers', () => {
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( [ 1, 5, 42 ] )
			);
			expect( loadExpandedIds() ).toEqual( [ 1, 5, 42 ] );
		} );

		it( 'tolerates malformed JSON', () => {
			window.localStorage.setItem( STORAGE_KEY, 'not-json' );
			expect( loadExpandedIds() ).toEqual( [] );
		} );

		it( 'tolerates non-array JSON', () => {
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( { foo: 'bar' } )
			);
			expect( loadExpandedIds() ).toEqual( [] );
		} );

		it( 'filters non-positive and non-numeric entries', () => {
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( [ 1, '2', 0, -3, 'foo', null, 4 ] )
			);
			expect( loadExpandedIds() ).toEqual( [ 1, 2, 4 ] );
		} );

		it( 'dedupes repeated entries', () => {
			window.localStorage.setItem(
				STORAGE_KEY,
				JSON.stringify( [ 1, 1, 2, 2, 3 ] )
			);
			expect( loadExpandedIds() ).toEqual( [ 1, 2, 3 ] );
		} );
	} );

	describe( 'saveExpandedIds', () => {
		it( 'persists the array under STORAGE_KEY', () => {
			saveExpandedIds( [ 7, 9, 11 ] );
			expect( window.localStorage.getItem( STORAGE_KEY ) ).toBe(
				JSON.stringify( [ 7, 9, 11 ] )
			);
		} );

		it( 'overwrites previous value', () => {
			saveExpandedIds( [ 1 ] );
			saveExpandedIds( [ 2, 3 ] );
			expect(
				JSON.parse( window.localStorage.getItem( STORAGE_KEY ) )
			).toEqual( [ 2, 3 ] );
		} );

		it( 'does not throw when localStorage.setItem throws (quota exceeded)', () => {
			const original = window.localStorage.setItem;
			window.localStorage.setItem = jest.fn( () => {
				throw new Error( 'QuotaExceeded' );
			} );
			expect( () => saveExpandedIds( [ 1 ] ) ).not.toThrow();
			window.localStorage.setItem = original;
		} );
	} );

	describe( 'allMedia toggle', () => {
		it( 'defaults to false when nothing is stored', () => {
			expect( loadAllMediaActive() ).toBe( false );
		} );

		it( 'returns true when set to "1"', () => {
			window.localStorage.setItem( ALL_MEDIA_KEY, '1' );
			expect( loadAllMediaActive() ).toBe( true );
		} );

		it( 'persists true', () => {
			saveAllMediaActive( true );
			expect( window.localStorage.getItem( ALL_MEDIA_KEY ) ).toBe( '1' );
		} );

		it( 'removes the key when persisting false', () => {
			window.localStorage.setItem( ALL_MEDIA_KEY, '1' );
			saveAllMediaActive( false );
			expect( window.localStorage.getItem( ALL_MEDIA_KEY ) ).toBeNull();
		} );
	} );
} );
