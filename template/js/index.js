import { createRoot } from '@wordpress/element';
import App from './components/App';

const container = document.getElementById( 'foldsnap-app' );
if ( container ) {
	const root = createRoot( container );
	root.render( <App /> );
}
