import { useState } from '@wordpress/element';
import { DropdownMenu } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useSortable } from '@dnd-kit/sortable';
import { useDroppable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store/constants';

/**
 * Formats a byte count to a human-readable string.
 *
 * @param {number} bytes Size in bytes.
 * @return {string} Formatted size string.
 */
const formatSize = ( bytes ) => {
	if ( bytes < 1024 ) {
		return bytes + ' B';
	}
	if ( bytes < 1024 * 1024 ) {
		return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
	}
	return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
};

/**
 * Renders a single folder node in the tree.
 *
 * @param {Object}      props                  Component props.
 * @param {Object}      props.folder           Folder data object.
 * @param {number|null} props.selectedFolderId Currently selected folder ID.
 * @param {Function}    props.onSelect         Callback when folder is selected.
 * @param {number}      props.depth            Nesting depth (0 = root).
 * @param {Function}    props.onAddSubfolder   Callback to open modal with a parent pre-set.
 * @return {JSX.Element} The rendered folder item.
 */
const FolderItem = ( {
	folder,
	selectedFolderId,
	onSelect,
	depth = 0,
	onAddSubfolder,
} ) => {
	const [ isExpanded, setIsExpanded ] = useState( true );
	const { deleteFolder } = useDispatch( STORE_NAME );

	const hasChildren = folder.children && folder.children.length > 0;
	const isSelected = selectedFolderId === folder.id;

	// Sortable: drag to reorder among siblings
	const {
		attributes,
		listeners,
		setNodeRef: setSortableRef,
		transform,
		transition,
		isDragging,
	} = useSortable( {
		id: folder.id,
		data: { type: 'folder', folder },
	} );

	// Droppable: accept media drops (folder assignment)
	const { isOver, setNodeRef: setDroppableRef } = useDroppable( {
		id: `folder-drop-${ folder.id }`,
		data: { type: 'folder', folderId: folder.id },
	} );

	const sortableStyle = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.4 : 1,
	};

	const handleToggleExpand = ( e ) => {
		e.stopPropagation();
		setIsExpanded( ( prev ) => ! prev );
	};

	const handleSelect = () => {
		onSelect( folder.id );
	};

	const handleDelete = async () => {
		// eslint-disable-next-line no-alert
		const confirmed = window.confirm(
			__( 'Delete this folder? Media will return to root.', 'foldsnap' )
		);
		if ( confirmed ) {
			await deleteFolder( folder.id );
		}
	};

	const dropdownControls = [
		{
			title: __( 'Add subfolder', 'foldsnap' ),
			onClick: () => onAddSubfolder( folder.id ),
		},
		{
			title: __( 'Delete', 'foldsnap' ),
			onClick: handleDelete,
		},
	];

	const indentStyle = { paddingLeft: depth * 16 + 'px' };

	return (
		<div ref={ setSortableRef } style={ sortableStyle } { ...attributes }>
			<div
				ref={ setDroppableRef }
				className={ [
					'foldsnap-folder-item',
					isSelected ? 'foldsnap-folder-item--selected' : '',
					isOver ? 'foldsnap-folder-item--drag-over' : '',
				]
					.filter( Boolean )
					.join( ' ' ) }
				style={ indentStyle }
				role="button"
				tabIndex={ 0 }
				onClick={ handleSelect }
				onKeyDown={ ( e ) => e.key === 'Enter' && handleSelect() }
			>
				{ /* Drag handle */ }
				<span
					className="foldsnap-folder-item__drag-handle"
					{ ...listeners }
					role="button"
					tabIndex={ 0 }
					aria-label={ __( 'Drag to reorder', 'foldsnap' ) }
					onClick={ ( e ) => e.stopPropagation() }
					onKeyDown={ ( e ) => e.stopPropagation() }
				>
					⠿
				</span>

				{ /* Expand/collapse chevron */ }
				{ hasChildren ? (
					<button
						type="button"
						className="foldsnap-folder-item__chevron"
						onClick={ handleToggleExpand }
						aria-label={
							isExpanded
								? __( 'Collapse', 'foldsnap' )
								: __( 'Expand', 'foldsnap' )
						}
					>
						{ isExpanded ? '▾' : '▸' }
					</button>
				) : (
					<span className="foldsnap-folder-item__chevron foldsnap-folder-item__chevron--empty" />
				) }

				{ /* Color dot */ }
				{ folder.color && (
					<span
						className="foldsnap-folder-item__color-dot"
						style={ { backgroundColor: folder.color } }
						aria-hidden="true"
					/>
				) }

				{ /* Folder name */ }
				<span className="foldsnap-folder-item__name">
					{ folder.name }
				</span>

				{ /* Media count badge */ }
				{ folder.total_media_count !== undefined && (
					<span className="foldsnap-folder-item__badge">
						{ folder.total_media_count }
					</span>
				) }

				{ /* Size label */ }
				{ folder.total_size !== undefined && folder.total_size > 0 && (
					<span className="foldsnap-folder-item__size">
						{ formatSize( folder.total_size ) }
					</span>
				) }

				{ /* Actions dropdown */ }
				<DropdownMenu
					icon="ellipsis"
					label={ __( 'Folder actions', 'foldsnap' ) }
					controls={ dropdownControls }
					className="foldsnap-folder-item__actions"
					toggleProps={ {
						onClick: ( e ) => e.stopPropagation(),
					} }
				/>
			</div>

			{ /* Recursive children */ }
			{ hasChildren && isExpanded && (
				<div className="foldsnap-folder-item__children">
					{ folder.children.map( ( child ) => (
						<FolderItem
							key={ child.id }
							folder={ child }
							selectedFolderId={ selectedFolderId }
							onSelect={ onSelect }
							depth={ depth + 1 }
							onAddSubfolder={ onAddSubfolder }
						/>
					) ) }
				</div>
			) }
		</div>
	);
};

export { formatSize };
export default FolderItem;
