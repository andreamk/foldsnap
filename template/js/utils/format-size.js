/**
 * Formats a byte count to a human-readable string.
 *
 * @param {number} bytes Size in bytes.
 * @return {string} Formatted size string.
 */
const formatSize = ( bytes ) => {
	if ( bytes < 1024 ) {
		return bytes + ' B';
	}
	if ( bytes < 1024 * 1024 ) {
		return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
	}
	return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ) + ' MB';
};

export default formatSize;
