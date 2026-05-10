import { useState, useEffect } from '@wordpress/element';
import { TextControl, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME, ROOT_PARENT_ID } from '../store/constants';
import useDebouncedCallback, {
	SEARCH_DEBOUNCE_MS,
} from '../hooks/useDebouncedCallback';
import FolderItem from './FolderItem';
import CreateFolderModal from './CreateFolderModal';
import SearchResultsList from './SearchResultsList';

/**
 * Sidebar folder tree with debounced search and a "New Folder" button.
 *
 * Two display modes mutually exclusive:
 *   - search active → SearchResultsList (paginated server-side)
 *   - empty query   → root folders + on-demand expand via FolderItem
 *
 * Search input is locally controlled (no roundtrip on every keystroke);
 * dispatches setSearchQuery + searchFolders only after the debounce window.
 *
 * @return {JSX.Element} Rendered folder tree.
 */
const FolderTree = () => {
	const [ isModalOpen, setIsModalOpen ] = useState( false );
	const [ modalParentId, setModalParentId ] = useState( 0 );
	const [ inputValue, setInputValue ] = useState( '' );

	const {
		rootHydrated,
		isRootLoading,
		selectedFolderId,
		error,
		searchQuery,
	} = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			rootHydrated: store.getFolderById( ROOT_PARENT_ID ) !== undefined,
			isRootLoading:
				! store.isFolderLoaded( ROOT_PARENT_ID ) &&
				store.isFolderFetching( ROOT_PARENT_ID ),
			selectedFolderId: store.getSelectedFolderId(),
			error: store.getError(),
			searchQuery: store.getSearchQuery(),
		};
	}, [] );

	const { setSelectedFolder, setSearchQuery, searchFolders, clearSearch } =
		useDispatch( STORE_NAME );

	useEffect( () => {
		// Keep the local input in sync with external resets (e.g. a search
		// result click clears the query).
		if ( searchQuery !== inputValue ) {
			setInputValue( searchQuery );
		}
		// Sync only on external changes; not on every render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ searchQuery ] );

	const commitSearch = useDebouncedCallback( ( value ) => {
		setSearchQuery( value );
		if ( value.trim() === '' ) {
			clearSearch();
		} else {
			searchFolders( value );
		}
	}, SEARCH_DEBOUNCE_MS );

	const handleSearchChange = ( value ) => {
		setInputValue( value );
		commitSearch( value );
	};

	const handleOpenModal = ( parentId ) => {
		setModalParentId( parentId );
		setIsModalOpen( true );
	};

	const handleCloseModal = () => {
		setIsModalOpen( false );
		setModalParentId( ROOT_PARENT_ID );
	};

	const isSearching = searchQuery.trim() !== '';

	return (
		<div className="foldsnap-folder-tree">
			<div className="foldsnap-folder-tree__search">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Search folders', 'foldsnap' ) }
					hideLabelFromVision
					placeholder={ __( 'Search folders…', 'foldsnap' ) }
					value={ inputValue }
					onChange={ handleSearchChange }
					className="foldsnap-search-input"
				/>
			</div>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ isSearching ? (
				<SearchResultsList />
			) : (
				<>
					{ isRootLoading && ! rootHydrated && (
						<div className="foldsnap-folder-tree__loading">
							<Spinner />
						</div>
					) }
					{ rootHydrated && (
						<FolderItem
							key={ ROOT_PARENT_ID }
							folderId={ ROOT_PARENT_ID }
							selectedFolderId={ selectedFolderId }
							onSelect={ setSelectedFolder }
							depth={ 0 }
							onAddSubfolder={ handleOpenModal }
						/>
					) }
				</>
			) }

			{ isModalOpen && (
				<CreateFolderModal
					parentId={ modalParentId }
					onClose={ handleCloseModal }
				/>
			) }
		</div>
	);
};

export default FolderTree;
