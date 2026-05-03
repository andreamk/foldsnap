import { render, screen, fireEvent } from '@testing-library/react';
import { useDispatch, useSelect } from '@wordpress/data';
import FolderItem from '../FolderItem';

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@dnd-kit/sortable', () => ( {
	useSortable: () => ( {
		attributes: {},
		listeners: {},
		setNodeRef: jest.fn(),
		transform: null,
		transition: null,
		isDragging: false,
	} ),
} ) );

jest.mock( '@dnd-kit/core', () => ( {
	useDroppable: () => ( {
		isOver: false,
		setNodeRef: jest.fn(),
	} ),
} ) );

jest.mock( '@dnd-kit/utilities', () => ( {
	CSS: { Transform: { toString: () => '' } },
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( { children, onClick } ) => (
		<button onClick={ onClick }>{ children }</button>
	),
	DropdownMenu: ( { label, controls } ) => (
		<div data-testid="dropdown-menu" aria-label={ label }>
			{ controls.map( ( c ) => (
				<button key={ c.title } onClick={ c.onClick }>
					{ c.title }
				</button>
			) ) }
		</div>
	),
	Modal: ( { children, title, onRequestClose } ) => (
		<div data-testid="confirm-modal" aria-label={ title }>
			{ children }
			<button onClick={ onRequestClose }>Close</button>
		</div>
	),
	Spinner: () => <div data-testid="spinner" />,
} ) );

const makeFolder = ( overrides = {} ) => ( {
	id: 1,
	name: 'Photos',
	parent_id: 0,
	color: '',
	total_media_count: 5,
	total_size: 1024,
	has_children: false,
	...overrides,
} );

const setupSelect = ( {
	folder,
	isExpanded = false,
	isFetching = false,
	children = [],
	childrenById = {},
} ) => {
	useSelect.mockImplementation( ( fn ) =>
		fn( () => ( {
			getFolderById: ( id ) =>
				id === folder?.id ? folder : childrenById[ id ],
			isFolderExpanded: () => isExpanded,
			isFolderFetching: () => isFetching,
			getChildrenOf: ( id ) => ( id === folder?.id ? children : [] ),
		} ) )
	);
};

