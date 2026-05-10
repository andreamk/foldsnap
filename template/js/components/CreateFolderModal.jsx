import { useState } from '@wordpress/element';
import { Modal, TextControl, Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME, ROOT_PARENT_ID } from '../store/constants';

/**
 * Modal dialog for creating a new folder.
 *
 * Parent is fixed by the caller — there is no parent picker. The user opens
 * this modal via the "Add subfolder" entry in a folder's dropdown menu, so
 * the target parent is always unambiguous.
 *
 * @param {Object}   props          Component props.
 * @param {number}   props.parentId Parent folder ID (0 = root).
 * @param {Function} props.onClose  Close-modal callback.
 * @return {JSX.Element} Rendered modal.
 */
const CreateFolderModal = ( { parentId = ROOT_PARENT_ID, onClose } ) => {
	const [ name, setName ] = useState( '' );
	const [ isCreating, setIsCreating ] = useState( false );

	const { createFolder } = useDispatch( STORE_NAME );

	const parentName = useSelect(
		( select ) => select( STORE_NAME ).getFolderById( parentId )?.name,
		[ parentId ]
	);

	const handleCreate = async () => {
		const trimmedName = name.trim();
		if ( ! trimmedName ) {
			return;
		}
		setIsCreating( true );
		try {
			await createFolder( {
				name: trimmedName,
				parentId,
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

	const title = parentName
		? sprintf(
				/* translators: %s: parent folder name */
				__( 'New folder in “%s”', 'foldsnap' ),
				parentName
		  )
		: __( 'New folder', 'foldsnap' );

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className="foldsnap-create-folder-modal"
		>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Folder name', 'foldsnap' ) }
				value={ name }
				onChange={ setName }
				onKeyDown={ handleKeyDown }
				placeholder={ __( 'Enter folder name…', 'foldsnap' ) }
				autoFocus // eslint-disable-line jsx-a11y/no-autofocus
			/>
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
