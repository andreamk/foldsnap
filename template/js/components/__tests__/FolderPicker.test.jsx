import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { useDispatch, useSelect } from '@wordpress/data';
import FolderPicker from '../FolderPicker';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Spinner: () => <div data-testid="spinner" />,
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
} ) );

const FOLDERS_BY_ID = {
	1: { id: 1, name: 'Photos', has_children: true, parent_id: 0 },
	2: { id: 2, name: 'Docs', has_children: false, parent_id: 0 },
	10: { id: 10, name: 'Vacation', has_children: false, parent_id: 1 },
};

const setup = ( {
	rootFolders = [ FOLDERS_BY_ID[ 1 ], FOLDERS_BY_ID[ 2 ] ],
	loaded = true,
	fetching = false,
	childrenById = {},
} = {} ) => {
	useSelect.mockImplementation( ( fn ) =>
		fn( () => ( {
			getRootFolders: () => rootFolders,
			isFolderLoaded: () => loaded,
			isFolderFetching: () => fetching,
			getFolderById: ( id ) => FOLDERS_BY_ID[ id ],
			getChildrenOf: ( id ) => childrenById[ id ] ?? [],
		} ) )
	);
};

describe( 'FolderPicker', () => {
	let fetchChildren;
	let user;

	beforeEach( () => {
		jest.useFakeTimers();
		user = userEvent.setup( {
			advanceTimers: jest.advanceTimersByTime,
		} );
		fetchChildren = jest.fn();
		apiFetch.mockReset();
		useDispatch.mockReturnValue( {
			fetchChildren,
		} );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'renders Root entry plus root folders', () => {
		setup();
		render( <FolderPicker value={ 0 } onChange={ jest.fn() } /> );
		expect( screen.getByText( '— Root —' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Photos' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Docs' ) ).toBeInTheDocument();
	} );

	it( 'fires onChange with 0 when Root is picked', async () => {
		setup();
		const onChange = jest.fn();
		render( <FolderPicker value={ 5 } onChange={ onChange } /> );
		await user.click( screen.getByText( '— Root —' ) );
		expect( onChange ).toHaveBeenCalledWith( 0 );
	} );

	it( 'fires onChange with folder id when a folder is picked', async () => {
		setup();
		const onChange = jest.fn();
		render( <FolderPicker value={ 0 } onChange={ onChange } /> );
		await user.click( screen.getByText( 'Docs' ) );
		expect( onChange ).toHaveBeenCalledWith( 2 );
	} );

	it( 'expand fetches children for that folder', async () => {
		setup();
		render( <FolderPicker value={ 0 } onChange={ jest.fn() } /> );
		await user.click( screen.getByLabelText( 'Expand' ) );
		expect( fetchChildren ).toHaveBeenCalledWith( 1 );
	} );

	it( 'excludes the excludeId folder from the tree', () => {
		setup();
		render(
			<FolderPicker value={ 0 } onChange={ jest.fn() } excludeId={ 1 } />
		);
		expect( screen.queryByText( 'Photos' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Docs' ) ).toBeInTheDocument();
	} );

	it( 'searches via apiFetch and renders results without touching the store', async () => {
		setup();
		apiFetch.mockResolvedValue( {
			query: 'res',
			results: [
				{
					folder: { id: 99, name: 'Result' },
					breadcrumb: [ { id: 1, name: 'Photos' } ],
				},
			],
		} );
		render( <FolderPicker value={ 0 } onChange={ jest.fn() } /> );
		await user.type(
			screen.getByPlaceholderText( 'Search folders…' ),
			'res'
		);
		await act( async () => {
			jest.advanceTimersByTime( 300 );
		} );
		expect( apiFetch ).toHaveBeenCalledWith( {
			path: expect.stringContaining( 'search=res' ),
			method: 'GET',
		} );
		expect( screen.getByText( 'Result' ) ).toBeInTheDocument();
	} );

	it( 'returns to tree view when input becomes empty without firing a request', async () => {
		setup();
		render( <FolderPicker value={ 0 } onChange={ jest.fn() } /> );
		const input = screen.getByPlaceholderText( 'Search folders…' );
		await user.type( input, 'foo' );
		await user.clear( input );
		await act( async () => {
			jest.advanceTimersByTime( 300 );
		} );
		// Empty query short-circuits: no apiFetch call for the cleared state,
		// and the tree (Root entry) is visible again.
		expect( screen.getByText( '— Root —' ) ).toBeInTheDocument();
	} );

	it( 'drops stale responses if the query changed mid-flight', async () => {
		setup();
		// First query "fo" resolves AFTER the user has already typed "foo";
		// the picker should ignore its results.
		let resolveFirst;
		apiFetch.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					resolveFirst = resolve;
				} )
		);
		apiFetch.mockResolvedValueOnce( {
			query: 'foo',
			results: [
				{
					folder: { id: 7, name: 'Foobar' },
					breadcrumb: [],
				},
			],
		} );
		render( <FolderPicker value={ 0 } onChange={ jest.fn() } /> );
		const input = screen.getByPlaceholderText( 'Search folders…' );
		await user.type( input, 'fo' );
		await act( async () => {
			jest.advanceTimersByTime( 300 );
		} );
		await user.type( input, 'o' );
		await act( async () => {
			jest.advanceTimersByTime( 300 );
		} );
		// Now resolve the first (stale) request.
		await act( async () => {
			resolveFirst( {
				query: 'fo',
				results: [
					{
						folder: { id: 1, name: 'StaleHit' },
						breadcrumb: [],
					},
				],
			} );
		} );
		expect( screen.queryByText( 'StaleHit' ) ).not.toBeInTheDocument();
		expect( screen.getByText( 'Foobar' ) ).toBeInTheDocument();
	} );
} );
