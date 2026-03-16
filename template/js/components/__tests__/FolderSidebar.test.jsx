import { render, screen } from '@testing-library/react';
import { useDispatch } from '@wordpress/data';
import FolderSidebar from '../FolderSidebar';

// Mock @wordpress/data
jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
} ) );

// Mock FolderTree to isolate FolderSidebar
jest.mock( '../FolderTree', () => {
	const MockFolderTree = () => (
		<div data-testid="folder-tree">FolderTree</div>
	);
	return MockFolderTree;
} );

// Mock @dnd-kit/core
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

describe( 'FolderSidebar', () => {
	let mockUpdateFolder;

	beforeEach( () => {
		mockUpdateFolder = jest.fn();
		useDispatch.mockReturnValue( {
			updateFolder: mockUpdateFolder,
		} );
		mockOnDragEnd.mockClear();
	} );

	it( 'renders FolderTree inside a DndContext', () => {
		render( <FolderSidebar /> );
		expect( screen.getByTestId( 'dnd-context' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'folder-tree' ) ).toBeInTheDocument();
	} );

	it( 'renders the sidebar container div', () => {
		const { container } = render( <FolderSidebar /> );
		expect(
			container.querySelector( '.foldsnap-sidebar' )
		).toBeInTheDocument();
	} );

	it( 'does not render a content area', () => {
		const { container } = render( <FolderSidebar /> );
		expect(
			container.querySelector( '.foldsnap-content' )
		).not.toBeInTheDocument();
	} );

	it( 'does nothing on drag end when there is no over target', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( { active: { id: 1 }, over: null } );
		expect( mockUpdateFolder ).not.toHaveBeenCalled();
	} );

	it( 'does nothing on drag end when active and over are the same', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: { id: 1, data: { current: { type: 'folder' } } },
			over: { id: 1, data: { current: { type: 'folder' } } },
		} );
		expect( mockUpdateFolder ).not.toHaveBeenCalled();
	} );

	it( 'calls updateFolder with new parentId on folder reparent drop', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: {
				id: 10,
				data: {
					current: {
						type: 'folder',
						folder: { id: 10, name: 'Photos' },
					},
				},
			},
			over: {
				id: 'folder-drop-5',
				data: { current: { type: 'folder', folderId: 5 } },
			},
		} );
		expect( mockUpdateFolder ).toHaveBeenCalledWith( 10, {
			name: 'Photos',
			parentId: 5,
		} );
	} );

	it( 'does not reparent when dropping folder onto itself', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: {
				id: 5,
				data: {
					current: {
						type: 'folder',
						folder: { id: 5, name: 'Photos' },
					},
				},
			},
			over: {
				id: 'folder-drop-5',
				data: { current: { type: 'folder', folderId: 5 } },
			},
		} );
		expect( mockUpdateFolder ).not.toHaveBeenCalled();
	} );

	it( 'calls updateFolder with new position on folder reorder drop', () => {
		render( <FolderSidebar /> );
		mockOnDragEnd( {
			active: {
				id: 10,
				data: {
					current: {
						type: 'folder',
						folder: { id: 10, name: 'Photos' },
					},
				},
			},
			over: {
				id: 20,
				data: {
					current: {
						type: 'folder',
						folder: { id: 20, name: 'Documents', position: 3 },
					},
				},
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
