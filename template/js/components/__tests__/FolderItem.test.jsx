import { render, screen, fireEvent } from '@testing-library/react';
import { useDispatch } from '@wordpress/data';
import FolderItem, { formatSize } from '../FolderItem';

// Mock @wordpress/data
jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
} ) );

// Mock @dnd-kit/sortable
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

// Mock @dnd-kit/core
jest.mock( '@dnd-kit/core', () => ( {
	useDroppable: () => ( {
		isOver: false,
		setNodeRef: jest.fn(),
	} ),
} ) );

// Mock @dnd-kit/utilities
jest.mock( '@dnd-kit/utilities', () => ( {
	CSS: {
		Transform: {
			toString: () => '',
		},
	},
} ) );

// Mock @wordpress/components DropdownMenu
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
} ) );

const makeFolder = ( overrides = {} ) => ( {
	id: 1,
	name: 'Photos',
	color: '',
	total_media_count: 5,
	total_size: 1024,
	children: [],
	...overrides,
} );

describe( 'formatSize', () => {
	it( 'formats bytes', () => {
		expect( formatSize( 500 ) ).toBe( '500 B' );
	} );

	it( 'formats kilobytes', () => {
		expect( formatSize( 1024 ) ).toBe( '1.0 KB' );
	} );

	it( 'formats megabytes', () => {
		expect( formatSize( 1024 * 1024 ) ).toBe( '1.0 MB' );
	} );
} );

describe( 'FolderItem', () => {
	let mockDeleteFolder;

	beforeEach( () => {
		mockDeleteFolder = jest.fn();
		useDispatch.mockReturnValue( { deleteFolder: mockDeleteFolder } );
	} );

	it( 'renders the folder name', () => {
		render(
			<FolderItem
				folder={ makeFolder() }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByText( 'Photos' ) ).toBeInTheDocument();
	} );

	it( 'calls onSelect when clicked', () => {
		const onSelect = jest.fn();
		render(
			<FolderItem
				folder={ makeFolder( { id: 42 } ) }
				selectedFolderId={ null }
				onSelect={ onSelect }
				onAddSubfolder={ jest.fn() }
			/>
		);
		fireEvent.click( screen.getByText( 'Photos' ) );
		expect( onSelect ).toHaveBeenCalledWith( 42 );
	} );

	it( 'applies selected class when folder is selected', () => {
		const { container } = render(
			<FolderItem
				folder={ makeFolder( { id: 7 } ) }
				selectedFolderId={ 7 }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect(
			container.querySelector( '.foldsnap-folder-item--selected' )
		).toBeInTheDocument();
	} );

	it( 'does not apply selected class when folder is not selected', () => {
		const { container } = render(
			<FolderItem
				folder={ makeFolder( { id: 7 } ) }
				selectedFolderId={ 3 }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect(
			container.querySelector( '.foldsnap-folder-item--selected' )
		).not.toBeInTheDocument();
	} );

	it( 'renders color dot when folder has a color', () => {
		const { container } = render(
			<FolderItem
				folder={ makeFolder( { color: '#ff0000' } ) }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect(
			container.querySelector( '.foldsnap-folder-item__color-dot' )
		).toBeInTheDocument();
	} );

	it( 'does not render color dot when folder has no color', () => {
		const { container } = render(
			<FolderItem
				folder={ makeFolder( { color: '' } ) }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect(
			container.querySelector( '.foldsnap-folder-item__color-dot' )
		).not.toBeInTheDocument();
	} );

	it( 'renders media count badge', () => {
		render(
			<FolderItem
				folder={ makeFolder( { total_media_count: 12 } ) }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByText( '12' ) ).toBeInTheDocument();
	} );

	it( 'renders size label when total_size > 0', () => {
		render(
			<FolderItem
				folder={ makeFolder( { total_size: 2048 } ) }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByText( '2.0 KB' ) ).toBeInTheDocument();
	} );

	it( 'renders expand chevron when folder has children', () => {
		const folder = makeFolder( {
			children: [ { id: 2, name: 'Child', children: [] } ],
		} );
		render(
			<FolderItem
				folder={ folder }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		expect( screen.getByLabelText( 'Collapse' ) ).toBeInTheDocument();
	} );

	it( 'toggles children visibility on chevron click', () => {
		const folder = makeFolder( {
			children: [ { id: 2, name: 'Child Folder', children: [] } ],
		} );

		// Children visible after mock
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

		render(
			<FolderItem
				folder={ folder }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		// Child should be visible initially (isExpanded = true)
		expect( screen.getByText( 'Child Folder' ) ).toBeInTheDocument();

		// Click chevron to collapse
		fireEvent.click( screen.getByLabelText( 'Collapse' ) );
		expect( screen.queryByText( 'Child Folder' ) ).not.toBeInTheDocument();
	} );

	it( 'calls onAddSubfolder when Add subfolder is clicked', () => {
		const onAddSubfolder = jest.fn();
		render(
			<FolderItem
				folder={ makeFolder( { id: 5 } ) }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ onAddSubfolder }
			/>
		);
		fireEvent.click( screen.getByText( 'Add subfolder' ) );
		expect( onAddSubfolder ).toHaveBeenCalledWith( 5 );
	} );
} );
