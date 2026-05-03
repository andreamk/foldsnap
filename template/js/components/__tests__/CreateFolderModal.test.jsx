import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { useDispatch } from '@wordpress/data';
import CreateFolderModal from '../CreateFolderModal';

jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
} ) );

jest.mock( '../FolderPicker', () => {
	const MockFolderPicker = ( { value, onChange } ) => (
		<div data-testid="folder-picker">
			<span data-testid="picker-value">{ value }</span>
			<button onClick={ () => onChange( 5 ) }>Pick 5</button>
		</div>
	);
	return MockFolderPicker;
} );

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

describe( 'CreateFolderModal', () => {
	let mockCreateFolder;
	let mockOnClose;

	beforeEach( () => {
		mockCreateFolder = jest.fn().mockResolvedValue( undefined );
		mockOnClose = jest.fn();
		useDispatch.mockReturnValue( { createFolder: mockCreateFolder } );
	} );

	it( 'renders the modal title', () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		expect( screen.getByTestId( 'modal-title' ) ).toHaveTextContent(
			'New Folder'
		);
	} );

	it( 'renders name input and lazy-loaded picker', () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		expect( screen.getByTestId( 'name-input' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'folder-picker' ) ).toBeInTheDocument();
	} );

	it( 'pre-selects the given parentId in the picker', () => {
		render( <CreateFolderModal parentId={ 7 } onClose={ mockOnClose } /> );
		expect( screen.getByTestId( 'picker-value' ) ).toHaveTextContent( '7' );
	} );

	it( 'disables Create when name is empty', () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		expect( screen.getByText( 'Create' ) ).toBeDisabled();
	} );

	it( 'enables Create after typing a name', () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		fireEvent.change( screen.getByTestId( 'name-input' ), {
			target: { value: 'My Folder' },
		} );
		expect( screen.getByText( 'Create' ) ).not.toBeDisabled();
	} );

	it( 'updates the parent on picker change', async () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		fireEvent.change( screen.getByTestId( 'name-input' ), {
			target: { value: 'Photos' },
		} );
		fireEvent.click( screen.getByText( 'Pick 5' ) );
		fireEvent.click( screen.getByText( 'Create' ) );
		await waitFor( () => {
			expect( mockCreateFolder ).toHaveBeenCalledWith( {
				name: 'Photos',
				parentId: 5,
			} );
		} );
	} );

	it( 'creates and closes on submit', async () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		fireEvent.change( screen.getByTestId( 'name-input' ), {
			target: { value: 'New Folder Name' },
		} );
		fireEvent.click( screen.getByText( 'Create' ) );
		await waitFor( () => {
			expect( mockCreateFolder ).toHaveBeenCalledWith( {
				name: 'New Folder Name',
				parentId: 0,
			} );
			expect( mockOnClose ).toHaveBeenCalled();
		} );
	} );

	it( 'closes via Cancel without creating', () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		fireEvent.click( screen.getByText( 'Cancel' ) );
		expect( mockOnClose ).toHaveBeenCalled();
		expect( mockCreateFolder ).not.toHaveBeenCalled();
	} );

	it( 'submits on Enter key in name input', async () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		fireEvent.change( screen.getByTestId( 'name-input' ), {
			target: { value: 'Enter Folder' },
		} );
		fireEvent.keyDown( screen.getByTestId( 'name-input' ), {
			key: 'Enter',
		} );
		await waitFor( () => {
			expect( mockCreateFolder ).toHaveBeenCalledWith( {
				name: 'Enter Folder',
				parentId: 0,
			} );
		} );
	} );

	it( 'does not submit when name is whitespace only', () => {
		render( <CreateFolderModal parentId={ 0 } onClose={ mockOnClose } /> );
		fireEvent.change( screen.getByTestId( 'name-input' ), {
			target: { value: '   ' },
		} );
		fireEvent.click( screen.getByText( 'Create' ) );
		expect( mockCreateFolder ).not.toHaveBeenCalled();
	} );
} );
