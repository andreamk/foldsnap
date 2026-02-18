import { useState } from '@wordpress/element';
import { TextControl, Button, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import {
	SortableContext,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { __ } from '@wordpress/i18n';
import { STORE_NAME } from '../store/constants';
import FolderItem from './FolderItem';
import CreateFolderModal from './CreateFolderModal';

/**
 * Sidebar folder tree with search, root item, and "New Folder" button.
 *
 * @return {JSX.Element} The rendered folder tree.
 */
const FolderTree = () => {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ modalParentId, setModalParentId ] = useState( 0 );

	const {
		folders,
		filteredFolders,
		selectedFolderId,
		isLoading,
		error,
		rootMediaCount,
	} = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			folders: store.getFolders(),
			filteredFolders: store.getFilteredFolders(),
			selectedFolderId: store.getSelectedFolderId(),
			isLoading: store.isLoading(),
			error: store.getError(),
			rootMediaCount: store.getRootMediaCount(),
		};
	} );

	const { setSelectedFolder, setSearchQuery } = useDispatch( STORE_NAME );

	const searchQuery = useSelect( ( select ) =>
		select( STORE_NAME ).getSearchQuery()
	);

	const handleRootSelect = () => {
		setSelectedFolder( null );
	};

	const handleOpenModal = ( parentId = 0 ) => {
		setModalParentId( parentId );
		setIsModalOpen( true );
	};

	const handleCloseModal = () => {
		setIsModalOpen( false );
		setModalParentId( 0 );
	};

	const rootFolderIds = folders.map( ( f ) => f.id );

	return (
		<div className="foldsnap-folder-tree">
			{ /* Search input */ }
			<div className="foldsnap-folder-tree__search">
				<TextControl
					label={ __( 'Search folders', 'foldsnap' ) }
					hideLabelFromVision
					placeholder={ __( 'Search folders…', 'foldsnap' ) }
					value={ searchQuery }
					onChange={ setSearchQuery }
					className="foldsnap-search-input"
				/>
			</div>

			{ /* Loading / error states */ }
			{ isLoading && (
				<div className="foldsnap-folder-tree__loading">
					<Spinner />
				</div>
			) }
			{ error && ! isLoading && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ /* Root "All Media" item */ }
			<div
				className={ [
					'foldsnap-root-item',
					selectedFolderId === null
						? 'foldsnap-root-item--selected'
						: '',
				]
					.filter( Boolean )
					.join( ' ' ) }
				role="button"
				tabIndex={ 0 }
				onClick={ handleRootSelect }
				onKeyDown={ ( e ) => e.key === 'Enter' && handleRootSelect() }
			>
				<span className="foldsnap-root-item__label">
					{ __( 'All Media', 'foldsnap' ) }
				</span>
				<span className="foldsnap-root-item__badge">
					{ rootMediaCount }
				</span>
			</div>

			{ /* Folder list with sortable context for root-level reordering */ }
			{ ! isLoading && ! error && (
				<SortableContext
					items={ rootFolderIds }
					strategy={ verticalListSortingStrategy }
				>
					{ filteredFolders.map( ( folder ) => (
						<FolderItem
							key={ folder.id }
							folder={ folder }
							selectedFolderId={ selectedFolderId }
							onSelect={ setSelectedFolder }
							depth={ 0 }
							onAddSubfolder={ handleOpenModal }
						/>
					) ) }
				</SortableContext>
			) }

			{ /* New Folder button */ }
			<div className="foldsnap-folder-tree__actions">
				<Button
					variant="secondary"
					onClick={ () => handleOpenModal( 0 ) }
					className="foldsnap-folder-tree__new-btn"
				>
					{ __( '+ New Folder', 'foldsnap' ) }
				</Button>
			</div>

			{ /* Create folder modal */ }
			{ isModalOpen && (
				<CreateFolderModal
					parentId={ modalParentId }
					folders={ folders }
					onClose={ handleCloseModal }
				/>
			) }
		</div>
	);
};

export default FolderTree;
