import { render, screen, fireEvent } from '@testing-library/react';
import { useSelect, useDispatch } from '@wordpress/data';
import MediaGrid from '../MediaGrid';

// Mock @wordpress/data
jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
	useDispatch: jest.fn(),
} ) );

// Mock MediaItem to isolate MediaGrid
jest.mock( '../MediaItem', () => {
	const MockMediaItem = ( { media, isSelected, onSelect } ) => (
		<div data-testid={ `media-item-${ media.id }` }>
			<span>{ media.title }</span>
			<button
				onClick={ ( e ) =>
					onSelect( media.id, {
						ctrl: e.ctrlKey || e.metaKey,
						shift: e.shiftKey,
					} )
				}
			>
				Select
			</button>
			{ isSelected && (
				<span data-testid="selected-marker">selected</span>
			) }
		</div>
	);
	return MockMediaItem;
} );

// Mock @wordpress/components
jest.mock( '@wordpress/components', () => ( {
	Spinner: () => <div data-testid="spinner" />,
} ) );

const MEDIA_ITEMS = [
	{
		id: 1,
		title: 'Beach',
		filename: 'beach.jpg',
		thumbnail_url: 'thumb1.jpg',
		url: 'beach.jpg',
		file_size: 1024,
		mime_type: 'image/jpeg',
		date: '2026-01-01',
	},
	{
		id: 2,
		title: 'Mountain',
		filename: 'mountain.jpg',
		thumbnail_url: 'thumb2.jpg',
		url: 'mountain.jpg',
		file_size: 2048,
		mime_type: 'image/jpeg',
		date: '2026-01-02',
	},
	{
		id: 3,
		title: 'Forest',
		filename: 'forest.jpg',
		thumbnail_url: 'thumb3.jpg',
		url: 'forest.jpg',
		file_size: 3072,
		mime_type: 'image/jpeg',
		date: '2026-01-03',
	},
];

const makeStoreState = ( overrides = {} ) => ( {
	media: MEDIA_ITEMS,
	mediaIsLoading: false,
	mediaTotal: 3,
	mediaTotalPages: 1,
	selectedFolderId: null,
	...overrides,
} );

