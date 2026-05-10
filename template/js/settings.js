// Internal maintenance tool: triggers a fresh bottom-up recount of every
// folder counter via the recalculate REST endpoint, looping chunks until
// `done: true`. Not user-facing — kept intentionally tiny.

import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

const CHUNK_LIMIT = 200;

const setStatus = ( el, message ) => {
	if ( el ) {
		el.textContent = message;
	}
};

export const runRecount = async ( btn, status ) => {
	btn.disabled = true;
	setStatus( status, __( 'Resetting and rebuilding stack…', 'foldsnap' ) );

	let totalProcessed = 0;
	let isFirstCall = true;

	try {
		while ( true ) {
			const response = await apiFetch( {
				path: '/foldsnap/v1/folders/recalculate',
				method: 'POST',
				data: {
					limit: CHUNK_LIMIT,
					reset: isFirstCall,
				},
			} );

			isFirstCall = false;
			totalProcessed += response.processed ?? 0;

			if ( response.done ) {
				setStatus(
					status,
					sprintf(
						/* translators: %d: total folders processed */
						__( 'Done — %d folders recounted.', 'foldsnap' ),
						totalProcessed
					)
				);
				break;
			}

			setStatus(
				status,
				sprintf(
					/* translators: 1: processed so far, 2: remaining */
					__( 'Processed %1$d so far, %2$d remaining…', 'foldsnap' ),
					totalProcessed,
					response.remaining ?? 0
				)
			);
		}
	} catch ( err ) {
		setStatus(
			status,
			sprintf(
				/* translators: %s: error message */
				__( 'Failed: %s', 'foldsnap' ),
				err?.message ?? String( err )
			)
		);
	} finally {
		btn.disabled = false;
	}
};

document.addEventListener( 'DOMContentLoaded', () => {
	const btn = document.getElementById( 'foldsnap-recount-btn' );
	if ( ! btn ) {
		return;
	}
	const status = document.getElementById( 'foldsnap-recount-status' );
	btn.addEventListener( 'click', () => runRecount( btn, status ) );
} );
