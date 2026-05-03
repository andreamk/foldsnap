import { useDispatch, useSelect } from '@wordpress/data';
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
 *
 * The dnd-kit data payload only carries folder IDs — the active folder's
 * current name (required by `updateFolder`) is read from the store via
 * `getFolderById()` at drop time.
 *
 * @return {JSX.Element} The rendered sidebar.
 */
const FolderSidebar = () => {
	const { updateFolder } = useDispatch( STORE_NAME );
	const { getFolderById } = useSelect(
		( select ) => select( STORE_NAME ),
		[]
	);

	const sensors = useSensors(
		useSensor( PointerSensor, {
			activationConstraint: { distance: 8 },
		} )
	);

	const handleDragEnd = ( event ) => {
		const { active, over } = event;
		if ( ! over || active.id === over.id ) {
			return;
		}

		const activeType = active.data.current?.type;
		const overType = over.data.current?.type;

		if ( activeType !== 'folder' || overType !== 'folder' ) {
			return;
		}

		const activeFolderId = active.data.current.folderId;
		const activeFolder = getFolderById( activeFolderId );
		if ( ! activeFolder ) {
			return;
		}

		// Drop on a folder-drop zone → reparent
		if ( over.id.toString().startsWith( 'folder-drop-' ) ) {
			const targetFolderId = over.data.current.folderId;
			if ( activeFolder.id !== targetFolderId ) {
				updateFolder( activeFolder.id, {
					name: activeFolder.name,
					parentId: targetFolderId,
				} );
			}
			return;
		}

		// Drop on sortable folder → reorder (same parent assumed)
		const overFolder = getFolderById( over.data.current.folderId );
		updateFolder( activeFolder.id, {
			name: activeFolder.name,
			position: overFolder?.position ?? 0,
		} );
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
