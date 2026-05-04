import { useEffect, useRef } from '@wordpress/element';

export const SEARCH_DEBOUNCE_MS = 300;

/**
 * Schedule a callback after a quiet period.
 *
 * Returns a stable wrapper that resets a shared timer on every call and only
 * fires `callback(...args)` once `delay` ms have passed without further calls.
 * The pending timer is cleared on unmount.
 *
 * @param {Function} callback Callback to invoke after the quiet window.
 * @param {number}   delay    Quiet window in ms.
 * @return {Function} Debounced wrapper.
 */
export default function useDebouncedCallback( callback, delay ) {
	const timerRef = useRef( null );
	const callbackRef = useRef( callback );

	// Keep the latest callback without resetting the timer on every render.
	callbackRef.current = callback;

	useEffect(
		() => () => {
			if ( timerRef.current ) {
				clearTimeout( timerRef.current );
			}
		},
		[]
	);

	return ( ...args ) => {
		if ( timerRef.current ) {
			clearTimeout( timerRef.current );
		}
		timerRef.current = setTimeout( () => {
			callbackRef.current( ...args );
		}, delay );
	};
}
