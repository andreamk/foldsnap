import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store/constants';
import MediaItem from './MediaItem';

/**
 * Renders a paginated grid of draggable media items for the selected folder.
 *
 * @return {JSX.Element} The rendered media grid.
 */
const MediaGrid = () => {
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const [ selectedIds, setSelectedIds ] = useState( [] );
	const [ lastClickedId, setLastClickedId ] = useState( null );

	const {
		media,
		mediaIsLoading,
		mediaTotal,
		mediaTotalPages,
		selectedFolderId,
	} = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			media: store.getMedia(),
			mediaIsLoading: store.isMediaLoading(),
			mediaTotal: store.getMediaTotal(),
			mediaTotalPages: store.getMediaTotalPages(),
			selectedFolderId: store.getSelectedFolderId(),
		};
	} );

	const { fetchMedia } = useDispatch( STORE_NAME );

	// Fetch media when folder or page changes.
	useEffect( () => {
		fetchMedia( selectedFolderId, currentPage );
	}, [ selectedFolderId, currentPage, fetchMedia ] );

	// Reset page and selection when folder changes.
	useEffect( () => {
		setCurrentPage( 1 );
		setSelectedIds( [] );
		setLastClickedId( null );
	}, [ selectedFolderId ] );

	/**
	 * Handles media item selection with shift/ctrl modifiers.
	 */
	const handleSelect = useCallback(
		( mediaId, { shift = false, ctrl = false } = {} ) => {
			if ( shift && lastClickedId !== null ) {
				// Range selection: select all items between lastClicked and current.
				const ids = media.map( ( m ) => m.id );
				const startIdx = ids.indexOf( lastClickedId );
				const endIdx = ids.indexOf( mediaId );
				if ( startIdx !== -1 && endIdx !== -1 ) {
					const min = Math.min( startIdx, endIdx );
					const max = Math.max( startIdx, endIdx );
					const rangeIds = ids.slice( min, max + 1 );
					setSelectedIds( ( prev ) => {
						const merged = new Set( [ ...prev, ...rangeIds ] );
						return [ ...merged ];
					} );
				}
			} else if ( ctrl ) {
				// Toggle single item.
				setSelectedIds( ( prev ) =>
					prev.includes( mediaId )
						? prev.filter( ( id ) => id !== mediaId )
						: [ ...prev, mediaId ]
				);
			} else {
				// Simple click: select only this item.
				setSelectedIds( [ mediaId ] );
			}
			setLastClickedId( mediaId );
		},
		[ lastClickedId, media ]
	);

	const handlePrevPage = () => {
		setCurrentPage( ( p ) => Math.max( 1, p - 1 ) );
	};

	const handleNextPage = () => {
		setCurrentPage( ( p ) => Math.min( mediaTotalPages, p + 1 ) );
	};

	if ( mediaIsLoading ) {
		return (
			<div className="foldsnap-media-grid__loading">
				<Spinner />
			</div>
		);
	}

	if ( ! media.length ) {
		return (
			<div className="foldsnap-media-grid__empty">
				<p>
					{ selectedFolderId
						? __(
								'No media in this folder. Drag items here from another folder.',
								'foldsnap'
						  )
						: __( 'No unassigned media.', 'foldsnap' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="foldsnap-media-grid-wrapper">
			<div className="foldsnap-media-grid">
				{ media.map( ( item ) => (
					<MediaItem
						key={ item.id }
						media={ item }
						isSelected={ selectedIds.includes( item.id ) }
						onSelect={ handleSelect }
						selectedIds={ selectedIds }
					/>
				) ) }
			</div>

			{ mediaTotalPages > 1 && (
				<div className="foldsnap-media-grid__pagination">
					<button
						type="button"
						className="button"
						onClick={ handlePrevPage }
						disabled={ currentPage <= 1 }
					>
						{ __( '← Previous', 'foldsnap' ) }
					</button>
					<span className="foldsnap-media-grid__page-info">
						{ currentPage } / { mediaTotalPages }{ ' ' }
						<span className="foldsnap-media-grid__total">
							({ mediaTotal } { __( 'items', 'foldsnap' ) })
						</span>
					</span>
					<button
						type="button"
						className="button"
						onClick={ handleNextPage }
						disabled={ currentPage >= mediaTotalPages }
					>
						{ __( 'Next →', 'foldsnap' ) }
					</button>
				</div>
			) }
		</div>
	);
};

export default MediaGrid;
