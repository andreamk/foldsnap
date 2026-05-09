import { useState, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { buildSearchPath } from '../store/actions';
import useDebouncedCallback, {
	SEARCH_DEBOUNCE_MS,
} from './useDebouncedCallback';

// Self-contained folder search isolated from the global store search slice.
// Used by FolderPicker and any future "pick a folder" flow that must not
// overwrite the sidebar's active query.
//
// Returns:
//   inputValue   — controlled value for the search <input>
//   activeQuery  — trimmed query currently driving results (for "is searching")
//   results      — search hit list (`{ folder, breadcrumb }[]`)
//   isLoading    — true while a request is in flight for the active query
//   setInput     — handler to wire to <input onChange>
//
// Out-of-order responses are discarded via `latestQueryRef`.
export default function useLocalFolderSearch( { perPage } ) {
	const [ inputValue, setInputValue ] = useState( '' );
	const [ activeQuery, setActiveQuery ] = useState( '' );
	const [ results, setResults ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const latestQueryRef = useRef( '' );

	const commit = useDebouncedCallback( ( next ) => {
		const trimmed = next.trim();
		latestQueryRef.current = trimmed;
		setActiveQuery( trimmed );
		if ( trimmed === '' ) {
			setResults( [] );
			setIsLoading( false );
			return;
		}
		setIsLoading( true );
		apiFetch( {
			path: buildSearchPath( trimmed, 1, perPage ),
			method: 'GET',
		} )
			.then( ( response ) => {
				if ( latestQueryRef.current !== trimmed ) {
					return;
				}
				setResults( response?.results ?? [] );
				setIsLoading( false );
			} )
			.catch( () => {
				if ( latestQueryRef.current !== trimmed ) {
					return;
				}
				setResults( [] );
				setIsLoading( false );
			} );
	}, SEARCH_DEBOUNCE_MS );

	const setInput = ( next ) => {
		setInputValue( next );
		commit( next );
	};

	return { inputValue, activeQuery, results, isLoading, setInput };
}
