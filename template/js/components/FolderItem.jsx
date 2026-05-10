import { useState } from '@wordpress/element';
import {
	Button,
	DropdownMenu,
	Modal,
	Spinner,
	Icon,
	TextControl,
} from '@wordpress/components';
import { chevronDown, chevronRight, dragHandle, home } from '@wordpress/icons';
import { useDispatch, useSelect } from '@wordpress/data';
import { useSortable } from '@dnd-kit/sortable';
import { useDroppable } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store/constants';
import formatSize from '../utils/format-size';

/**
 * Renders a single folder node in the lazy-loaded tree.
 *
 * Reads the folder, expansion, fetch state and children list directly from
 * the store via `folderId` (no folder object passed down) so a parent's
 * mutation doesn't force an unrelated subtree to re-render.
 *
 * Children list is rendered only when `isExpanded` AND children have been
 * loaded; while a fetch is in flight a small spinner stands in.
 *
 * @param {Object}      props                  Component props.
 * @param {number}      props.folderId         Folder term ID.
 * @param {number|null} props.selectedFolderId Currently selected folder ID.
 * @param {Function}    props.onSelect         Selection callback.
 * @param {number}      props.depth            Nesting depth (0 = root).
 * @param {Function}    props.onAddSubfolder   Open create-modal with parent pre-set.
 * @return {JSX.Element|null} Rendered folder item, or null if folder not in store.
 */
