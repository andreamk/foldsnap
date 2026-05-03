import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useDispatch, useSelect } from '@wordpress/data';
import SearchResultsList from '../SearchResultsList';

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

const setup = ( {
	results = [],
	isLoading = false,
	pagination = { page: 1, totalPages: 1, total: results.length },
} = {} ) => {
	useSelect.mockImplementation( ( fn ) =>
		fn( () => ( {
			getSearchResults: () => results,
			isSearchLoading: () => isLoading,
			getSearchPagination: () => pagination,
		} ) )
	);
};

describe( 'SearchResultsList', () => {
	let setSelectedFolder;
	let expandPathTo;
	let setSearchQuery;
	let clearSearch;
	let loadMoreSearchResults;

	beforeEach( () => {
		setSelectedFolder = jest.fn();
		expandPathTo = jest.fn().mockResolvedValue( undefined );
		setSearchQuery = jest.fn();
		clearSearch = jest.fn();
		loadMoreSearchResults = jest.fn();
		useDispatch.mockReturnValue( {
			setSelectedFolder,
			expandPathTo,
			setSearchQuery,
			clearSearch,
			loadMoreSearchResults,
		} );
	} );

	it( 'renders spinner while initial load is pending', () => {
		setup( { isLoading: true } );
		render( <SearchResultsList /> );
		expect( screen.getByTestId( 'spinner' ) ).toBeInTheDocument();
	} );

	it( 'renders empty state when no results', () => {
		setup();
		render( <SearchResultsList /> );
		expect(
			screen.getByText( 'No folders match your search.' )
		).toBeInTheDocument();
	} );

	it( 'renders results with breadcrumb', () => {
		setup( {
			results: [
				{
					folder: { id: 1, name: 'Vacation' },
					breadcrumb: [
						{ id: 5, name: 'Photos' },
						{ id: 1, name: 'Vacation' },
					],
				},
			],
			pagination: { page: 1, totalPages: 1, total: 1 },
		} );
		render( <SearchResultsList /> );
		expect( screen.getByText( 'Vacation' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Photos / Vacation' ) ).toBeInTheDocument();
		expect( screen.getByText( '1 folders found' ) ).toBeInTheDocument();
	} );

	it( 'on click: selects, expands path, clears search', async () => {
		const user = userEvent.setup();
		setup( {
			results: [ { folder: { id: 7, name: 'Hit' }, breadcrumb: [] } ],
		} );
		render( <SearchResultsList /> );
		await user.click( screen.getByText( 'Hit' ) );
		expect( setSelectedFolder ).toHaveBeenCalledWith( 7 );
		await waitFor( () => {
			expect( expandPathTo ).toHaveBeenCalledWith( 7 );
			expect( setSearchQuery ).toHaveBeenCalledWith( '' );
			expect( clearSearch ).toHaveBeenCalled();
		} );
	} );

	it( 'shows Load more button when more pages available', async () => {
		const user = userEvent.setup();
		setup( {
			results: [ { folder: { id: 1, name: 'A' }, breadcrumb: [] } ],
			pagination: { page: 1, totalPages: 3, total: 100 },
		} );
		render( <SearchResultsList /> );
		await user.click( screen.getByText( 'Load more' ) );
		expect( loadMoreSearchResults ).toHaveBeenCalled();
	} );

	it( 'hides Load more button on last page', () => {
		setup( {
			results: [ { folder: { id: 1, name: 'A' }, breadcrumb: [] } ],
			pagination: { page: 3, totalPages: 3, total: 100 },
		} );
		render( <SearchResultsList /> );
		expect( screen.queryByText( 'Load more' ) ).not.toBeInTheDocument();
	} );
} );
