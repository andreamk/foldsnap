import { useEffect, useRef, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { STORE_NAME, ROOT_PARENT_ID } from '../store/constants';

const SEARCH_DEBOUNCE_MS = 300;

/**
 * One node in the embedded picker tree.
 *
 * Shares the same lazy-fetch model as the main FolderTree but renders a
 * radio-button selection instead of opening folders. Excludes the
 * `excludeId` subtree (used by reparent flows so a folder can't be moved
 * into itself or one of its descendants).
 *
 * @param {Object}   props                Props.
 * @param {number}   props.folderId       Folder ID to render.
 * @param {number}   props.depth          Indent level in pixels (× 16).
 * @param {number}   props.selectedId     Currently selected parent ID.
 * @param {Function} props.onSelect       Selection callback.
 * @param {number}   props.excludeId      Folder to hide (and its descendants).
 * @param {number[]} props.expandedIds    Locally expanded IDs.
 * @param {Function} props.onToggleExpand Expand/collapse callback.
 * @return {JSX.Element|null} Rendered node, or null when filtered out.
 */
const PickerNode = ( {
	folderId,
	depth,
	selectedId,
	onSelect,
	excludeId,
	expandedIds,
	onToggleExpand,
} ) => {
	const { folder, isFetching, children } = useSelect(
		( select ) => {
			const store = select( STORE_NAME );
			return {
				folder: store.getFolderById( folderId ),
				isFetching: store.isFolderFetching( folderId ),
				children: store.getChildrenOf( folderId ),
			};
		},
		[ folderId ]
	);

	if ( ! folder || folder.id === excludeId ) {
		return null;
	}

	const isExpanded = expandedIds.includes( folderId );
	const hasChildren = folder.has_children === true;

	return (
		<>
			<div
				className="foldsnap-picker__row"
				style={ { paddingLeft: depth * 16 } }
			>
				{ hasChildren ? (
					<button
						type="button"
						className="foldsnap-picker__chevron"
						onClick={ () => onToggleExpand( folderId ) }
						aria-label={
							isExpanded
								? __( 'Collapse', 'foldsnap' )
								: __( 'Expand', 'foldsnap' )
						}
					>
						{ isExpanded ? '▾' : '▸' }
					</button>
				) : (
					<span className="foldsnap-picker__chevron foldsnap-picker__chevron--empty" />
				) }
				<input
					id={ `foldsnap-picker-radio-${ folder.id }` }
					type="radio"
					name="foldsnap-picker-parent"
					value={ folder.id }
					checked={ selectedId === folder.id }
					onChange={ () => onSelect( folder.id ) }
				/>
				<label
					htmlFor={ `foldsnap-picker-radio-${ folder.id }` }
					className="foldsnap-picker__label"
				>
					{ folder.name }
				</label>
			</div>
			{ isExpanded && (
				<div className="foldsnap-picker__children">
					{ isFetching && children.length === 0 && <Spinner /> }
					{ children.map( ( child ) => (
						<PickerNode
							key={ child.id }
							folderId={ child.id }
							depth={ depth + 1 }
							selectedId={ selectedId }
							onSelect={ onSelect }
							excludeId={ excludeId }
							expandedIds={ expandedIds }
							onToggleExpand={ onToggleExpand }
						/>
					) ) }
				</div>
			) }
		</>
	);
};

/**
 * Lazy-loaded folder picker shared by Create / Reparent flows.
 *
 * Reuses the same `foldsnap/folders` store as the main tree, so a folder
 * the user expanded in the sidebar is already populated here. The "Root"
 * pseudo-entry maps to ROOT_PARENT_ID (0).
 *
 * Selection is committed via `onChange(parentId)`; the parent component
 * owns the form state. Search uses the existing search action and renders
 * a flat hit list while a query is active.
 *
 * @param {Object}   props           Props.
 * @param {number}   props.value     Currently selected parent ID (0 = root).
 * @param {Function} props.onChange  Selection callback (parentId: number).
 * @param {number}   props.excludeId Folder ID to hide (and its descendants).
 * @return {JSX.Element} Picker.
 */
const FolderPicker = ( { value, onChange, excludeId = 0 } ) => {
	const [ expandedIds, setExpandedIds ] = useState( [] );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ activeQuery, setActiveQuery ] = useState( '' );
	const debounceRef = useRef( null );

	const { rootFolders, isRootLoading, searchResults, searchIsLoading } =
		useSelect( ( select ) => {
			const store = select( STORE_NAME );
			return {
				rootFolders: store.getRootFolders(),
				isRootLoading:
					! store.isFolderLoaded( ROOT_PARENT_ID ) &&
					store.isFolderFetching( ROOT_PARENT_ID ),
				searchResults: store.getSearchResults(),
				searchIsLoading: store.isSearchLoading(),
			};
		}, [] );

	const { fetchChildren, searchFolders, clearSearch } =
		useDispatch( STORE_NAME );

	useEffect(
		() => () => {
			if ( debounceRef.current ) {
				clearTimeout( debounceRef.current );
			}
		},
		[]
	);

	const handleToggleExpand = ( folderId ) => {
		setExpandedIds( ( prev ) => {
			if ( prev.includes( folderId ) ) {
				return prev.filter( ( id ) => id !== folderId );
			}
			fetchChildren( folderId );
			return [ ...prev, folderId ];
		} );
	};

	const handleSearchChange = ( next ) => {
		setSearchInput( next );
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		debounceRef.current = setTimeout( () => {
			setActiveQuery( next.trim() );
			if ( next.trim() === '' ) {
				clearSearch();
			} else {
				searchFolders( next );
			}
		}, SEARCH_DEBOUNCE_MS );
	};

	const isSearching = activeQuery !== '';

	return (
		<div className="foldsnap-picker">
			<input
				type="search"
				aria-label={ __( 'Search folders', 'foldsnap' ) }
				className="foldsnap-picker__search"
				placeholder={ __( 'Search folders…', 'foldsnap' ) }
				value={ searchInput }
				onChange={ ( e ) => handleSearchChange( e.target.value ) }
			/>

			{ isSearching ? (
				<div className="foldsnap-picker__results">
					{ searchIsLoading && searchResults.length === 0 && (
						<Spinner />
					) }
					{ searchResults
						.filter( ( entry ) => entry.folder.id !== excludeId )
						.map( ( entry ) => (
							<button
								type="button"
								key={ entry.folder.id }
								className={ [
									'foldsnap-picker__result',
									value === entry.folder.id
										? 'foldsnap-picker__result--selected'
										: '',
								]
									.filter( Boolean )
									.join( ' ' ) }
								onClick={ () => onChange( entry.folder.id ) }
							>
								<span className="foldsnap-picker__result-name">
									{ entry.folder.name }
								</span>
								{ entry.breadcrumb?.length > 0 && (
									<span className="foldsnap-picker__result-path">
										{ entry.breadcrumb
											.map( ( a ) => a.name )
											.join( ' / ' ) }
									</span>
								) }
							</button>
						) ) }
				</div>
			) : (
				<div className="foldsnap-picker__tree">
					<div className="foldsnap-picker__row">
						<span className="foldsnap-picker__chevron foldsnap-picker__chevron--empty" />
						<input
							id="foldsnap-picker-radio-root"
							type="radio"
							name="foldsnap-picker-parent"
							value="0"
							checked={ value === ROOT_PARENT_ID }
							onChange={ () => onChange( ROOT_PARENT_ID ) }
						/>
						<label
							htmlFor="foldsnap-picker-radio-root"
							className="foldsnap-picker__label"
						>
							{ __( '— Root —', 'foldsnap' ) }
						</label>
					</div>
					{ isRootLoading && <Spinner /> }
					{ rootFolders.map( ( folder ) => (
						<PickerNode
							key={ folder.id }
							folderId={ folder.id }
							depth={ 1 }
							selectedId={ value }
							onSelect={ onChange }
							excludeId={ excludeId }
							expandedIds={ expandedIds }
							onToggleExpand={ handleToggleExpand }
						/>
					) ) }
				</div>
			) }
		</div>
	);
};

export default FolderPicker;