const FolderItem = ( {
	folderId,
	selectedFolderId,
	onSelect,
	depth = 0,
	onAddSubfolder,
} ) => {
	const [ showDeleteConfirm, setShowDeleteConfirm ] = useState( false );
	const [ showRenameModal, setShowRenameModal ] = useState( false );
	const [ renameValue, setRenameValue ] = useState( '' );

	const { folder, isExpanded, isFetching, children } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			return {
				folder: store.getFolderById( folderId ),
				isExpanded: store.isFolderExpanded( folderId ),
				isFetching: store.isFolderFetching( folderId ),
				children: store.getChildrenOf( folderId ),
			};
		},
		[ folderId ]
	);

	const { deleteFolder, expandFolder, collapseFolder, updateFolder } =
		useDispatch( STORE_NAME );

	// Droppable target for folder reparenting. Registered BEFORE useSortable
	// so that when both rects coincide (folder with no children), pointerWithin
	// picks this droppable (id "folder-drop-X") instead of the sortable's
	// numeric id, routing the drop into the reparent branch.
	const { isOver, setNodeRef: setDroppableRef } = useDroppable( {
		id: `folder-drop-${ folderId }`,
		data: { type: 'folder', folderId },
	} );

	// Sortable drag for reordering folders among siblings.
	const {
		attributes,
		listeners,
		setNodeRef: setSortableRef,
		setActivatorNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( {
		id: folderId,
		data: { type: 'folder', folderId },
	} );

	if ( ! folder ) {
		return null;
	}

	const isRoot = folder.is_root === true;
	const hasChildren = isRoot || folder.has_children === true;
	const isSelected = selectedFolderId === folder.id;

	const sortableStyle = {
		transform: CSS.Transform.toString( transform ),
		transition,
		opacity: isDragging ? 0.4 : 1,
	};

	const handleToggleExpand = ( e ) => {
		e.stopPropagation();
		if ( isExpanded ) {
			collapseFolder( folder.id );
		} else {
			expandFolder( folder.id );
		}
	};

	const handleSelect = () => {
		onSelect( folder.id );
	};

	const dropdownControls = [
		{
			title: __( 'Add subfolder', 'foldsnap' ),
			onClick: () => onAddSubfolder( folder.id ),
		},
		// Root cannot be renamed or deleted; omit those entries entirely so
		// the menu hides them instead of showing them greyed out.
		...( isRoot
			? []
			: [
					{
						title: __( 'Rename', 'foldsnap' ),
						onClick: () => {
							setRenameValue( folder.name );
							setShowRenameModal( true );
						},
					},
					{
						title: __( 'Delete', 'foldsnap' ),
						onClick: () => setShowDeleteConfirm( true ),
					},
			  ] ),
	];

	const trimmedRename = renameValue.trim();
	const isRenameInvalid = ! trimmedRename || trimmedRename === folder.name;

	const handleRenameSubmit = async () => {
		if ( isRenameInvalid ) {
			return;
		}
		setShowRenameModal( false );
		await updateFolder( folder.id, { name: trimmedRename } );
	};

	const indentStyle = { paddingLeft: depth * 16 + 'px' };

	return (
		<div ref={ setSortableRef } style={ sortableStyle }>
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
				data-folder-id={ folder.id }
				onClick={ handleSelect }
				onKeyDown={ ( e ) => e.key === 'Enter' && handleSelect() }
			>
				{ ! isRoot && (
					<button
						type="button"
						ref={ setActivatorNodeRef }
						className="foldsnap-folder-item__drag-handle"
						{ ...listeners }
						{ ...attributes }
						aria-label={ __( 'Drag to reorder', 'foldsnap' ) }
						onClick={ ( e ) => e.stopPropagation() }
					>
						<Icon icon={ dragHandle } size={ 18 } />
					</button>
				) }

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
						<Icon
							icon={ isExpanded ? chevronDown : chevronRight }
							size={ 20 }
						/>
					</button>
				) : (
					<span className="foldsnap-folder-item__chevron foldsnap-folder-item__chevron--empty" />
				) }

				{ isRoot && (
					<span
						className="foldsnap-folder-item__icon"
						aria-label={ __( 'Root folder', 'foldsnap' ) }
						title={ __(
							'Root holds all media and folders in your library.',
							'foldsnap'
						) }
					>
						<Icon icon={ home } size={ 16 } />
					</span>
				) }

				{ folder.color && (
					<span
						className="foldsnap-folder-item__color-dot"
						style={ { backgroundColor: folder.color } }
						aria-hidden="true"
					/>
				) }

				<span className="foldsnap-folder-item__name">
					{ folder.name }
				</span>

				{ folder.total_media_count !== undefined && (
					<span className="foldsnap-folder-item__badge">
						{ folder.total_media_count }
					</span>
				) }

				{ folder.total_size !== undefined && folder.total_size > 0 && (
					<span className="foldsnap-folder-item__size">
						{ formatSize( folder.total_size ) }
					</span>
				) }

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

			{ isExpanded && (
				<div className="foldsnap-folder-item__children">
					{ isFetching && children.length === 0 && (
						<div className="foldsnap-folder-item__loading">
							<Spinner />
						</div>
					) }
					{ children.map( ( child ) => (
						<FolderItem
							key={ child.id }
							folderId={ child.id }
							selectedFolderId={ selectedFolderId }
							onSelect={ onSelect }
							depth={ depth + 1 }
							onAddSubfolder={ onAddSubfolder }
						/>
					) ) }
				</div>
			) }

			{ showRenameModal && (
				<Modal
					title={ __( 'Rename folder', 'foldsnap' ) }
					onRequestClose={ () => setShowRenameModal( false ) }
					size="small"
				>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Folder name', 'foldsnap' ) }
						value={ renameValue }
						onChange={ setRenameValue }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' && ! isRenameInvalid ) {
								handleRenameSubmit();
							}
						} }
					/>
					<div className="foldsnap-confirm-actions">
						<Button
							variant="tertiary"
							onClick={ () => setShowRenameModal( false ) }
						>
							{ __( 'Cancel', 'foldsnap' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ handleRenameSubmit }
							disabled={ isRenameInvalid }
						>
							{ __( 'Rename', 'foldsnap' ) }
						</Button>
					</div>
				</Modal>
			) }

			{ showDeleteConfirm && (
				<Modal
					title={ __( 'Delete folder', 'foldsnap' ) }
					onRequestClose={ () => setShowDeleteConfirm( false ) }
					size="small"
				>
					<p>
						{ __(
							'Delete this folder? Media will return to root.',
							'foldsnap'
						) }
					</p>
					<div className="foldsnap-confirm-actions">
						<Button
							variant="tertiary"
							onClick={ () => setShowDeleteConfirm( false ) }
						>
							{ __( 'Cancel', 'foldsnap' ) }
						</Button>
						<Button
							variant="primary"
							isDestructive
							onClick={ async () => {
								setShowDeleteConfirm( false );
								await deleteFolder( folder.id );
							} }
						>
							{ __( 'Delete', 'foldsnap' ) }
						</Button>
					</div>
				</Modal>
			) }
		</div>
	);
};

export default FolderItem;
