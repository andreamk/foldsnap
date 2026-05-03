import apiFetch from '@wordpress/api-fetch';
import { select } from '@wordpress/data';
import { STORE_NAME } from './constants';

const controls = {
	API_FETCH( { request } ) {
		return apiFetch( request );
	},

	SELECT( { selector, args } ) {
		return select( STORE_NAME )[ selector ]( ...args );
	},
};

export default controls;
