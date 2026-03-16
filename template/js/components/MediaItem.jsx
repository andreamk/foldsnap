import { useDraggable } from '@dnd-kit/core';
import formatSize from '../utils/format-size';

/**
 * Renders a single media item in the grid, draggable onto folders.
 *
 * @param {Object}   props             Component props.
 * @param {Object}   props.media       Media data object from REST API.
 * @param {boolean}  props.isSelected  Whether this item is selected.
 * @param {Function} props.onSelect    Callback when item is clicked for selection.
 * @param {number[]} props.selectedIds All currently selected media IDs (for bulk drag).
 * @return {JSX.Element} The rendered media item.
 */
const MediaItem = ( { media, isSelected, onSelect, selectedIds } ) => {
	const dragIds =
		isSelected && selectedIds.length > 1 ? selectedIds : [ media.id ];

	const { attributes, listeners, setNodeRef, isDragging } = useDraggable( {
		id: `media-${ media.id }`,
		data: { type: 'media', mediaIds: dragIds },
	} );

	const handleClick = ( e ) => {
		onSelect( media.id, {
			shift: e.shiftKey,
			ctrl: e.ctrlKey || e.metaKey,
		} );
	};

	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			onSelect( media.id, {
				shift: e.shiftKey,
				ctrl: e.ctrlKey || e.metaKey,
			} );
		}
	};

	const isImage = media.mime_type && media.mime_type.startsWith( 'image/' );

	const classNames = [
		'foldsnap-media-item',
		isSelected ? 'foldsnap-media-item--selected' : '',
		isDragging ? 'foldsnap-media-item--dragging' : '',
	]
		.filter( Boolean )
		.join( ' ' );

	return (
		<div
			ref={ setNodeRef }
			className={ classNames }
			role="button"
			tabIndex={ 0 }
			onClick={ handleClick }
			onKeyDown={ handleKeyDown }
			{ ...attributes }
			{ ...listeners }
		>
			<div className="foldsnap-media-item__thumbnail">
				{ isImage && media.thumbnail_url ? (
					<img
						src={ media.thumbnail_url }
						alt={ media.title }
						loading="lazy"
					/>
				) : (
					<span className="foldsnap-media-item__icon dashicons dashicons-media-default" />
				) }
				{ isDragging && isSelected && selectedIds.length > 1 && (
					<span className="foldsnap-media-item__drag-count">
						{ selectedIds.length }
					</span>
				) }
			</div>
			<div className="foldsnap-media-item__info">
				<span className="foldsnap-media-item__title">
					{ media.title || media.filename }
				</span>
				{ media.file_size > 0 && (
					<span className="foldsnap-media-item__size">
						{ formatSize( media.file_size ) }
					</span>
				) }
			</div>
		</div>
	);
};

export default MediaItem;
