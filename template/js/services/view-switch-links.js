// The grid/list mode toggle is a server-rendered <a class="view-switch">
// pair: rewrite their hrefs so the current folder survives the mode change.
export const updateModeToggleLinks = ( folderId ) => {
	document.querySelectorAll( '.view-switch a' ).forEach( ( link ) => {
		const linkUrl = new URL( link.href );
		if ( folderId === null ) {
			linkUrl.searchParams.delete( 'foldsnap_folder_id' );
		} else {
			linkUrl.searchParams.set( 'foldsnap_folder_id', folderId );
		}
		link.href = linkUrl.toString();
	} );
};
