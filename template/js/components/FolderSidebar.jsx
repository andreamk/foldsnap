import { ResizableBox, ToggleControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	DndContext,
	PointerSensor,
	pointerWithin,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store/constants';
import {
	PREF_KEYS,
	getInitialPreferences,
	savePreference,
} from '../preferences';
import FolderTree from './FolderTree';

const SIDEBAR_MIN_WIDTH = 200;
const SIDEBAR_MAX_WIDTH = 600;

/**
 * Root sidebar component mounted inside the native WordPress Media Library.
 *
 * Wraps FolderTree in a DndContext for folder reorder/reparent and exposes
 * the "All Media" toggle that disables the folder UI entirely. When the
 * toggle is on, the folder tree is rendered inert (greyed out, not
 * interactive) and the native media grid stops being filtered.
 *
 * The dnd-kit data payload only carries folder IDs — the active folder's
 * current name (required by `updateFolder`) is read from the store via
 * `getFolderById()` at drop time.
 *
 * @return {JSX.Element} The rendered sidebar.
 */
const FolderSidebar = () => {
	const { updateFolder, setAllMedia } = useDispatch( STORE_NAME );
	const { getFolderById, allMediaActive } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			getFolderById: store.getFolderById,
			allMediaActive: store.isAllMediaActive(),
		};
	}, [] );

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

	const initialWidth = getInitialPreferences()[ PREF_KEYS.SIDEBAR_WIDTH ];

	return (
		<DndContext
			sensors={ sensors }
			collisionDetection={ pointerWithin }
			onDragEnd={ handleDragEnd }
		>
			<ResizableBox
				className={ [
					'foldsnap-sidebar',
					allMediaActive ? 'foldsnap-sidebar--all-media' : '',
				]
					.filter( Boolean )
					.join( ' ' ) }
				defaultSize={ { width: initialWidth, height: 'auto' } }
				minWidth={ SIDEBAR_MIN_WIDTH }
				maxWidth={ SIDEBAR_MAX_WIDTH }
				enable={ {
					top: false,
					right: true,
					bottom: false,
					left: false,
					topRight: false,
					bottomRight: false,
					bottomLeft: false,
					topLeft: false,
				} }
				onResizeStop={ ( _event, _direction, ref ) => {
					savePreference( PREF_KEYS.SIDEBAR_WIDTH, ref.offsetWidth );
				} }
			>
				<div className="foldsnap-sidebar__all-media-toggle">
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'All Media', 'foldsnap' ) }
						help={ __(
							'Bypass the folder sidebar and show every media item.',
							'foldsnap'
						) }
						checked={ allMediaActive }
						onChange={ setAllMedia }
					/>
				</div>
				<div
					className="foldsnap-sidebar__tree"
					inert={ allMediaActive ? '' : undefined }
					aria-hidden={ allMediaActive ? 'true' : undefined }
				>
					<FolderTree />
				</div>
			</ResizableBox>
		</DndContext>
	);
};

export default FolderSidebar;
