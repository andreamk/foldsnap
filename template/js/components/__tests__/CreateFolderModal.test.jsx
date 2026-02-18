import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { useDispatch } from '@wordpress/data';
import CreateFolderModal, { flattenFolders } from '../CreateFolderModal';

// Mock @wordpress/data
jest.mock( '@wordpress/data', () => ( {
	useDispatch: jest.fn(),
} ) );

// Mock @wordpress/components
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
	SelectControl: ( { label, value, options, onChange } ) => (
		<div>
			<label htmlFor="parent-select">{ label }</label>
			<select
				id="parent-select"
				data-testid="parent-select"
				value={ value }
				onChange={ ( e ) => onChange( e.target.value ) }
			>
				{ options.map( ( opt ) => (
					<option key={ opt.value } value={ opt.value }>
						{ opt.label }
					</option>
				) ) }
			</select>
		</div>
	),
	Button: ( { children, onClick, disabled } ) => (
		<button onClick={ onClick } disabled={ disabled }>
			{ children }
		</button>
	),
} ) );

const FOLDERS = [
	{
		id: 1,
		name: 'Photos',
		children: [ { id: 3, name: 'Vacation', children: [] } ],
	},
	{ id: 2, name: 'Documents', children: [] },
];

describe( 'flattenFolders', () => {
	it( 'flattens a simple list', () => {
		const result = flattenFolders( [
			{ id: 1, name: 'A', children: [] },
			{ id: 2, name: 'B', children: [] },
		] );
		expect( result ).toHaveLength( 2 );
		expect( result[ 0 ].value ).toBe( '1' );
		expect( result[ 1 ].value ).toBe( '2' );
	} );

	it( 'indents nested folders with dashes', () => {
		const result = flattenFolders( [
			{
				id: 1,
				name: 'Parent',
				children: [ { id: 2, name: 'Child', children: [] } ],
			},
		] );
		expect( result ).toHaveLength( 2 );
		expect( result[ 0 ].label ).toBe( 'Parent' );
		expect( result[ 1 ].label ).toContain( 'Child' );
		expect( result[ 1 ].label ).toContain( '—' );
	} );
} );

describe( 'CreateFolderModal', () => {
	let mockCreateFolder;
	let mockOnClose;

	beforeEach( () => {
		mockCreateFolder = jest.fn().mockResolvedValue( undefined );
		mockOnClose = jest.fn();
		useDispatch.mockReturnValue( { createFolder: mockCreateFolder } );
	} );

	it( 'renders the modal with title', () => {
		render(
			<CreateFolderModal
				parentId={ 0 }
				folders={ [] }
				onClose={ mockOnClose }
			/>
		);
		expect( screen.getByTestId( 'modal-title' ) ).toHaveTextContent(
			'New Folder'
		);
	} );

	it( 'renders name input and parent select', () => {
		render(
			<CreateFolderModal
				parentId={ 0 }
				folders={ [] }
				onClose={ mockOnClose }
			/>
		);
		expect( screen.getByTestId( 'name-input' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'parent-select' ) ).toBeInTheDocument();
	} );

	it( 'pre-selects the given parentId', () => {
		render(
			<CreateFolderModal
				parentId={ 1 }
				folders={ FOLDERS }
				onClose={ mockOnClose }
			/>
		);
		expect( screen.getByTestId( 'parent-select' ).value ).toBe( '1' );
	} );

	it( 'Create button is disabled when name is empty', () => {
		render(
			<CreateFolderModal
				parentId={ 0 }
				folders={ [] }
				onClose={ mockOnClose }
			/>
		);
		expect( screen.getByText( 'Create' ) ).toBeDisabled();
	} );

	it( 'Create button is enabled after typing a name', () => {
		render(
			<CreateFolderModal
				parentId={ 0 }
				folders={ [] }
				onClose={ mockOnClose }
			/>
		);
		fireEvent.change( screen.getByTestId( 'name-input' ), {
			target: { value: 'My Folder' },
		} );
		expect( screen.getByText( 'Create' ) ).not.toBeDisabled();
	} );

	it( 'calls createFolder and onClose when Create is clicked', async () => {
		render(
			<CreateFolderModal
				parentId={ 0 }
				folders={ [] }
				onClose={ mockOnClose }
			/>
		);
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

	it( 'calls onClose when Cancel is clicked', () => {
		render(
			<CreateFolderModal
				parentId={ 0 }
				folders={ [] }
				onClose={ mockOnClose }
			/>
		);
		fireEvent.click( screen.getByText( 'Cancel' ) );
		expect( mockOnClose ).toHaveBeenCalled();
	} );

	it( 'calls createFolder on Enter key in name input', async () => {
		render(
			<CreateFolderModal
				parentId={ 0 }
				folders={ [] }
				onClose={ mockOnClose }
			/>
		);
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

	it( 'does not call createFolder when name is only whitespace', async () => {
		render(
			<CreateFolderModal
				parentId={ 0 }
				folders={ [] }
				onClose={ mockOnClose }
			/>
		);
		fireEvent.change( screen.getByTestId( 'name-input' ), {
			target: { value: '   ' },
		} );
		fireEvent.click( screen.getByText( 'Create' ) );
		// Create button is disabled for whitespace, but even if triggered, should not call
		expect( mockCreateFolder ).not.toHaveBeenCalled();
	} );

	it( 'includes all folders in parent select options', () => {
		render(
			<CreateFolderModal
				parentId={ 0 }
				folders={ FOLDERS }
				onClose={ mockOnClose }
			/>
		);
		const select = screen.getByTestId( 'parent-select' );
		// Root option + Photos + Vacation (child) + Documents = 4
		expect( select.options ).toHaveLength( 4 );
	} );
} );
