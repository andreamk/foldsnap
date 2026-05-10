import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useDispatch, useSelect } from '@wordpress/data';
import CreateFolderModal from '../CreateFolderModal';

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

jest.mock( '@wordpress/components', () => ( {
	Modal: ( { title, children, onRequestClose } ) => (
		<div data-testid="modal">
			<div data-testid="modal-title">{ title }</div>
			<button data-testid="modal-close" onClick={ onRequestClose }>
				Close
			</button>
			{ children }
		</div>
	),
	TextControl: ( { label, value, onChange, onKeyDown } ) => (
		<div>
			<label htmlFor="name-input">{ label }</label>
			<input
				id="name-input"
				data-testid="name-input"
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
				onKeyDown={ onKeyDown }
			/>
		</div>
	),
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
} ) );

const FOLDER_BY_ID = {
	0: { id: 0, name: 'Root' },
	7: { id: 7, name: 'Photos' },
};

const mockSelect = ( folders = FOLDER_BY_ID ) => {
	useSelect.mockImplementation( ( fn ) =>
		fn( () => ( {
			getFolderById: ( id ) => folders[ id ],
		} ) )
	);
};

describe( 'CreateFolderModal', () => {
	let mockCreateFolder;
	let mockOnClose;
	let user;

	beforeEach( () => {
		mockCreateFolder = jest.fn().mockResolvedValue( undefined );
		mockOnClose = jest.fn();
		useDispatch.mockReturnValue( { createFolder: mockCreateFolder } );
		mockSelect();
		user = userEvent.setup();
	} );

	it( 'shows the parent name in the title', () => {
		render( <CreateFolderModal parentId={ 7 } onClose={ mockOnClose } /> );
		expect( screen.getByTestId( 'modal-title' ) ).toHaveTextContent(
			'New folder in “Photos”'
		);
	} );

	it( 'falls back to a generic title when the parent is unknown', () => {
		mockSelect( {} );
		render( <CreateFolderModal parentId={ 99 } onClose={ mockOnClose } /> );
		expect( screen.getByTestId( 'modal-title' ) ).toHaveTextContent(
			'New folder'
		);
	} );

	it( 'renders the name input only (no parent picker)', () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		expect( screen.getByTestId( 'name-input' ) ).toBeInTheDocument();
		expect(
			screen.queryByTestId( 'folder-picker' )
		).not.toBeInTheDocument();
	} );

	it( 'disables Create when name is empty', () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		expect( screen.getByText( 'Create' ) ).toBeDisabled();
	} );

	it( 'enables Create after typing a name', async () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		await user.type( screen.getByTestId( 'name-input' ), 'My Folder' );
		expect( screen.getByText( 'Create' ) ).not.toBeDisabled();
	} );

	it( 'creates with the fixed parentId on submit', async () => {
		render( <CreateFolderModal parentId={ 7 } onClose={ mockOnClose } /> );
		await user.type( screen.getByTestId( 'name-input' ), 'Vacation' );
		await user.click( screen.getByText( 'Create' ) );
		await waitFor( () => {
			expect( mockCreateFolder ).toHaveBeenCalledWith( {
				name: 'Vacation',
				parentId: 7,
			} );
			expect( mockOnClose ).toHaveBeenCalled();
		} );
	} );

	it( 'closes via Cancel without creating', async () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		await user.click( screen.getByText( 'Cancel' ) );
		expect( mockOnClose ).toHaveBeenCalled();
		expect( mockCreateFolder ).not.toHaveBeenCalled();
	} );

	it( 'submits on Enter key in name input', async () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		const input = screen.getByTestId( 'name-input' );
		await user.type( input, 'Enter Folder{Enter}' );
		await waitFor( () => {
			expect( mockCreateFolder ).toHaveBeenCalledWith( {
				name: 'Enter Folder',
				parentId: 0,
			} );
		} );
	} );

	it( 'does not submit when name is whitespace only', async () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		await user.type( screen.getByTestId( 'name-input' ), '   ' );
		await user.click( screen.getByText( 'Create' ) );
		expect( mockCreateFolder ).not.toHaveBeenCalled();
	} );
} );
