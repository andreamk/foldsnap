import { render, screen, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useSelect, useDispatch } from '@wordpress/data';
import FolderTree from '../FolderTree';

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );

jest.mock( '../FolderItem', () => {
	const MockFolderItem = ( { folderId, onSelect, onAddSubfolder } ) => (
		<div data-testid={ `folder-item-${ folderId }` }>
			<button onClick={ () => onSelect( folderId ) }>
				Select { folderId }
			</button>
			<button onClick={ () => onAddSubfolder( folderId ) }>
				Add Sub { folderId }
			</button>
		</div>
	);
	return MockFolderItem;
} );

jest.mock( '../CreateFolderModal', () => {
	const MockCreateFolderModal = ( { onClose, parentId } ) => (
		<div data-testid="create-folder-modal">
			<span>parent={ parentId }</span>
			<button onClick={ onClose }>Close Modal</button>
		</div>
	);
	return MockCreateFolderModal;
} );

jest.mock( '../SearchResultsList', () => {
	const MockSearchResultsList = () => (
		<div data-testid="search-results">Results</div>
	);
	return MockSearchResultsList;
} );

jest.mock( '@wordpress/components', () => ( {
	TextControl: ( { value, onChange, placeholder } ) => (
		<input
			data-testid="search-input"
			value={ value }
			onChange={ ( e ) => onChange( e.target.value ) }
			placeholder={ placeholder }
		/>
	),
	Button: ( { children, onClick } ) => (
		<button onClick={ onClick }>{ children }</button>
	),
	Spinner: () => <div data-testid="spinner" />,
	Notice: ( { children } ) => (
		<div data-testid="error-notice">{ children }</div>
	),
} ) );

const ROOT_FOLDER = { id: 0, name: 'Root', is_root: true };

const makeStoreState = ( overrides = {} ) => ( {
	rootFolder: ROOT_FOLDER,
	loadedRoot: true,
	fetchingRoot: false,
	selectedFolderId: null,
	error: null,
	searchQuery: '',
	...overrides,
} );

const setupSelect = ( storeState ) => {
	useSelect.mockImplementation( ( fn ) =>
		fn( () => ( {
			getFolderById: ( id ) =>
				id === 0 ? storeState.rootFolder : undefined,
			isFolderLoaded: () => storeState.loadedRoot,
			isFolderFetching: () => storeState.fetchingRoot,
			getSelectedFolderId: () => storeState.selectedFolderId,
			getError: () => storeState.error,
			getSearchQuery: () => storeState.searchQuery,
		} ) )
	);
};

describe( 'FolderTree', () => {
	let mockSetSelectedFolder;
	let mockSetSearchQuery;
	let mockSearchFolders;
	let mockClearSearch;
	let user;

	beforeEach( () => {
		jest.useFakeTimers();
		user = userEvent.setup( {
			advanceTimers: jest.advanceTimersByTime,
		} );
		mockSetSelectedFolder = jest.fn();
		mockSetSearchQuery = jest.fn();
		mockSearchFolders = jest.fn();
		mockClearSearch = jest.fn();
		useDispatch.mockReturnValue( {
			setSelectedFolder: mockSetSelectedFolder,
			setSearchQuery: mockSetSearchQuery,
			searchFolders: mockSearchFolders,
			clearSearch: mockClearSearch,
		} );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'renders the Root FolderItem when Root is hydrated', () => {
		setupSelect( makeStoreState() );
		render( <FolderTree /> );
		expect( screen.getByTestId( 'folder-item-0' ) ).toBeInTheDocument();
	} );

	it( 'shows root spinner before Root is hydrated and a fetch is in flight', () => {
		setupSelect(
			makeStoreState( {
				rootFolder: undefined,
				loadedRoot: false,
				fetchingRoot: true,
			} )
		);
		render( <FolderTree /> );
		expect( screen.getByTestId( 'spinner' ) ).toBeInTheDocument();
		expect(
			screen.queryByTestId( 'folder-item-0' )
		).not.toBeInTheDocument();
	} );

	it( 'shows error notice when there is an error', () => {
		setupSelect( makeStoreState( { error: 'Network failure' } ) );
		render( <FolderTree /> );
		expect( screen.getByTestId( 'error-notice' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Network failure' ) ).toBeInTheDocument();
	} );

	it( 'debounces search input then dispatches setSearchQuery + searchFolders', async () => {
		setupSelect( makeStoreState() );
		render( <FolderTree /> );
		await user.type( screen.getByTestId( 'search-input' ), 'photo' );
		expect( mockSetSearchQuery ).not.toHaveBeenCalled();
		act( () => {
			jest.advanceTimersByTime( 300 );
		} );
		expect( mockSetSearchQuery ).toHaveBeenCalledWith( 'photo' );
		expect( mockSearchFolders ).toHaveBeenCalledWith( 'photo' );
	} );

	it( 'clears search when input becomes empty', async () => {
		setupSelect( makeStoreState( { searchQuery: 'photo' } ) );
		render( <FolderTree /> );
		const input = screen.getByTestId( 'search-input' );
		await user.type( input, 'foo' );
		await user.clear( input );
		act( () => {
			jest.advanceTimersByTime( 300 );
		} );
		expect( mockClearSearch ).toHaveBeenCalled();
	} );

	it( 'shows SearchResultsList instead of tree while query is active', () => {
		setupSelect( makeStoreState( { searchQuery: 'photo' } ) );
		render( <FolderTree /> );
		expect( screen.getByTestId( 'search-results' ) ).toBeInTheDocument();
		expect(
			screen.queryByTestId( 'folder-item-0' )
		).not.toBeInTheDocument();
	} );

	it( 'opens CreateFolderModal with the parent id when Add Sub is triggered', async () => {
		setupSelect( makeStoreState() );
		render( <FolderTree /> );
		expect(
			screen.queryByTestId( 'create-folder-modal' )
		).not.toBeInTheDocument();
		await user.click( screen.getByText( 'Add Sub 0' ) );
		expect( screen.getByTestId( 'create-folder-modal' ) ).toBeInTheDocument();
		expect( screen.getByText( 'parent=0' ) ).toBeInTheDocument();
	} );

	it( 'closes CreateFolderModal when its onClose fires', async () => {
		setupSelect( makeStoreState() );
		render( <FolderTree /> );
		await user.click( screen.getByText( 'Add Sub 0' ) );
		expect( screen.getByTestId( 'create-folder-modal' ) ).toBeInTheDocument();
		await user.click( screen.getByText( 'Close Modal' ) );
		expect(
			screen.queryByTestId( 'create-folder-modal' )
		).not.toBeInTheDocument();
	} );
} );
