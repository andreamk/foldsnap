import { render, screen, fireEvent } from '@testing-library/react';
import { useDispatch, useSelect } from '@wordpress/data';
import FolderSidebar from '../FolderSidebar';

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/components', () => ( {
	ToggleControl: ( { label, checked, onChange } ) => (
		<div data-testid="all-media-toggle">
			<input
				type="checkbox"
				aria-label={ label }
				checked={ checked }
				onChange={ ( e ) => onChange( e.target.checked ) }
			/>
		</div>
	),
} ) );

jest.mock( '../FolderTree', () => {
	const MockFolderTree = () => (
		<div data-testid="folder-tree">FolderTree</div>
	);
	return MockFolderTree;
} );

const mockOnDragEnd = jest.fn();
jest.mock( '@dnd-kit/core', () => ( {
	DndContext: ( { children, onDragEnd } ) => {
		mockOnDragEnd.mockImplementation( onDragEnd );
		return <div data-testid="dnd-context">{ children }</div>;
	},
	PointerSensor: jest.fn(),
	useSensor: jest.fn(),
	useSensors: jest.fn( () => [] ),
} ) );

const FOLDER_BY_ID = {
	10: { id: 10, name: 'Photos', position: 0 },
	20: { id: 20, name: 'Documents', position: 3 },
};

const setupSelect = ( { allMediaActive = false } = {} ) => {
	useSelect.mockImplementation( ( fn ) =>
		fn( () => ( {
			getFolderById: ( id ) => FOLDER_BY_ID[ id ],
			isAllMediaActive: () => allMediaActive,
		} ) )
	);
};

describe( 'FolderSidebar', () => {
	let mockUpdateFolder;
	let mockSetAllMedia;

	beforeEach( () => {
		mockUpdateFolder = jest.fn();
		mockSetAllMedia = jest.fn();
		useDispatch.mockReturnValue( {
			updateFolder: mockUpdateFolder,
			setAllMedia: mockSetAllMedia,
		} );
		setupSelect();
		mockOnDragEnd.mockClear();
	} );

	it( 'renders FolderTree inside DndContext', () => {
		render( <FolderSidebar /> );
		expect( screen.getByTestId( 'dnd-context' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'folder-tree' ) ).toBeInTheDocument();
	} );

	it( 'renders the All Media toggle in the off state by default', () => {
		render( <FolderSidebar /> );
		const toggle = screen.getByTestId( 'all-media-toggle' );
		const input = toggle.querySelector( 'input' );
		expect( input.checked ).toBe( false );
	} );

	it( 'dispatches setAllMedia when the toggle is flipped', () => {
		render( <FolderSidebar /> );
		fireEvent.click(
			screen.getByTestId( 'all-media-toggle' ).querySelector( 'input' )
		);
		expect( mockSetAllMedia ).toHaveBeenCalledWith( true );
	} );

	it( 'applies the all-media modifier class when active', () => {
		setupSelect( { allMediaActive: true } );
		const { container } = render( <FolderSidebar /> );
		expect(
			container.querySelector( '.foldsnap-sidebar--all-media' )
		).toBeInTheDocument();
	} );

	it( 'no-ops on drag end when All Media is active', () => {
		setupSelect( { allMediaActive: true } );
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: {
				id: 10,
				data: { current: { type: 'folder', folderId: 10 } },
			},
			over: {
				id: 'folder-drop-20',
				data: { current: { type: 'folder', folderId: 20 } },
			},
		} );
		expect( mockUpdateFolder ).not.toHaveBeenCalled();
	} );

	it( 'no-ops when over is null', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( { active: { id: 10 }, over: null } );
		expect( mockUpdateFolder ).not.toHaveBeenCalled();
	} );

	it( 'no-ops when active and over are the same', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: {
				id: 10,
				data: { current: { type: 'folder', folderId: 10 } },
			},
			over: {
				id: 10,
				data: { current: { type: 'folder', folderId: 10 } },
			},
		} );
		expect( mockUpdateFolder ).not.toHaveBeenCalled();
	} );

	it( 'reparents on folder-drop drop zone', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: {
				id: 10,
				data: { current: { type: 'folder', folderId: 10 } },
			},
			over: {
				id: 'folder-drop-20',
				data: { current: { type: 'folder', folderId: 20 } },
			},
		} );
		expect( mockUpdateFolder ).toHaveBeenCalledWith( 10, {
			name: 'Photos',
			parentId: 20,
		} );
	} );

	it( 'does not reparent when target is the same folder', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: {
				id: 10,
				data: { current: { type: 'folder', folderId: 10 } },
			},
			over: {
				id: 'folder-drop-10',
				data: { current: { type: 'folder', folderId: 10 } },
			},
		} );
		expect( mockUpdateFolder ).not.toHaveBeenCalled();
	} );

	it( 'reorders by setting position to over folder position', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: {
				id: 10,
				data: { current: { type: 'folder', folderId: 10 } },
			},
			over: {
				id: 20,
				data: { current: { type: 'folder', folderId: 20 } },
			},
		} );
		expect( mockUpdateFolder ).toHaveBeenCalledWith( 10, {
			name: 'Photos',
			position: 3,
		} );
	} );

	it( 'ignores non-folder drag types', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: {
				id: 'media-99',
				data: { current: { type: 'media', mediaIds: [ 99 ] } },
			},
			over: {
				id: 5,
				data: { current: { type: 'folder', folderId: 5 } },
			},
		} );
		expect( mockUpdateFolder ).not.toHaveBeenCalled();
	} );
} );
