import { useState, useEffect, useRef } from '@wordpress/element';
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
import SearchResultsList from './SearchResultsList';

const SEARCH_DEBOUNCE_MS = 300;

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
	const debounceRef = useRef( null );

	const {
		rootFolders,
		isRootLoading,
		selectedFolderId,
		error,
		rootMediaCount,
		searchQuery,
	} = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			rootFolders: store.getRootFolders(),
			isRootLoading:
				! store.isFolderLoaded( 0 ) && store.isFolderFetching( 0 ),
			selectedFolderId: store.getSelectedFolderId(),
			error: store.getError(),
			rootMediaCount: store.getRootMediaCount(),
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

	useEffect(
		() => () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		},
		[]
	);

	const handleSearchChange = ( value ) => {
		setInputValue( value );
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		debounceRef.current = setTimeout( () => {
			setSearchQuery( value );
			if ( value.trim() === '' ) {
				clearSearch();
			} else {
				searchFolders( value );
			}
		}, SEARCH_DEBOUNCE_MS );
	};

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

	const isSearching = searchQuery.trim() !== '';
	const rootFolderIds = rootFolders.map( ( f ) => f.id );

	return (
		<div className="foldsnap-folder-tree">
			<div className="foldsnap-folder-tree__search">
				<TextControl
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

			{ ! isSearching && (
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
					onKeyDown={ ( e ) =>
						e.key === 'Enter' && handleRootSelect()
					}
				>
					<span className="foldsnap-root-item__label">
						{ __( 'All Media', 'foldsnap' ) }
					</span>
					<span className="foldsnap-root-item__badge">
						{ rootMediaCount }
					</span>
				</div>
			) }

			{ isSearching ? (
				<SearchResultsList />
			) : (
				<>
					{ isRootLoading && (
						<div className="foldsnap-folder-tree__loading">
							<Spinner />
						</div>
					) }
					{ ! isRootLoading && (
						<SortableContext
							items={ rootFolderIds }
							strategy={ verticalListSortingStrategy }
						>
							{ rootFolders.map( ( folder ) => (
								<FolderItem
									key={ folder.id }
									folderId={ folder.id }
									selectedFolderId={ selectedFolderId }
									onSelect={ setSelectedFolder }
									depth={ 0 }
									onAddSubfolder={ handleOpenModal }
								/>
							) ) }
						</SortableContext>
					) }
				</>
			) }

			<div className="foldsnap-folder-tree__actions">
				<Button
					variant="secondary"
					onClick={ () => handleOpenModal( 0 ) }
					className="foldsnap-folder-tree__new-btn"
				>
					{ __( '+ New Folder', 'foldsnap' ) }
				</Button>
			</div>

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
