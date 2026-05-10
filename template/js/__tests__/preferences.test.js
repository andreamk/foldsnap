jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const CACHE_KEY = 'foldsnap.preferencesCache';

let preferences;
let apiFetch;

const reloadModule = () => {
	jest.resetModules();
	// Re-require AFTER resetModules so both the production module and the
	// test see the same fresh mock instance.
	// eslint-disable-next-line global-require
	apiFetch = require( '@wordpress/api-fetch' );
	apiFetch.mockReset();
	// eslint-disable-next-line global-require
	preferences = require( '../preferences' );
};

beforeEach( () => {
	window.localStorage.clear();
	jest.useFakeTimers();
	reloadModule();
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'readCachedPreferences', () => {
	it( 'returns DEFAULTS when cache is empty', () => {
		const result = preferences.readCachedPreferences();

		expect( result ).toEqual( {
			expandedFolders: [],
			allMedia: false,
		} );
	} );

	it( 'returns cached values when cache exists, filling gaps with defaults', () => {
		window.localStorage.setItem(
			CACHE_KEY,
			JSON.stringify( { expandedFolders: [ 1, 2 ] } )
		);
		reloadModule();

		const result = preferences.readCachedPreferences();

		expect( result.expandedFolders ).toEqual( [ 1, 2 ] );
		expect( result.allMedia ).toBe( false );
	} );

	it( 'returns DEFAULTS when cache JSON is malformed', () => {
		window.localStorage.setItem( CACHE_KEY, '{not json' );
		reloadModule();

		const result = preferences.readCachedPreferences();

		expect( result ).toEqual( {
			expandedFolders: [],
			allMedia: false,
		} );
	} );

	it( 'returns DEFAULTS when cache contains a non-object payload', () => {
		window.localStorage.setItem( CACHE_KEY, JSON.stringify( [ 1, 2, 3 ] ) );
		reloadModule();

		const result = preferences.readCachedPreferences();

		expect( result ).toEqual( {
			expandedFolders: [],
			allMedia: false,
		} );
	} );
} );

describe( 'loadPreferences', () => {
	it( 'fetches the server map, fills with defaults, and writes the cache', async () => {
		apiFetch.mockResolvedValueOnce( {
			preferences: { expandedFolders: [ 9, 10 ] },
		} );

		const result = await preferences.loadPreferences();

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/foldsnap/v1/preferences',
		} );
		expect( result.expandedFolders ).toEqual( [ 9, 10 ] );
		expect( result.allMedia ).toBe( false );

		const cached = JSON.parse( window.localStorage.getItem( CACHE_KEY ) );
		expect( cached.expandedFolders ).toEqual( [ 9, 10 ] );
	} );

	it( 'returns the cached values when apiFetch rejects', async () => {
		window.localStorage.setItem(
			CACHE_KEY,
			JSON.stringify( { expandedFolders: [ 7 ], allMedia: true } )
		);
		reloadModule();
		apiFetch.mockRejectedValueOnce( new Error( 'offline' ) );

		const result = await preferences.loadPreferences();

		expect( result.expandedFolders ).toEqual( [ 7 ] );
		expect( result.allMedia ).toBe( true );
	} );

	it( 'returns DEFAULTS when both server and cache are empty', async () => {
		apiFetch.mockRejectedValueOnce( new Error( 'offline' ) );

		const result = await preferences.loadPreferences();

		expect( result ).toEqual( {
			expandedFolders: [],
			allMedia: false,
		} );
	} );

	it( 'falls back to cache when the server response is missing the preferences key', async () => {
		window.localStorage.setItem(
			CACHE_KEY,
			JSON.stringify( { allMedia: true } )
		);
		reloadModule();
		apiFetch.mockResolvedValueOnce( { somethingElse: true } );

		const result = await preferences.loadPreferences();

		expect( result.allMedia ).toBe( true );
	} );

	it( 'filters unknown keys nested inside preferences', async () => {
		apiFetch.mockResolvedValueOnce( {
			preferences: {
				expandedFolders: [ 1 ],
				allMedia: true,
				rogueKey: 'should not appear',
			},
		} );

		const result = await preferences.loadPreferences();

		expect( result ).toEqual( {
			expandedFolders: [ 1 ],
			allMedia: true,
		} );
		expect( result ).not.toHaveProperty( 'rogueKey' );
		const cached = JSON.parse( window.localStorage.getItem( CACHE_KEY ) );
		expect( cached ).not.toHaveProperty( 'rogueKey' );
	} );

	it( 'falls back to defaults when server response.preferences is null', async () => {
		apiFetch.mockResolvedValueOnce( { preferences: null } );

		const result = await preferences.loadPreferences();

		expect( result ).toEqual( {
			expandedFolders: [],
			allMedia: false,
		} );
	} );
} );

describe( 'savePreference', () => {
	it( 'writes through the cache immediately', () => {
		preferences.savePreference( 'expandedFolders', [ 1, 2 ] );

		const cached = JSON.parse( window.localStorage.getItem( CACHE_KEY ) );
		expect( cached.expandedFolders ).toEqual( [ 1, 2 ] );
		// Server PUT not yet fired (still debouncing).
		expect( apiFetch ).not.toHaveBeenCalled();
	} );

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

		// Without flush: nothing has fired yet.
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
	} );
} );
