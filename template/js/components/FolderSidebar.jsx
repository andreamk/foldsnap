import { useDispatch } from '@wordpress/data';
import {
	DndContext,
	PointerSensor,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import { STORE_NAME } from '../store/constants';
import FolderTree from './FolderTree';

/**
 * Root sidebar component mounted inside the native WordPress Media Library.
 *
 * Wraps FolderTree in a DndContext that handles:
 * - Folder reordering (drag folder onto a sibling position)
 * - Folder reparenting (drag folder onto a drop zone of another folder)
 * - Media assignment (drag media item onto a folder)
 *
 * @return {JSX.Element} The rendered sidebar.
 */
const FolderSidebar = () => {
	const { updateFolder, assignMedia } = useDispatch( STORE_NAME );

	const sensors = useSensors(
		useSensor( PointerSensor, {
			activationConstraint: { distance: 8 },
		} )
	);

	/**
	 * Handles the end of a drag operation.
	 *
	 * @param {Object} event DndKit drag end event.
	 */
	const handleDragEnd = ( event ) => {
		const { active, over } = event;
		if ( ! over || active.id === over.id ) {
			return;
		}

		const activeType = active.data.current?.type;
		const overType = over.data.current?.type;

		if ( activeType === 'folder' && overType === 'folder' ) {
			const activeFolder = active.data.current.folder;
			const overData = over.data.current;

			// Drop on a folder-drop zone → reparent
			if ( over.id.toString().startsWith( 'folder-drop-' ) ) {
				const targetFolderId = overData.folderId;
				if ( activeFolder.id !== targetFolderId ) {
					updateFolder( activeFolder.id, {
						name: activeFolder.name,
						parentId: targetFolderId,
					} );
				}
				return;
			}

			// Drop on sortable folder → reorder (same parent assumed)
			updateFolder( activeFolder.id, {
				name: activeFolder.name,
				position: over.data.current?.folder?.position ?? 0,
			} );
		}

		if ( activeType === 'media' && overType === 'folder' ) {
			const mediaIds = active.data.current.mediaIds;
			const folderId = over.data.current.folderId;
			assignMedia( folderId, mediaIds );
		}
	};

	return (
		<DndContext sensors={ sensors } onDragEnd={ handleDragEnd }>
			<div className="foldsnap-sidebar">
				<FolderTree />
			</div>
		</DndContext>
	);
};

export default FolderSidebar;
