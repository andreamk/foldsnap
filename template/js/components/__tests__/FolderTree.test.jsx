import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import FolderTree from '../FolderTree';

// Mock @wordpress/data
jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );

// Mock child components to isolate FolderTree
jest.mock( '../FolderItem', () => {
	const MockFolderItem = ( { folder, onSelect, onAddSubfolder } ) => (
		<div data-testid={ `folder-item-${ folder.id }` }>
			<span>{ folder.name }</span>
			<button onClick={ () => onSelect( folder.id ) }>Select</button>
			<button onClick={ () => onAddSubfolder( folder.id ) }>
				Add Sub
			</button>
		</div>
	);
	return MockFolderItem;
} );

jest.mock( '../CreateFolderModal', () => {
	const MockCreateFolderModal = ( { onClose } ) => (
		<div data-testid="create-folder-modal">
			<button onClick={ onClose }>Close Modal</button>
		</div>
	);
	return MockCreateFolderModal;
} );

// Mock @dnd-kit/sortable
jest.mock( '@dnd-kit/sortable', () => ( {
	SortableContext: ( { children } ) => <div>{ children }</div>,
	verticalListSortingStrategy: 'verticalListSortingStrategy',
} ) );

// Mock @wordpress/components
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

const FOLDERS = [
	{ id: 1, name: 'Photos', children: [] },
	{ id: 2, name: 'Documents', children: [] },
];

const makeStoreState = ( overrides = {} ) => ( {
	folders: FOLDERS,
	filteredFolders: FOLDERS,
	selectedFolderId: null,
	isLoading: false,
	error: null,
	rootMediaCount: 10,
	searchQuery: '',
	...overrides,
} );

describe( 'FolderTree', () => {
	let mockSetSelectedFolder;
	let mockSetSearchQuery;
	let mockUpdateFolder;

	beforeEach( () => {
		mockSetSelectedFolder = jest.fn();
		mockSetSearchQuery = jest.fn();
		mockUpdateFolder = jest.fn();

		useDispatch.mockReturnValue( {
			setSelectedFolder: mockSetSelectedFolder,
			setSearchQuery: mockSetSearchQuery,
			updateFolder: mockUpdateFolder,
		} );
	} );

	const makeSelectFn = ( storeState ) => () => ( {
		getFolders: () => storeState.folders,
		getFilteredFolders: () => storeState.filteredFolders,
		getSelectedFolderId: () => storeState.selectedFolderId,
		isLoading: () => storeState.isLoading,
		getError: () => storeState.error,
		getRootMediaCount: () => storeState.rootMediaCount,
		getSearchQuery: () => storeState.searchQuery,
	} );

	const setupUseSelect = ( storeState ) => {
		useSelect.mockImplementation( ( selector ) =>
			selector( makeSelectFn( storeState ) )
		);
	};

	it( 'renders All Media root item', () => {
		setupUseSelect( makeStoreState() );
		render( <FolderTree /> );
		expect( screen.getByText( 'All Media' ) ).toBeInTheDocument();
	} );

	it( 'renders root media count', () => {
		setupUseSelect( makeStoreState( { rootMediaCount: 42 } ) );
		render( <FolderTree /> );
		expect( screen.getByText( '42' ) ).toBeInTheDocument();
	} );

	it( 'renders folder items for each folder in filteredFolders', () => {
		setupUseSelect( makeStoreState() );
		render( <FolderTree /> );
		expect( screen.getByTestId( 'folder-item-1' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'folder-item-2' ) ).toBeInTheDocument();
	} );

	it( 'shows spinner when loading', () => {
		setupUseSelect( makeStoreState( { isLoading: true } ) );
		render( <FolderTree /> );
		expect( screen.getByTestId( 'spinner' ) ).toBeInTheDocument();
	} );

	it( 'does not show folder list when loading', () => {
		setupUseSelect( makeStoreState( { isLoading: true } ) );
		render( <FolderTree /> );
		expect(
			screen.queryByTestId( 'folder-item-1' )
		).not.toBeInTheDocument();
	} );

	it( 'shows error notice when there is an error', () => {
		setupUseSelect(
			makeStoreState( { error: 'Network failure', isLoading: false } )
		);
		render( <FolderTree /> );
		expect( screen.getByTestId( 'error-notice' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Network failure' ) ).toBeInTheDocument();
	} );

	it( 'calls setSelectedFolder(null) when All Media is clicked', () => {
		setupUseSelect( makeStoreState() );
		render( <FolderTree /> );
		fireEvent.click( screen.getByText( 'All Media' ) );
		expect( mockSetSelectedFolder ).toHaveBeenCalledWith( null );
	} );

	it( 'opens modal when New Folder button is clicked', () => {
		setupUseSelect( makeStoreState() );
		render( <FolderTree /> );
		fireEvent.click( screen.getByText( '+ New Folder' ) );
		expect(
			screen.getByTestId( 'create-folder-modal' )
		).toBeInTheDocument();
	} );

	it( 'closes modal when modal onClose is called', () => {
		setupUseSelect( makeStoreState() );
		render( <FolderTree /> );
		fireEvent.click( screen.getByText( '+ New Folder' ) );
		fireEvent.click( screen.getByText( 'Close Modal' ) );
		expect(
			screen.queryByTestId( 'create-folder-modal' )
		).not.toBeInTheDocument();
	} );

	it( 'renders search input', () => {
		setupUseSelect( makeStoreState() );
		render( <FolderTree /> );
		expect( screen.getByTestId( 'search-input' ) ).toBeInTheDocument();
	} );

	it( 'calls setSearchQuery when search input changes', () => {
		setupUseSelect( makeStoreState() );
		render( <FolderTree /> );
		fireEvent.change( screen.getByTestId( 'search-input' ), {
			target: { value: 'photo' },
		} );
		expect( mockSetSearchQuery ).toHaveBeenCalledWith( 'photo' );
	} );

	it( 'applies selected class to All Media when selectedFolderId is null', () => {
		setupUseSelect( makeStoreState( { selectedFolderId: null } ) );
		const { container } = render( <FolderTree /> );
		expect(
			container.querySelector( '.foldsnap-root-item--selected' )
		).toBeInTheDocument();
	} );

	it( 'does not apply selected class to All Media when a folder is selected', () => {
		setupUseSelect( makeStoreState( { selectedFolderId: 1 } ) );
		const { container } = render( <FolderTree /> );
		expect(
			container.querySelector( '.foldsnap-root-item--selected' )
		).not.toBeInTheDocument();
	} );
} );
