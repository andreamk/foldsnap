// In list mode the page is a server-rendered table, so changing folder
// requires a full reload with `foldsnap_folder_id` in the query string.
export const redirectListMode = ( folderId ) => {
	const url = new URL( window.location.href );
	if ( folderId === null ) {
		url.searchParams.delete( 'foldsnap_folder_id' );
	} else {
		url.searchParams.set( 'foldsnap_folder_id', folderId );
	}
	window.location.href = url.toString();
};
