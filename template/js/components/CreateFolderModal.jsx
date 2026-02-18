import { useState } from '@wordpress/element';
import {
	Modal,
	TextControl,
	SelectControl,
	Button,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store/constants';

/**
 * Flattens the folder tree into a flat list for use in SelectControl.
 *
 * @param {Array}  folders Folder tree array.
 * @param {number} depth   Current nesting depth.
 * @return {Array} Flat array of { label, value } options.
 */
const flattenFolders = ( folders, depth = 0 ) => {
	const options = [];
	for ( const folder of folders ) {
		options.push( {
			label: '—'.repeat( depth ) + ( depth > 0 ? ' ' : '' ) + folder.name,
			value: String( folder.id ),
		} );
		if ( folder.children && folder.children.length > 0 ) {
			options.push( ...flattenFolders( folder.children, depth + 1 ) );
		}
	}
	return options;
};

/**
 * Modal dialog for creating a new folder.
 *
 * @param {Object}   props          Component props.
 * @param {number}   props.parentId Pre-selected parent folder ID (0 = root).
 * @param {Array}    props.folders  Full folder tree for parent selection.
 * @param {Function} props.onClose  Callback to close the modal.
 * @return {JSX.Element} The rendered modal.
 */
const CreateFolderModal = ( { parentId = 0, folders, onClose } ) => {
	const [ name, setName ] = useState( '' );
	const [ selectedParentId, setSelectedParentId ] = useState(
		String( parentId )
	);
	const [ isCreating, setIsCreating ] = useState( false );

	const { createFolder } = useDispatch( STORE_NAME );

	const parentOptions = [
		{ label: __( '— Root —', 'foldsnap' ), value: '0' },
		...flattenFolders( folders ),
	];

	const handleCreate = async () => {
		const trimmedName = name.trim();
		if ( ! trimmedName ) {
			return;
		}
		setIsCreating( true );
		await createFolder( {
			name: trimmedName,
			parentId: parseInt( selectedParentId, 10 ),
		} );
		setIsCreating( false );
		onClose();
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
			<SelectControl
				label={ __( 'Parent folder', 'foldsnap' ) }
				value={ selectedParentId }
				options={ parentOptions }
				onChange={ setSelectedParentId }
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

export { flattenFolders };
export default CreateFolderModal;