describe( 'FolderItem', () => {
	let mockDeleteFolder;
	let mockExpandFolder;
	let mockCollapseFolder;

	beforeEach( () => {
		mockDeleteFolder = jest.fn();
		mockExpandFolder = jest.fn();
		mockCollapseFolder = jest.fn();
		useDispatch.mockReturnValue( {
			deleteFolder: mockDeleteFolder,
			expandFolder: mockExpandFolder,
			collapseFolder: mockCollapseFolder,
		} );
	} );

	it( 'returns null when folder not in store', () => {
		setupSelect( { folder: undefined } );
		const { container } = render(
			<FolderItem
				folderId={ 99 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( container.firstChild ).toBeNull();
	} );

	it( 'renders folder name and data-folder-id', () => {
		setupSelect( { folder: makeFolder( { id: 42, name: 'Vacation' } ) } );
		const { container } = render(
			<FolderItem
				folderId={ 42 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByText( 'Vacation' ) ).toBeInTheDocument();
		expect(
			container.querySelector( '.foldsnap-folder-item' )
		).toHaveAttribute( 'data-folder-id', '42' );
	} );

	it( 'fires onSelect when row is clicked', () => {
		const onSelect = jest.fn();
		setupSelect( { folder: makeFolder( { id: 42 } ) } );
		render(
			<FolderItem
				folderId={ 42 }
				selectedFolderId={ null }
				onSelect={ onSelect }
				onAddSubfolder={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByText( 'Photos' ) );
		expect( onSelect ).toHaveBeenCalledWith( 42 );
	} );

	it( 'applies selected class when selectedFolderId matches', () => {
		setupSelect( { folder: makeFolder( { id: 7 } ) } );
		const { container } = render(
			<FolderItem
				folderId={ 7 }
				selectedFolderId={ 7 }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect(
			container.querySelector( '.foldsnap-folder-item--selected' )
		).toBeInTheDocument();
	} );

	it( 'renders chevron only when has_children is true', () => {
		setupSelect( { folder: makeFolder( { has_children: true } ) } );
		render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByLabelText( 'Expand' ) ).toBeInTheDocument();
	} );

	it( 'expandFolder is dispatched when chevron is clicked while collapsed', () => {
		setupSelect( {
			folder: makeFolder( { id: 1, has_children: true } ),
			isExpanded: false,
		} );
		render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByLabelText( 'Expand' ) );
		expect( mockExpandFolder ).toHaveBeenCalledWith( 1 );
	} );

	it( 'collapseFolder is dispatched when chevron is clicked while expanded', () => {
		setupSelect( {
			folder: makeFolder( { id: 1, has_children: true } ),
			isExpanded: true,
			children: [],
		} );
		render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByLabelText( 'Collapse' ) );
		expect( mockCollapseFolder ).toHaveBeenCalledWith( 1 );
	} );

	it( 'shows spinner while fetching with no children loaded', () => {
		setupSelect( {
			folder: makeFolder( { id: 1, has_children: true } ),
			isExpanded: true,
			isFetching: true,
			children: [],
		} );
		render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByTestId( 'spinner' ) ).toBeInTheDocument();
	} );

	it( 'renders child FolderItems when expanded with loaded children', () => {
		const child = makeFolder( {
			id: 2,
			name: 'Subfolder',
			parent_id: 1,
			has_children: false,
		} );
		// Two-level mock: parent first, then child renders use childrenById.
		useSelect.mockImplementation( ( fn ) =>
			fn( () => ( {
				getFolderById: ( id ) => {
					if ( id === 1 ) {
						return makeFolder( { id: 1, has_children: true } );
					}
					if ( id === 2 ) {
						return child;
					}
					return undefined;
				},
				isFolderExpanded: ( id ) => id === 1,
				isFolderFetching: () => false,
				getChildrenOf: ( id ) => ( id === 1 ? [ child ] : [] ),
			} ) )
		);
		render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByText( 'Subfolder' ) ).toBeInTheDocument();
	} );

	it( 'renders color dot when folder has color', () => {
		setupSelect( { folder: makeFolder( { color: '#ff0000' } ) } );
		const { container } = render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect(
			container.querySelector( '.foldsnap-folder-item__color-dot' )
		).toBeInTheDocument();
	} );

	it( 'renders total_media_count badge', () => {
		setupSelect( { folder: makeFolder( { total_media_count: 12 } ) } );
		render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByText( '12' ) ).toBeInTheDocument();
	} );

	it( 'renders size label when total_size > 0', () => {
		setupSelect( { folder: makeFolder( { total_size: 2048 } ) } );
		render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByText( '2.0 KB' ) ).toBeInTheDocument();
	} );

	it( 'fires onAddSubfolder from dropdown', () => {
		const onAddSubfolder = jest.fn();
		setupSelect( { folder: makeFolder( { id: 5 } ) } );
		render(
			<FolderItem
				folderId={ 5 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ onAddSubfolder }
			/>
		);
		fireEvent.click( screen.getByText( 'Add subfolder' ) );
		expect( onAddSubfolder ).toHaveBeenCalledWith( 5 );
	} );

	it( 'shows confirmation modal then calls deleteFolder on confirm', () => {
		setupSelect( { folder: makeFolder( { id: 7 } ) } );
		render(
			<FolderItem
				folderId={ 7 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByText( 'Delete' ) );
		expect( screen.getByTestId( 'confirm-modal' ) ).toBeInTheDocument();
		const deleteButtons = screen.getAllByText( 'Delete' );
		fireEvent.click( deleteButtons[ deleteButtons.length - 1 ] );
		expect( mockDeleteFolder ).toHaveBeenCalledWith( 7 );
	} );

	it( 'cancel closes modal without deleting', () => {
		setupSelect( { folder: makeFolder() } );
		render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByText( 'Delete' ) );
		fireEvent.click( screen.getByText( 'Cancel' ) );
		expect(
			screen.queryByTestId( 'confirm-modal' )
		).not.toBeInTheDocument();
		expect( mockDeleteFolder ).not.toHaveBeenCalled();
	} );
} );
