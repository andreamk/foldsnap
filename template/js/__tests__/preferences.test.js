jest.mock( '@wordpress/api-fetch', () => jest.fn() );

let preferences;
let apiFetch;

const reloadModule = () => {
	jest.resetModules();
	apiFetch = require( '@wordpress/api-fetch' );
	apiFetch.mockReset();
	preferences = require( '../preferences' );
};

beforeEach( () => {
	jest.useFakeTimers();
	reloadModule();
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'getInitialPreferences', () => {
	it( 'returns the localised preferences blob from window.foldsnap_data', () => {
		window.foldsnap_data = {
			preferences: {
				expandedFolders: [ 0, 5 ],
				allMedia: true,
				sidebarWidth: 320,
			},
		};
		reloadModule();

		expect( preferences.getInitialPreferences() ).toEqual( {
			expandedFolders: [ 0, 5 ],
			allMedia: true,
			sidebarWidth: 320,
		} );
	} );
} );

describe( 'savePreference', () => {
	it( 'debounces multiple rapid calls on the same key into one PUT', () => {
		apiFetch.mockResolvedValue( {} );

		preferences.savePreference( 'expandedFolders', [ 1 ] );
		preferences.savePreference( 'expandedFolders', [ 1, 2 ] );
		preferences.savePreference( 'expandedFolders', [ 1, 2, 3 ] );

		jest.advanceTimersByTime( 800 );

		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/foldsnap/v1/preferences/expandedFolders',
			method: 'PUT',
			data: { value: [ 1, 2, 3 ] },
		} );
	} );

	it( 'debounces independently per key', () => {
		apiFetch.mockResolvedValue( {} );

		preferences.savePreference( 'expandedFolders', [ 5 ] );
		preferences.savePreference( 'allMedia', true );

		jest.advanceTimersByTime( 800 );

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		const paths = apiFetch.mock.calls.map( ( c ) => c[ 0 ].path );
		expect( paths ).toEqual(
			expect.arrayContaining( [
				'/foldsnap/v1/preferences/expandedFolders',
				'/foldsnap/v1/preferences/allMedia',
			] )
		);
	} );

	it( 'does not fire the PUT before the debounce window elapses', () => {
		preferences.savePreference( 'allMedia', true );

		jest.advanceTimersByTime( 799 );

		expect( apiFetch ).not.toHaveBeenCalled();
	} );

	it( 'swallows server-side rejections silently', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'server down' ) );

		preferences.savePreference( 'allMedia', true );
		jest.advanceTimersByTime( 800 );

		await expect(
			preferences.flushPendingSaves()
		).resolves.toBeUndefined();
	} );
} );

describe( 'flushPendingSaves', () => {
	it( 'fires every pending PUT immediately and clears the queue', async () => {
		apiFetch.mockResolvedValue( {} );

		preferences.savePreference( 'expandedFolders', [ 1 ] );
		preferences.savePreference( 'allMedia', true );

		expect( apiFetch ).not.toHaveBeenCalled();

		await preferences.flushPendingSaves();

		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	} );
} );

describe( 'PREF_KEYS', () => {
	it( 'exposes the expected key constants', () => {
		expect( preferences.PREF_KEYS.EXPANDED_FOLDERS ).toBe(
			'expandedFolders'
		);
		expect( preferences.PREF_KEYS.ALL_MEDIA ).toBe( 'allMedia' );
		expect( preferences.PREF_KEYS.SIDEBAR_WIDTH ).toBe( 'sidebarWidth' );
	} );
} );
