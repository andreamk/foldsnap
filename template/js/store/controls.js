import apiFetch from '@wordpress/api-fetch';

const controls = {
	API_FETCH( { request } ) {
		return apiFetch( request );
	},
};

export default controls;
