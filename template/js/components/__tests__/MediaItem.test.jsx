import { render, screen, fireEvent } from '@testing-library/react';
import MediaItem from '../MediaItem';
import formatSize from '../../utils/format-size';

// Mock @dnd-kit/core
let mockIsDragging = false;
jest.mock( '@dnd-kit/core', () => ( {
	useDraggable: ( { id, data } ) => ( {
		attributes: { 'data-draggable-id': id },
		listeners: {},
		setNodeRef: jest.fn(),
		isDragging: mockIsDragging,
		data,
	} ),
} ) );

const makeMedia = ( overrides = {} ) => ( {
	id: 1,
	title: 'Beach Photo',
	filename: 'beach.jpg',
	thumbnail_url: 'https://example.com/beach-thumb.jpg',
	url: 'https://example.com/beach.jpg',
	file_size: 204800,
	mime_type: 'image/jpeg',
	date: '2026-01-15',
	...overrides,
} );

describe( 'formatSize', () => {
	it( 'formats bytes', () => {
		expect( formatSize( 500 ) ).toBe( '500 B' );
	} );

	it( 'formats kilobytes', () => {
		expect( formatSize( 2048 ) ).toBe( '2.0 KB' );
	} );

	it( 'formats megabytes', () => {
		expect( formatSize( 3 * 1024 * 1024 ) ).toBe( '3.0 MB' );
	} );
} );

describe( 'MediaItem', () => {
	beforeEach( () => {
		mockIsDragging = false;
	} );

	it( 'renders the media title', () => {
		render(
			<MediaItem
				media={ makeMedia() }
				isSelected={ false }
				onSelect={ jest.fn() }
				selectedIds={ [] }
			/>
		);
		expect( screen.getByText( 'Beach Photo' ) ).toBeInTheDocument();
	} );

	it( 'renders the filename when title is empty', () => {
		render(
			<MediaItem
				media={ makeMedia( { title: '', filename: 'doc.pdf' } ) }
				isSelected={ false }
				onSelect={ jest.fn() }
				selectedIds={ [] }
			/>
		);
		expect( screen.getByText( 'doc.pdf' ) ).toBeInTheDocument();
	} );

	it( 'renders file size', () => {
		render(
			<MediaItem
				media={ makeMedia( { file_size: 1024 } ) }
				isSelected={ false }
				onSelect={ jest.fn() }
				selectedIds={ [] }
			/>
		);
		expect( screen.getByText( '1.0 KB' ) ).toBeInTheDocument();
	} );

	it( 'does not render file size when it is 0', () => {
		const { container } = render(
			<MediaItem
				media={ makeMedia( { file_size: 0 } ) }
				isSelected={ false }
				onSelect={ jest.fn() }
				selectedIds={ [] }
			/>
		);
		expect(
			container.querySelector( '.foldsnap-media-item__size' )
		).not.toBeInTheDocument();
	} );

	it( 'renders thumbnail for images', () => {
		render(
			<MediaItem
				media={ makeMedia() }
				isSelected={ false }
				onSelect={ jest.fn() }
				selectedIds={ [] }
			/>
		);
		const img = screen.getByAltText( 'Beach Photo' );
		expect( img ).toBeInTheDocument();
		expect( img.src ).toBe( 'https://example.com/beach-thumb.jpg' );
	} );

	it( 'renders icon for non-image types', () => {
		const { container } = render(
			<MediaItem
				media={ makeMedia( {
					mime_type: 'application/pdf',
					thumbnail_url: '',
				} ) }
				isSelected={ false }
				onSelect={ jest.fn() }
				selectedIds={ [] }
			/>
		);
		expect(
			container.querySelector( '.dashicons-media-default' )
		).toBeInTheDocument();
	} );

	it( 'applies selected class when isSelected is true', () => {
		const { container } = render(
			<MediaItem
				media={ makeMedia() }
				isSelected={ true }
				onSelect={ jest.fn() }
				selectedIds={ [ 1 ] }
			/>
		);
		expect(
			container.querySelector( '.foldsnap-media-item--selected' )
		).toBeInTheDocument();
	} );

	it( 'does not apply selected class when isSelected is false', () => {
		const { container } = render(
			<MediaItem
				media={ makeMedia() }
				isSelected={ false }
				onSelect={ jest.fn() }
				selectedIds={ [] }
			/>
		);
		expect(
			container.querySelector( '.foldsnap-media-item--selected' )
		).not.toBeInTheDocument();
	} );

	it( 'calls onSelect with media id on click', () => {
		const onSelect = jest.fn();
		render(
			<MediaItem
				media={ makeMedia( { id: 42 } ) }
				isSelected={ false }
				onSelect={ onSelect }
				selectedIds={ [] }
			/>
		);
		fireEvent.click( screen.getByText( 'Beach Photo' ) );
		expect( onSelect ).toHaveBeenCalledWith( 42, {
			shift: false,
			ctrl: false,
		} );
	} );

	it( 'passes shift modifier on shift-click', () => {
		const onSelect = jest.fn();
		render(
			<MediaItem
				media={ makeMedia( { id: 7 } ) }
				isSelected={ false }
				onSelect={ onSelect }
				selectedIds={ [] }
			/>
		);
		fireEvent.click( screen.getByText( 'Beach Photo' ), {
			shiftKey: true,
		} );
		expect( onSelect ).toHaveBeenCalledWith( 7, {
			shift: true,
			ctrl: false,
		} );
	} );

	it( 'passes ctrl modifier on ctrl-click', () => {
		const onSelect = jest.fn();
		render(
			<MediaItem
				media={ makeMedia( { id: 3 } ) }
				isSelected={ false }
				onSelect={ onSelect }
				selectedIds={ [] }
			/>
		);
		fireEvent.click( screen.getByText( 'Beach Photo' ), {
			ctrlKey: true,
		} );
		expect( onSelect ).toHaveBeenCalledWith( 3, {
			shift: false,
			ctrl: true,
		} );
	} );

	it( 'calls onSelect on Enter key press', () => {
		const onSelect = jest.fn();
		render(
			<MediaItem
				media={ makeMedia( { id: 5 } ) }
				isSelected={ false }
				onSelect={ onSelect }
				selectedIds={ [] }
			/>
		);
		fireEvent.keyDown( screen.getByRole( 'button' ), {
			key: 'Enter',
		} );
		expect( onSelect ).toHaveBeenCalledWith( 5, {
			shift: false,
			ctrl: false,
		} );
	} );

	it( 'has draggable attributes', () => {
		render(
			<MediaItem
				media={ makeMedia( { id: 10 } ) }
				isSelected={ false }
				onSelect={ jest.fn() }
				selectedIds={ [] }
			/>
		);
		const item = screen.getByRole( 'button' );
		expect( item ).toHaveAttribute( 'data-draggable-id', 'media-10' );
	} );
} );
