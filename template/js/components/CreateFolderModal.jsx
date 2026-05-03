import { useState } from '@wordpress/element';
import { Modal, TextControl, Button } from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME, ROOT_PARENT_ID } from '../store/constants';
import FolderPicker from './FolderPicker';

/**
 * Modal dialog for creating a new folder.
 *
 * Parent selection uses FolderPicker, which lazy-loads the same store as
 * the main FolderTree — no flat list of every folder is loaded upfront.
 *
 * @param {Object}   props          Component props.
 * @param {number}   props.parentId Pre-selected parent folder ID (0 = root).
 * @param {Function} props.onClose  Close-modal callback.
 * @return {JSX.Element} Rendered modal.
 */
const CreateFolderModal = ( { parentId = ROOT_PARENT_ID, onClose } ) => {
	const [ name, setName ] = useState( '' );
	const [ selectedParentId, setSelectedParentId ] = useState( parentId );
	const [ isCreating, setIsCreating ] = useState( false );

	const { createFolder } = useDispatch( STORE_NAME );

	const handleCreate = async () => {
		const trimmedName = name.trim();
		if ( ! trimmedName ) {
			return;
		}
		setIsCreating( true );
		try {
			await createFolder( {
				name: trimmedName,
				parentId: selectedParentId,
			} );
			onClose();
		} finally {
			setIsCreating( false );
		}
	};

	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' ) {
			handleCreate();
		}
	};

	return (
		<Modal
			title={ __( 'New Folder', 'foldsnap' ) }
			onRequestClose={ onClose }
			className="foldsnap-create-folder-modal"
		>
			<TextControl
				label={ __( 'Folder name', 'foldsnap' ) }
				value={ name }
				onChange={ setName }
				onKeyDown={ handleKeyDown }
				placeholder={ __( 'Enter folder name…', 'foldsnap' ) }
				autoFocus // eslint-disable-line jsx-a11y/no-autofocus
			/>
			<div className="foldsnap-create-folder-modal__picker">
				<span className="foldsnap-create-folder-modal__picker-label">
					{ __( 'Parent folder', 'foldsnap' ) }
				</span>
				<FolderPicker
					value={ selectedParentId }
					onChange={ setSelectedParentId }
				/>
			</div>
			<div className="foldsnap-create-folder-modal__actions">
				<Button
					variant="secondary"
					onClick={ onClose }
					disabled={ isCreating }
				>
					{ __( 'Cancel', 'foldsnap' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ handleCreate }
					disabled={ ! name.trim() || isCreating }
					isBusy={ isCreating }
				>
					{ __( 'Create', 'foldsnap' ) }
				</Button>
			</div>
		</Modal>
	);
};

export default CreateFolderModal;