describe( 'MediaGrid', () => {
	let mockFetchMedia;

	beforeEach( () => {
		mockFetchMedia = jest.fn();
		useDispatch.mockReturnValue( { fetchMedia: mockFetchMedia } );
	} );

	const setupUseSelect = ( storeState ) => {
		useSelect.mockImplementation( ( selector ) =>
			selector( () => ( {
				getMedia: () => storeState.media,
				isMediaLoading: () => storeState.mediaIsLoading,
				getMediaTotal: () => storeState.mediaTotal,
				getMediaTotalPages: () => storeState.mediaTotalPages,
				getSelectedFolderId: () => storeState.selectedFolderId,
			} ) )
		);
	};

	it( 'renders media items', () => {
		setupUseSelect( makeStoreState() );
		render( <MediaGrid /> );
		expect( screen.getByTestId( 'media-item-1' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'media-item-2' ) ).toBeInTheDocument();
		expect( screen.getByTestId( 'media-item-3' ) ).toBeInTheDocument();
	} );

	it( 'shows spinner when loading', () => {
		setupUseSelect( makeStoreState( { mediaIsLoading: true } ) );
		render( <MediaGrid /> );
		expect( screen.getByTestId( 'spinner' ) ).toBeInTheDocument();
	} );

	it( 'shows empty message when no media in folder', () => {
		setupUseSelect( makeStoreState( { media: [], selectedFolderId: 5 } ) );
		render( <MediaGrid /> );
		expect(
			screen.getByText( /No media in this folder/ )
		).toBeInTheDocument();
	} );

	it( 'shows unassigned empty message when on root', () => {
		setupUseSelect(
			makeStoreState( { media: [], selectedFolderId: null } )
		);
		render( <MediaGrid /> );
		expect( screen.getByText( /No unassigned media/ ) ).toBeInTheDocument();
	} );

	it( 'calls fetchMedia on mount', () => {
		setupUseSelect( makeStoreState() );
		render( <MediaGrid /> );
		expect( mockFetchMedia ).toHaveBeenCalledWith( null, 1 );
	} );

	it( 'does not render pagination when only 1 page', () => {
		setupUseSelect( makeStoreState( { mediaTotalPages: 1 } ) );
		const { container } = render( <MediaGrid /> );
		expect(
			container.querySelector( '.foldsnap-media-grid__pagination' )
		).not.toBeInTheDocument();
	} );

	it( 'renders pagination when multiple pages', () => {
		setupUseSelect(
			makeStoreState( { mediaTotalPages: 3, mediaTotal: 120 } )
		);
		const { container } = render( <MediaGrid /> );
		expect(
			container.querySelector( '.foldsnap-media-grid__pagination' )
		).toBeInTheDocument();
		expect( screen.getByText( /1 \/ 3/ ) ).toBeInTheDocument();
	} );

	it( 'disables Previous button on first page', () => {
		setupUseSelect( makeStoreState( { mediaTotalPages: 3 } ) );
		render( <MediaGrid /> );
		const prevButton = screen.getByText( '← Previous' );
		expect( prevButton ).toBeDisabled();
	} );

	it( 'calls fetchMedia with next page when Next is clicked', () => {
		setupUseSelect( makeStoreState( { mediaTotalPages: 3 } ) );
		render( <MediaGrid /> );
		fireEvent.click( screen.getByText( 'Next →' ) );
		expect( mockFetchMedia ).toHaveBeenCalledWith( null, 2 );
	} );

	it( 'selects a media item when clicked', () => {
		setupUseSelect( makeStoreState() );
		render( <MediaGrid /> );
		fireEvent.click( screen.getAllByText( 'Select' )[ 0 ] );
		expect(
			screen
				.getByTestId( 'media-item-1' )
				.querySelector( '[data-testid="selected-marker"]' )
		).toBeInTheDocument();
	} );

	it( 'toggles selection on ctrl-click', () => {
		setupUseSelect( makeStoreState() );
		render( <MediaGrid /> );

		const selectButtons = screen.getAllByText( 'Select' );

		// Select item 1 with a normal click.
		fireEvent.click( selectButtons[ 0 ] );
		expect(
			screen
				.getByTestId( 'media-item-1' )
				.querySelector( '[data-testid="selected-marker"]' )
		).toBeInTheDocument();

		// Ctrl-click item 1 again to deselect it.
		fireEvent.click( selectButtons[ 0 ], { ctrlKey: true } );
		expect(
			screen
				.getByTestId( 'media-item-1' )
				.querySelector( '[data-testid="selected-marker"]' )
		).not.toBeInTheDocument();
	} );

	it( 'selects range on shift-click', () => {
		setupUseSelect( makeStoreState() );
		render( <MediaGrid /> );

		const selectButtons = screen.getAllByText( 'Select' );

		// Click item 1 (simple click to set anchor).
		fireEvent.click( selectButtons[ 0 ] );
		expect(
			screen
				.getByTestId( 'media-item-1' )
				.querySelector( '[data-testid="selected-marker"]' )
		).toBeInTheDocument();

		// Shift-click item 3 to select range 1–3.
		fireEvent.click( selectButtons[ 2 ], { shiftKey: true } );
		expect(
			screen
				.getByTestId( 'media-item-1' )
				.querySelector( '[data-testid="selected-marker"]' )
		).toBeInTheDocument();
		expect(
			screen
				.getByTestId( 'media-item-2' )
				.querySelector( '[data-testid="selected-marker"]' )
		).toBeInTheDocument();
		expect(
			screen
				.getByTestId( 'media-item-3' )
				.querySelector( '[data-testid="selected-marker"]' )
		).toBeInTheDocument();
	} );

	it( 'displays total item count in pagination', () => {
		setupUseSelect(
			makeStoreState( { mediaTotalPages: 2, mediaTotal: 50 } )
		);
		render( <MediaGrid /> );
		expect( screen.getByText( '(50 items)' ) ).toBeInTheDocument();
	} );
} );
