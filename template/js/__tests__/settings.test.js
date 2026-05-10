import apiFetch from '@wordpress/api-fetch';
import { runRecount } from '../settings';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '@wordpress/i18n', () => ( {
	__: ( s ) => s,
	sprintf: ( fmt, ...args ) => {
		let i = 0;
		return fmt
			.replace( /%\d?\$?d/g, () => String( args[ i++ ] ) )
			.replace( /%\d?\$?s/g, () => String( args[ i++ ] ) );
	},
} ) );

const makeBtnAndStatus = () => {
	const btn = { disabled: false };
	const status = { textContent: '' };
	return { btn, status };
};

describe( 'runRecount', () => {
	beforeEach( () => {
		apiFetch.mockReset();
	} );

	it( 'sends reset:true on the first call and reset:false on subsequent calls', async () => {
		apiFetch
			.mockResolvedValueOnce( {
				processed: 10,
				remaining: 5,
				done: false,
			} )
			.mockResolvedValueOnce( {
				processed: 5,
				remaining: 0,
				done: true,
			} );

		const { btn, status } = makeBtnAndStatus();
		await runRecount( btn, status );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
			limit: 200,
			reset: true,
		} );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data ).toEqual( {
			limit: 200,
			reset: false,
		} );
	} );

	it( 'accumulates processed counts across chunks and reports total on completion', async () => {
		apiFetch
			.mockResolvedValueOnce( {
				processed: 7,
				remaining: 4,
				done: false,
			} )
			.mockResolvedValueOnce( {
				processed: 3,
				remaining: 1,
				done: false,
			} )
			.mockResolvedValueOnce( {
				processed: 1,
				remaining: 0,
				done: true,
			} );

		const { btn, status } = makeBtnAndStatus();
		await runRecount( btn, status );

		expect( status.textContent ).toContain( '11' );
		expect( status.textContent ).toMatch( /Done/ );
	} );

	it( 're-enables the button and reports error on apiFetch rejection', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'boom' ) );

		const { btn, status } = makeBtnAndStatus();
		await runRecount( btn, status );

		expect( btn.disabled ).toBe( false );
		expect( status.textContent ).toContain( 'boom' );
	} );

	it( 'falls back to String(err) when the rejection is a plain string', async () => {
		apiFetch.mockRejectedValueOnce( 'network down' );

		const { btn, status } = makeBtnAndStatus();
		await runRecount( btn, status );

		expect( btn.disabled ).toBe( false );
		expect( status.textContent ).toContain( 'network down' );
	} );

	it( 'falls back to String(err) when the rejection is null', async () => {
		apiFetch.mockRejectedValueOnce( null );

		const { btn, status } = makeBtnAndStatus();
		await runRecount( btn, status );

		expect( btn.disabled ).toBe( false );
		expect( status.textContent ).toContain( 'null' );
	} );

	it( 'treats missing processed/remaining fields as zero', async () => {
		apiFetch.mockResolvedValueOnce( { done: true } );

		const { btn, status } = makeBtnAndStatus();
		await runRecount( btn, status );

		expect( status.textContent ).toContain( '0' );
		expect( status.textContent ).toMatch( /Done/ );
	} );

	it( 'disables the button while running and re-enables it after completion', async () => {
		let resolveFirst;
		apiFetch.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					resolveFirst = resolve;
				} )
		);

		const { btn, status } = makeBtnAndStatus();
		const promise = runRecount( btn, status );

		await Promise.resolve();
		expect( btn.disabled ).toBe( true );

		resolveFirst( { processed: 1, remaining: 0, done: true } );
		await promise;
		expect( btn.disabled ).toBe( false );
	} );
} );
