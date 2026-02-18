import { createRoot } from '@wordpress/element';
import { subscribe } from '@wordpress/data';
import FolderSidebar from './components/FolderSidebar';
import { STORE_NAME } from './store/constants';
import './store'; // Ensure store is registered

/**
 * Create the sidebar container, insert it before #wpbody-content inside
 * #wpbody, then mount the React app on it.
 */
const wpbodyContent = document.getElementById( 'wpbody-content' );
if ( wpbodyContent && wpbodyContent.parentNode ) {
	const container = document.createElement( 'div' );
	container.id = 'foldsnap-sidebar';
	wpbodyContent.parentNode.insertBefore( container, wpbodyContent );

	const root = createRoot( container );
	root.render( <FolderSidebar /> );

	/**
	 * Subscribe to store changes and filter the native Media Library grid
	 * via the wp.media Backbone API whenever the selected folder changes.
	 *
	 * wp.media.frame.content.get('browse').collection is the Attachments
	 * collection powering the grid. Setting a prop on it triggers a re-fetch
	 * with the new query parameter, which our REST API reads as `folder_id`.
	 */
	subscribe( () => {
		if ( ! window.wp || ! window.wp.media || ! window.wp.media.frame ) {
			return;
		}

		const { select } = window.wp.data ?? {};
		if ( ! select ) {
			return;
		}

		const folderId = select( STORE_NAME ).getSelectedFolderId();

		try {
			const browse = window.wp.media.frame.content.get( 'browse' );
			if ( browse && browse.collection ) {
				browse.collection.props.set( {
					foldsnap_folder_id: folderId,
				} );
			}
		} catch {
			// Frame may not be initialised yet — safe to ignore.
		}
	} );
}
