import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
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
		setActivatorNodeRef: jest.fn(),
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
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
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
	TextControl: ( { label, value, onChange, onKeyDown } ) => (
		<input
			aria-label={ label }
			value={ value }
			onChange={ ( e ) => onChange( e.target.value ) }
			onKeyDown={ onKeyDown }
		/>
	),
	Spinner: () => <div data-testid="spinner" />,
	Icon: ( { icon } ) => <span data-testid={ `icon-${ icon }` } />,
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
	let mockUpdateFolder;
	let user;

	beforeEach( () => {
		mockDeleteFolder = jest.fn();
		mockExpandFolder = jest.fn();
		mockCollapseFolder = jest.fn();
		mockUpdateFolder = jest.fn();
		useDispatch.mockReturnValue( {
			deleteFolder: mockDeleteFolder,
			expandFolder: mockExpandFolder,
			collapseFolder: mockCollapseFolder,
			updateFolder: mockUpdateFolder,
		} );
		user = userEvent.setup();
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

	it( 'fires onSelect when row is clicked', async () => {
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
		await user.click( screen.getByText( 'Photos' ) );
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

	it( 'expandFolder is dispatched when chevron is clicked while collapsed', async () => {
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
		await user.click( screen.getByLabelText( 'Expand' ) );
		expect( mockExpandFolder ).toHaveBeenCalledWith( 1 );
	} );

	it( 'collapseFolder is dispatched when chevron is clicked while expanded', async () => {
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
		await user.click( screen.getByLabelText( 'Collapse' ) );
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

	it( 'fires onAddSubfolder from dropdown', async () => {
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
		await user.click( screen.getByText( 'Add subfolder' ) );
		expect( onAddSubfolder ).toHaveBeenCalledWith( 5 );
	} );

	it( 'shows confirmation modal then calls deleteFolder on confirm', async () => {
		setupSelect( { folder: makeFolder( { id: 7 } ) } );
		render(
			<FolderItem
				folderId={ 7 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		await user.click( screen.getByText( 'Delete' ) );
		expect( screen.getByTestId( 'confirm-modal' ) ).toBeInTheDocument();
		const deleteButtons = screen.getAllByText( 'Delete' );
		await user.click( deleteButtons[ deleteButtons.length - 1 ] );
		expect( mockDeleteFolder ).toHaveBeenCalledWith( 7 );
	} );

	it( 'cancel closes modal without deleting', async () => {
		setupSelect( { folder: makeFolder() } );
		render(
			<FolderItem
				folderId={ 1 }
				selectedFolderId={ null }
				onSelect={ jest.fn() }
				onAddSubfolder={ jest.fn() }
			/>
		);
		await user.click( screen.getByText( 'Delete' ) );
		await user.click( screen.getByText( 'Cancel' ) );
		expect(
			screen.queryByTestId( 'confirm-modal' )
		).not.toBeInTheDocument();
		expect( mockDeleteFolder ).not.toHaveBeenCalled();
	} );

	describe( 'rename modal', () => {
		const renderItem = () =>
			render(
				<FolderItem
					folderId={ 1 }
					selectedFolderId={ null }
					onSelect={ jest.fn() }
					onAddSubfolder={ jest.fn() }
				/>
			);

		it( 'opens modal pre-filled with the current folder name', async () => {
			setupSelect( {
				folder: makeFolder( { id: 1, name: 'Photos' } ),
			} );
			renderItem();
			await user.click( screen.getByText( 'Rename' ) );
			const input = screen.getByLabelText( 'Folder name' );
			expect( input ).toHaveValue( 'Photos' );
		} );

		it( 'submits trimmed value via updateFolder', async () => {
			setupSelect( {
				folder: makeFolder( { id: 1, name: 'Photos' } ),
			} );
			renderItem();
			await user.click( screen.getByText( 'Rename' ) );
			const input = screen.getByLabelText( 'Folder name' );
			await user.clear( input );
			await user.type( input, '  Travel  ' );
			// The last "Rename" button in the DOM is the modal's submit button.
			const buttons = screen.getAllByText( 'Rename' );
			await user.click( buttons[ buttons.length - 1 ] );
			expect( mockUpdateFolder ).toHaveBeenCalledWith( 1, {
				name: 'Travel',
			} );
			expect(
				screen.queryByLabelText( 'Folder name' )
			).not.toBeInTheDocument();
		} );

		it( 'cancel closes modal without dispatching', async () => {
			setupSelect( {
				folder: makeFolder( { id: 1, name: 'Photos' } ),
			} );
			renderItem();
			await user.click( screen.getByText( 'Rename' ) );
			await user.click( screen.getByText( 'Cancel' ) );
			expect(
				screen.queryByLabelText( 'Folder name' )
			).not.toBeInTheDocument();
			expect( mockUpdateFolder ).not.toHaveBeenCalled();
		} );

		it( 'does not call updateFolder when the value is blank', async () => {
			setupSelect( {
				folder: makeFolder( { id: 1, name: 'Photos' } ),
			} );
			renderItem();
			await user.click( screen.getByText( 'Rename' ) );
			const input = screen.getByLabelText( 'Folder name' );
			await user.clear( input );
			await user.type( input, '   ' );
			// Submit button is disabled, so click the unfocused-but-present last button.
			const buttons = screen.getAllByText( 'Rename' );
			const submit = buttons[ buttons.length - 1 ];
			expect( submit ).toBeDisabled();
			expect( mockUpdateFolder ).not.toHaveBeenCalled();
		} );

		it( 'does not call updateFolder when the value is unchanged', async () => {
			setupSelect( {
				folder: makeFolder( { id: 1, name: 'Photos' } ),
			} );
			renderItem();
			await user.click( screen.getByText( 'Rename' ) );
			const buttons = screen.getAllByText( 'Rename' );
			const submit = buttons[ buttons.length - 1 ];
			expect( submit ).toBeDisabled();
			const input = screen.getByLabelText( 'Folder name' );
			await user.type( input, '{Enter}' );
			expect( mockUpdateFolder ).not.toHaveBeenCalled();
			expect(
				screen.queryByLabelText( 'Folder name' )
			).not.toBeInTheDocument();
		} );

		it( 'submits when Enter is pressed in the input', async () => {
			setupSelect( {
				folder: makeFolder( { id: 1, name: 'Photos' } ),
			} );
			renderItem();
			await user.click( screen.getByText( 'Rename' ) );
			const input = screen.getByLabelText( 'Folder name' );
			await user.clear( input );
			await user.type( input, 'Travel{Enter}' );
			expect( mockUpdateFolder ).toHaveBeenCalledWith( 1, {
				name: 'Travel',
			} );
			expect(
				screen.queryByLabelText( 'Folder name' )
			).not.toBeInTheDocument();
		} );
	} );

	describe( 'when rendering the virtual Root folder', () => {
		const rootFolder = {
			id: 0,
			name: 'Root',
			parent_id: 0,
			color: '',
			total_media_count: 50,
			total_size: 0,
			has_children: true,
			is_root: true,
		};

		it( 'shows the home icon', () => {
			setupSelect( { folder: rootFolder } );
			render(
				<FolderItem
					folderId={ 0 }
					selectedFolderId={ null }
					onSelect={ jest.fn() }
					onAddSubfolder={ jest.fn() }
				/>
			);
			expect(
				screen.getByTestId( 'icon-admin-home' )
			).toBeInTheDocument();
		} );

		it( 'omits the Delete option from the dropdown', () => {
			setupSelect( { folder: rootFolder } );
			render(
				<FolderItem
					folderId={ 0 }
					selectedFolderId={ null }
					onSelect={ jest.fn() }
					onAddSubfolder={ jest.fn() }
				/>
			);
			expect( screen.queryByText( 'Delete' ) ).not.toBeInTheDocument();
			expect( screen.getByText( 'Add subfolder' ) ).toBeInTheDocument();
		} );

		it( 'omits the Rename option from the dropdown', () => {
			setupSelect( { folder: rootFolder } );
			render(
				<FolderItem
					folderId={ 0 }
					selectedFolderId={ null }
					onSelect={ jest.fn() }
					onAddSubfolder={ jest.fn() }
				/>
			);
			expect( screen.queryByText( 'Rename' ) ).not.toBeInTheDocument();
		} );

		it( 'does not render the drag handle', () => {
			setupSelect( { folder: rootFolder } );
			const { container } = render(
				<FolderItem
					folderId={ 0 }
					selectedFolderId={ null }
					onSelect={ jest.fn() }
					onAddSubfolder={ jest.fn() }
				/>
			);
			expect(
				container.querySelector( '.foldsnap-folder-item__drag-handle' )
			).not.toBeInTheDocument();
		} );
	} );
} );
