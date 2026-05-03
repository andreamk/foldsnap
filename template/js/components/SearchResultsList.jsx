import { Spinner, Button } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { STORE_NAME } from '../store/constants';

/**
 * Renders the paginated search-results panel that replaces the folder tree
 * while a query is active.
 *
 * Each row shows the matched folder plus its breadcrumb (root → … → folder)
 * so users can disambiguate same-named folders. Clicking a row selects the
 * folder, inflates the path so the tree shows it expanded, and clears the
 * search so the user lands back on the tree view focused on the result.
 *
 * @return {JSX.Element} Search results panel.
 */
const SearchResultsList = () => {
	const { results, isLoading, pagination } = useSelect( ( select ) => {
		const store = select( STORE_NAME );
		return {
			results: store.getSearchResults(),
			isLoading: store.isSearchLoading(),
			pagination: store.getSearchPagination(),
		};
	}, [] );

	const {
		setSelectedFolder,
		expandPathTo,
		setSearchQuery,
		clearSearch,
		loadMoreSearchResults,
	} = useDispatch( STORE_NAME );

	const handleSelect = async ( folderId ) => {
		setSelectedFolder( folderId );
		await expandPathTo( folderId );
		setSearchQuery( '' );
		clearSearch();
	};

	if ( isLoading && results.length === 0 ) {
		return (
			<div className="foldsnap-search-results foldsnap-search-results--loading">
				<Spinner />
			</div>
		);
	}

	if ( results.length === 0 ) {
		return (
			<div className="foldsnap-search-results foldsnap-search-results--empty">
				{ __( 'No folders match your search.', 'foldsnap' ) }
			</div>
		);
	}

	const hasMore = pagination.page < pagination.totalPages;

	return (
		<div className="foldsnap-search-results">
			<div className="foldsnap-search-results__count" aria-live="polite">
				{ sprintf(
					/* translators: %d: total number of matching folders. */
					__( '%d folders found', 'foldsnap' ),
					pagination.total
				) }
			</div>
			<ul className="foldsnap-search-results__list">
				{ results.map( ( entry ) => (
					<li
						key={ entry.folder.id }
						className="foldsnap-search-results__item"
					>
						<button
							type="button"
							className="foldsnap-search-results__button"
							onClick={ () => handleSelect( entry.folder.id ) }
						>
							<span className="foldsnap-search-results__name">
								{ entry.folder.name }
							</span>
							{ entry.breadcrumb?.length > 0 && (
								<span className="foldsnap-search-results__breadcrumb">
									{ entry.breadcrumb
										.map( ( ancestor ) => ancestor.name )
										.join( ' / ' ) }
								</span>
							) }
						</button>
					</li>
				) ) }
			</ul>
			{ hasMore && (
				<div className="foldsnap-search-results__more">
					<Button
						variant="tertiary"
						onClick={ loadMoreSearchResults }
						disabled={ isLoading }
						isBusy={ isLoading }
					>
						{ __( 'Load more', 'foldsnap' ) }
					</Button>
				</div>
			) }
		</div>
	);
};

export default SearchResultsList;
