import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useDispatch, useSelect } from '@wordpress/data';
import FolderPicker from '../FolderPicker';

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
	searchResults = [],
	searchLoading = false,
	childrenById = {},
} = {} ) => {
	useSelect.mockImplementation( ( fn ) =>
		fn( () => ( {
			getRootFolders: () => rootFolders,
			isFolderLoaded: () => loaded,
			isFolderFetching: () => fetching,
			getSearchResults: () => searchResults,
			isSearchLoading: () => searchLoading,
			getFolderById: ( id ) => FOLDERS_BY_ID[ id ],
			getChildrenOf: ( id ) => childrenById[ id ] ?? [],
		} ) )
	);
};

describe( 'FolderPicker', () => {
	let fetchChildren;
	let searchFolders;
	let clearSearch;
	let user;

	beforeEach( () => {
		jest.useFakeTimers();
		user = userEvent.setup( {
			advanceTimers: jest.advanceTimersByTime,
		} );
		fetchChildren = jest.fn();
		searchFolders = jest.fn();
		clearSearch = jest.fn();
		useDispatch.mockReturnValue( {
			fetchChildren,
			searchFolders,
			clearSearch,
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

	it( 'switches to results view while a query is active', async () => {
		setup( {
			searchResults: [
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
		act( () => {
			jest.advanceTimersByTime( 300 );
		} );
		expect( searchFolders ).toHaveBeenCalledWith( 'res' );
		// Now in results mode: tree row "Photos" disappears.
		expect( screen.getByText( 'Result' ) ).toBeInTheDocument();
	} );

	it( 'clears search when input becomes empty', async () => {
		setup();
		render( <FolderPicker value={ 0 } onChange={ jest.fn() } /> );
		const input = screen.getByPlaceholderText( 'Search folders…' );
		await user.type( input, 'foo' );
		await user.clear( input );
		act( () => {
			jest.advanceTimersByTime( 300 );
		} );
		expect( clearSearch ).toHaveBeenCalled();
	} );
} );
