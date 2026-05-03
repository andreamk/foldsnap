import { render, screen, fireEvent, act } from '@testing-library/react';
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

jest.mock( '@dnd-kit/sortable', () => ( {
	SortableContext: ( { children } ) => <div>{ children }</div>,
	verticalListSortingStrategy: 'verticalListSortingStrategy',
} ) );

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

const ROOT_FOLDERS = [
	{ id: 1, name: 'Photos' },
	{ id: 2, name: 'Documents' },
];

const makeStoreState = ( overrides = {} ) => ( {
	rootFolders: ROOT_FOLDERS,
	loadedRoot: true,
	fetchingRoot: false,
	selectedFolderId: null,
	error: null,
	rootMediaCount: 10,
	searchQuery: '',
	...overrides,
} );

const setupSelect = ( storeState ) => {
	useSelect.mockImplementation( ( fn ) =>
		fn( () => ( {
			getRootFolders: () => storeState.rootFolders,
			isFolderLoaded: () => storeState.loadedRoot,
			isFolderFetching: () => storeState.fetchingRoot,
			getSelectedFolderId: () => storeState.selectedFolderId,
			getError: () => storeState.error,
			getRootMediaCount: () => storeState.rootMediaCount,
			getSearchQuery: () => storeState.searchQuery,
		} ) )
	);
};

describe( 'FolderTree', () => {
	let mockSetSelectedFolder;
	let mockSetSearchQuery;
	let mockSearchFolders;
	let mockClearSearch;

	beforeEach( () => {
		jest.useFakeTimers();
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

	it( 'renders All Media root with media count', () => {
		setupSelect( makeStoreState( { rootMediaCount: 42 } ) );
		render( <FolderTree /> );
		expect( screen.getByText( 'All Media' ) ).toBeInTheDocument();
		expect( screen.getByText( '42' ) ).toBeInTheDocument();
	} );

	it( 'renders one mocked FolderItem per root folder', () => {
		setupSelect( makeStoreState() );
		render( <FolderTree /> );
		expect( screen.getByTestId( 'folder-item-1' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'folder-item-2' ) ).toBeInTheDocument();
	} );

	it( 'shows root spinner when root is fetching for the first time', () => {
		setupSelect(
			makeStoreState( { loadedRoot: false, fetchingRoot: true } )
		);
		render( <FolderTree /> );
		expect( screen.getByTestId( 'spinner' ) ).toBeInTheDocument();
	} );

	it( 'shows error notice when there is an error', () => {
		setupSelect( makeStoreState( { error: 'Network failure' } ) );
		render( <FolderTree /> );
		expect( screen.getByTestId( 'error-notice' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Network failure' ) ).toBeInTheDocument();
	} );

	it( 'selects root when All Media is clicked', () => {
		setupSelect( makeStoreState() );
		render( <FolderTree /> );
		fireEvent.click( screen.getByText( 'All Media' ) );
		expect( mockSetSelectedFolder ).toHaveBeenCalledWith( null );
	} );

	it( 'opens and closes the create folder modal', () => {
		setupSelect( makeStoreState() );
		render( <FolderTree /> );
		fireEvent.click( screen.getByText( '+ New Folder' ) );
		expect(
			screen.getByTestId( 'create-folder-modal' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByText( 'Close Modal' ) );
		expect(
			screen.queryByTestId( 'create-folder-modal' )
		).not.toBeInTheDocument();
	} );

	it( 'debounces search input then dispatches setSearchQuery + searchFolders', () => {
		setupSelect( makeStoreState() );
		render( <FolderTree /> );
		fireEvent.change( screen.getByTestId( 'search-input' ), {
			target: { value: 'photo' },
		} );
		expect( mockSetSearchQuery ).not.toHaveBeenCalled();
		act( () => {
			jest.advanceTimersByTime( 300 );
		} );
		expect( mockSetSearchQuery ).toHaveBeenCalledWith( 'photo' );
		expect( mockSearchFolders ).toHaveBeenCalledWith( 'photo' );
	} );

	it( 'clears search when input becomes empty', () => {
		setupSelect( makeStoreState( { searchQuery: 'photo' } ) );
		render( <FolderTree /> );
		fireEvent.change( screen.getByTestId( 'search-input' ), {
			target: { value: '' },
		} );
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
			screen.queryByTestId( 'folder-item-1' )
		).not.toBeInTheDocument();
	} );

	it( 'applies selected class to All Media when selection is null', () => {
		setupSelect( makeStoreState() );
		const { container } = render( <FolderTree /> );
		expect(
			container.querySelector( '.foldsnap-root-item--selected' )
		).toBeInTheDocument();
	} );
} );
