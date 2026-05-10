import { createRoot } from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import FolderSidebar from './components/FolderSidebar';
import initMediaModeBridge from './services/media-mode-bridge';
import { STORE_NAME } from './store/constants';
import './preferences';
import './store';

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

	dispatch( STORE_NAME ).bootFromUrl();
	initMediaModeBridge();
}
